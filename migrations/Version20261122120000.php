<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * 10.6: the last-admin guard reads the admin rows under a locking read, so the
 * role needs an index. Without it MySQL scans the whole `users` table for
 * `WHERE role = 'admin' FOR UPDATE` and takes next-key locks on every row and
 * gap, blocking registrations for the duration of the admin transaction.
 */
final class Version20261122120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Last-admin guard: idx_user_role (role) on users';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_user_role ON users (role)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_user_role ON users');
    }
}
