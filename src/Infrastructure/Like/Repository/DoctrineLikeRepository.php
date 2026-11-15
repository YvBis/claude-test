<?php

declare(strict_types=1);

namespace App\Infrastructure\Like\Repository;

use App\Domain\Common\ValueObject\OwnerId;
use App\Domain\Item\Entity\Item;
use App\Domain\Item\ValueObject\ItemId;
use App\Domain\Like\Entity\Like;
use App\Domain\Like\Repository\LikeRepositoryInterface;
use App\Domain\Like\ValueObject\LikeId;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
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
    public function likeOrGet(OwnerId $ownerId, Item $item): Like
    {
        $connection = $this->getEntityManager()->getConnection();

        if ($connection->isTransactionActive()) {
            throw new \LogicException('likeOrGet must not run inside a transaction: its re-select needs the winning row committed (PRD/10.3a).');
        }

        $like = Like::create($ownerId, $item);
        $this->upsert($like, $ownerId, $item);

        $found = $this->findByOwnerAndItem($ownerId, $item->getId());
        if ($found instanceof Like) {
            return $found;
        }

        $this->upsert($like, $ownerId, $item);

        return $this->findByOwnerAndItem($ownerId, $item->getId())
            ?? throw new \RuntimeException(\sprintf('Like for item "%s" not found after upsert', $item->getId()->toString()));
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
            ->where('l.ownerId = :ownerId')
            ->andWhere('IDENTITY(l.item) = :itemId')
            ->setParameter('ownerId', $ownerId->toBytes(), 'binary')
            ->setParameter('itemId', $itemId->toBytes(), 'binary')
            ->getQuery()
            ->getOneOrNullResult();
    }

    #[\Override]
    public function findAll(int $limit = 50, int $offset = 0): array
    {
        return $this->withAll($this->createQueryBuilder('l'))
            ->orderBy('l.createdAt', \SortDirection::Descending)
            ->addOrderBy('l.id', \SortDirection::Descending)
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    #[\Override]
    public function findByItemId(ItemId $itemId, int $limit = 50, int $offset = 0): array
    {
        return $this->withAll($this->createQueryBuilder('l'))
            ->where('IDENTITY(l.item) = :itemId')
            ->setParameter('itemId', $itemId->toBytes(), 'binary')
            ->orderBy('l.createdAt', \SortDirection::Ascending)
            ->addOrderBy('l.id', \SortDirection::Ascending)
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    #[\Override]
    public function findByOwnerId(OwnerId $ownerId, int $limit = 50, int $offset = 0): array
    {
        return $this->withAll($this->createQueryBuilder('l'))
            ->where('l.ownerId = :ownerId')
            ->setParameter('ownerId', $ownerId->toBytes(), 'binary')
            ->orderBy('l.createdAt', \SortDirection::Ascending)
            ->addOrderBy('l.id', \SortDirection::Ascending)
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
     * Atomic upsert: the losing INSERT becomes a no-op and InnoDB holds the
     * unique-pair lock until the winner commits, so a following SELECT reads the
     * canonical row. `$like` is a transient value object used only for its id and
     * timestamp — it must never be persisted, or the next flush would add a
     * second INSERT for the same pair.
     */
    private function upsert(Like $like, OwnerId $ownerId, Item $item): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO likes (id, created_at, owner_id, item_id)
             VALUES (:id, :createdAt, :ownerId, :itemId)
             ON DUPLICATE KEY UPDATE id = id',
            [
                'id' => $like->getId()->toBytes(),
                'createdAt' => $like->getCreatedAt()->format('Y-m-d H:i:s.u'),
                'ownerId' => $ownerId->toBytes(),
                'itemId' => $item->getId()->toBytes(),
            ],
            [
                'id' => ParameterType::BINARY,
                'createdAt' => ParameterType::STRING,
                'ownerId' => ParameterType::BINARY,
                'itemId' => ParameterType::BINARY,
            ],
        );
    }

    /**
     * Hydrates the item's chain inline (final classes are not proxiable in ORM 3,
     * so every read joins the chain to avoid ghost proxies). The collection's
     * owner is not part of it: since fwd-5 that is an `owner_id` column rather
     * than an association, and nothing on this read path needs a `User`.
     */
    private function withAll(QueryBuilder $qb): QueryBuilder
    {
        return $qb
            ->innerJoin('l.item', 'item')
            ->innerJoin('item.collection', 'collection')
            ->addSelect('item', 'collection');
    }
}
