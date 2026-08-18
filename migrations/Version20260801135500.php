<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Consolidated migration for Task 3.2:
 * - Create collection_fields table with unique constraint (collection_id, slot_index)
 * - Create collections table if not exists (safe for dev DBs where 3.1 applied via schema:update)
 * - Fix datetime precision to DATETIME(6) on both tables
 */
final class Version20260801135500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Consolidated: collection_fields with unique constraint + datetime(6) precision';
    }

    public function up(Schema $schema): void
    {
        // collections table — IF NOT EXISTS handles dev DBs where 3.1 already applied via schema:update
        // indexes use explicit names to match entity class-level #[ORM\Index] attributes
        $this->addSql('CREATE TABLE IF NOT EXISTS collections (id VARBINARY(16) NOT NULL, image VARCHAR(500) DEFAULT NULL, description LONGTEXT DEFAULT NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, name VARCHAR(100) NOT NULL, theme VARCHAR(20) NOT NULL, owner_id VARBINARY(16) NOT NULL, INDEX idx_collection_owner (owner_id), INDEX idx_collection_theme (theme), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        // dev DBs that already had collections (created by 3.1 schema:update) get datetime precision corrected
        $this->addSql('ALTER TABLE collections MODIFY created_at DATETIME(6) NOT NULL');
        $this->addSql('ALTER TABLE collections MODIFY updated_at DATETIME(6) NOT NULL');

        // collection_fields table with unique constraint on (collection_id, slot_index)
        $this->addSql('DROP TABLE IF EXISTS collection_fields');
        $this->addSql('CREATE TABLE collection_fields (id VARBINARY(16) NOT NULL, slot_index SMALLINT NOT NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, field_name VARCHAR(50) NOT NULL, field_type VARCHAR(20) NOT NULL, collection_id VARBINARY(16) NOT NULL, INDEX idx_collection_field_collection (collection_id), UNIQUE INDEX uniq_collection_field_slot (collection_id, slot_index), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        // Conditional FKs: only add if not already present (safe on dev DBs where 3.1 added them)
        $this->addSql(<<<'SQL'
            SET @fk_collection_fields := (
              SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'collection_fields'
                AND CONSTRAINT_NAME = 'FK_8CC01B7E514956FD'
            );
            SET @sql_collection_fields := IF(@fk_collection_fields = 0,
              'ALTER TABLE collection_fields ADD CONSTRAINT FK_8CC01B7E514956FD FOREIGN KEY (collection_id) REFERENCES collections (id) ON DELETE CASCADE',
              'SELECT 1'
            );
            PREPARE stmt_collection_fields FROM @sql_collection_fields;
            EXECUTE stmt_collection_fields;
            DEALLOCATE PREPARE stmt_collection_fields;
        SQL);

        $this->addSql(<<<'SQL'
            SET @fk_collections := (
              SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'collections'
                AND CONSTRAINT_NAME = 'FK_D325D3EE7E3C61F9'
            );
            SET @sql_collections := IF(@fk_collections = 0,
              'ALTER TABLE collections ADD CONSTRAINT FK_D325D3EE7E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE',
              'SELECT 1'
            );
            PREPARE stmt_collections FROM @sql_collections;
            EXECUTE stmt_collections;
            DEALLOCATE PREPARE stmt_collections;
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE collection_fields DROP FOREIGN KEY FK_8CC01B7E514956FD');
        $this->addSql('ALTER TABLE collections DROP FOREIGN KEY FK_D325D3EE7E3C61F9');
        $this->addSql('DROP TABLE IF EXISTS collection_fields');
    }
}
