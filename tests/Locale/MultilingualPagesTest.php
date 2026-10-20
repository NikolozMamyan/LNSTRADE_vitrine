<?php

declare(strict_types=1);

namespace App\Tests\Locale;

use App\Entity\Prospect;
use App\Entity\VisitorSession;
use App\HubSpot\HubSpotGateway;
use App\Locale\SiteLocale;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class MultilingualPagesTest extends WebTestCase
{
    private const LANDINGS = [
        'de' => '/de/manga-snacks-getraenke-grosshandel',
        'es' => '/es/mayorista-snacks-bebidas-manga',
        'nb' => '/nb/grossist-manga-snacks-drikke',
        'nl' => '/nl/groothandel-manga-snacks-dranken',
    ];

    public function testBrowserLanguageSelectionIncludesNorwegianAliasesAndKeepsPreferenceOrder(): void
    {
        $resolver = new SiteLocale();
        foreach (['de-CH,de;q=0.9' => 'de', 'es-ES' => 'es', 'nb-NO' => 'nb', 'no-NO' => 'nb', 'nn-NO' => 'nb', 'nl-BE' => 'nl', 'en-GB,de;q=0.8' => 'en', 'pt-BR,es;q=0.8' => 'es', 'ja-JP' => 'en'] as $header => $expected) {
            self::assertSame($expected, $resolver->preferred(Request::create('/', server: ['HTTP_ACCEPT_LANGUAGE' => $header])));
        }
    }

    public function testEveryNewLandingHasTranslatedMetadataAndReciprocalLanguageLinks(): void
    {
        $client = static::createClient();
        foreach (self::LANDINGS as $locale => $path) {
            $crawler = $client->request('GET', $path);
            self::assertResponseIsSuccessful();
            self::assertSame($locale, $crawler->filter('html')->attr('lang'));
            self::assertSame('https://lnstrade.fr'.$path, $crawler->filter('link[rel="canonical"]')->attr('href'));
            self::assertSame(1, $crawler->filter('h1')->count());
            self::assertSame($locale, $crawler->filter('#contact-form input[name="locale"]')->attr('value'));
            self::assertSame(6, $crawler->filter('.language-menu a')->count());
            foreach (SiteLocale::CODES as $alternate) {
                self::assertSame(1, $crawler->filter('link[rel="alternate"][hreflang="'.$alternate.'"]')->count());
                self::assertStringStartsWith('https://lnstrade.fr/'.$alternate.'/', $crawler->filter('link[rel="alternate"][hreflang="'.$alternate.'"]')->attr('href'));
            }
            self::assertStringNotContainsString('meta.', $crawler->filter('title')->text());
            self::assertNotSame('Wholesale manga snacks and drinks for professionals', $crawler->filter('title')->text());
        }
    }

    public function testTranslationFilesCoverAllExistingMessages(): void
    {
        $root = dirname(__DIR__, 2).'/translations/';
        $english = json_decode(file_get_contents($root.'messages.en.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach (['de', 'es', 'nb', 'nl'] as $locale) {
            $messages = json_decode(file_get_contents($root.'messages.'.$locale.'.json'), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame([], array_diff_key($english, $messages), $locale);
            foreach ($messages as $key => $message) {
                self::assertIsString($message);
                self::assertNotSame('', trim($message), $locale.':'.$key);
                self::assertStringNotContainsString('ZXBR', $message, $locale.':'.$key);
            }
        }
    }

    public function testNewLanguagesAreRecordedOnTheProspectWithoutCallingHubSpot(): void
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
        foreach (self::LANDINGS as $locale => $path) {
            $crawler = $client->request('GET', $path);
            $client->request('POST', '/contact', [
                '_csrf_token' => $crawler->filter('#contact-form input[name="_csrf_token"]')->attr('value'),
                'name' => 'Test '.$locale,
                'company' => 'Example',
                'email' => $locale.'@example.com',
                'locale' => $locale,
                'origin' => 'https://lnstrade.fr'.$path,
            ]);
            self::assertResponseIsSuccessful();
            self::assertTrue(json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['submitted']);
            $prospect = $manager->getRepository(Prospect::class)->findOneBy(['email' => $locale.'@example.com']);
            self::assertInstanceOf(Prospect::class, $prospect);
            self::assertSame($locale, $prospect->getLocale());
        }
    }
}
