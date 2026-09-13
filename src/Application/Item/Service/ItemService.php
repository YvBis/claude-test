<?php

declare(strict_types=1);

namespace App\Application\Item\Service;

use App\Application\Common\Transaction\UnitOfWorkInterface;
use App\Application\Item\DTO\CreateItemDTO;
use App\Application\Item\DTO\ItemDTO;
use App\Application\Item\DTO\UpdateItemDTO;
use App\Application\Tag\Service\TagService;
use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\ValueObject\CollectionId;
use App\Domain\Collection\ValueObject\OwnerId;
use App\Domain\Item\Entity\Item;
use App\Domain\Item\Exception\ItemNotFoundException;
use App\Domain\Item\Repository\ItemRepositoryInterface;
use App\Domain\Item\ValueObject\ItemId;

final readonly class ItemService
{
    public function __construct(
        private ItemRepositoryInterface $itemRepository,
        private UnitOfWorkInterface $unitOfWork,
        private TagService $tagService,
        private ItemSlotMapper $slotMapper,
    ) {
    }

    public function create(CreateItemDTO $dto, Collection $collection): Item
    {
        return $this->unitOfWork->transactional(function () use ($dto, $collection): Item {
            $item = Item::create($collection, $dto->name);
            $this->slotMapper->applySlots($item, $dto->slots);

            foreach ($this->tagService->resolveByNames($dto->tags) as $tag) {
                $item->addTag($tag);
            }

            $this->itemRepository->save($item);

            return $item;
        });
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
        return $this->unitOfWork->transactional(function () use ($dto, $item): Item {
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
    }

    public function delete(Item $item): void
    {
        $this->itemRepository->remove($item);
        $this->unitOfWork->flush();
    }

    /**
     * @return array<Item>
     */
    public function listByCollection(CollectionId $collectionId, int $limit = 50, int $offset = 0): array
    {
        return $this->itemRepository->findByCollectionId($collectionId, $limit, $offset);
    }

    /**
     * @return array<Item>
     */
    public function listByOwner(OwnerId $ownerId, int $limit = 50, int $offset = 0): array
    {
        return $this->itemRepository->findByOwnerId($ownerId, $limit, $offset);
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
            fn (Item $item): ItemDTO => $this->toDTO($item),
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
}
