<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Deterministic listing order (fwd-27): composite indexes serving the
 * (created_at, id) sort of the item/collection list queries, mirroring the
 * comment/like precedent (idx_comment_item, idx_comment_owner). Item is read
 * under a collection_id filter (ASC side); collections are read newest-first,
 * served by a backward scan of the plain ASC index. Tag needs none:
 * uniq_tag_name already serves (name, id). The two pre-existing single-column
 * indexes (idx_item_collection, idx_collection_owner) are now leftmost-prefix
 * redundant, so they are dropped here (Comment entity keeps only composites).
 * WARNING: data is unaffected, only the indexes.
 */
final class Version20260927115659 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Composite listing indexes for items and collections (fwd-27)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_item_collection_list ON items (collection_id, created_at, id)');
        $this->addSql('CREATE INDEX idx_collection_owner_list ON collections (owner_id, created_at, id)');
        $this->addSql('DROP INDEX idx_item_collection ON items');
        $this->addSql('DROP INDEX idx_collection_owner ON collections');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_collection_owner ON collections (owner_id)');
        $this->addSql('CREATE INDEX idx_item_collection ON items (collection_id)');
        $this->addSql('DROP INDEX idx_collection_owner_list ON collections');
        $this->addSql('DROP INDEX idx_item_collection_list ON items');
    }
}
