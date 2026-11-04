<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261104150000 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Create B2B products, localized content and ordered product images.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE shop_product (id INT AUTO_INCREMENT NOT NULL, reference VARCHAR(64) NOT NULL, name VARCHAR(255) NOT NULL, character_name VARCHAR(120) NOT NULL, flavor VARCHAR(180) NOT NULL, format VARCHAR(40) NOT NULL, description LONGTEXT NOT NULL, ingredients LONGTEXT NOT NULL, license VARCHAR(120) NOT NULL, family VARCHAR(120) NOT NULL, category_group VARCHAR(16) NOT NULL, ean VARCHAR(13) DEFAULT NULL, unit_price_excl_tax NUMERIC(14, 6) NOT NULL, tax_rate_basis_points INT NOT NULL, pcb INT NOT NULL, stock_cartons INT DEFAULT NULL, published TINYINT NOT NULL, available TINYINT NOT NULL, bestseller TINYINT NOT NULL, sort_order INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_D0794487AEA34913 (reference), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE shop_product_image (id INT AUTO_INCREMENT NOT NULL, path VARCHAR(512) NOT NULL, kind VARCHAR(16) NOT NULL, position INT NOT NULL, product_id INT NOT NULL, INDEX IDX_7A7DE80C4584665A (product_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE shop_product_translation (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, character_name VARCHAR(120) NOT NULL, flavor VARCHAR(180) NOT NULL, format VARCHAR(40) NOT NULL, family VARCHAR(120) NOT NULL, description LONGTEXT NOT NULL, ingredients LONGTEXT NOT NULL, locale VARCHAR(5) NOT NULL, product_id INT NOT NULL, UNIQUE INDEX uniq_shop_product_locale (product_id, locale), INDEX IDX_8641F4F84584665A (product_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE shop_product_image ADD CONSTRAINT FK_7A7DE80C4584665A FOREIGN KEY (product_id) REFERENCES shop_product (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE shop_product_translation ADD CONSTRAINT FK_8641F4F84584665A FOREIGN KEY (product_id) REFERENCES shop_product (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE shop_product_image');
        $this->addSql('DROP TABLE shop_product_translation');
        $this->addSql('DROP TABLE shop_product');
    }
}
