<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110120000 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Add external catalogues with a language and independently revocable sharing links.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE catalog ADD external TINYINT DEFAULT 0 NOT NULL, ADD locale VARCHAR(5) DEFAULT 'fr' NOT NULL");
        $this->addSql('CREATE TABLE catalog_share (id INT AUTO_INCREMENT NOT NULL, catalog_id INT NOT NULL, token VARCHAR(64) NOT NULL, label VARCHAR(120) NOT NULL, created_at DATETIME NOT NULL, revoked_at DATETIME DEFAULT NULL, UNIQUE INDEX uniq_catalog_share_token (token), INDEX idx_catalog_share_catalog (catalog_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE catalog_share ADD CONSTRAINT fk_catalog_share_catalog FOREIGN KEY (catalog_id) REFERENCES catalog (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE catalog_share');
        $this->addSql('ALTER TABLE catalog DROP external, DROP locale');
    }
}
