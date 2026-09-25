<?php

declare(strict_types=1);

namespace App\Infrastructure\Collection\Repository;

use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\Entity\CollectionField;
use App\Domain\Collection\Repository\CollectionFieldRepositoryInterface;
use App\Domain\Collection\ValueObject\CollectionFieldId;
use App\Domain\Collection\ValueObject\CollectionId;
use App\Domain\Collection\ValueObject\FieldType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CollectionField>
 */
final class DoctrineCollectionFieldRepository extends ServiceEntityRepository implements CollectionFieldRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CollectionField::class);
    }

    #[\Override]
    public function save(CollectionField $field): void
    {
        $this->getEntityManager()->persist($field);
    }

    #[\Override]
    public function remove(CollectionField $field): void
    {
        $this->getEntityManager()->remove($field);
    }

    #[\Override]
    public function findById(CollectionFieldId $id): ?CollectionField
    {
        return $this->createQueryBuilder('f')
            ->innerJoin('f.collection', 'collection')
            ->addSelect('collection')
            ->innerJoin('collection.owner', 'owner')
            ->addSelect('owner')
            ->where('f.id = :id')
            ->setParameter('id', $id->toBytes(), 'binary')
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return array<CollectionField> */
    #[\Override]
    public function findByCollection(Collection $collection): array
    {
        return $this->createQueryBuilder('f')
            ->innerJoin('f.collection', 'collection')
            ->addSelect('collection')
            ->innerJoin('collection.owner', 'owner')
            ->addSelect('owner')
            ->where('f.collection = :collection')
            ->setParameter('collection', $collection)
            ->orderBy('f.slotIndex', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    #[\Override]
    public function findByCollectionAndTypeAndSlot(CollectionId $collectionId, FieldType $type, int $slotIndex): ?CollectionField
    {
        return $this->createQueryBuilder('f')
            ->innerJoin('f.collection', 'collection')
            ->addSelect('collection')
            ->innerJoin('collection.owner', 'owner')
            ->addSelect('owner')
            ->where('f.collection = :collectionId')
            ->andWhere('f.type.type = :type')
            ->andWhere('f.slotIndex = :slotIndex')
            ->setParameter('collectionId', $collectionId->toBytes(), 'binary')
            ->setParameter('type', $type->value())
            ->setParameter('slotIndex', $slotIndex)
            ->getQuery()
            ->getOneOrNullResult();
    }

    #[\Override]
    public function nextSlotIndexFor(Collection $collection): int
    {
        $maxSlot = (int) $this->createQueryBuilder('f')
            ->select('MAX(f.slotIndex)')
            ->where('f.collection = :collection')
            ->setParameter('collection', $collection)
            ->getQuery()
            ->getSingleScalarResult();

        return $maxSlot + 1;
    }

    #[\Override]
    public function countByCollection(Collection $collection): int
    {
        $result = $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->where('f.collection = :collection')
            ->setParameter('collection', $collection)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $result;
    }
}
