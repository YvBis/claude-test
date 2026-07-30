<?php

declare(strict_types=1);

namespace App\Domain\Common\ValueObject;

use Ramsey\Uuid\Uuid;

/**
 * Shared behaviour for Value Objects that wrap a 16-byte UUID (binary).
 *
 * The owning class must declare a private string property named `$uuid`
 * holding the raw 16-byte representation, and forward construction via
 * `UuidBinaryValue::fromBytes($uuid)` (or equivalent static helper) so the
 * byte invariants can be enforced.
 */
trait UuidBinaryValue
{
    private static function wrapBytes(string $bytes): self
    {
        if (16 !== \strlen($bytes)) {
            throw new \InvalidArgumentException('UUID bytes must be exactly 16 bytes');
        }

        return new self($bytes);
    }

    public static function generate(): static
    {
        return self::wrapBytes(Uuid::uuid7()->getBytes());
    }

    public static function fromString(string $uuid): static
    {
        if (!Uuid::isValid($uuid)) {
            throw new \InvalidArgumentException(\sprintf('Invalid UUID: %s', $uuid));
        }

        return self::wrapBytes(Uuid::fromString($uuid)->getBytes());
    }

    public static function fromBytes(string $bytes): static
    {
        return self::wrapBytes($bytes);
    }

    public function toString(): string
    {
        /** @var string $bytes */
        $bytes = $this->uuid;

        return Uuid::fromBytes($bytes)->toString();
    }

    public function toBytes(): string
    {
        return $this->uuid;
    }

    public function equals(self $other): bool
    {
        return $this->uuid === $other->uuid;
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
