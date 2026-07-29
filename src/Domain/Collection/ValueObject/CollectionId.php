<?php

declare(strict_types=1);

namespace App\Domain\Collection\ValueObject;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Embeddable]
final readonly class CollectionId
{
    #[ORM\Column(name: 'id', type: 'binary', length: 16)]
    private string $uuid;

    private function __construct(string $uuid)
    {
        $this->uuid = $uuid;
    }

    public static function generate(): self
    {
        return new self(Uuid::uuid7()->getBytes());
    }

    public static function fromString(string $uuid): self
    {
        if (!Uuid::isValid($uuid)) {
            throw new \InvalidArgumentException(\sprintf('Invalid UUID: %s', $uuid));
        }

        return new self(Uuid::fromString($uuid)->getBytes());
    }

    public static function fromBytes(string $bytes): self
    {
        if (16 !== \strlen($bytes)) {
            throw new \InvalidArgumentException('UUID bytes must be exactly 16 bytes');
        }

        return new self($bytes);
    }

    public function toString(): string
    {
        return Uuid::fromBytes($this->uuid)->toString();
    }

    public function toBytes(): string
    {
        return $this->uuid;
    }

    public function equals(self $other): bool
    {
        return $this->uuid === $other->uuid;
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->toString();
    }
}
