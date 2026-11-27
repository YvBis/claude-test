<?php

declare(strict_types=1);

namespace App\Application\Search;

/**
 * Signals that an engine document does not satisfy the hit contract. Reads are
 * not fail-open (unlike writes), so this is deliberately uncaught: it surfaces
 * as a bare 500 through ApiExceptionSubscriber, the same path as a dead engine.
 */
final class InvalidSearchDocumentException extends \RuntimeException
{
    public static function forField(string $hitClass, string $field): self
    {
        return new self(\sprintf('%s received a document without a valid "%s" field', $hitClass, $field));
    }
}
