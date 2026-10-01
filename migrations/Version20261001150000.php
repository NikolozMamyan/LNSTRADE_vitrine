<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261001150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add an empty configurable ULTRAPOP catalogue.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE catalog (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(180) NOT NULL, enabled TINYINT(1) NOT NULL, updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE catalog_page (id INT AUTO_INCREMENT NOT NULL, catalog_id INT NOT NULL, position_index INT NOT NULL, title VARCHAR(180) NOT NULL, image_path VARCHAR(255) DEFAULT NULL, pdf_path VARCHAR(255) DEFAULT NULL, pdf_page INT DEFAULT NULL, link_url VARCHAR(2048) DEFAULT NULL, open_link_in_new_tab TINYINT(1) NOT NULL, enabled TINYINT(1) NOT NULL, updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_61BF0656CC3C66FC (catalog_id), INDEX idx_catalog_page_position (catalog_id, position_index), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE catalog_page ADD CONSTRAINT FK_CATALOG_PAGE_CATALOG FOREIGN KEY (catalog_id) REFERENCES catalog (id) ON DELETE CASCADE');
        $this->addSql("INSERT INTO catalog (title, enabled, updated_at) VALUES ('Catalogue ULTRAPOP', 0, CURRENT_TIMESTAMP)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE catalog_page DROP FOREIGN KEY FK_CATALOG_PAGE_CATALOG');
        $this->addSql('DROP TABLE catalog_page');
        $this->addSql('DROP TABLE catalog');
    }
}
