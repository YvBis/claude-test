<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the comments table (Task 5.2 domain Comment): a user may comment on
 * an item many times, so there is no UNIQUE constraint — only the two lookup
 * indexes idx_comment_item and idx_comment_owner. Both indexes are composite
 * with created_at and id so the (created_at, id) listing order is index-served
 * (InnoDB appends the PK implicitly; listed explicitly for clarity). Both FKs
 * cascade so removing an item (or user) removes its comments. Manual
 * adjustments vs auto-generated diff: DATETIME(6) precision and COLLATE
 * utf8mb4_0900_ai_ci to match the squashed baseline golden reference.
 * WARNING: down() drops comments — all comment data is lost on rollback.
 */
final class Version20260916145324 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create comments table (Task 5.2 domain Comment)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE comments (id VARBINARY(16) NOT NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, content VARCHAR(3000) NOT NULL, owner_id VARBINARY(16) NOT NULL, item_id VARBINARY(16) NOT NULL, INDEX idx_comment_item (item_id, created_at, id), INDEX idx_comment_owner (owner_id, created_at, id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        $this->addSql('ALTER TABLE comments ADD CONSTRAINT FK_5F9E962A7E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE comments ADD CONSTRAINT FK_5F9E962A126F525E FOREIGN KEY (item_id) REFERENCES items (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE comments DROP FOREIGN KEY FK_5F9E962A7E3C61F9');
        $this->addSql('ALTER TABLE comments DROP FOREIGN KEY FK_5F9E962A126F525E');
        $this->addSql('DROP TABLE comments');
    }
}
