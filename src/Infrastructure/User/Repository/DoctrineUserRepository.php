<?php

declare(strict_types=1);

namespace App\Infrastructure\User\Repository;

use App\Domain\User\Entity\User;
use App\Domain\User\Repository\UserRepositoryInterface;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\UserId;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
final class DoctrineUserRepository extends ServiceEntityRepository implements UserRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    #[\Override]
    public function save(User $user): void
    {
        $this->getEntityManager()->persist($user);
    }

    #[\Override]
    public function remove(User $user): void
    {
        $this->getEntityManager()->remove($user);
    }

    #[\Override]
    public function findById(UserId $id): ?User
    {
        return $this->find($id->toBytes());
    }

    #[\Override]
    public function findByEmail(Email $email): ?User
    {
        return $this->createQueryBuilder('u')
            ->where('u.email.email = :email')
            ->setParameter('email', $email->value())
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return array<User> */
    #[\Override]
    public function findAll(int $limit = 50, int $offset = 0): array
    {
        return $this->createQueryBuilder('u')
            ->orderBy('u.createdAt', \SortDirection::Descending)
            ->addOrderBy('u.id', \SortDirection::Descending)
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    /**
     * fwd-5: a projection, not entities — like and comment listings only need the
     * display name, so selecting id and name avoids hydrating a `User` per row and
     * keeps a page of social content to one statement.
     *
     * The `id` comes back as raw bytes over PDO, so the keys are normalised to
     * the textual form the callers hold. Ids with no matching user are simply
     * absent from the map.
     *
     * @param array<UserId> $userIds
     *
     * @return array<string, string>
     */
    #[\Override]
    public function findNamesByIds(array $userIds): array
    {
        if ([] === $userIds) {
            return [];
        }

        $bytes = \array_map(static fn (UserId $userId): string => $userId->toBytes(), $userIds);

        /** @var array<int, array{id: string, name: string}> $rows */
        $rows = $this->createQueryBuilder('u')
            ->select('u.id AS id, u.name AS name')
            ->where('u.id IN (:ids)')
            ->setParameter('ids', $bytes, ArrayParameterType::BINARY)
            ->getQuery()
            ->getArrayResult();

        $names = [];
        foreach ($rows as $row) {
            $names[UserId::fromBytes($row['id'])->toString()] = $row['name'];
        }

        return $names;
    }

    #[\Override]
    public function existsByEmail(Email $email): bool
    {
        $count = $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.email.email = :email')
            ->setParameter('email', $email->value())
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }
}
