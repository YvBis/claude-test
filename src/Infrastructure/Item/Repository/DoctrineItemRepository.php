<?php

declare(strict_types=1);

namespace App\Infrastructure\Item\Repository;

use App\Domain\Collection\ValueObject\CollectionId;
use App\Domain\Collection\ValueObject\OwnerId;
use App\Domain\Item\Entity\Item;
use App\Domain\Item\Repository\ItemRepositoryInterface;
use App\Domain\Item\ValueObject\ItemId;
use App\Domain\Tag\Entity\Tag;
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
    public function findByCollectionId(CollectionId $collectionId, int $limit = 50, int $offset = 0, ?string $name = null, array $tagNames = []): array
    {
        $queryBuilder = $this->withCollectionAndOwner($this->createQueryBuilder('i'))
            // IDENTITY avoids loading the related entity just to compare its id;
            // the binary UUID compares directly against the FK column.
            ->where('IDENTITY(i.collection) = :collectionId')
            ->setParameter('collectionId', $collectionId->toBytes(), 'binary');
        $this->applyFilters($queryBuilder, $name, $tagNames);

        return $queryBuilder
            ->orderBy('i.createdAt', \SortDirection::Ascending)
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    #[\Override]
    public function findByOwnerId(OwnerId $ownerId, int $limit = 50, int $offset = 0, ?string $name = null, array $tagNames = []): array
    {
        $queryBuilder = $this->withCollectionAndOwner($this->createQueryBuilder('i'))
            ->where('IDENTITY(collection.owner) = :ownerId')
            ->setParameter('ownerId', $ownerId->toBytes(), 'binary');
        $this->applyFilters($queryBuilder, $name, $tagNames);

        return $queryBuilder
            ->orderBy('i.createdAt', \SortDirection::Ascending)
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

    /**
     * @param array<string> $tagNames
     */
    private function applyFilters(QueryBuilder $qb, ?string $name, array $tagNames): void
    {
        if (null !== $name && '' !== $name) {
            $qb->andWhere('i.name LIKE :name')
                // % and _ are escaped so user input is matched literally;
                // MySQL LIKE treats backslash as the default escape character.
                ->setParameter('name', '%'.\addcslashes($name, '%_\\').'%');
        }

        // AND semantics: one correlated EXISTS per tag. Kept as separate
        // subqueries (instead of JOIN + GROUP BY/HAVING) so the outer query
        // can still use setMaxResults/setFirstResult without row inflation.
        foreach (\array_values(\array_unique($tagNames)) as $index => $tagName) {
            $alias = 'tag'.$index;
            $parameter = 'tagName'.$index;
            $qb->andWhere(\sprintf(
                'EXISTS (SELECT %1$s.id FROM %2$s %1$s WHERE %1$s MEMBER OF i.tags AND %1$s.name.value = :%3$s)',
                $alias,
                Tag::class,
                $parameter,
            ))
                ->setParameter($parameter, $tagName);
        }
    }
}
