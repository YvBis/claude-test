<?php

declare(strict_types=1);

namespace App\Domain\User\ValueObject;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Embeddable]
final readonly class PasswordHash implements \Stringable
{
    public const int BCRYPT_COST = 13;

    #[ORM\Column(name: 'hash', type: 'string', length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 60, max: 255)]
    private string $hash;

    private function __construct(string $hash)
    {
        $this->hash = $hash;
    }

    public static function fromHash(string $hash): self
    {
        if (!self::isValidHash($hash)) {
            throw new \InvalidArgumentException('Invalid password hash format');
        }

        return new self($hash);
    }

    public static function createFromPlain(string $plainPassword): self
    {
        if (\strlen($plainPassword) < 8) {
            throw new \InvalidArgumentException('Password must be at least 8 characters');
        }

        // `password_hash()` with hardcoded valid params cannot fail: PHPStan 2.2
        // models its return as `non-empty-string` (verified via dumpType),
        // so a `false` branch would be statically dead (`varTag.nativeType`).
        $hash = \password_hash($plainPassword, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);

        return new self($hash);
    }

    private static function isValidHash(string $hash): bool
    {
        return 1 === \preg_match('/^\$2[aby]\$\d{2}\$[\.\/A-Za-z0-9]{53}$/', $hash);
    }

    public function value(): string
    {
        return $this->hash;
    }

    public function verify(string $plainPassword): bool
    {
        return \password_verify($plainPassword, $this->hash);
    }

    public function needsRehash(): bool
    {
        return \password_needs_rehash($this->hash, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);
    }

    public function equals(self $other): bool
    {
        return $this->hash === $other->hash;
    }

    #[\Override]
    public function __toString(): string
    {
        return '***';
    }
}
