<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * 7.6b: index serving the admin like audit listing, which orders globally
 * by (created_at, id). Neither scoped index (item/owner-prefixed) serves a
 * global sort, so the listing filesorts without this one.
 */
final class Version20261011100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Admin audit: idx_like_created (created_at, id) on likes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_like_created ON likes (created_at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_like_created ON likes');
    }
}
