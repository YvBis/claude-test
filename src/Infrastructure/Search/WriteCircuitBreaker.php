<?php

declare(strict_types=1);

namespace App\Infrastructure\Search;

use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

/**
 * Circuit breaker for search writes (task 6.7 / review-10).
 *
 * Fail-open alone does not bound the damage of a dead engine: every write waits
 * the full transport timeout, so renaming or deleting a collection with N items
 * costs N×timeout inside a single request while still answering 200. After the
 * first failure further writes are skipped for a TTL, keeping the fail-open
 * semantics the caller depends on.
 *
 * The state is per-process and in-memory on purpose: it is a backpressure
 * guard, not a distributed lock, and cluttering Redis for it would add a second
 * failure mode.
 */
final class WriteCircuitBreaker
{
    private ?\DateTimeImmutable $openUntil = null;

    public function __construct(
        private readonly int $ttlSeconds,
        private readonly ?ClockInterface $clock = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        if ($ttlSeconds < 1) {
            throw new \InvalidArgumentException('Breaker TTL must be at least 1 second.');
        }
    }

    public function isOpen(): bool
    {
        if (!$this->openUntil instanceof \DateTimeImmutable) {
            return false;
        }

        if ($this->now() >= $this->openUntil->getTimestamp()) {
            $this->openUntil = null;

            return false;
        }

        return true;
    }

    public function recordFailure(): void
    {
        $this->openUntil = (new \DateTimeImmutable())->setTimestamp(
            $this->now() + $this->ttlSeconds,
        );

        $this->logger?->debug('Search write circuit opened', [
            'until' => $this->openUntil->format(\DateTimeInterface::ATOM),
        ]);
    }

    public function logSkipped(): void
    {
        $this->logger?->debug('Search index write skipped: circuit open', [
            'until' => $this->openUntil?->format(\DateTimeInterface::ATOM),
        ]);
    }

    private function now(): int
    {
        return ($this->clock ?? new NativeClock())->now()->getTimestamp();
    }
}
