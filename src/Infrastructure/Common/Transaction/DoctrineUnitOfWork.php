<?php

declare(strict_types=1);

namespace App\Infrastructure\Common\Transaction;

use App\Application\Common\Transaction\UnitOfWorkInterface;
use App\Application\Exception\ConflictException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineUnitOfWork implements UnitOfWorkInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
    }

    #[\Override]
    public function flush(): void
    {
        $this->translateUniqueViolation(fn () => $this->entityManager->flush());
    }

    #[\Override]
    public function transactional(callable $callback): mixed
    {
        return $this->translateUniqueViolation(fn (): mixed => $this->entityManager->wrapInTransaction($callback));
    }

    #[\Override]
    public function clear(): void
    {
        $this->entityManager->clear();
    }

    private function translateUniqueViolation(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (UniqueConstraintViolationException $uniqueConstraintViolationException) {
            throw new ConflictException('Duplicate entry', 0, $uniqueConstraintViolationException);
        }
    }
}
