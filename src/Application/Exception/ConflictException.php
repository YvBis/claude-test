<?php

declare(strict_types=1);

namespace App\Application\Exception;

/**
 * Raised when a write collides with a uniqueness rule in persistence.
 *
 * The unit of work translates DBAL unique-constraint violations into this
 * exception, so Application code can map a duplicate to its own domain error
 * without importing DBAL. Translation alone does not choose an HTTP status:
 * today only registration maps this to a 409 (`UserAlreadyExistsException`), so
 * any other colliding write still answers 500 until it gets its own mapping.
 * Reach and the constraint inventory live in ARCHITECTURE.md («Трансляция
 * уникальности»).
 */
final class ConflictException extends \RuntimeException
{
}
