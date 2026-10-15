<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261015170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the decision to refuse HubSpot synchronization for a prospect.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE prospect ADD hub_spot_rejected_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE prospect DROP hub_spot_rejected_at');
    }
}
