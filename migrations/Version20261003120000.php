<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Squashed baseline migration.
 *
 * Replaces all seven previous migrations (Version20260911120000 …
 * Version20260927115659) with a single one producing the identical final
 * schema. Hand-written from the live schema produced by those seven
 * (`mysqldump --no-data` diffed against a database built from this file alone,
 * byte-for-byte, see PRD/fwd-5-cross-domain-decoupling.md "Фаза 0").
 *
 * fwd-5: the three `owner_id` foreign keys to `users` are declared HERE ONLY —
 * the ORM mapping of Collection/Like/Comment holds a plain `owner_id` column,
 * not a ManyToOne association, so Doctrine no longer knows these constraints
 * exist and a future `doctrine:schema:update` would DROP them. That would turn
 * "deleting a user removes their collections, likes and comments" into silent
 * orphans. The `ON DELETE CASCADE` behaviour is a product requirement decided
 * by the owner on 2026-10-03; it is enforced by the database on purpose.
 *
 * Keeping the constraints by hand also keeps the dependency the domain layer
 * just dropped enforced where it costs nothing: the schema still refuses rows
 * pointing at a user that does not exist.
 *
 * OPERATIONAL CAVEAT: because this file replaces the seven it supersedes, it
 * cannot be applied to a database that already has them. A `doctrine_migration_versions`
 * table listing any `Version2026091*` row will make this baseline try to
 * `CREATE TABLE` over existing tables and fail. There is no release and no
 * production data, so the supported remedy is to rebuild the database
 * (`docker compose down -v && docker compose up -d`, or drop and recreate it).
 * Once a release exists this stops being true and normal incremental migrations
 * apply on top — do not squash again after that point.
 */
final class Version20261003120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Squashed baseline: users, tags, collections, collection_fields, items, item_tags, likes, comments';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE users (id VARBINARY(16) NOT NULL, is_active TINYINT DEFAULT 1 NOT NULL, name VARCHAR(100) NOT NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, email VARCHAR(255) NOT NULL, password_hash VARCHAR(255) NOT NULL, role VARCHAR(20) NOT NULL, UNIQUE INDEX UNIQ_1483A5E9E7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        $this->addSql('CREATE TABLE tags (id VARBINARY(16) NOT NULL, name VARCHAR(30) NOT NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, UNIQUE INDEX uniq_tag_name (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        $this->addSql('CREATE TABLE collections (id VARBINARY(16) NOT NULL, image VARCHAR(500) DEFAULT NULL, description LONGTEXT DEFAULT NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, name VARCHAR(100) NOT NULL, theme VARCHAR(20) NOT NULL, owner_id VARBINARY(16) NOT NULL, INDEX idx_collection_owner_list (owner_id, created_at, id), INDEX idx_collection_theme (theme), CONSTRAINT FK_D325D3EE7E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        $this->addSql('CREATE TABLE items (id VARBINARY(16) NOT NULL, name VARCHAR(100) NOT NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, text_1 VARCHAR(1000) DEFAULT NULL, text_2 VARCHAR(1000) DEFAULT NULL, text_3 VARCHAR(1000) DEFAULT NULL, num_1 DOUBLE PRECISION DEFAULT NULL, num_2 DOUBLE PRECISION DEFAULT NULL, num_3 DOUBLE PRECISION DEFAULT NULL, date_1 DATETIME(6) DEFAULT NULL, date_2 DATETIME(6) DEFAULT NULL, date_3 DATETIME(6) DEFAULT NULL, bool_1 TINYINT DEFAULT NULL, bool_2 TINYINT DEFAULT NULL, bool_3 TINYINT DEFAULT NULL, collection_id VARBINARY(16) NOT NULL, INDEX idx_item_collection_list (collection_id, created_at, id), CONSTRAINT FK_E11EE94D514956FD FOREIGN KEY (collection_id) REFERENCES collections (id) ON DELETE CASCADE, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        $this->addSql('CREATE TABLE collection_fields (id VARBINARY(16) NOT NULL, slot_index SMALLINT NOT NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, field_name VARCHAR(50) NOT NULL, field_type VARCHAR(20) NOT NULL, collection_id VARBINARY(16) NOT NULL, UNIQUE INDEX uniq_collection_field_slot (collection_id, field_type, slot_index), INDEX idx_collection_field_collection (collection_id), CONSTRAINT FK_8CC01B7E514956FD FOREIGN KEY (collection_id) REFERENCES collections (id) ON DELETE CASCADE, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        $this->addSql('CREATE TABLE item_tags (item_id VARBINARY(16) NOT NULL, tag_id VARBINARY(16) NOT NULL, INDEX IDX_A78CD0DD126F525E (item_id), INDEX IDX_A78CD0DDBAD26311 (tag_id), PRIMARY KEY (item_id, tag_id), CONSTRAINT FK_A78CD0DD126F525E FOREIGN KEY (item_id) REFERENCES items (id) ON DELETE CASCADE, CONSTRAINT FK_A78CD0DDBAD26311 FOREIGN KEY (tag_id) REFERENCES tags (id) ON DELETE CASCADE) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        $this->addSql('CREATE TABLE likes (id VARBINARY(16) NOT NULL, created_at DATETIME(6) NOT NULL, owner_id VARBINARY(16) NOT NULL, item_id VARBINARY(16) NOT NULL, UNIQUE INDEX uniq_like_owner_item (owner_id, item_id), INDEX idx_like_item (item_id), INDEX idx_like_owner (owner_id, created_at, id), CONSTRAINT FK_49CA4E7D7E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE, CONSTRAINT FK_49CA4E7D126F525E FOREIGN KEY (item_id) REFERENCES items (id) ON DELETE CASCADE, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        $this->addSql('CREATE TABLE comments (id VARBINARY(16) NOT NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, content VARCHAR(3000) NOT NULL, owner_id VARBINARY(16) NOT NULL, item_id VARBINARY(16) NOT NULL, INDEX idx_comment_item (item_id, created_at, id), INDEX idx_comment_owner (owner_id, created_at, id), CONSTRAINT FK_5F9E962A7E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE, CONSTRAINT FK_5F9E962A126F525E FOREIGN KEY (item_id) REFERENCES items (id) ON DELETE CASCADE, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE comments');
        $this->addSql('DROP TABLE likes');
        $this->addSql('DROP TABLE item_tags');
        $this->addSql('DROP TABLE collection_fields');
        $this->addSql('DROP TABLE items');
        $this->addSql('DROP TABLE collections');
        $this->addSql('DROP TABLE tags');
        $this->addSql('DROP TABLE users');
    }
}
