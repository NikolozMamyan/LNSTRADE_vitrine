<?php

declare(strict_types=1);

namespace App\Tests\Seo;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class SeoSmokeTest extends WebTestCase
{
    public function testSitemapContainsEveryLocalizedCanonicalUrl(): void
    {
        $client = static::createClient();
        $client->request('GET', '/sitemap.xml', server: ['HTTP_HOST' => 'internal-proxy.local']);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/xml; charset=UTF-8');
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');

        $xml = $client->getResponse()->getContent();
        self::assertIsString($xml);
        self::assertSame(78, substr_count($xml, '<url>'));
        self::assertStringContainsString('<loc>https://lnstrade.fr/en/</loc>', $xml);
        self::assertStringContainsString('<loc>https://lnstrade.fr/fr/</loc>', $xml);
        self::assertStringNotContainsString('xml-stylesheet', $xml);
        self::assertSame(78, substr_count($xml, '<lastmod>'));
        self::assertStringNotContainsString('<priority>', $xml);
        self::assertStringNotContainsString('<changefreq>', $xml);
        self::assertStringNotContainsString('internal-proxy.local', $xml);

        $document = new \DOMDocument();
        self::assertTrue($document->loadXML($xml));
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('sm', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        self::assertSame(78, $xpath->query('/sm:urlset/sm:url')->length);
    }

    public function testProductionRobotsAllowsCrawlersAndDeclaresSitemap(): void
    {
        $client = static::createClient([], ['HTTP_HOST' => 'lnstrade.fr', 'HTTPS' => 'on']);
        $client->request('GET', '/robots.txt');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/plain; charset=UTF-8');
        self::assertStringContainsString("User-agent: *\nAllow: /", (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Sitemap: https://lnstrade.fr/sitemap.xml', (string) $client->getResponse()->getContent());
    }

    public function testUnknownHostRobotsBlocksCrawlers(): void
    {
        $client = static::createClient([], ['HTTP_HOST' => 'preprod.lnstrade.fr', 'HTTPS' => 'on']);
        $client->request('GET', '/robots.txt');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString("User-agent: *\nDisallow: /", (string) $client->getResponse()->getContent());
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    public function testEverySitemapUrlIsAReachableCanonicalPage(): void
    {
        $client = static::createClient();
        $client->request('GET', '/sitemap.xml');
        $xml = (string) $client->getResponse()->getContent();
        $document = new \DOMDocument();
        $document->loadXML($xml);
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('sm', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        foreach ($xpath->query('/sm:urlset/sm:url/sm:loc') as $location) {
            $path = parse_url($location->textContent, PHP_URL_PATH);
            self::assertIsString($path);
            $crawler = $client->request('GET', $path);

            self::assertResponseIsSuccessful();
            self::assertFalse($client->getResponse()->isRedirect());
            self::assertSame($location->textContent, $crawler->filter('link[rel="canonical"]')->attr('href'));
        }
    }

    public function testLocalizedPageUsesThePublicCanonicalDomain(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/grossiste-snacks-boissons-manga', server: ['HTTP_HOST' => 'internal-proxy.local']);

        self::assertResponseIsSuccessful();
        self::assertSame(
            'https://lnstrade.fr/fr/grossiste-snacks-boissons-manga',
            $crawler->filter('link[rel="canonical"]')->attr('href'),
        );
        self::assertSame(1, $crawler->filter('h1')->count());
        self::assertNotSame('', $crawler->filter('title')->text());
        self::assertNotSame('', $crawler->filter('meta[name="description"]')->attr('content'));
    }

    public function testPublicPagesLoadGoogleTagManagerAndAnalytics(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('script[src="https://www.googletagmanager.com/gtag/js?id=G-55X3CXZ5B7"]')->count());
        self::assertSame(1, $crawler->filter('noscript iframe[src="https://www.googletagmanager.com/ns.html?id=GTM-WX33M7V8"]')->count());

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString("gtag('config', 'G-55X3CXZ5B7')", $html);
        self::assertStringContainsString("'GTM-WX33M7V8'", $html);
    }

    public function testLegacyWordPressSitemapsAreGoneWithoutRedirect(): void
    {
        $client = static::createClient();

        foreach (['/wp-sitemap.xml', '/wp-sitemap-posts-page-1.xml'] as $path) {
            $client->request('GET', $path);

            self::assertResponseStatusCodeSame(Response::HTTP_GONE);
            self::assertResponseHeaderSame('X-Robots-Tag', 'noindex');
            self::assertFalse($client->getResponse()->isRedirect());
        }
    }

    public function testAnonymousPagesDoNotStartAPhpSessionAndFormsRemainUnique(): void
    {
        $client = static::createClient();
        foreach (['/fr/', '/en/', '/fr/contact', '/en/contact', '/fr/grossiste-snacks-boissons-manga', '/fr/catalogue-produits', '/fr/mentions-legales'] as $path) {
            $crawler = $client->request('GET', $path);
            self::assertResponseIsSuccessful();
            self::assertSame([], $client->getResponse()->headers->getCookies(), $path);
            self::assertSame(1, $crawler->filter('#contact-form')->count(), $path);
            self::assertSame('csrf-token', $crawler->filter('#contact-form input[name="_csrf_token"]')->attr('value'));
            self::assertSame(0, $crawler->filter('.site-footer h2, #range-builder-modal h2, #range-builder-modal h3, #range-builder-modal h4')->count());
        }
    }

    public function testFaqStructuredDataMatchesTheVisibleAnswersInBothLanguages(): void
    {
        $client = static::createClient();
        foreach (['/fr/', '/en/', '/fr/grossiste-snacks-boissons-manga', '/en/manga-snacks-drinks-wholesaler'] as $path) {
            $crawler = $client->request('GET', $path);
            self::assertResponseIsSuccessful();
            $graph = json_decode($crawler->filter('script[type="application/ld+json"]')->text(), true, flags: JSON_THROW_ON_ERROR)['@graph'];
            $faq = array_values(array_filter($graph, static fn (array $item): bool => 'FAQPage' === $item['@type']))[0];
            self::assertCount($crawler->filter('.faq-list details')->count(), $faq['mainEntity']);
            foreach ($faq['mainEntity'] as $index => $question) {
                $detail = $crawler->filter('.faq-list details')->eq($index);
                self::assertSame($question['name'], trim(str_replace('+', '', $detail->filter('summary')->text())));
                self::assertSame($question['acceptedAnswer']['text'], $detail->filter('p')->text());
            }
            $page = array_values(array_filter($graph, static fn (array $item): bool => 'WebPage' === $item['@type']))[0];
            self::assertArrayNotHasKey('datePublished', $page);
            self::assertArrayNotHasKey('author', $page);
        }
    }

    public function testArticleMetadataKeepsPublicationAndUpdateDatesAndServerRenderedBreadcrumbs(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/blog/choisir-grossiste-boissons-manga');
        self::assertResponseIsSuccessful();
        $graph = json_decode($crawler->filter('script[type="application/ld+json"]')->text(), true, flags: JSON_THROW_ON_ERROR)['@graph'];
        $article = array_values(array_filter($graph, static fn (array $item): bool => 'BlogPosting' === $item['@type']))[0];
        self::assertSame('2026-09-21', $article['datePublished']);
        self::assertSame('2026-10-16', $article['dateModified']);
        self::assertSame('Organization', $article['author']['@type']);
        self::assertSame(1, $crawler->filter('.article-author time[datetime="2026-10-16"]')->count());
        $breadcrumb = array_values(array_filter($graph, static fn (array $item): bool => 'BreadcrumbList' === $item['@type']))[0];
        self::assertCount(3, $breadcrumb['itemListElement']);
        self::assertSame('https://lnstrade.fr/fr/blog', $breadcrumb['itemListElement'][1]['item']);
        self::assertGreaterThanOrEqual(3, $crawler->filter('.article-body a[href^="/fr/"]')->count());
    }

    public function testLanguageNegotiationAndCampaignParametersArePreserved(): void
    {
        $client = static::createClient();
        foreach (['fr-FR,fr;q=0.9,en;q=0.5' => 'fr', 'en-GB,en;q=0.9' => 'en'] as $language => $locale) {
            $client->request('GET', '/?utm_source=google&utm_campaign=lnstrade_europe', server: ['HTTP_ACCEPT_LANGUAGE' => $language]);
            self::assertResponseRedirects('/'.$locale.'/?utm_source=google&utm_campaign=lnstrade_europe', Response::HTTP_FOUND);
            self::assertContains('Accept-Language', $client->getResponse()->getVary());
        }
        $client->request('GET', '/contact?utm_source=google', server: ['HTTP_ACCEPT_LANGUAGE' => 'fr-FR']);
        self::assertResponseRedirects('/fr/contact?utm_source=google', Response::HTTP_FOUND);
    }
}
