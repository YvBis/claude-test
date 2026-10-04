<?php

declare(strict_types=1);

namespace App\Infrastructure\Collection\Repository;

use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\Repository\CollectionRepositoryInterface;
use App\Domain\Collection\ValueObject\CollectionId;
use App\Domain\Common\ValueObject\OwnerId;
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
        return $this->createQueryBuilder('c')
            ->where('c.id = :id')
            ->setParameter('id', $id->toBytes(), 'binary')
            ->getQuery()
            ->getOneOrNullResult();
    }

    #[\Override]
    public function findByOwnerId(OwnerId $ownerId, int $limit = 50, int $offset = 0): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.ownerId = :ownerId')
            ->setParameter('ownerId', $ownerId->toBytes(), 'binary')
            ->orderBy('c.createdAt', \SortDirection::Descending)
            // fwd-27: tie-breaker direction follows the primary sort (DESC), so
            // the ASC composite index serves the whole order on backward scan.
            ->addOrderBy('c.id', \SortDirection::Descending)
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    #[\Override]
    public function findAll(int $limit = 50, int $offset = 0): array
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.createdAt', \SortDirection::Descending)
            // fwd-27: same direction-following tie-breaker for determinism.
            // No owner filter, so idx_collection_owner_list cannot serve the
            // sort — filesort stays on this admin path, accepted.
            ->addOrderBy('c.id', \SortDirection::Descending)
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }
}
