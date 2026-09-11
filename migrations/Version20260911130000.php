<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the items table (Task 4.1 domain Item) and changes the
 * collection_fields unique constraint to be per field type:
 * (collection_id, field_type, slot_index) instead of (collection_id, slot_index).
 * Manual adjustments vs auto-generated diff: DATETIME(6) precision and
 * COLLATE utf8mb4_0900_ai_ci to match the squashed baseline golden reference.
 * WARNING: down() drops the items table — all item data is lost on rollback.
 */
final class Version20260911130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create items table; unique collection_field slot per field type';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE items (id VARBINARY(16) NOT NULL, name VARCHAR(100) NOT NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, text_1 VARCHAR(1000) DEFAULT NULL, text_2 VARCHAR(1000) DEFAULT NULL, text_3 VARCHAR(1000) DEFAULT NULL, num_1 DOUBLE PRECISION DEFAULT NULL, num_2 DOUBLE PRECISION DEFAULT NULL, num_3 DOUBLE PRECISION DEFAULT NULL, date_1 DATETIME(6) DEFAULT NULL, date_2 DATETIME(6) DEFAULT NULL, date_3 DATETIME(6) DEFAULT NULL, bool_1 TINYINT DEFAULT NULL, bool_2 TINYINT DEFAULT NULL, bool_3 TINYINT DEFAULT NULL, collection_id VARBINARY(16) NOT NULL, INDEX idx_item_collection (collection_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        $this->addSql('ALTER TABLE items ADD CONSTRAINT FK_E11EE94D514956FD FOREIGN KEY (collection_id) REFERENCES collections (id) ON DELETE CASCADE');
        $this->addSql('DROP INDEX uniq_collection_field_slot ON collection_fields');
        $this->addSql('CREATE UNIQUE INDEX uniq_collection_field_slot ON collection_fields (collection_id, field_type, slot_index)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE items DROP FOREIGN KEY FK_E11EE94D514956FD');
        $this->addSql('DROP TABLE items');
        $this->addSql('DROP INDEX uniq_collection_field_slot ON collection_fields');
        $this->addSql('CREATE UNIQUE INDEX uniq_collection_field_slot ON collection_fields (collection_id, slot_index)');
    }
}
