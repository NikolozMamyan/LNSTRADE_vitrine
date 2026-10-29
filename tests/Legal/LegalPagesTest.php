<?php

declare(strict_types=1);

namespace App\Tests\Legal;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LegalPagesTest extends WebTestCase
{
    public function testSalesTermsAreAvailableInFrenchAndEnglishWithReciprocalLanguageLinks(): void
    {
        $client = static::createClient();

        foreach (['fr' => 'Conditions générales de vente 2026', 'en' => 'Terms and conditions of sale 2026'] as $locale => $title) {
            $crawler = $client->request('GET', '/'.$locale.'/cgv');
            self::assertResponseIsSuccessful();
            self::assertSame($locale, $crawler->filter('html')->attr('lang'));
            self::assertSame($title, $crawler->filter('h1')->text());
            self::assertSame(16, $crawler->filter('.legal-document h2')->count());
            self::assertStringContainsString('803 854 447 00031', $crawler->filter('.legal-document')->text());
            self::assertStringContainsString('FR216822_01AFXZ', $crawler->filter('.legal-document p')->last()->text());
            self::assertSame('https://lnstrade.fr/'.$locale.'/cgv', $crawler->filter('link[rel="canonical"]')->attr('href'));
            self::assertSame(1, $crawler->filter('.footer-bottom a[href="https://lnstrade.fr/'.$locale.'/cgv"]')->count());
            self::assertSame(2, $crawler->filter('.language-menu a')->count());
            self::assertSame(3, $crawler->filter('link[rel="alternate"][hreflang]')->count());

            foreach (['fr', 'en'] as $alternate) {
                self::assertSame('/'.$alternate.'/cgv', $crawler->filter('.language-menu a[lang="'.$alternate.'"]')->attr('href'));
                self::assertSame('https://lnstrade.fr/'.$alternate.'/cgv', $crawler->filter('link[rel="alternate"][hreflang="'.$alternate.'"]')->attr('href'));
            }
        }
    }

    public function testSalesTermsAliasRedirectsToTheFrenchVersion(): void
    {
        static::createClient()->request('GET', '/cgv');
        self::assertResponseRedirects('/fr/cgv', 301);
    }

    public function testOtherLanguagesLinkToTheEnglishSalesTerms(): void
    {
        $client = static::createClient();

        foreach (['de', 'es', 'nb', 'nl'] as $locale) {
            $crawler = $client->request('GET', '/'.$locale.'/');
            self::assertResponseIsSuccessful();
            self::assertSame('Terms of sale', $crawler->filter('.footer-bottom a[href="https://lnstrade.fr/en/cgv"]')->text());
        }
    }

    public function testOtherSalesTermsLanguagesRedirectToEnglish(): void
    {
        $client = static::createClient();

        foreach (['de', 'es', 'nb', 'nl', 'pt', 'it'] as $locale) {
            $client->request('GET', '/'.$locale.'/cgv');
            self::assertResponseRedirects('/en/cgv', 301);
        }
    }

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

    public function testLegalNoticesIncludeTheCompanyIdentityAndAreLinkedFromTheFooter(): void
    {
        $client = static::createClient();
        foreach (['/fr/mentions-legales', '/en/legal-notice'] as $path) {
            $crawler = $client->request('GET', $path);
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('803 854 447', $crawler->filter('main')->text());
            self::assertStringContainsString('FR84803854447', $crawler->filter('main')->text());
            self::assertStringContainsString('13 Quai Kléber', $crawler->filter('main')->text());
            self::assertStringContainsString('Éric Uzan', $crawler->filter('main')->text());
            self::assertSame(1, $crawler->filter('.footer-bottom a[href="https://lnstrade.fr'.$path.'"]')->count());
        }
    }
}
