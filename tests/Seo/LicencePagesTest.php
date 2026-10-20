<?php

declare(strict_types=1);

namespace App\Tests\Seo;

use App\Catalog\LicenceCatalog;
use App\Entity\Prospect;
use App\Entity\VisitorSession;
use App\HubSpot\HubSpotGateway;
use App\Locale\SiteLocale;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class LicencePagesTest extends WebTestCase
{
    public function testCollectionsHaveLocalizedContentImagesFormsAndReciprocalLinks(): void
    {
        $client = static::createClient();
        $router = static::getContainer()->get(UrlGeneratorInterface::class);
        $titles = [];
        foreach (SiteLocale::CODES as $locale) {
            $copy = json_decode(file_get_contents(dirname(__DIR__, 2).'/translations/licences.'.$locale.'.json'), true, flags: JSON_THROW_ON_ERROR);
            foreach ((new LicenceCatalog())->all() as $slug => $collection) {
                $path = $router->generate('app_licence.'.$locale, ['licence' => $slug]);
                $crawler = $client->request('GET', $path.'?utm_source=test');
                self::assertResponseIsSuccessful();
                self::assertSame([], $client->getResponse()->headers->getCookies());
                self::assertSame($locale, $crawler->filter('html')->attr('lang'));
                self::assertSame('https://lnstrade.fr'.$path, $crawler->filter('link[rel="canonical"]')->attr('href'));
                self::assertSame($copy[$slug.'.meta.title'], $crawler->filter('title')->text());
                self::assertSame($copy[$slug.'.meta.description'], $crawler->filter('meta[name="description"]')->attr('content'));
                $titles[] = $crawler->filter('title')->text();
                self::assertSame(1, $crawler->filter('h1')->count());
                self::assertSame($copy[$slug.'.h1'], $crawler->filter('h1')->text());
                self::assertSame(1, $crawler->filter('#contact-form')->count());
                self::assertSame($locale, $crawler->filter('#contact-form input[name="locale"]')->attr('value'));
                self::assertStringContainsString($collection['name'], $crawler->filter('#contact-form input[name="context"]')->attr('value'));
                self::assertSame(0, $crawler->filter('#contact-modal')->count());
                self::assertSame(1, $crawler->filter('.licence-hero img[fetchpriority="high"]')->count());
                self::assertSame(4, $crawler->filter('.licence-product img[loading="lazy"]')->count());
                foreach ($crawler->filter('.licence-hero img, .licence-product img') as $image) {
                    self::assertStringEndsWith('.webp', $image->getAttribute('src'));
                    self::assertNotSame('', $image->getAttribute('srcset'));
                    self::assertNotSame('', $image->getAttribute('alt'));
                    self::assertGreaterThan(0, (int) $image->getAttribute('width'));
                    self::assertGreaterThan(0, (int) $image->getAttribute('height'));
                }
                foreach (SiteLocale::CODES as $alternate) {
                    $alternatePath = $router->generate('app_licence.'.$alternate, ['licence' => $slug]);
                    self::assertSame('https://lnstrade.fr'.$alternatePath, $crawler->filter('link[hreflang="'.$alternate.'"]')->attr('href'));
                    self::assertSame($alternatePath, $crawler->filter('.language-menu a[data-language="'.$alternate.'"]')->attr('href'));
                }
                $graph = json_decode($crawler->filter('script[data-page-schema]')->text(), true, flags: JSON_THROW_ON_ERROR)['@graph'];
                $page = array_values(array_filter($graph, static fn (array $item): bool => 'CollectionPage' === $item['@type']))[0];
                self::assertSame(4, $page['mainEntity']['numberOfItems']);
                foreach ($page['mainEntity']['itemListElement'] as $index => $item) {
                    self::assertSame($crawler->filter('.licence-product h3')->eq($index)->text(), $item['item']['name']);
                }
                $faq = array_values(array_filter($graph, static fn (array $item): bool => 'FAQPage' === $item['@type']))[0];
                self::assertCount(4, $faq['mainEntity']);
                foreach ($faq['mainEntity'] as $index => $question) {
                    $detail = $crawler->filter('.faq-list details')->eq($index);
                    self::assertSame($question['name'], trim(str_replace('+', '', $detail->filter('summary')->text())));
                    self::assertSame($question['acceptedAnswer']['text'], $detail->filter('p')->text());
                }
                $breadcrumb = array_values(array_filter($graph, static fn (array $item): bool => 'BreadcrumbList' === $item['@type']))[0];
                self::assertCount(3, $breadcrumb['itemListElement']);
                self::assertSame($collection['name'], $breadcrumb['itemListElement'][2]['name']);
            }
        }
        self::assertCount(30, array_unique($titles));
    }

    public function testCollectionsAreLinkedFromCommercialPagesAndUnknownLicencesAreNotIndexed(): void
    {
        $client = static::createClient();
        foreach (['/fr/marque-ultrapop', '/fr/catalogue-produits', '/fr/grossiste-snacks-boissons-manga'] as $path) {
            $crawler = $client->request('GET', $path);
            self::assertResponseIsSuccessful();
            self::assertSame(5, $crawler->filter('.licence-discovery-card')->count());
        }
        $client->request('GET', '/fr/grossiste-inconnue-boissons-snacks');
        self::assertResponseStatusCodeSame(404);
    }

    public function testTranslatedCollectionsKeepTheirLocaleAndInterestWhenTheLeadIsSaved(): void
    {
        $client = static::createClient([], ['HTTP_ORIGIN' => 'http://localhost']);
        $client->disableReboot();
        $container = static::getContainer();
        $manager = $container->get(EntityManagerInterface::class);
        self::assertSame('pdo_sqlite', $manager->getConnection()->getParams()['driver']);
        (new SchemaTool($manager))->createSchema(array_map($manager->getClassMetadata(...), [Prospect::class, VisitorSession::class]));
        $hubSpot = $this->createMock(HubSpotGateway::class);
        $hubSpot->expects(self::never())->method('submit');
        $container->set(HubSpotGateway::class, $hubSpot);
        foreach (['fr' => '/fr/grossiste-naruto-boissons-snacks', 'de' => '/de/league-of-legends-getraenke-snacks-grosshandel'] as $locale => $path) {
            $crawler = $client->request('GET', $path);
            $context = $crawler->filter('#contact-form input[name="context"]')->attr('value');
            $client->request('POST', '/contact', [
                '_csrf_token' => $crawler->filter('#contact-form input[name="_csrf_token"]')->attr('value'),
                'name' => 'Test '.$locale,
                'company' => 'Example',
                'email' => $locale.'@example.com',
                'locale' => $locale,
                'context' => $context,
                'origin' => 'https://lnstrade.fr'.$path,
            ]);
            self::assertResponseIsSuccessful();
            $prospect = $manager->getRepository(Prospect::class)->findOneBy(['email' => $locale.'@example.com']);
            self::assertInstanceOf(Prospect::class, $prospect);
            self::assertSame($locale, $prospect->getLocale());
            self::assertSame($context, $prospect->toContactSubmission()->context);
        }
    }

    public function testEveryLanguageHasTheWholeCollectionCopyAndPreservesPlaceholders(): void
    {
        $root = dirname(__DIR__, 2).'/translations/';
        $english = json_decode(file_get_contents($root.'licences.en.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach (SiteLocale::CODES as $locale) {
            $messages = json_decode(file_get_contents($root.'licences.'.$locale.'.json'), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(array_keys($english), array_keys($messages), $locale);
            foreach ($messages as $key => $message) {
                self::assertNotSame('', trim($message));
                self::assertStringNotContainsString('ZXBR', $message);
                self::assertSame(substr_count($english[$key], '%licence%'), substr_count($message, '%licence%'));
            }
        }
    }
}
