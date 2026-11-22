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
    /**
     * Writes everything scheduled by the repositories.
     *
     * A uniqueness collision in persistence surfaces as
     * {@see \App\Application\Exception\ConflictException} carrying the DBAL
     * exception as its previous, and so does a lost lock race (lock-wait
     * timeout or deadlock) — "Concurrent modification, retry". Any other DBAL
     * failure is propagated unchanged. The EntityManager is closed after a
     * failed flush, so a caller cannot retry on the same instance — map the
     * conflict to a domain error and return.
     */
    public function flush(): void;

    /**
     * Runs the callback inside a database transaction. The transaction is
     * committed after the callback returns and rolled back on any throwable.
     * The callback's return value is passed through unchanged.
     *
     * The ORM flushes before committing, so the same translations as in
     * {@see self::flush()} apply here: a uniqueness collision becomes
     * {@see \App\Application\Exception\ConflictException} ("Duplicate entry"),
     * a lock-wait timeout or deadlock becomes one too ("Concurrent
     * modification, retry"). A commit-time failure that is neither is
     * propagated unchanged.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function transactional(callable $callback): mixed;

    /**
     * Detaches every managed entity. Bulk walks over large tables call it
     * between pages so memory stays flat; entities read before the call must
     * not be used after it (they become detached).
     */
    public function clear(): void;
}
