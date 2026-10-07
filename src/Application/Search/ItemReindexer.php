<?php

declare(strict_types=1);

namespace App\Application\Search;

use App\Application\Common\Transaction\UnitOfWorkInterface;
use App\Domain\Item\Repository\ItemRepositoryInterface;

/**
 * Rebuilds the items index from the database.
 *
 * The recovery path for fail-open indexing: a write that the engine dropped
 * leaves a stale index with no error anywhere, and this is the only way to
 * repair it. Idempotent — documents are upserted by id — so re-running is safe
 * and also covers rows skipped by offset drift under concurrent writes.
 *
 * Memory stays flat: each page is fetched fresh, turned into documents, then
 * the unit of work is cleared. Documents are built BEFORE clear() — reading an
 * entity after the identity map is cleared would hit a detached proxy.
 *
 * Does not remove documents whose rows are gone; a full rebuild that also
 * prunes orphans is out of scope until deletes are observed to be lost.
 */
final readonly class ItemReindexer
{
    public function __construct(
        private ItemRepositoryInterface $itemRepository,
        private UnitOfWorkInterface $unitOfWork,
        private SearchIndexerInterface $searchIndexer,
    ) {
    }

    /**
     * @return int number of items sent to the index
     */
    public function reindexAll(int $batchSize = 100): int
    {
        if ($batchSize < 1) {
            throw new \InvalidArgumentException(\sprintf('Batch size must be positive, got %d.', $batchSize));
        }

        $count = 0;
        $offset = 0;

        while ([] !== ($items = $this->itemRepository->findAll($batchSize, $offset))) {
            foreach ($items as $item) {
                $this->searchIndexer->indexItem(ItemDocument::fromEntity($item));
                ++$count;
            }

            $this->unitOfWork->clear();
            $offset += $batchSize;

            if (\count($items) < $batchSize) {
                break;
            }
        }

        return $count;
    }
}
