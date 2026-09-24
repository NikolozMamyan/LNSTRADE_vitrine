<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store contact form prospects and their HubSpot synchronization state.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE prospect (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(254) NOT NULL, name VARCHAR(200) NOT NULL, company VARCHAR(200) NOT NULL, phone VARCHAR(100) DEFAULT NULL, vat VARCHAR(100) DEFAULT NULL, message LONGTEXT DEFAULT NULL, context VARCHAR(255) DEFAULT NULL, origin VARCHAR(1000) DEFAULT NULL, locale VARCHAR(2) NOT NULL, submission_count INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, last_submitted_at DATETIME NOT NULL, hub_spot_synced_at DATETIME DEFAULT NULL, UNIQUE INDEX uniq_prospect_email (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE prospect');
    }
}
