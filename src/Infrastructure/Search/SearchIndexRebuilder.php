<?php

declare(strict_types=1);

namespace App\Infrastructure\Search;

use App\Application\Search\CollectionReindexer;
use App\Application\Search\ItemReindexer;

/**
 * One place that knows the order of a full search rebuild: provision, optionally
 * wipe, then walk the repositories.
 *
 * Extracted in 9.4 because two callers need it (`search:reindex` and
 * `app:seed:demo`) and the order is the part that matters: a wipe after the
 * walk would destroy the fresh documents, and a walk without provisioning
 * fails on a fresh engine.
 */
final readonly class SearchIndexRebuilder
{
    public function __construct(
        private MeilisearchSearchAdapter $adapter,
        private ItemReindexer $itemReindexer,
        private CollectionReindexer $collectionReindexer,
    ) {
    }

    /**
     * @return array{items: int, collections: int} documents built per index
     */
    public function rebuild(int $batchSize, bool $reset = false): array
    {
        $this->adapter->ensureIndexes();

        if ($reset) {
            $this->adapter->resetIndexes();
        }

        return [
            'items' => $this->itemReindexer->reindexAll($batchSize),
            'collections' => $this->collectionReindexer->reindexAll($batchSize),
        ];
    }
}
