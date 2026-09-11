<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the tags table (Task 4.2 domain Tag) and the item_tags join table
 * (Item <-> Tag many-to-many). Manual adjustments vs auto-generated diff:
 * DATETIME(6) precision and COLLATE utf8mb4_0900_ai_ci to match the squashed
 * baseline golden reference. The name column collation gives case-insensitive
 * tag uniqueness: "Books" and "books" collide.
 * WARNING: down() drops item_tags and tags — all tag data and item tag links
 * are lost on rollback.
 */
final class Version20260911195240 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create tags table and item_tags many-to-many join table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE tags (id VARBINARY(16) NOT NULL, name VARCHAR(30) NOT NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, UNIQUE INDEX uniq_tag_name (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        $this->addSql('CREATE TABLE item_tags (item_id VARBINARY(16) NOT NULL, tag_id VARBINARY(16) NOT NULL, INDEX IDX_A78CD0DD126F525E (item_id), INDEX IDX_A78CD0DDBAD26311 (tag_id), PRIMARY KEY (item_id, tag_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        $this->addSql('ALTER TABLE item_tags ADD CONSTRAINT FK_A78CD0DD126F525E FOREIGN KEY (item_id) REFERENCES items (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE item_tags ADD CONSTRAINT FK_A78CD0DDBAD26311 FOREIGN KEY (tag_id) REFERENCES tags (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE item_tags DROP FOREIGN KEY FK_A78CD0DD126F525E');
        $this->addSql('ALTER TABLE item_tags DROP FOREIGN KEY FK_A78CD0DDBAD26311');
        $this->addSql('DROP TABLE item_tags');
        $this->addSql('DROP TABLE tags');
    }
}
