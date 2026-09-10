<?php

declare(strict_types=1);

namespace App\Application\Collection\Service;

use App\Application\Collection\DTO\CollectionDTO;
use App\Application\Collection\DTO\CreateCollectionDTO;
use App\Application\Collection\DTO\UpdateCollectionDTO;
use App\Application\Common\Transaction\UnitOfWorkInterface;
use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\Exception\CollectionNotFoundException;
use App\Domain\Collection\Repository\CollectionRepositoryInterface;
use App\Domain\Collection\ValueObject\CollectionId;
use App\Domain\Collection\ValueObject\CollectionName;
use App\Domain\Collection\ValueObject\Theme;
use App\Domain\User\Entity\User;
use App\Domain\User\ValueObject\UserId;

final readonly class CollectionService
{
    public function __construct(
        private CollectionRepositoryInterface $collectionRepository,
        private UnitOfWorkInterface $unitOfWork,
    ) {
    }

    public function create(CreateCollectionDTO $dto, User $owner): Collection
    {
        $collection = Collection::create(
            owner: $owner,
            name: CollectionName::fromString($dto->name),
            theme: Theme::fromString($dto->theme),
            description: $dto->description,
            image: $dto->image,
        );

        $this->collectionRepository->save($collection);
        $this->unitOfWork->flush();

        return $collection;
    }

    public function update(UpdateCollectionDTO $dto, Collection $collection): Collection
    {
        if (null !== $dto->name) {
            $collection->changeName(CollectionName::fromString($dto->name));
        }

        if (null !== $dto->description) {
            $collection->changeDescription($dto->description);
        }

        if (null !== $dto->image) {
            $collection->changeImage($dto->image);
        }

        $this->collectionRepository->save($collection);
        $this->unitOfWork->flush();

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
    public function listByOwner(User $owner, int $limit = 50, int $offset = 0): array
    {
        return $this->collectionRepository->findByOwner($owner, $limit, $offset);
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
    public function listByOwnerId(UserId $ownerId, int $limit = 50, int $offset = 0): array
    {
        return $this->collectionRepository->findByOwnerId($ownerId, $limit, $offset);
    }

    public function delete(Collection $collection): void
    {
        $this->collectionRepository->remove($collection);
        $this->unitOfWork->flush();
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
            fn (Collection $collection): CollectionDTO => $this->toDTO($collection),
            $collections,
        );
    }
}
