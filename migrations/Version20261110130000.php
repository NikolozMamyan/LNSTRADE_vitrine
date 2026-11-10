<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110130000 extends AbstractMigration
{
    public function isTransactional(): bool { return false; }
    public function getDescription(): string { return 'Store positioned link and video elements on external catalogue pages.'; }
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE catalog_page_element (id INT AUTO_INCREMENT NOT NULL, page_id INT NOT NULL, kind VARCHAR(10) NOT NULL, url VARCHAR(2048) NOT NULL, label VARCHAR(160) NOT NULL, x DOUBLE PRECISION NOT NULL, y DOUBLE PRECISION NOT NULL, width DOUBLE PRECISION NOT NULL, height DOUBLE PRECISION NOT NULL, position INT NOT NULL, INDEX idx_catalog_element_page (page_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE catalog_page_element ADD CONSTRAINT fk_catalog_element_page FOREIGN KEY (page_id) REFERENCES catalog_page (id) ON DELETE CASCADE');
    }
    public function down(Schema $schema): void { $this->addSql('DROP TABLE catalog_page_element'); }
}
