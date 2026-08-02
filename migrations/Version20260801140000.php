<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rename legacy auto-named Doctrine index on collections.owner_id to the
 * explicit name used by Collection::class class-level #[ORM\Index].
 * Required because Round #3 of PR #22 review moved indexes/uniqueConstraints
 * out of #[ORM\Table(...)] into class-level attributes; Doctrine now generates
 * the metadata against the explicit name 'idx_collection_owner'.
 * Safe on dev DBs (3.1 ran via schema:update → created hash-named index) and
 * fresh DBs (the renaming block becomes a no-op because the explicit name
 * already exists; verified via information_schema conditional).
 */
final class Version20260801140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename legacy collections.owner_id index to idx_collection_owner (matches class-level #[ORM\Index])';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
                SET @legacy_idx := (
                  SELECT COUNT(*) FROM information_schema.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'collections'
                    AND INDEX_NAME = 'IDX_D325D3EE7E3C61F9'
                );
                SET @new_idx := (
                  SELECT COUNT(*) FROM information_schema.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'collections'
                    AND INDEX_NAME = 'idx_collection_owner'
                );
                SET @sql := IF(@legacy_idx = 1 AND @new_idx = 0,
                  'ALTER TABLE collections RENAME INDEX IDX_D325D3EE7E3C61F9 TO idx_collection_owner',
                  'SELECT 1'
                );
                PREPARE stmt FROM @sql;
                EXECUTE stmt;
                DEALLOCATE PREPARE stmt;
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
                SET @legacy_idx := (
                  SELECT COUNT(*) FROM information_schema.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'collections'
                    AND INDEX_NAME = 'IDX_D325D3EE7E3C61F9'
                );
                SET @new_idx := (
                  SELECT COUNT(*) FROM information_schema.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'collections'
                    AND INDEX_NAME = 'idx_collection_owner'
                );
                SET @sql := IF(@legacy_idx = 0 AND @new_idx = 1,
                  'ALTER TABLE collections RENAME INDEX idx_collection_owner TO IDX_D325D3EE7E3C61F9',
                  'SELECT 1'
                );
                PREPARE stmt FROM @sql;
                EXECUTE stmt;
                DEALLOCATE PREPARE stmt;
            SQL);
    }
}
