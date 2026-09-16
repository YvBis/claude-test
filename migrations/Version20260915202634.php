<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the likes table (Task 5.1 domain Like): one like per (owner, item)
 * enforced by UNIQUE uniq_like_owner_item; both FKs cascade so removing an
 * item (or user) removes its likes. Manual adjustments vs auto-generated diff:
 * DATETIME(6) precision and COLLATE utf8mb4_0900_ai_ci to match the squashed
 * baseline golden reference.
 * WARNING: down() drops likes — all like data is lost on rollback.
 */
final class Version20260915202634 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create likes table (Task 5.1 domain Like)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE likes (id VARBINARY(16) NOT NULL, created_at DATETIME(6) NOT NULL, owner_id VARBINARY(16) NOT NULL, item_id VARBINARY(16) NOT NULL, INDEX idx_like_item (item_id), UNIQUE INDEX uniq_like_owner_item (owner_id, item_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        $this->addSql('ALTER TABLE likes ADD CONSTRAINT FK_49CA4E7D7E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE likes ADD CONSTRAINT FK_49CA4E7D126F525E FOREIGN KEY (item_id) REFERENCES items (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE likes DROP FOREIGN KEY FK_49CA4E7D7E3C61F9');
        $this->addSql('ALTER TABLE likes DROP FOREIGN KEY FK_49CA4E7D126F525E');
        $this->addSql('DROP TABLE likes');
    }
}
