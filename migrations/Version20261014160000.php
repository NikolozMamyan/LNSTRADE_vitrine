<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store visitor sessions and their page visit history.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE visitor_session (id INT AUTO_INCREMENT NOT NULL, visitor_key VARCHAR(64) NOT NULL, ip_address VARCHAR(45) NOT NULL, user_agent VARCHAR(500) NOT NULL, started_at DATETIME NOT NULL, last_seen_at DATETIME NOT NULL, entry_path VARCHAR(1000) NOT NULL, current_path VARCHAR(1000) NOT NULL, page_view_count INT NOT NULL, source VARCHAR(200) NOT NULL, medium VARCHAR(100) DEFAULT NULL, campaign VARCHAR(200) DEFAULT NULL, INDEX idx_visitor_key_activity (visitor_key, last_seen_at), INDEX idx_visitor_activity (last_seen_at), INDEX idx_visitor_started (started_at), INDEX idx_visitor_ip (ip_address), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE page_visit (id INT AUTO_INCREMENT NOT NULL, session_id INT NOT NULL, path VARCHAR(1000) NOT NULL, visited_at DATETIME NOT NULL, INDEX idx_page_visit_session (session_id, visited_at), INDEX idx_page_visit_date (visited_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE page_visit ADD CONSTRAINT FK_PAGE_VISIT_SESSION FOREIGN KEY (session_id) REFERENCES visitor_session (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE page_visit DROP FOREIGN KEY FK_PAGE_VISIT_SESSION');
        $this->addSql('DROP TABLE page_visit');
        $this->addSql('DROP TABLE visitor_session');
    }
}
