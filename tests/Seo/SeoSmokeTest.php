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
        self::assertSame(16, substr_count($xml, '<url>'));
        self::assertStringContainsString('<loc>https://lnstrade.fr/en/</loc>', $xml);
        self::assertStringContainsString('<loc>https://lnstrade.fr/fr/</loc>', $xml);
        self::assertStringNotContainsString('internal-proxy.local', $xml);
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
