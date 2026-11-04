<?php

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Entity\Product;
use App\Entity\ProductImage;
use App\Entity\ProductTranslation;
use App\Product\ProductImporter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ShopControllerTest extends WebTestCase
{
    public function testPreviewRendersInEveryLanguageWithLocalizedProductsAndNoPayment(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame('pdo_sqlite', $entityManager->getConnection()->getParams()['driver']);
        (new SchemaTool($entityManager))->createSchema(array_map($entityManager->getClassMetadata(...), [Product::class, ProductImage::class, ProductTranslation::class]));
        static::getContainer()->get(ProductImporter::class)->import();
        foreach (['fr' => '/fr/boutique', 'en' => '/en/shop', 'de' => '/de/shop', 'es' => '/es/tienda', 'nb' => '/nb/butikk', 'nl' => '/nl/winkel'] as $locale => $path) {
            $crawler = $client->request('GET', $path);
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
            self::assertSelectorExists('html[lang="'.$locale.'"]');
            self::assertSelectorExists('#checkout-preview button[disabled]');
            $data = json_decode($crawler->filter('#shop-data')->text(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame($locale, $data['locale']);
            self::assertNotEmpty($data['products']);
            self::assertSame('Cerise' === $data['products'][0]['flavor'], 'fr' === $locale);
            self::assertSame(['drinks', 'Jus fruités', '33cl'], json_decode($data['products'][0]['variantGroup'], true, flags: JSON_THROW_ON_ERROR));
            foreach ($data['products'] as $product) {
                self::assertStringStartsWith('/assets/images/shop/', $product['images'][0]);
            }
        }
    }
}
