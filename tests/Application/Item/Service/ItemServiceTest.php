<?php

declare(strict_types=1);

namespace App\Tests\Application\Item\Service;

use App\Application\Common\Transaction\UnitOfWorkInterface;
use App\Application\Item\DTO\CreateItemDTO;
use App\Application\Item\DTO\ItemSlotDTO;
use App\Application\Item\DTO\UpdateItemDTO;
use App\Application\Item\Service\ItemService;
use App\Application\Item\Service\ItemSlotMapper;
use App\Application\Tag\Service\TagService;
use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\ValueObject\CollectionName;
use App\Domain\Collection\ValueObject\FieldType;
use App\Domain\Collection\ValueObject\OwnerId;
use App\Domain\Collection\ValueObject\Theme;
use App\Domain\Item\Entity\Item;
use App\Domain\Item\Exception\ItemNotFoundException;
use App\Domain\Item\Repository\ItemRepositoryInterface;
use App\Domain\Tag\Entity\Tag;
use App\Domain\Tag\Repository\TagRepositoryInterface;
use App\Domain\Tag\ValueObject\TagName;
use App\Domain\User\Entity\User;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\PasswordHash;
use PHPUnit\Framework\TestCase;

final class ItemServiceTest extends TestCase
{
    private ItemRepositoryInterface $itemRepo;
    private UnitOfWorkInterface $uow;
    private TagRepositoryInterface $tagRepo;
    private ItemService $service;

    protected function setUp(): void
    {
        $this->itemRepo = $this->createMock(ItemRepositoryInterface::class);
        $this->uow = $this->createMock(UnitOfWorkInterface::class);
        $this->uow->method('transactional')
            ->willReturnCallback(static fn (callable $callback) => $callback());
        $this->tagRepo = $this->createMock(TagRepositoryInterface::class);
        $tagService = new TagService($this->tagRepo);

        $this->service = new ItemService(
            $this->itemRepo,
            $this->uow,
            $tagService,
            new ItemSlotMapper(),
        );
    }

    private function createCollection(): Collection
    {
        $user = User::register(
            name: 'Item Service Test',
            email: Email::fromString('item_service_test@example.com'),
            passwordHash: PasswordHash::createFromPlain('Pass123!'),
        );

        return Collection::create(
            owner: $user,
            name: CollectionName::fromString('Item Service Collection'),
            theme: Theme::books(),
        );
    }

    private function createItem(string $name = 'Item'): Item
    {
        return Item::create($this->createCollection(), $name);
    }

    public function testCreateSavesWithinTransaction(): void
    {
        $this->itemRepo->expects($this->once())->method('save');
        $this->uow->expects($this->never())->method('flush');

        $item = $this->service->create(new CreateItemDTO('1984'), $this->createCollection());

        $this->assertSame('1984', $item->getName());
    }

    public function testCreateAppliesSlots(): void
    {
        $dto = new CreateItemDTO('1984', slots: [new ItemSlotDTO('text', 1, 'Dystopia')]);

        $item = $this->service->create($dto, $this->createCollection());

        $this->assertSame('Dystopia', $item->getSlotValue(FieldType::text(), 1));
    }

    public function testCreateResolvesAndAddsTags(): void
    {
        $tag = Tag::create(TagName::fromString('Books'));
        $this->tagRepo->method('getOrCreate')->willReturn($tag);

        $item = $this->service->create(new CreateItemDTO('1984', tags: ['Books']), $this->createCollection());

        $this->assertCount(1, $item->getTags());
        $this->assertSame('Books', $item->getTags()[0]->getName()->value());
    }

    public function testGetByIdReturnsItem(): void
    {
        $item = $this->createItem();
        $this->itemRepo->method('findById')->willReturn($item);

        $this->assertSame($item, $this->service->getById($item->getId()->toString()));
    }

    public function testGetByIdThrowsWhenNotFound(): void
    {
        $this->itemRepo->method('findById')->willReturn(null);

        $this->expectException(ItemNotFoundException::class);
        $this->service->getById('00000000-0000-0000-0000-000000000001');
    }

    public function testUpdateChangesName(): void
    {
        $item = $this->createItem('Old');
        $this->itemRepo->expects($this->once())->method('save');
        $this->uow->expects($this->never())->method('flush');

        $this->service->update(new UpdateItemDTO(name: 'New'), $item);

        $this->assertSame('New', $item->getName());
    }

