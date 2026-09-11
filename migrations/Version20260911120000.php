<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Squashed baseline migration.
 *
 * Replaces the original four migrations (Version20260717073959 …
 * Version20260801140000) with a single one producing the identical final
 * schema: users, collections, collection_fields. Hand-written from the live
 * schema (SHOW CREATE TABLE) to keep column order, indexes, foreign-key names
 * and the utf8mb4_0900_ai_ci collation byte-for-byte.
 */
final class Version20260911120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Squashed baseline: users, collections, collection_fields';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE users (id VARBINARY(16) NOT NULL, is_active TINYINT DEFAULT 1 NOT NULL, name VARCHAR(100) NOT NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, email VARCHAR(255) NOT NULL, password_hash VARCHAR(255) NOT NULL, role VARCHAR(20) NOT NULL, UNIQUE INDEX UNIQ_1483A5E9E7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        $this->addSql('CREATE TABLE collections (id VARBINARY(16) NOT NULL, image VARCHAR(500) DEFAULT NULL, description LONGTEXT DEFAULT NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, name VARCHAR(100) NOT NULL, theme VARCHAR(20) NOT NULL, owner_id VARBINARY(16) NOT NULL, INDEX idx_collection_owner (owner_id), INDEX idx_collection_theme (theme), CONSTRAINT FK_D325D3EE7E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        $this->addSql('CREATE TABLE collection_fields (id VARBINARY(16) NOT NULL, slot_index SMALLINT NOT NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, field_name VARCHAR(50) NOT NULL, field_type VARCHAR(20) NOT NULL, collection_id VARBINARY(16) NOT NULL, UNIQUE INDEX uniq_collection_field_slot (collection_id, slot_index), INDEX idx_collection_field_collection (collection_id), CONSTRAINT FK_8CC01B7E514956FD FOREIGN KEY (collection_id) REFERENCES collections (id) ON DELETE CASCADE, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE collection_fields');
        $this->addSql('DROP TABLE collections');
        $this->addSql('DROP TABLE users');
    }
}
