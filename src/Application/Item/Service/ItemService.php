<?php

declare(strict_types=1);

namespace App\Application\Item\Service;

use App\Application\Common\Transaction\UnitOfWorkInterface;
use App\Application\Item\DTO\CreateItemDTO;
use App\Application\Item\DTO\ItemDTO;
use App\Application\Item\DTO\UpdateItemDTO;
use App\Application\Search\ItemDocument;
use App\Application\Search\SearchIndexerInterface;
use App\Application\Tag\Service\TagService;
use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\ValueObject\CollectionId;
use App\Domain\Common\ValueObject\OwnerId;
use App\Domain\Item\Entity\Item;
use App\Domain\Item\Exception\ItemNotFoundException;
use App\Domain\Item\Repository\ItemRepositoryInterface;
use App\Domain\Item\ValueObject\ItemId;
use App\Domain\Tag\ValueObject\TagName;

final readonly class ItemService
{
    public function __construct(
        private ItemRepositoryInterface $itemRepository,
        private UnitOfWorkInterface $unitOfWork,
        private TagService $tagService,
        private ItemSlotMapper $slotMapper,
        private SearchIndexerInterface $searchIndexer,
    ) {
    }

    /**
     * Indexing runs after transactional() has returned, i.e. after commit:
     * calling it inside the closure would leave a ghost document behind if the
     * transaction rolled back. No try/catch here — fail-open is the adapter's
     * job (see SearchIndexerInterface).
     *
     * Never wrap these methods in an outer transaction: the inner one would
     * become a savepoint, indexing would run before the real commit, and an
     * outer rollback would leave exactly the ghost this placement prevents.
     */
    public function create(CreateItemDTO $dto, Collection $collection): Item
    {
        $item = $this->unitOfWork->transactional(function () use ($dto, $collection): Item {
            $item = Item::create($collection, $dto->name);
            $this->slotMapper->applySlots($item, $dto->slots);

            foreach ($this->tagService->resolveByNames($dto->tags) as $tag) {
                $item->addTag($tag);
            }

            $this->itemRepository->save($item);

            return $item;
        });

        $this->searchIndexer->indexItem(ItemDocument::fromEntity($item));

        return $item;
    }

    public function getById(string $id): Item
    {
        $itemId = ItemId::fromString($id);
        $item = $this->itemRepository->findById($itemId);

        if (!$item instanceof Item) {
            throw ItemNotFoundException::withId($itemId);
        }

        return $item;
    }

    public function update(UpdateItemDTO $dto, Item $item): Item
    {
        $item = $this->unitOfWork->transactional(function () use ($dto, $item): Item {
            if (null !== $dto->name) {
                $item->changeName($dto->name);
            }

            if (null !== $dto->slots) {
                $this->slotMapper->applySlots($item, $dto->slots);
            }

            if (null !== $dto->tags) {
                $this->replaceTags($item, $dto->tags);
            }

            $this->itemRepository->save($item);

            return $item;
        });

        $this->searchIndexer->indexItem(ItemDocument::fromEntity($item));

        return $item;
    }

    /**
     * No surrounding transaction: the DELETE autocommits on flush(), so the
     * index is touched only after it. If flush() throws, removeItem() never
     * runs and the document stays — matching a delete that did not happen.
     */
    public function delete(Item $item): void
    {
        $id = $item->getId();

        $this->itemRepository->remove($item);
        $this->unitOfWork->flush();

        $this->searchIndexer->removeItem($id);
    }

    /**
     * @param array<string> $tagNames
     *
     * @return array<Item>
     */
    public function listByCollection(CollectionId $collectionId, int $limit = 50, int $offset = 0, ?string $name = null, array $tagNames = []): array
    {
        return $this->itemRepository->findByCollectionId(
            $collectionId,
            $limit,
            $offset,
            $this->normalizeName($name),
            $this->normalizeTagNames($tagNames),
        );
    }

    /**
     * @param array<string> $tagNames
     *
     * @return array<Item>
     */
    public function listByOwner(OwnerId $ownerId, int $limit = 50, int $offset = 0, ?string $name = null, array $tagNames = []): array
    {
        return $this->itemRepository->findByOwnerId(
            $ownerId,
            $limit,
            $offset,
            $this->normalizeName($name),
            $this->normalizeTagNames($tagNames),
        );
    }

    public function toDTO(Item $item): ItemDTO
    {
        return ItemDTO::fromEntity($item);
    }

    /**
     * @param array<Item> $items
     *
     * @return array<ItemDTO>
     */
    public function toDTOList(array $items): array
    {
        return \array_map(
            $this->toDTO(...),
            $items,
        );
    }

    /**
     * @param array<string> $names
     */
    private function replaceTags(Item $item, array $names): void
    {
        $desired = [];
        foreach ($this->tagService->resolveByNames($names) as $tag) {
            $desired[$tag->getId()->toBytes()] = $tag;
        }

        $current = [];
        foreach ($item->getTags() as $tag) {
            $current[$tag->getId()->toBytes()] = $tag;
        }

        foreach ($current as $id => $tag) {
            if (!isset($desired[$id])) {
                $item->removeTag($tag);
            }
        }

        foreach ($desired as $id => $tag) {
            if (!isset($current[$id])) {
                $item->addTag($tag);
            }
        }
    }

    private function normalizeName(?string $name): ?string
    {
        if (null === $name) {
            return null;
        }

        $name = \trim($name);

        return '' === $name ? null : $name;
    }

    /**
     * @param array<string> $tagNames
     *
     * @return array<string>
     */
    private function normalizeTagNames(array $tagNames): array
    {
        $normalized = [];
        foreach ($tagNames as $tagName) {
            $tagName = \trim($tagName);
            if ('' === $tagName) {
                continue;
            }

            $normalized[] = TagName::fromString($tagName)->value();
        }

        return \array_values(\array_unique($normalized));
    }
}
