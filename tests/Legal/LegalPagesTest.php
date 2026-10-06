<?php

declare(strict_types=1);

namespace App\Tests\Legal;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LegalPagesTest extends WebTestCase
{
    public function testFrenchLegalPagesAndFooterLinksAreAvailable(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/fr/conditions-generales-utilisation');
        self::assertResponseIsSuccessful();
        self::assertSame('Conditions générales', $crawler->filter('h1')->text());

        $termsUrl = 'https://lnstrade.fr/fr/conditions-generales-utilisation';
        $privacyUrl = 'https://lnstrade.fr/fr/politique-de-confidentialite';
        self::assertSame(1, $crawler->filter('.footer-bottom a[href="'.$termsUrl.'"]')->count());
        self::assertSame(1, $crawler->filter('.footer-bottom a[href="'.$privacyUrl.'"]')->count());

        $crawler = $client->request('GET', '/fr/politique-de-confidentialite');
        self::assertResponseIsSuccessful();
        self::assertSame('Politique de confidentialité', $crawler->filter('h1')->text());
    }

    public function testEnglishLegalPagesAreAvailable(): void
    {
        $client = static::createClient();

        foreach (['/en/terms-and-conditions', '/en/privacy-policy'] as $path) {
            $client->request('GET', $path);
            self::assertResponseIsSuccessful();
        }
    }
}
