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
        self::assertTrue($client->getResponse()->headers->has('ETag'));
        self::assertTrue($client->getResponse()->headers->has('Last-Modified'));
        $etag = $client->getResponse()->headers->get('ETag');

        $xml = $client->getResponse()->getContent();
        self::assertIsString($xml);
        self::assertSame(16, substr_count($xml, '<url>'));
        self::assertStringContainsString('<loc>https://lnstrade.fr/en/</loc>', $xml);
        self::assertStringContainsString('<loc>https://lnstrade.fr/fr/</loc>', $xml);
        self::assertStringContainsString('<?xml-stylesheet type="text/xsl" href="/sitemap.xsl"?>', $xml);
        self::assertStringNotContainsString('<priority>', $xml);
        self::assertStringNotContainsString('<changefreq>', $xml);
        self::assertStringNotContainsString('internal-proxy.local', $xml);

        $document = new \DOMDocument();
        self::assertTrue($document->loadXML($xml));
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('sm', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $xpath->registerNamespace('xhtml', 'http://www.w3.org/1999/xhtml');
        self::assertSame(16, $xpath->query('/sm:urlset/sm:url')->length);
        self::assertSame(48, $xpath->query('/sm:urlset/sm:url/xhtml:link')->length);

        $client->request('GET', '/sitemap.xml', server: ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_MODIFIED);
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
}
