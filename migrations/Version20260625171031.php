<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260625171031 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE api_keys (id UUID NOT NULL, key_id VARCHAR(255) NOT NULL, secret_hash VARCHAR(255) NOT NULL, name VARCHAR(255) DEFAULT NULL, scope VARCHAR(255) NOT NULL, active BOOLEAN DEFAULT true NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_by_keycloak_id VARCHAR(255) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9579321FD145533 ON api_keys (key_id)');
        $this->addSql('CREATE TABLE categories (id UUID NOT NULL, name VARCHAR(255) NOT NULL, key VARCHAR(255) NOT NULL, color VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_3AF346688A90ABA9 ON categories (key)');
        $this->addSql('CREATE TABLE charts (id UUID NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, objects_json JSON DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C05050F7989D9B62 ON charts (slug)');
        $this->addSql('CREATE TABLE event_seats (id UUID NOT NULL, seat_key VARCHAR(255) NOT NULL, status VARCHAR(255) NOT NULL, hold_token VARCHAR(255) DEFAULT NULL, held_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, event_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_FEF9E67C71F7E88B ON event_seats (event_id)');
        $this->addSql('CREATE TABLE events (id UUID NOT NULL, title VARCHAR(255) NOT NULL, identifier VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, chart_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5387574A772E836A ON events (identifier)');
        $this->addSql('CREATE INDEX IDX_5387574ABEF83E0A ON events (chart_id)');
        $this->addSql('CREATE TABLE users (id UUID NOT NULL, email VARCHAR(255) NOT NULL, password VARCHAR(255) NOT NULL, display_name VARCHAR(255) DEFAULT NULL, roles JSON NOT NULL, active BOOLEAN DEFAULT true NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_login_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9E7927C74 ON users (email)');
        $this->addSql('ALTER TABLE event_seats ADD CONSTRAINT FK_FEF9E67C71F7E88B FOREIGN KEY (event_id) REFERENCES events (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE events ADD CONSTRAINT FK_5387574ABEF83E0A FOREIGN KEY (chart_id) REFERENCES charts (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE event_seats DROP CONSTRAINT FK_FEF9E67C71F7E88B');
        $this->addSql('ALTER TABLE events DROP CONSTRAINT FK_5387574ABEF83E0A');
        $this->addSql('DROP TABLE api_keys');
        $this->addSql('DROP TABLE categories');
        $this->addSql('DROP TABLE charts');
        $this->addSql('DROP TABLE event_seats');
        $this->addSql('DROP TABLE events');
        $this->addSql('DROP TABLE users');
    }
}
