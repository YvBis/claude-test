<?php

declare(strict_types=1);

namespace App\Tests\Application\Collection\Service;

use App\Application\Collection\DTO\CreateCollectionDTO;
use App\Application\Collection\DTO\UpdateCollectionDTO;
use App\Application\Collection\Service\CollectionService;
use App\Application\Common\Transaction\UnitOfWorkInterface;
use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\Exception\CollectionNotFoundException;
use App\Domain\Collection\Repository\CollectionRepositoryInterface;
use App\Domain\Collection\ValueObject\CollectionId;
use App\Domain\Collection\ValueObject\CollectionName;
use App\Domain\Collection\ValueObject\OwnerId;
use App\Domain\Collection\ValueObject\Theme;
use App\Domain\User\Entity\User;
use PHPUnit\Framework\TestCase;

final class CollectionServiceTest extends TestCase
{
    private CollectionRepositoryInterface $collectionRepository;
    private UnitOfWorkInterface $unitOfWork;
    private CollectionService $service;
    private User $owner;

    protected function setUp(): void
    {
        $this->collectionRepository = $this->createMock(CollectionRepositoryInterface::class);
        $this->unitOfWork = $this->createMock(UnitOfWorkInterface::class);
        $this->service = new CollectionService($this->collectionRepository, $this->unitOfWork);
        $this->owner = User::register('Test User', \App\Domain\User\ValueObject\Email::fromString('test@example.com'), \App\Domain\User\ValueObject\PasswordHash::createFromPlain('password123'));
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
                    && $collection->getOwner()->getId()->toString() === $this->owner->getId()->toString();
            }));

        $this->unitOfWork->expects($this->once())->method('flush');

        $collection = $this->service->create($dto, $this->owner);

        $this->assertInstanceOf(Collection::class, $collection);
        $this->assertSame('My Collection', $collection->getName()->value());
        $this->assertSame('books', $collection->getTheme()->value());
        $this->assertSame('A test collection', $collection->getDescription());
        $this->assertSame('image.jpg', $collection->getImage());
        $this->assertSame($this->owner->getId()->toString(), $collection->getOwner()->getId()->toString());
    }

    public function testUpdateModifiesCollection(): void
    {
        $collectionId = CollectionId::generate();
        $collection = Collection::create(
            owner: $this->owner,
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
        $otherOwner = User::register('Other User', \App\Domain\User\ValueObject\Email::fromString('other@example.com'), \App\Domain\User\ValueObject\PasswordHash::createFromPlain('password123'));
        $ownerId = OwnerId::fromBytes($otherOwner->getId()->toBytes());
        $collection = Collection::create(
            owner: $otherOwner,
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
            owner: $this->owner,
            name: CollectionName::fromString('Collection 1'),
            theme: Theme::fromString('books')
        );
        $collection2 = Collection::create(
            owner: $this->owner,
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
            owner: $this->owner,
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
            owner: $this->owner,
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
        $this->assertSame($collection->getOwner()->getId()->toString(), $dto->ownerId->toString());
        $this->assertSame($collection->getCreatedAt(), $dto->createdAt);
        $this->assertSame($collection->getUpdatedAt(), $dto->updatedAt);
    }

    public function testToDTOListConvertsCollectionArray(): void
    {
        $collection1 = Collection::create(
            owner: $this->owner,
            name: CollectionName::fromString('Collection 1'),
            theme: Theme::fromString('books')
        );
        $collection2 = Collection::create(
            owner: $this->owner,
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
