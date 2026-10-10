<?php

declare(strict_types=1);

namespace App\Infrastructure\Item\Repository;

use App\Domain\Collection\ValueObject\CollectionId;
use App\Domain\Common\ValueObject\OwnerId;
use App\Domain\Item\Entity\Item;
use App\Domain\Item\Repository\ItemRepositoryInterface;
use App\Domain\Item\ValueObject\ItemId;
use App\Domain\Tag\Entity\Tag;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
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
        $item = $this->withCollection($this->createQueryBuilder('i'))
            ->where('i.id = :id')
            ->setParameter('id', $id->toBytes(), 'binary')
            ->getQuery()
            ->getOneOrNullResult();

        if (null === $item) {
            return null;
        }

        $this->initializeTags([$item]);

        return $item;
    }

    #[\Override]
    public function findByCollectionId(CollectionId $collectionId, int $limit = 50, int $offset = 0, ?string $name = null, array $tagNames = []): array
    {
        $queryBuilder = $this->withCollection($this->createQueryBuilder('i'))
            // IDENTITY avoids loading the related entity just to compare its id;
            // the binary UUID compares directly against the FK column.
            ->where('IDENTITY(i.collection) = :collectionId')
            ->setParameter('collectionId', $collectionId->toBytes(), 'binary');
        $this->applyFilters($queryBuilder, $name, $tagNames);

        $items = $queryBuilder
            ->orderBy('i.createdAt', \SortDirection::Ascending)
            // fwd-27: id tie-breaker follows the primary direction so the
            // (collection_id, created_at, id) index serves both keys.
            ->addOrderBy('i.id', \SortDirection::Ascending)
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
        $this->initializeTags($items);

        return $items;
    }

    #[\Override]
    public function findByOwnerId(OwnerId $ownerId, int $limit = 50, int $offset = 0, ?string $name = null, array $tagNames = []): array
    {
        $queryBuilder = $this->withCollection($this->createQueryBuilder('i'))
            ->where('collection.ownerId = :ownerId')
            ->setParameter('ownerId', $ownerId->toBytes(), 'binary');
        $this->applyFilters($queryBuilder, $name, $tagNames);

        $items = $queryBuilder
            ->orderBy('i.createdAt', \SortDirection::Ascending)
            // fwd-27: same direction as the primary sort. No covering index exists
            // here — the filter reads the collections.owner_id column through the
            // collection alias that withCollection() joins for hydration, and nothing
            // joins for the owner (it is a column since fwd-5) — so this key buys
            // determinism, not index service. Filesort stays, accepted; it also does not
            // stop at the page — every item the owner owns is materialized before
            // the first 50 are taken, so the cost is linear in their total count.
            // Tracked as review-8, triggered by a measured cost over 50 ms; the
            // measurements and their limits live in the log, not here.
            ->addOrderBy('i.id', \SortDirection::Ascending)
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
        $this->initializeTags($items);

        return $items;
    }

    #[\Override]
    public function findAll(int $limit = 50, int $offset = 0): array
    {
        $items = $this->withCollection($this->createQueryBuilder('i'))
            ->orderBy('i.createdAt', \SortDirection::Ascending)
            // fwd-27 tie-breaker: without it equal createdAt values could
            // shuffle between pages and the reindex would skip rows.
            ->addOrderBy('i.id', \SortDirection::Ascending)
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
        $this->initializeTags($items);

        return $items;
    }

    #[\Override]
    public function findIdsByCollectionId(CollectionId $collectionId): array
    {
        /** @var list<array{id: string}> $rows */
        $rows = $this->createQueryBuilder('i')
            ->select('i.id')
            ->where('IDENTITY(i.collection) = :collectionId')
            ->setParameter('collectionId', $collectionId->toBytes(), 'binary')
            ->orderBy('i.createdAt', \SortDirection::Ascending)
            ->addOrderBy('i.id', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        return \array_map(
            static fn (array $row): ItemId => ItemId::fromBytes($row['id']),
            $rows,
        );
    }

    /**
     * @return array<ItemId>
     */
    #[\Override]
    public function findIdsByOwnerId(OwnerId $ownerId): array
    {
        /** @var list<array{id: string}> $rows */
        $rows = $this->createQueryBuilder('i')
            ->select('i.id')
            ->join('i.collection', 'c')
            ->where('c.ownerId = :ownerId')
            ->setParameter('ownerId', $ownerId->toBytes(), 'binary')
            ->orderBy('i.createdAt', \SortDirection::Ascending)
            ->addOrderBy('i.id', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        return \array_map(
            static fn (array $row): ItemId => ItemId::fromBytes($row['id']),
            $rows,
        );
    }

    /**
     * fwd-6: pre-initialize the tag collections of a page of items in one
     * extra query.
     *
     * The batch is a second statement rather than a fetch join on the listing
     * query, because joining a to-many collection inflates rows and breaks
     * setMaxResults/setFirstResult. It runs against the ids of the page just
     * fetched, so the entities come back from the identity map and Doctrine
     * initializes their existing collections in place (ObjectHydrator's
     * initRelatedCollection), leaving the order and contents of $items alone.
     *
     * LEFT JOIN, not INNER: an item without tags produces no row under INNER,
     * would stay uninitialized and lazy-load on the next getTags() — the N+1
     * would come back for exactly those items.
     *
     * The two statements are not wrapped in a transaction here, so a tag change
     * committed between them yields a snapshot taken across both. Accepted for
     * list reads; the same trade-off already applies to the listing query
     * itself.
     *
     * @param array<Item> $items
     */
    private function initializeTags(array $items): void
    {
        if ([] === $items) {
            return;
        }

        $ids = \array_map(static fn (Item $item): string => $item->getId()->toBytes(), $items);

        $this->createQueryBuilder('i')
            ->leftJoin('i.tags', 't')
            ->addSelect('t')
            ->where('i.id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::BINARY)
            ->getQuery()
            ->getResult();
    }

    private function withCollection(QueryBuilder $qb): QueryBuilder
    {
        return $qb
            ->innerJoin('i.collection', 'collection')
            ->addSelect('collection');
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
