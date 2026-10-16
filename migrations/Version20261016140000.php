<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the source of each prospect’s latest enquiry and recover existing URL campaign parameters.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE prospect ADD source VARCHAR(200) DEFAULT NULL, ADD medium VARCHAR(100) DEFAULT NULL, ADD campaign VARCHAR(200) DEFAULT NULL');
        foreach ($this->connection->fetchAllAssociative('SELECT id, origin FROM prospect WHERE origin IS NOT NULL') as $row) {
            parse_str((string) parse_url($row['origin'], PHP_URL_QUERY), $query);
            $source = is_string($query['utm_source'] ?? null) ? trim($query['utm_source']) : '';
            $medium = is_string($query['utm_medium'] ?? null) ? trim($query['utm_medium']) : '';
            $campaign = is_string($query['utm_campaign'] ?? null) ? trim($query['utm_campaign']) : '';
            $source = '' !== $source ? mb_substr($source, 0, 200) : (isset($query['gclid']) ? 'google' : null);
            $medium = '' !== $medium ? mb_substr($medium, 0, 100) : (isset($query['gclid']) ? 'cpc' : null);
            $campaign = '' !== $campaign ? mb_substr($campaign, 0, 200) : null;
            if (null !== $source || null !== $medium || null !== $campaign) {
                $this->addSql('UPDATE prospect SET source = :source, medium = :medium, campaign = :campaign WHERE id = :id', [
                    'source' => $source, 'medium' => $medium, 'campaign' => $campaign, 'id' => $row['id'],
                ]);
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE prospect DROP source, DROP medium, DROP campaign');
    }
}
