<?php

declare(strict_types=1);

namespace App\Infrastructure\Collection\Repository;

use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\Repository\CollectionRepositoryInterface;
use App\Domain\Collection\ValueObject\CollectionId;
use App\Domain\User\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Collection>
 */
final class DoctrineCollectionRepository extends ServiceEntityRepository implements CollectionRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Collection::class);
    }

    #[\Override]
    public function save(Collection $collection): void
    {
        $this->getEntityManager()->persist($collection);
    }

    #[\Override]
    public function remove(Collection $collection): void
    {
        $this->getEntityManager()->remove($collection);
    }

    #[\Override]
    public function findById(CollectionId $id): ?Collection
    {
        return $this->find($id->toBytes());
    }

    #[\Override]
    public function findByOwner(User $owner, int $limit = 50, int $offset = 0): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    #[\Override]
    public function findAll(int $limit = 50, int $offset = 0): array
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }
}
