<?php

declare(strict_types=1);

namespace App\Application\Collection\Service;

use App\Application\Collection\DTO\CollectionDTO;
use App\Application\Collection\DTO\CreateCollectionDTO;
use App\Application\Collection\DTO\UpdateCollectionDTO;
use App\Application\Common\Transaction\UnitOfWorkInterface;
use App\Application\Search\CollectionDocument;
use App\Application\Search\ItemReindexer;
use App\Application\Search\SearchIndexerInterface;
use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\Exception\CollectionNotFoundException;
use App\Domain\Collection\Repository\CollectionRepositoryInterface;
use App\Domain\Collection\ValueObject\CollectionId;
use App\Domain\Collection\ValueObject\CollectionName;
use App\Domain\Collection\ValueObject\Theme;
use App\Domain\Common\ValueObject\OwnerId;
use App\Domain\Item\Repository\ItemRepositoryInterface;

final readonly class CollectionService
{
    public function __construct(
        private CollectionRepositoryInterface $collectionRepository,
        private UnitOfWorkInterface $unitOfWork,
        private ItemRepositoryInterface $itemRepository,
        private ItemReindexer $itemReindexer,
        private SearchIndexerInterface $searchIndexer,
    ) {
    }

    /**
     * Indexing runs after flush(), i.e. after the write: this service never
     * opens a transaction, so flush() autocommits. No try/catch here —
     * fail-open is the adapter's job (see SearchIndexerInterface).
     *
     * Never wrap these methods in an outer transaction: indexing would run
     * before the real commit, and an outer rollback would leave a ghost
     * document (the same hazard ItemService documents on its own methods).
     */
    public function create(CreateCollectionDTO $dto, OwnerId $ownerId): Collection
    {
        $collection = Collection::create(
            ownerId: $ownerId,
            name: CollectionName::fromString($dto->name),
            theme: Theme::fromString($dto->theme),
            description: $dto->description,
            image: $dto->image,
        );

        $this->collectionRepository->save($collection);
        $this->unitOfWork->flush();

        $this->searchIndexer->indexCollection(CollectionDocument::fromEntity($collection));

        return $collection;
    }

    public function update(UpdateCollectionDTO $dto, Collection $collection): Collection
    {
        // The VO is built once and compared before mutation: comparing raw
        // strings would fan out on a same-value "rename" (whitespace aside),
        // and comparing after changeName could never see a difference.
        $newName = null !== $dto->name ? CollectionName::fromString($dto->name) : null;
        $renamed = $newName instanceof CollectionName && !$collection->getName()->equals($newName);

        if ($newName instanceof CollectionName) {
            $collection->changeName($newName);
        }

        if (null !== $dto->description) {
            $collection->changeDescription($dto->description);
        }

        if (null !== $dto->image) {
            $collection->changeImage($dto->image);
        }

        $this->collectionRepository->save($collection);
        $this->unitOfWork->flush();

        // The collection document (with the new name) lands before the
        // fan-out, whose clear() detaches the collection.
        $this->searchIndexer->indexCollection(CollectionDocument::fromEntity($collection));

        if ($renamed) {
            $this->itemReindexer->reindexCollection($collection->getId());
        }

        return $collection;
    }

    public function getById(string $id): Collection
    {
        $collectionId = CollectionId::fromString($id);
        $collection = $this->collectionRepository->findById($collectionId);

        if (!$collection instanceof Collection) {
            throw CollectionNotFoundException::withId($collectionId);
        }

        return $collection;
    }

    /**
     * @return array<Collection>
     */
    public function listAll(int $limit = 50, int $offset = 0): array
    {
        return $this->collectionRepository->findAll($limit, $offset);
    }

    /**
     * @return array<Collection>
     */
    public function listByOwnerId(OwnerId $ownerId, int $limit = 50, int $offset = 0): array
    {
        return $this->collectionRepository->findByOwnerId($ownerId, $limit, $offset);
    }

    /**
     * Ids are collected BEFORE remove(): the rows cascade-delete in the
     * database without the app iterating them, so anything not collected here
     * stays in the index as an orphan. A paged entity walk with clear() is out
     * of the question — clearing detaches the collection itself and remove()
     * then throws on the detached entity.
     */
    public function delete(Collection $collection): void
    {
        $itemIds = $this->itemRepository->findIdsByCollectionId($collection->getId());
        $collectionId = $collection->getId();

        $this->collectionRepository->remove($collection);
        $this->unitOfWork->flush();

        foreach ($itemIds as $itemId) {
            $this->searchIndexer->removeItem($itemId);
        }

        $this->searchIndexer->removeCollection($collectionId);
    }

    public function toDTO(Collection $collection): CollectionDTO
    {
        return CollectionDTO::fromEntity($collection);
    }

    /**
     * @param array<Collection> $collections
     *
     * @return array<CollectionDTO>
     */
    public function toDTOList(array $collections): array
    {
        return \array_map(
            $this->toDTO(...),
            $collections,
        );
    }
}
