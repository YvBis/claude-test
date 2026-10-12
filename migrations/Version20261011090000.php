<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * 7.6a: index serving the admin comment audit listing, which orders globally
 * by (created_at, id). Neither scoped index (item/owner-prefixed) serves a
 * global sort, so the listing filesorts without this one.
 */
final class Version20261011090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Admin audit: idx_comment_created (created_at, id) on comments';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_comment_created ON comments (created_at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_comment_created ON comments');
    }
}
