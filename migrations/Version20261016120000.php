<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store newsletter subscriptions separately from sales prospects, with their consent date and version.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE newsletter_subscriber (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(254) NOT NULL, locale VARCHAR(2) NOT NULL, origin_path VARCHAR(1000) NOT NULL, consent_version VARCHAR(32) NOT NULL, subscribed_at DATETIME NOT NULL, UNIQUE INDEX uniq_newsletter_email (email), INDEX idx_newsletter_subscribed (subscribed_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE newsletter_subscriber');
    }
}
