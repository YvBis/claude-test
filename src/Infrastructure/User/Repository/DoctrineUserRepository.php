<?php

declare(strict_types=1);

namespace App\Infrastructure\User\Repository;

use App\Domain\User\Entity\User;
use App\Domain\User\Repository\UserRepositoryInterface;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\UserId;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
    public function findAll(): array
    {
        return $this->createQueryBuilder('u')
            ->orderBy('u.createdAt', \SortDirection::Descending)
            ->getQuery()
            ->getResult();
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
