<?php

declare(strict_types=1);

namespace App\Application\Common\Transaction;

/**
 * Application-level Unit of Work boundary.
 *
 * Repositories schedule entities for persistence; callers decide when
 * changes are written to the database by invoking flush().
 */
interface UnitOfWorkInterface
{
    public function flush(): void;
}
