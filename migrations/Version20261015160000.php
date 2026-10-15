<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261015160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the submission IP and estimated country for each prospect.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE prospect ADD ip_address VARCHAR(45) DEFAULT NULL, ADD country_code VARCHAR(2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE prospect DROP ip_address, DROP country_code');
    }
}
