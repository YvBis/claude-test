<?php

declare(strict_types=1);

namespace App\Infrastructure\Item\Repository;

use App\Domain\Collection\ValueObject\CollectionId;
use App\Domain\Item\Entity\Item;
use App\Domain\Item\Repository\ItemRepositoryInterface;
use App\Domain\Item\ValueObject\ItemId;
use App\Domain\User\ValueObject\UserId;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Item>
 */
final class DoctrineItemRepository extends ServiceEntityRepository implements ItemRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Item::class);
    }

    #[\Override]
    public function save(Item $item): void
    {
        $this->getEntityManager()->persist($item);
    }

    #[\Override]
    public function remove(Item $item): void
    {
        $this->getEntityManager()->remove($item);
    }

    #[\Override]
    public function findById(ItemId $id): ?Item
    {
        return $this->withCollectionAndOwner($this->createQueryBuilder('i'))
            ->where('i.id = :id')
            ->setParameter('id', $id->toBytes(), 'binary')
            ->getQuery()
            ->getOneOrNullResult();
    }

    #[\Override]
    public function findByCollectionId(CollectionId $collectionId, int $limit = 50, int $offset = 0): array
    {
        return $this->withCollectionAndOwner($this->createQueryBuilder('i'))
            // IDENTITY avoids loading the related entity just to compare its id;
            // the binary UUID compares directly against the FK column.
            ->where('IDENTITY(i.collection) = :collectionId')
            ->setParameter('collectionId', $collectionId->toBytes(), 'binary')
            ->orderBy('i.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    #[\Override]
    public function findByOwnerId(UserId $ownerId, int $limit = 50, int $offset = 0): array
    {
        return $this->withCollectionAndOwner($this->createQueryBuilder('i'))
            ->where('IDENTITY(collection.owner) = :ownerId')
            ->setParameter('ownerId', $ownerId->toBytes(), 'binary')
            ->orderBy('i.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    private function withCollectionAndOwner(QueryBuilder $qb): QueryBuilder
    {
        return $qb
            ->innerJoin('i.collection', 'collection')
            ->innerJoin('collection.owner', 'owner')
            ->addSelect('collection', 'owner');
    }
}
