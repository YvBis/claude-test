<?php

declare(strict_types=1);

namespace App\Infrastructure\Common\Transaction;

use App\Application\Common\Transaction\UnitOfWorkInterface;
use App\Application\Exception\ConflictException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
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
        $this->translateConcurrencyViolation(fn () => $this->entityManager->flush());
    }

    #[\Override]
    public function transactional(callable $callback): mixed
    {
        return $this->translateConcurrencyViolation(fn (): mixed => $this->entityManager->wrapInTransaction($callback));
    }

    #[\Override]
    public function clear(): void
    {
        $this->entityManager->clear();
    }

    private function translateConcurrencyViolation(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (UniqueConstraintViolationException $uniqueConstraintViolationException) {
            throw new ConflictException('Duplicate entry', 0, $uniqueConstraintViolationException);
        } catch (LockWaitTimeoutException $lockWaitTimeoutException) {
            throw new ConflictException('Concurrent modification, retry', 0, $lockWaitTimeoutException);
        } catch (DeadlockException $deadlockException) {
            throw new ConflictException('Concurrent modification, retry', 0, $deadlockException);
        }
    }
}
