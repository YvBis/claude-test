<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the idx_like_owner covering index (Task 5.7 "my likes" listing):
 * findByOwnerId filters by owner_id and orders by (created_at, id), so the
 * composite index serves both the filter and the sort without a filesort.
 * Mirrors idx_comment_owner on the comments table (Task 5.2). Non-destructive:
 * down() only drops the index, no data is touched.
 */
final class Version20260920201821 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add idx_like_owner covering index for the owner likes listing (Task 5.7)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_like_owner ON likes (owner_id, created_at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_like_owner ON likes');
    }
}
