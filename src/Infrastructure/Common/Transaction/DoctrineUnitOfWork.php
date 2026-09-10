<?php

declare(strict_types=1);

namespace App\Infrastructure\Common\Transaction;

use App\Application\Common\Transaction\UnitOfWorkInterface;
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
        $this->entityManager->flush();
    }
}
