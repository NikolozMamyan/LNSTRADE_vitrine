<?php

declare(strict_types=1);

namespace App\Tests\Seo;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StorefrontLinksTest extends WebTestCase
{
    public function testHomeProductAndLicenseCardsLinkToFilteredUltrapopPages(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/');

        self::assertResponseIsSuccessful();
        self::assertSame(3, $crawler->filter('.product-gallery a.product-card-link')->count());
        self::assertSame(16, $crawler->filter('.license-strip a.license-carousel-card')->count());

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('https://ultrapop.com/boutique?category=Boissons', $html);
        self::assertStringContainsString('https://ultrapop.com/boutique?category=%C3%89picerie%20sal%C3%A9e', $html);
        self::assertStringContainsString('https://ultrapop.com/boutique?category=N%C3%A9gisan%20-%20Nouilles%20instantan%C3%A9es', $html);
        self::assertStringContainsString('https://ultrapop.com/licences?license=One%20Piece', $html);
        self::assertStringContainsString('https://ultrapop.com/licences?license=Dragon%20Ball%20Z', $html);
        self::assertStringContainsString('https://ultrapop.com/licences?license=Jujutsu%20Kaisen', $html);
    }

    public function testUltrapopPageLinksEveryRangeAndCallToActionToTheShop(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/marque-ultrapop');

        self::assertResponseIsSuccessful();
        self::assertSame(3, $crawler->filter('.product-gallery a.product-card-link')->count());

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('https://ultrapop.com/boutique?category=Boissons', $html);
        self::assertStringContainsString('https://ultrapop.com/boutique?category=%C3%89picerie%20sucr%C3%A9e', $html);
        self::assertStringContainsString('https://ultrapop.com/boutique?category=%C3%89picerie%20sal%C3%A9e', $html);
        self::assertStringContainsString('href="https://ultrapop.com/licences"', $html);
        self::assertStringContainsString('href="https://ultrapop.com/boutique"', $html);
    }
}
