<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261104160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed the 55 ULTRAPOP B2B products, translations and images without changing existing references.';
    }

    public function up(Schema $schema): void
    {
        $snapshot = json_decode(file_get_contents(__DIR__.'/data/ultrapop-products-20261104.json'), true, flags: JSON_THROW_ON_ERROR);
        $existing = array_fill_keys($this->connection->fetchFirstColumn('SELECT reference FROM shop_product'), true);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        foreach ($snapshot['products'] as $row) {
            $reference = $row['product']['reference'];
            if (isset($existing[$reference])) {
                continue;
            }

            $this->addSql('INSERT INTO shop_product (reference, name, character_name, flavor, format, description, ingredients, license, family, category_group, ean, unit_price_excl_tax, tax_rate_basis_points, pcb, stock_cartons, published, available, bestseller, sort_order, created_at, updated_at) VALUES (:reference, :name, :character_name, :flavor, :format, :description, :ingredients, :license, :family, :category_group, :ean, :unit_price_excl_tax, :tax_rate_basis_points, :pcb, :stock_cartons, :published, :available, :bestseller, :sort_order, :created_at, :updated_at)', $row['product'] + [
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($row['translations'] as $translation) {
                $this->addSql('INSERT INTO shop_product_translation (locale, name, character_name, flavor, format, family, description, ingredients, product_id) VALUES (:locale, :name, :character_name, :flavor, :format, :family, :description, :ingredients, (SELECT id FROM shop_product WHERE reference = :reference))', $translation + ['reference' => $reference]);
            }

            foreach ($row['images'] as $image) {
                $this->addSql('INSERT INTO shop_product_image (path, kind, position, product_id) VALUES (:path, :kind, :position, (SELECT id FROM shop_product WHERE reference = :reference))', $image + ['reference' => $reference]);
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The imported products may have been edited in the admin and must not be deleted automatically.');
    }
}
