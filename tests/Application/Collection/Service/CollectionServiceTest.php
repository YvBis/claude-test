<?php

declare(strict_types=1);

namespace App\Tests\Application\Collection\Service;

use App\Application\Collection\DTO\CreateCollectionDTO;
use App\Application\Collection\DTO\UpdateCollectionDTO;
use App\Application\Collection\Service\CollectionService;
use App\Application\Common\Transaction\UnitOfWorkInterface;
use App\Application\Search\CollectionDocument;
use App\Application\Search\ItemDocument;
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
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class CollectionServiceTest extends TestCase
{
    private CollectionRepositoryInterface $collectionRepository;
    private UnitOfWorkInterface $unitOfWork;
    private ItemRepositoryInterface $itemRepository;
    private SearchIndexerInterface $searchIndexer;
    private ItemReindexer $itemReindexer;
    private CollectionService $service;
    private OwnerId $ownerId;

    protected function setUp(): void
    {
        $this->collectionRepository = $this->createMock(CollectionRepositoryInterface::class);
        $this->unitOfWork = $this->createMock(UnitOfWorkInterface::class);
        $this->itemRepository = $this->createMock(ItemRepositoryInterface::class);
        $this->searchIndexer = $this->createMock(SearchIndexerInterface::class);
        // ItemReindexer is final and cannot be doubled; the real one over the
        // mocked port is deterministic and tests the actual fan-out path.
        $this->itemReindexer = new ItemReindexer($this->itemRepository, $this->unitOfWork, $this->searchIndexer);
        $this->service = new CollectionService(
            $this->collectionRepository,
            $this->unitOfWork,
            $this->itemRepository,
            $this->itemReindexer,
            $this->searchIndexer,
        );
        $this->ownerId = OwnerId::generate();
    }

    public function testCreateIndexesTheCollectionAfterFlush(): void
    {
        $calls = [];
        $this->unitOfWork->method('flush')->willReturnCallback(static function () use (&$calls): void {
            $calls[] = 'flush';
        });
        $this->searchIndexer->expects($this->once())
            ->method('indexCollection')
            ->with($this->callback(static fn (CollectionDocument $d): bool => 'My Collection' === $d->name))
            ->willReturnCallback(static function () use (&$calls): void {
                $calls[] = 'indexCollection';
            });

        $this->service->create(new CreateCollectionDTO('My Collection', 'books'), $this->ownerId);

        $this->assertSame(['flush', 'indexCollection'], $calls);
    }

    public function testUpdateWithoutRenameIndexesOnlyTheCollection(): void
    {
        $collection = $this->collectionNamed('Old Name');

        $this->searchIndexer->expects($this->once())->method('indexCollection');
        $this->itemRepository->expects($this->never())->method('findByCollectionId');
        $this->searchIndexer->expects($this->never())->method('indexItem');

        $this->service->update(new UpdateCollectionDTO(description: 'New description'), $collection);
    }

    public function testUpdateWithSameNameDoesNotFanOut(): void
    {
        $collection = $this->collectionNamed('Old Name');

        $this->searchIndexer->expects($this->once())->method('indexCollection');
        $this->itemRepository->expects($this->never())->method('findByCollectionId');

        $this->service->update(new UpdateCollectionDTO(name: 'Old Name'), $collection);
    }

    public function testUpdateWithBlankNameDoesNotFanOut(): void
    {
        $collection = $this->collectionNamed('Old Name');

        $this->searchIndexer->expects($this->once())->method('indexCollection');
        $this->itemRepository->expects($this->never())->method('findByCollectionId');

        // '' normalizes to null in the DTO: no name change at all.
        $this->service->update(new UpdateCollectionDTO(name: '   '), $collection);

        $this->assertSame('Old Name', $collection->getName()->value());
    }

    public function testUpdateWithRenameFansOutToItems(): void
    {
        $collection = $this->collectionNamed('Old Name');
        $item = \App\Domain\Item\Entity\Item::create($collection, 'Fan');
        $this->itemRepository->method('findByCollectionId')->willReturn([$item], []);

        // Method names, not a generic marker: indexCollection must land before
        // the fan-out, whose clear() detaches the collection.
        $calls = [];
        $this->searchIndexer->method('indexCollection')
            ->willReturnCallback(static function () use (&$calls): void {
                $calls[] = 'indexCollection';
            });
        $this->searchIndexer->method('indexItem')
            ->willReturnCallback(static function () use (&$calls): void {
                $calls[] = 'indexItem';
            });

        $this->service->update(new UpdateCollectionDTO(name: 'New Name'), $collection);

        $this->assertSame(['indexCollection', 'indexItem'], $calls);
    }

    public function testFannedDocumentCarriesTheNewCollectionName(): void
    {
        $collection = $this->collectionNamed('Old Name');
        $item = \App\Domain\Item\Entity\Item::create($collection, 'Fan');
        $this->itemRepository->method('findByCollectionId')->willReturn([$item], []);

        $seen = [];
        $this->searchIndexer->method('indexItem')
            ->willReturnCallback(static function (ItemDocument $d) use (&$seen): void {
                $seen[] = $d;
            });

        $this->service->update(new UpdateCollectionDTO(name: 'New Name'), $collection);

        $this->assertCount(1, $seen);
        $this->assertSame('New Name', $seen[0]->collectionName);
    }

    public function testUpdateWithCaseOnlyChangeFansOut(): void
    {
        $collection = $this->collectionNamed('old name');
        $item = \App\Domain\Item\Entity\Item::create($collection, 'Fan');
        $this->itemRepository->method('findByCollectionId')->willReturn([$item], []);

        $this->searchIndexer->expects($this->once())->method('indexItem');

        $this->service->update(new UpdateCollectionDTO(name: 'Old Name'), $collection);
    }

    public function testFlushFailureLeavesTheIndexUntouched(): void
    {
        $this->unitOfWork->method('flush')->willThrowException(new \RuntimeException('flush failed'));
        // Every port method must stay silent on all three paths.
        $this->searchIndexer->expects($this->never())->method('indexItem');
        $this->searchIndexer->expects($this->never())->method('removeItem');
        $this->searchIndexer->expects($this->never())->method('indexCollection');
        $this->searchIndexer->expects($this->never())->method('removeCollection');
        $this->itemRepository->expects($this->never())->method('findByCollectionId');

        $collection = $this->collectionNamed('Old Name');

        try {
            $this->service->create(new CreateCollectionDTO('Valid Name', 'books'), $this->ownerId);
            $this->fail('create must propagate the flush failure');
        } catch (\RuntimeException) {
        }

        try {
            $this->service->update(new UpdateCollectionDTO(name: 'Valid Rename'), $collection);
            $this->fail('update must propagate the flush failure');
        } catch (\RuntimeException) {
        }

        try {
            $this->service->delete($collection);
            $this->fail('delete must propagate the flush failure');
        } catch (\RuntimeException) {
        }
    }

    public function testDeleteCollectsItemIdsBeforeRemove(): void
    {
        $collection = $this->collectionNamed('Gone');
        $itemId = \App\Domain\Item\ValueObject\ItemId::generate();

        $calls = [];
        $this->itemRepository->expects($this->once())
            ->method('findIdsByCollectionId')
            ->with($collection->getId())
            ->willReturnCallback(static function () use (&$calls, $itemId): array {
                $calls[] = 'findIds';

                return [$itemId];
            });
        $this->collectionRepository->expects($this->once())
            ->method('remove')
            ->willReturnCallback(static function () use (&$calls): void {
                $calls[] = 'remove';
            });
        $this->unitOfWork->method('flush')->willReturnCallback(static function () use (&$calls): void {
            $calls[] = 'flush';
        });
        // remove() on a detached collection throws, so ids-before-remove is
        // load-bearing, not stylistic. Method names pin the order: every
        // removeItem lands after flush and before removeCollection.
        $this->searchIndexer->method('removeItem')
            ->willReturnCallback(static function () use (&$calls): void {
                $calls[] = 'removeItem';
            });
        $this->searchIndexer->method('removeCollection')
            ->willReturnCallback(static function () use (&$calls): void {
                $calls[] = 'removeCollection';
            });

        $this->service->delete($collection);

        $this->assertSame(['findIds', 'remove', 'flush', 'removeItem', 'removeCollection'], $calls);
    }

    private function collectionNamed(string $name): Collection
    {
        return Collection::create(
            ownerId: $this->ownerId,
            name: CollectionName::fromString($name),
            theme: Theme::fromString('books'),
        );
    }

    public function testCreateSavesCollection(): void
    {
        $dto = new CreateCollectionDTO('My Collection', 'books', 'A test collection', 'image.jpg');

        $this->collectionRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function (Collection $collection) {
                return 'My Collection' === $collection->getName()->value()
                    && 'books' === $collection->getTheme()->value()
                    && 'A test collection' === $collection->getDescription()
                    && 'image.jpg' === $collection->getImage()
                    && $collection->getOwnerId()->equals($this->ownerId);
            }));

        $this->unitOfWork->expects($this->once())->method('flush');

        $collection = $this->service->create($dto, $this->ownerId);

        $this->assertInstanceOf(Collection::class, $collection);
        $this->assertSame('My Collection', $collection->getName()->value());
        $this->assertSame('books', $collection->getTheme()->value());
        $this->assertSame('A test collection', $collection->getDescription());
        $this->assertSame('image.jpg', $collection->getImage());
        $this->assertTrue($collection->getOwnerId()->equals($this->ownerId));
    }

    public function testUpdateModifiesCollection(): void
    {
        $collectionId = CollectionId::generate();
        $collection = Collection::create(
            ownerId: $this->ownerId,
            name: CollectionName::fromString('Old Name'),
            theme: Theme::fromString('books'),
            description: 'Old description',
            image: 'old.jpg'
        );
        // Manually set ID for test (normally done by constructor)
        // Using reflection to set private property
        $reflection = new \ReflectionObject($collection);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($collection, $collectionId->toBytes());

        $dto = new UpdateCollectionDTO('New Name', 'New description', 'new.jpg');

        $this->collectionRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(static function (Collection $updated): bool {
                return 'New Name' === $updated->getName()->value()
                    && 'New description' === $updated->getDescription()
                    && 'new.jpg' === $updated->getImage()
                    && 'books' === $updated->getTheme()->value(); // unchanged
            }));

        $this->unitOfWork->expects($this->once())->method('flush');

        $updated = $this->service->update($dto, $collection);

        $this->assertSame('New Name', $updated->getName()->value());
        $this->assertSame('New description', $updated->getDescription());
        $this->assertSame('new.jpg', $updated->getImage());
        $this->assertSame('books', $updated->getTheme()->value()); // unchanged
    }

    public function testGetByIdThrowsWhenNotFound(): void
    {
        $id = CollectionId::generate()->toString();

        $this->collectionRepository
            ->expects($this->once())
            ->method('findById')
            ->with($this->callback(function (CollectionId $foundId) use ($id): bool {
                return $foundId->toString() === $id;
            }))
            ->willReturn(null);

        $this->expectException(CollectionNotFoundException::class);
        $this->expectExceptionMessage(\sprintf('Collection with id "%s" not found', $id));

        $this->service->getById($id);
    }

    public function testListByOwnerIdReturnsCollections(): void
    {
        $ownerId = OwnerId::generate();
        $collection = Collection::create(
            ownerId: $ownerId,
            name: CollectionName::fromString('Other Collection'),
            theme: Theme::fromString('movies'),
        );

        $this->collectionRepository
            ->expects($this->once())
            ->method('findByOwnerId')
            ->with($this->identicalTo($ownerId), 50, 0)
            ->willReturn([$collection]);

        $result = $this->service->listByOwnerId($ownerId);

        $this->assertCount(1, $result);
        $this->assertSame('Other Collection', $result[0]->getName()->value());
    }

    public function testListAllReturnsCollections(): void
    {
        $collection1 = Collection::create(
            ownerId: $this->ownerId,
            name: CollectionName::fromString('Collection 1'),
            theme: Theme::fromString('books')
        );
        $collection2 = Collection::create(
            ownerId: $this->ownerId,
            name: CollectionName::fromString('Collection 2'),
            theme: Theme::fromString('games')
        );

        $this->collectionRepository
            ->expects($this->once())
            ->method('findAll')
            ->with(50, 0)
            ->willReturn([$collection1, $collection2]);

        $result = $this->service->listAll();

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
    }

    public function testDeleteRemovesCollection(): void
    {
        $collection = Collection::create(
            ownerId: $this->ownerId,
            name: CollectionName::fromString('To Delete'),
            theme: Theme::fromString('books')
        );

        $this->collectionRepository
            ->expects($this->once())
            ->method('remove')
            ->with($this->identicalTo($collection));

        $this->unitOfWork->expects($this->once())->method('flush');

        $this->service->delete($collection);
        // No return value to assert, just verifying no exception thrown
    }

    public function testToDTOConvertsCollection(): void
    {
        $collection = Collection::create(
            ownerId: $this->ownerId,
            name: CollectionName::fromString('Test Collection'),
            theme: Theme::fromString('books'),
            description: 'Test description',
            image: 'test.jpg'
        );

        $dto = $this->service->toDTO($collection);

        $this->assertSame($collection->getId()->toString(), $dto->id->toString());
        $this->assertSame($collection->getName()->value(), $dto->name);
        $this->assertSame($collection->getTheme()->value(), $dto->theme);
        $this->assertSame($collection->getDescription(), $dto->description);
        $this->assertSame($collection->getImage(), $dto->image);
        $this->assertTrue($dto->ownerId->equals($collection->getOwnerId()));
        $this->assertSame($collection->getCreatedAt(), $dto->createdAt);
        $this->assertSame($collection->getUpdatedAt(), $dto->updatedAt);
    }

    public function testToDTOListConvertsCollectionArray(): void
    {
        $collection1 = Collection::create(
            ownerId: $this->ownerId,
            name: CollectionName::fromString('Collection 1'),
            theme: Theme::fromString('books')
        );
        $collection2 = Collection::create(
            ownerId: $this->ownerId,
            name: CollectionName::fromString('Collection 2'),
            theme: Theme::fromString('games')
        );

        $dtos = $this->service->toDTOList([$collection1, $collection2]);

        $this->assertIsArray($dtos);
        $this->assertCount(2, $dtos);
        $this->assertSame('Collection 1', $dtos[0]->name);
        $this->assertSame('Collection 2', $dtos[1]->name);
    }
}
