<?php

declare(strict_types=1);

namespace App\Application\Search;

use App\Application\Common\Transaction\UnitOfWorkInterface;
use App\Domain\Collection\Repository\CollectionRepositoryInterface;

/**
 * Rebuilds the collections index from the database.
 *
 * The parallel of ItemReindexer for the other aggregate — deliberately a
 * separate class, not a generic reindexer: two near-identical paginate-index-
 * clear walks are cheaper to keep than a generic walker is to design, and each
 * keeps its own document type and ordering. Same discipline: pages are fetched
 * fresh, documents are built BEFORE clear(), the walk is idempotent, orphans
 * are not pruned.
 */
final readonly class CollectionReindexer
{
    public function __construct(
        private CollectionRepositoryInterface $collectionRepository,
        private UnitOfWorkInterface $unitOfWork,
        private SearchIndexerInterface $searchIndexer,
    ) {
    }

    /**
     * @return int number of collections sent to the index
     */
    public function reindexAll(int $batchSize = 100): int
    {
        if ($batchSize < 1) {
            throw new \InvalidArgumentException(\sprintf('Batch size must be positive, got %d.', $batchSize));
        }

        $count = 0;
        $offset = 0;

        while ([] !== ($collections = $this->collectionRepository->findAll($batchSize, $offset))) {
            foreach ($collections as $collection) {
                $this->searchIndexer->indexCollection(CollectionDocument::fromEntity($collection));
                ++$count;
            }

            $this->unitOfWork->clear();
            $offset += $batchSize;

            if (\count($collections) < $batchSize) {
                break;
            }
        }

        return $count;
    }
}
