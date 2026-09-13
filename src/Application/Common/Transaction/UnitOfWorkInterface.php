<?php

declare(strict_types=1);

namespace App\Application\Common\Transaction;

/**
 * Application-level Unit of Work boundary.
 *
 * Repositories schedule entities for persistence; callers decide when
 * changes are written to the database by invoking flush(), or wrap an
 * entire operation in a transaction via transactional().
 */
interface UnitOfWorkInterface
{
    public function flush(): void;

    /**
     * Runs the callback inside a database transaction. The transaction is
     * committed after the callback returns and rolled back on any throwable.
     * The callback's return value is passed through unchanged.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function transactional(callable $callback): mixed;
}
