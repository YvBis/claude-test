<?php

declare(strict_types=1);

namespace App\Infrastructure\Tag\Repository;

use App\Domain\Tag\Entity\Tag;
use App\Domain\Tag\Repository\TagRepositoryInterface;
use App\Domain\Tag\ValueObject\TagId;
use App\Domain\Tag\ValueObject\TagName;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tag>
 */
final class DoctrineTagRepository extends ServiceEntityRepository implements TagRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tag::class);
    }

    #[\Override]
    public function save(Tag $tag): void
    {
        $this->getEntityManager()->persist($tag);
    }

    #[\Override]
    public function remove(Tag $tag): void
    {
        $this->getEntityManager()->remove($tag);
    }

    #[\Override]
    public function findById(TagId $id): ?Tag
    {
        return $this->find($id->toBytes());
    }

    #[\Override]
    public function findByName(TagName $name): ?Tag
    {
        return $this->createQueryBuilder('t')
            ->where('t.name.value = :name')
            ->setParameter('name', $name->value())
            ->getQuery()
            ->getOneOrNullResult();
    }

    #[\Override]
    public function getOrCreate(TagName $name): Tag
    {
        $existing = $this->findByName($name);
        if ($existing instanceof Tag) {
            return $existing;
        }

        $tag = Tag::create($name);

        // Atomic upsert: under a race the losing INSERT becomes a no-op and the
        // already persisted row wins. InnoDB holds the unique-name lock until
        // the concurrent transaction commits, so the follow-up SELECT below
        // always reads the canonical row. No exceptions to juggle here.
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO tags (id, name, created_at, updated_at)
             VALUES (:id, :name, :createdAt, :updatedAt)
             ON DUPLICATE KEY UPDATE id = id',
            [
                'id' => $tag->getId()->toBytes(),
                'name' => $tag->getName()->value(),
                'createdAt' => $tag->getCreatedAt()->format('Y-m-d H:i:s.u'),
                'updatedAt' => $tag->getUpdatedAt()->format('Y-m-d H:i:s.u'),
            ],
            [
                'id' => ParameterType::BINARY,
                'name' => ParameterType::STRING,
                'createdAt' => ParameterType::STRING,
                'updatedAt' => ParameterType::STRING,
            ],
        );

        return $this->findByName($name)
            ?? throw new \RuntimeException(\sprintf('Tag "%s" not found after upsert', $name->value()));
    }

    #[\Override]
    public function search(?string $term, int $limit = 50, int $offset = 0): array
    {
        $qb = $this->createQueryBuilder('t')
            ->orderBy('t.name.value', \SortDirection::Ascending);

        if (null !== $term) {
            $qb->andWhere('t.name.value LIKE :term')
                ->setParameter('term', '%'.\addcslashes($term, '%_\\').'%');
        }

        return $qb
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }
}
