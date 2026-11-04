<?php

declare(strict_types=1);

namespace App\Tests\Product;

use App\Entity\AdminSession;
use App\Entity\AdminUser;
use App\Entity\Product;
use App\Entity\ProductImage;
use App\Entity\ProductTranslation;
use App\Product\ProductImporter;
use App\Product\ProductManager;
use App\Repository\ProductRepository;
use App\Security\SessionManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AdminProductControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private int $productId;
    private string $directory;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        self::assertSame('pdo_sqlite', $this->em->getConnection()->getParams()['driver']);
        (new SchemaTool($this->em))->createSchema(array_map($this->em->getClassMetadata(...), [Product::class, ProductImage::class, ProductTranslation::class, AdminUser::class, AdminSession::class]));
        $user = new AdminUser('products-test@example.com');
        $user->setPassword('unused-test-password');
        $product = (new Product())->setReference('TEST-B2B')->setName('Produit de test')->setCharacter('Luffy')
            ->setFlavor('Cerise')->setFormat('33cl')->setFamily('Jus fruités')->setLicense('One Piece')
            ->setUnitPriceExclTax('1.410000')->setTaxRateBasisPoints(550)->setPcb(24)->setPublished(true)->setStockCartons(10);
        $product->addImage(new ProductImage($product, 'images/shop/30449.webp', 'unit'));
        $this->em->persist($product);
        $this->em->persist($user);
        $this->em->flush();
        $this->productId = $product->getId();
        $this->directory = sys_get_temp_dir().'/lns-products-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        $container->set(ProductManager::class, new ProductManager($this->em, $this->directory));
        $cookie = $container->get(SessionManager::class)->start($user, Request::create('https://localhost/admin'));
        $this->client->getCookieJar()->set(Cookie::fromString((string) $cookie, 'https://localhost'));
    }

    protected function tearDown(): void
    {
        foreach ([$this->directory.'/uploads/products', $this->directory.'/uploads', $this->directory] as $directory) {
            if (!is_dir($directory)) continue;
            foreach (glob($directory.'/*') as $file) if (is_file($file)) unlink($file);
            rmdir($directory);
        }
        parent::tearDown();
    }

    public function testEditingPricesAndTranslationsUpdatesThePublicDatabaseCatalogue(): void
    {
        $crawler = $this->client->request('GET', 'https://localhost/admin/produits/'.$this->productId.'/modifier');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('#product-editor')->form();
        $form['product[pcb]'] = '12';
        $form['product[unitPriceExclTax]'] = '1,234567';
        $form['product[taxRate]'] = '20';
        $english = $crawler->filter('.admin-product-translation')->reduce(static fn ($node): bool => str_starts_with(trim($node->text()), 'English'));
        $form[$english->filter('input[id$="_flavor"]')->attr('name')] = 'Custom cherry';
        $this->client->submit($form);
        self::assertResponseRedirects();
        $this->em->clear();
        $product = $this->em->find(Product::class, $this->productId);
        self::assertSame('1.234567', $product->getUnitPriceExclTax());
        self::assertSame(2000, $product->getTaxRateBasisPoints());
        self::assertSame(12, $product->getPcb());
        self::assertSame(1777, $product->getPricing()['cartonPriceInclTaxCents']);
        $crawler = $this->client->request('GET', '/en/shop');
        $data = json_decode($crawler->filter('#shop-data')->text(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Custom cherry', $data['products'][0]['flavor']);
        self::assertSame(1481, $data['products'][0]['cartonPriceCents']);
        self::assertSame(2000, $data['products'][0]['taxRateBasisPoints']);
    }

    public function testTtcEntryPreservesAnExactHtPriceAndZeroStockMakesTheProductUnavailable(): void
    {
        $crawler = $this->client->request('GET', 'https://localhost/admin/produits/'.$this->productId.'/modifier');
        $form = $crawler->filter('#product-editor')->form();
        $form['product[priceBasis]'] = 'ttc';
        $form['product[unitPriceInclTax]'] = '1.05';
        $form['product[taxRate]'] = '20';
        $form['product[stockCartons]'] = '0';
        $this->client->submit($form);
        self::assertResponseRedirects();
        $this->em->clear();
        $product = $this->em->find(Product::class, $this->productId);
        self::assertSame('0.875000', $product->getUnitPriceExclTax());
        self::assertSame('1.050000', $product->getUnitPriceInclTax());
        self::assertFalse($product->isPurchasable());
    }

    public function testInvalidPcbAndCsrfCannotChangeStoredProductData(): void
    {
        $path = 'https://localhost/admin/produits/'.$this->productId.'/modifier';
        $crawler = $this->client->request('GET', $path);
        $form = $crawler->filter('#product-editor')->form();
        $form['product[pcb]'] = '0';
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        $this->em->clear();
        self::assertSame(24, $this->em->find(Product::class, $this->productId)->getPcb());
        $crawler = $this->client->request('GET', $path);
        $form = $crawler->filter('#product-editor')->form();
        $form['product[_token]'] = 'invalid-token';
        $form['product[unitPriceExclTax]'] = '42.00';
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        $this->em->clear();
        self::assertSame('1.410000', $this->em->find(Product::class, $this->productId)->getUnitPriceExclTax());
    }

    public function testNewDraftCanBeCreatedWithoutPublishingIt(): void
    {
        $crawler = $this->client->request('GET', 'https://localhost/admin/produits/nouveau');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('#product-editor')->form();
        foreach (['reference' => 'NEW-B2B', 'name' => 'Nouveau produit', 'character' => 'Naruto', 'flavor' => 'Orange', 'format' => '33cl', 'family' => 'Sodas', 'license' => 'Naruto', 'pcb' => '12', 'unitPriceExclTax' => '1.50', 'taxRate' => '5.5'] as $field => $value) $form['product['.$field.']'] = $value;
        $this->client->submit($form);
        self::assertResponseRedirects();
        $product = $this->em->getRepository(Product::class)->findOneBy(['reference' => 'NEW-B2B']);
        self::assertNotNull($product);
        self::assertFalse($product->isPublished());
    }

    public function testImageUploadIsOptimizedAndCanBeUsedAsTheCartonImage(): void
    {
        $file = $this->directory.'/upload.png';
        $image = imagecreatetruecolor(10, 10);
        imagepng($image, $file);
        imagedestroy($image);
        $crawler = $this->client->request('GET', 'https://localhost/admin/produits/'.$this->productId.'/modifier');
        $values = $crawler->filter('#product-editor')->form()->getPhpValues();
        $values['product']['newImageKind'] = 'carton';
        $this->client->request('POST', 'https://localhost/admin/produits/'.$this->productId.'/modifier', $values, ['product' => ['newImages' => [new UploadedFile($file, 'carton.png', 'image/png', test: true)]]]);
        self::assertResponseRedirects();
        $this->em->clear();
        $product = $this->em->find(Product::class, $this->productId);
        self::assertCount(2, $product->getImages());
        self::assertSame('carton', $product->getImages()->first()->getKind());
        self::assertFileExists($this->directory.'/'.$product->getImages()->first()->getPath());
        self::assertSame('image/webp', mime_content_type($this->directory.'/'.$product->getImages()->first()->getPath()));
    }

    public function testImporterDoesNotOverwriteAdminEditsAndCanCompleteOnlyImages(): void
    {
        $row = json_decode(file_get_contents(dirname(__DIR__, 2).'/assets/data/ultrapop-products.json'), true, flags: JSON_THROW_ON_ERROR)['products'][0];
        $row['reference'] = 'TEST-B2B';
        $row['images'] = ['images/shop/cartons/30037.webp', 'images/shop/30449.webp'];
        $path = $this->directory.'/snapshot.json';
        file_put_contents($path, json_encode(['products' => [$row]], JSON_THROW_ON_ERROR));
        $importer = new ProductImporter($this->em, static::getContainer()->get(ProductRepository::class), static::getContainer()->get(TranslatorInterface::class), $path);
        self::assertSame(1, $importer->import()['skipped']);
        self::assertSame(1, $importer->import(true)['images']);
        $this->em->clear();
        $product = $this->em->find(Product::class, $this->productId);
        self::assertSame('1.410000', $product->getUnitPriceExclTax());
        self::assertSame(24, $product->getPcb());
        self::assertCount(2, $product->getImages());
    }

    public function testUnpublishingRemovesTheProductFromTheStorefront(): void
    {
        $crawler = $this->client->request('GET', 'https://localhost/admin/produits/'.$this->productId.'/modifier');
        $form = $crawler->filter('#product-editor')->form();
        $form['product[published]']->untick();
        $this->client->submit($form);
        self::assertResponseRedirects();
        $crawler = $this->client->request('GET', '/fr/boutique');
        self::assertResponseIsSuccessful();
        $data = json_decode($crawler->filter('#shop-data')->text(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([], $data['products']);
    }

    public function testNonImageUploadIsRejectedWithoutChangingTheGallery(): void
    {
        $file = $this->directory.'/payload.svg';
        file_put_contents($file, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $crawler = $this->client->request('GET', 'https://localhost/admin/produits/'.$this->productId.'/modifier');
        $values = $crawler->filter('#product-editor')->form()->getPhpValues();
        $this->client->request('POST', 'https://localhost/admin/produits/'.$this->productId.'/modifier', $values, ['product' => ['newImages' => [new UploadedFile($file, 'payload.svg', 'image/svg+xml', test: true)]]]);
        self::assertResponseStatusCodeSame(422);
        $this->em->clear();
        self::assertCount(1, $this->em->find(Product::class, $this->productId)->getImages());
    }

    public function testProductAdministrationRequiresAuthentication(): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', 'https://localhost/admin/produits');
        self::assertResponseRedirects('/admin/login');
    }
}
