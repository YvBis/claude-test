<?php

declare(strict_types=1);

namespace App\Infrastructure\Comment\Repository;

use App\Domain\Collection\ValueObject\OwnerId;
use App\Domain\Comment\Entity\Comment;
use App\Domain\Comment\Repository\CommentRepositoryInterface;
use App\Domain\Comment\ValueObject\CommentId;
use App\Domain\Item\ValueObject\ItemId;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Comment>
 */
final class DoctrineCommentRepository extends ServiceEntityRepository implements CommentRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Comment::class);
    }

    #[\Override]
    public function save(Comment $comment): void
    {
        $this->getEntityManager()->persist($comment);
    }

    #[\Override]
    public function remove(Comment $comment): void
    {
        $this->getEntityManager()->remove($comment);
    }

    #[\Override]
    public function findById(CommentId $id): ?Comment
    {
        return $this->withAll($this->createQueryBuilder('c'))
            ->where('c.id = :id')
            ->setParameter('id', $id->toBytes(), 'binary')
            ->getQuery()
            ->getOneOrNullResult();
    }

    #[\Override]
    public function findByItemId(ItemId $itemId, int $limit = 50, int $offset = 0): array
    {
        return $this->withAll($this->createQueryBuilder('c'))
            ->where('IDENTITY(c.item) = :itemId')
            ->setParameter('itemId', $itemId->toBytes(), 'binary')
            ->orderBy('c.createdAt', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    #[\Override]
    public function findByOwnerId(OwnerId $ownerId, int $limit = 50, int $offset = 0): array
    {
        return $this->withAll($this->createQueryBuilder('c'))
            ->where('IDENTITY(c.owner) = :ownerId')
            ->setParameter('ownerId', $ownerId->toBytes(), 'binary')
            ->orderBy('c.createdAt', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    #[\Override]
    public function countByItemId(ItemId $itemId): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('IDENTITY(c.item) = :itemId')
            ->setParameter('itemId', $itemId->toBytes(), 'binary')
            ->getQuery()
            ->getSingleScalarResult();
    }

    #[\Override]
    public function countByOwnerId(OwnerId $ownerId): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('IDENTITY(c.owner) = :ownerId')
            ->setParameter('ownerId', $ownerId->toBytes(), 'binary')
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
            ->innerJoin('c.owner', 'owner')
            ->innerJoin('c.item', 'item')
            ->innerJoin('item.collection', 'collection')
            ->innerJoin('collection.owner', 'collectionOwner')
            ->addSelect('owner', 'item', 'collection', 'collectionOwner');
    }
}
