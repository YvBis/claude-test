<?php

declare(strict_types=1);

namespace App\Infrastructure\Like\Repository;

use App\Domain\Collection\ValueObject\OwnerId;
use App\Domain\Item\ValueObject\ItemId;
use App\Domain\Like\Entity\Like;
use App\Domain\Like\Repository\LikeRepositoryInterface;
use App\Domain\Like\ValueObject\LikeId;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Like>
 */
final class DoctrineLikeRepository extends ServiceEntityRepository implements LikeRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Like::class);
    }

    #[\Override]
    public function save(Like $like): void
    {
        $this->getEntityManager()->persist($like);
    }

    #[\Override]
    public function remove(Like $like): void
    {
        $this->getEntityManager()->remove($like);
    }

    #[\Override]
    public function findById(LikeId $id): ?Like
    {
        return $this->withAll($this->createQueryBuilder('l'))
            ->where('l.id = :id')
            ->setParameter('id', $id->toBytes(), 'binary')
            ->getQuery()
            ->getOneOrNullResult();
    }

    #[\Override]
    public function findByOwnerAndItem(OwnerId $ownerId, ItemId $itemId): ?Like
    {
        return $this->withAll($this->createQueryBuilder('l'))
            ->where('IDENTITY(l.owner) = :ownerId')
            ->andWhere('IDENTITY(l.item) = :itemId')
            ->setParameter('ownerId', $ownerId->toBytes(), 'binary')
            ->setParameter('itemId', $itemId->toBytes(), 'binary')
            ->getQuery()
            ->getOneOrNullResult();
    }

    #[\Override]
    public function findByItemId(ItemId $itemId, int $limit = 50, int $offset = 0): array
    {
        return $this->withAll($this->createQueryBuilder('l'))
            ->where('IDENTITY(l.item) = :itemId')
            ->setParameter('itemId', $itemId->toBytes(), 'binary')
            ->orderBy('l.createdAt', 'ASC')
            ->addOrderBy('l.id', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    #[\Override]
    public function findByOwnerId(OwnerId $ownerId, int $limit = 50, int $offset = 0): array
    {
        return $this->withAll($this->createQueryBuilder('l'))
            ->where('IDENTITY(l.owner) = :ownerId')
            ->setParameter('ownerId', $ownerId->toBytes(), 'binary')
            ->orderBy('l.createdAt', 'ASC')
            ->addOrderBy('l.id', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    #[\Override]
    public function countByItemId(ItemId $itemId): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('IDENTITY(l.item) = :itemId')
            ->setParameter('itemId', $itemId->toBytes(), 'binary')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Hydrates owner and the item's full chain inline (final classes are not
     * proxiable in ORM 3, so every read joins the chain to avoid ghost proxies).
     */
    private function withAll(QueryBuilder $qb): QueryBuilder
    {
        return $qb
            ->innerJoin('l.owner', 'owner')
            ->innerJoin('l.item', 'item')
            ->innerJoin('item.collection', 'collection')
            ->innerJoin('collection.owner', 'collectionOwner')
            ->addSelect('owner', 'item', 'collection', 'collectionOwner');
    }
}