    public function testUpdateAppliesSlotsPartially(): void
    {
        $item = $this->createItem();
        $item->setSlotValue(FieldType::text(), 1, 'Keep');

        $this->service->update(new UpdateItemDTO(slots: [new ItemSlotDTO('text', 2, 'Add')]), $item);

        $this->assertSame('Keep', $item->getSlotValue(FieldType::text(), 1));
        $this->assertSame('Add', $item->getSlotValue(FieldType::text(), 2));
    }

    public function testUpdateReplacesTags(): void
    {
        $item = $this->createItem();
        $tagA = Tag::create(TagName::fromString('Books'));
        $tagB = Tag::create(TagName::fromString('Games'));
        $item->addTag($tagA);

        $this->tagRepo->method('getOrCreate')->willReturn($tagB);

        $this->service->update(new UpdateItemDTO(tags: ['Games']), $item);

        $this->assertCount(1, $item->getTags());
        $this->assertSame('Games', $item->getTags()[0]->getName()->value());
        $this->assertFalse($item->hasTag($tagA));
    }

    public function testUpdateWithEmptyTagsClearsAllTags(): void
    {
        $item = $this->createItem();
        $item->addTag(Tag::create(TagName::fromString('Books')));
        $item->addTag(Tag::create(TagName::fromString('Games')));

        $this->tagRepo->expects($this->never())->method('getOrCreate');

        $this->service->update(new UpdateItemDTO(tags: []), $item);

        $this->assertCount(0, $item->getTags());
    }

    public function testDeleteRemovesAndFlushes(): void
    {
        $item = $this->createItem();
        $this->itemRepo->expects($this->once())->method('remove')->with($item);
        $this->uow->expects($this->once())->method('flush');

        $this->service->delete($item);
    }

    public function testListByCollectionDelegatesToRepository(): void
    {
        $collection = $this->createCollection();
        $item = $this->createItem();
        $this->itemRepo->expects($this->once())
            ->method('findByCollectionId')
            ->with($collection->getId(), 50, 0, null, [])
            ->willReturn([$item]);

        $this->assertSame([$item], $this->service->listByCollection($collection->getId()));
    }

    public function testListByOwnerDelegatesToRepository(): void
    {
        $collection = $this->createCollection();
        $item = $this->createItem();
        $ownerId = OwnerId::fromBytes($collection->getOwner()->getId()->toBytes());
        $this->itemRepo->expects($this->once())
            ->method('findByOwnerId')
            ->with($ownerId, 10, 5, null, [])
            ->willReturn([$item]);

        $this->assertSame([$item], $this->service->listByOwner($ownerId, 10, 5));
    }

    public function testListByCollectionNormalizesFilters(): void
    {
        $collection = $this->createCollection();
        $item = $this->createItem();
        $this->itemRepo->expects($this->once())
            ->method('findByCollectionId')
            ->with($collection->getId(), 50, 0, 'Book', ['Sci-fi', 'drama'])
            ->willReturn([$item]);

        $this->assertSame(
            [$item],
            $this->service->listByCollection($collection->getId(), 50, 0, '  Book  ', [' Sci-fi ', 'Sci-fi', ' drama ']),
        );
    }

    public function testListByOwnerNormalizesEmptyFiltersToDefaults(): void
    {
        $collection = $this->createCollection();
        $item = $this->createItem();
        $ownerId = OwnerId::fromBytes($collection->getOwner()->getId()->toBytes());
        $this->itemRepo->expects($this->once())
            ->method('findByOwnerId')
            ->with($ownerId, 10, 5, null, ['Books'])
            ->willReturn([$item]);

        $this->assertSame(
            [$item],
            $this->service->listByOwner($ownerId, 10, 5, '   ', ['', 'Books']),
        );
    }

    public function testToDTOBuildsItemDTO(): void
    {
        $item = $this->createItem('DTO');
        $item->setSlotValue(FieldType::text(), 1, 'X');

        $dto = $this->service->toDTO($item);

        $this->assertSame($item->getId()->toString(), $dto->id);
        $this->assertSame('DTO', $dto->name);
        $this->assertCount(1, $dto->slots);
    }

    public function testToDTOListBuildsDTOsInOrder(): void
    {
        $alpha = $this->createItem('Alpha');
        $beta = $this->createItem('Beta');

        $dtos = $this->service->toDTOList([$alpha, $beta]);

        $this->assertCount(2, $dtos);
        $this->assertSame('Alpha', $dtos[0]->name);
        $this->assertSame('Beta', $dtos[1]->name);
    }
}
