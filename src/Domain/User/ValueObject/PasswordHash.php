<?php

declare(strict_types=1);

namespace App\Domain\User\ValueObject;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Embeddable]
final readonly class PasswordHash implements \Stringable
{
    public const int BCRYPT_COST = 13;

    /**
     * Bounds for the plain-text password at the API boundary. MAX is an
     * API/product limit with no domain invariant behind it: the hash column is
     * 255, but nothing in the domain constrains the plain input above, so the
     * check lives in the DTOs (RegisterUserDTO, CreateUserDTO, LoginUserDTO).
     * Recorded, not silently aligned, per the 8.6 review.
     */
    public const int MIN_PLAIN_LENGTH = 8;

    public const int MAX_PLAIN_LENGTH = 255;

    /**
     * Storage bound for the hash column: bcrypt hashes are 60 chars, 255 is
     * headroom. Not a validation bound — the plain-text limit lives above.
     */
    public const int MAX_HASH_LENGTH = 255;

    #[ORM\Column(name: 'hash', type: 'string', length: self::MAX_HASH_LENGTH)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 60, max: self::MAX_HASH_LENGTH)]
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
        if (\strlen($plainPassword) < self::MIN_PLAIN_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('Password must be at least %d characters', self::MIN_PLAIN_LENGTH));
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
