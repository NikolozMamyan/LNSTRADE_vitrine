<?php

declare(strict_types=1);

namespace App\Tests\Product;

use App\Entity\Product;
use App\Entity\ProductImage;
use App\Entity\ProductTranslation;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\IrreversibleMigration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use DoctrineMigrations\Version20261104160000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once dirname(__DIR__, 2).'/migrations/Version20261104160000.php';

final class ProductMigrationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $this->em->getConnection();
        self::assertSame('pdo_sqlite', $this->connection->getParams()['driver']);
        (new SchemaTool($this->em))->createSchema(array_map($this->em->getClassMetadata(...), [Product::class, ProductImage::class, ProductTranslation::class]));
    }

    public function testSeedsTheCompleteCatalogueWithPricingTranslationsAndOrderedImages(): void
    {
        $this->migrate();

        self::assertSame(55, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM shop_product'));
        self::assertSame(275, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM shop_product_translation'));
        self::assertSame(146, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM shop_product_image'));

        $snapshot = json_decode(file_get_contents(dirname(__DIR__, 2).'/assets/data/ultrapop-products.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($snapshot['products'] as $position => $row) {
            $product = $this->em->getRepository(Product::class)->findOneBy(['reference' => $row['reference']]);
            self::assertNotNull($product);
            self::assertSame(trim($row['name']), $product->getName());
            self::assertSame(trim($row['ingredients']), $product->getIngredients());
            self::assertSame($row['unitPriceExclTax'], $product->getUnitPriceExclTax());
            self::assertSame($row['taxRateBasisPoints'], $product->getTaxRateBasisPoints());
            self::assertSame($row['pcb'], $product->getPcb());
            self::assertSame($position, $product->getSortOrder());
            self::assertTrue($product->isPublished());
            self::assertNull($product->getStockCartons());
            self::assertSame($row['available'], $product->isAvailable());
            self::assertSame($row['bestseller'], $product->isBestseller());
            self::assertSame($row['images'], array_map(static fn (ProductImage $image): string => $image->getPath(), $product->getImages()->toArray()));
            self::assertSame('carton', $product->getImages()->first()->getKind());
            self::assertCount(5, $product->getTranslations());
        }

        $english = $this->connection->fetchAssociative('SELECT flavor, family, description FROM shop_product_translation WHERE locale = :locale AND product_id = (SELECT id FROM shop_product WHERE reference = :reference)', ['locale' => 'en', 'reference' => '30037']);
        self::assertSame('Cherry', $english['flavor']);
        self::assertSame('Fruit drinks', $english['family']);
        self::assertStringContainsString('Discover Luffy', $english['description']);
    }

    public function testPreservesAdminEditsAndCanBeRepeatedWithoutDuplicates(): void
    {
        $product = (new Product())->setReference('30037')->setName('Edited product')->setCharacter('Luffy')
            ->setFlavor('Custom flavour')->setFormat('33cl')->setFamily('Custom family')->setLicense('One Piece')
            ->setUnitPriceExclTax('3.123456')->setTaxRateBasisPoints(2000)->setPcb(6)->setStockCartons(8)->setPublished(false);
        $product->addTranslation((new ProductTranslation($product, 'en'))->setFlavor('Custom translation'));
        $product->addImage(new ProductImage($product, 'uploads/products/custom.webp', 'carton'));
        $this->em->persist($product);
        $this->em->flush();
        $before = $this->connection->fetchAssociative('SELECT * FROM shop_product WHERE reference = :reference', ['reference' => '30037']);

        $this->migrate();
        $this->em->clear();

        self::assertSame($before, $this->connection->fetchAssociative('SELECT * FROM shop_product WHERE reference = :reference', ['reference' => '30037']));
        $saved = $this->em->find(Product::class, $product->getId());
        self::assertCount(1, $saved->getTranslations());
        self::assertSame('Custom translation', $saved->getTranslations()->first()->getFlavor());
        self::assertCount(1, $saved->getImages());
        self::assertSame('uploads/products/custom.webp', $saved->getImages()->first()->getPath());
        self::assertSame(55, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM shop_product'));

        $migration = new Version20261104160000($this->connection, new NullLogger());
        $migration->up(new Schema());
        self::assertSame([], $migration->getSql());
    }

    public function testRollbackDoesNotDeleteProductsThatMayHaveBeenEdited(): void
    {
        $this->expectException(IrreversibleMigration::class);
        (new Version20261104160000($this->connection, new NullLogger()))->down(new Schema());
    }

    private function migrate(): void
    {
        $migration = new Version20261104160000($this->connection, new NullLogger());
        self::assertTrue($migration->isTransactional());
        $migration->up(new Schema());
        $this->connection->transactional(function () use ($migration): void {
            foreach ($migration->getSql() as $query) {
                $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
        });
    }
}
