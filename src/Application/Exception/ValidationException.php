<?php

declare(strict_types=1);

namespace App\Application\Exception;

/**
 * Exception thrown when DTO validation fails.
 * Contains structured validation errors for API responses.
 */
final class ValidationException extends \RuntimeException
{
    /** @var string[] */
    private array $details;

    /**
     * @param string[] $details
     */
    public function __construct(string $message, array $details)
    {
        parent::__construct($message);
        $this->details = $details;
    }

    /**
     * @return string[]
     */
    public function getDetails(): array
    {
        return $this->details;
    }

    /**
     * @return string[]
     *
     * @deprecated Use getDetails() instead
     */
    public function getErrors(): array
    {
        return $this->details;
    }
}
