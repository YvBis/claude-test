<?php

declare(strict_types=1);

namespace App\Domain\User\Entity;

use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\PasswordHash;
use App\Domain\User\ValueObject\Role;
use App\Domain\User\ValueObject\UserId;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Clock\ClockAwareTrait;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'users', indexes: [
    new ORM\Index(name: 'idx_user_email', columns: ['email']),
    new ORM\Index(name: 'idx_user_is_active', columns: ['is_active']),
])]
#[ORM\HasLifecycleCallbacks]
final class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    use ClockAwareTrait {
        now as protected clockNow;
    }

    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'binary', length: 16)]
    private string $id;

    #[ORM\Embedded(class: Email::class, columnPrefix: false)]
    #[Assert\Valid]
    private Email $email;

    #[ORM\Embedded(class: PasswordHash::class, columnPrefix: 'password_')]
    #[Assert\Valid]
    private PasswordHash $passwordHash;

    #[ORM\Embedded(class: Role::class, columnPrefix: false)]
    #[Assert\Valid]
    private Role $role;

    #[ORM\Column(name: 'is_active', type: Types::BOOLEAN, options: ['default' => true])]
    #[Assert\NotNull]
    private bool $isActive = true;

    #[ORM\Column(name: 'name', type: Types::STRING, length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 100)]
    private string $name;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /**
     * @internal This constructor is public to allow test object creation.
     * Use User::register() or User::createAdmin() for production code.
     */
    public function __construct(
        string $id,
        string $name,
        Email $email,
        PasswordHash $passwordHash,
        Role $role,
        bool $isActive = true,
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->email = $email;
        $this->passwordHash = $passwordHash;
        $this->role = $role;
        $this->isActive = $isActive;
        $this->createdAt = $this->clockNow();
        $this->updatedAt = $this->createdAt;
    }

    public static function register(
        string $name,
        Email $email,
        PasswordHash $passwordHash,
        ?Role $role = null,
    ): self {
        return new self(
            id: UserId::generate()->toBytes(),
            name: $name,
            email: $email,
            passwordHash: $passwordHash,
            role: $role ?? Role::user(),
            isActive: true,
        );
    }

    public static function createAdmin(
        string $name,
        Email $email,
        PasswordHash $passwordHash,
    ): self {
        return new self(
            id: UserId::generate()->toBytes(),
            name: $name,
            email: $email,
            passwordHash: $passwordHash,
            role: Role::admin(),
            isActive: true,
        );
    }

    public function getId(): UserId
    {
        return UserId::fromBytes($this->id);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getEmail(): Email
    {
        return $this->email;
    }

    public function getPasswordHash(): PasswordHash
    {
        return $this->passwordHash;
    }

    public function getRole(): Role
    {
        return $this->role;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function changeName(string $name): void
    {
        if ('' === \trim($name)) {
            throw new \InvalidArgumentException('Name cannot be empty');
        }

        if (\strlen($name) > 100) {
            throw new \InvalidArgumentException('Name cannot exceed 100 characters');
        }

        $this->name = $name;
        $this->touch();
    }

    public function changeEmail(Email $email): void
    {
        $this->email = $email;
        $this->touch();
    }

    public function changePassword(PasswordHash $passwordHash): void
    {
        $this->passwordHash = $passwordHash;
        $this->touch();
    }

    public function promoteToAdmin(): void
    {
        if ($this->role->isAdmin()) {
            throw new \LogicException('User is already admin');
        }

        $this->role = Role::admin();
        $this->touch();
    }

    public function demoteToUser(): void
    {
        if ($this->role->isUser()) {
            throw new \LogicException('User is already regular user');
        }

        $this->role = Role::user();
        $this->touch();
    }

    public function activate(): void
    {
        $this->isActive = true;
        $this->touch();
    }

    public function deactivate(): void
    {
        $this->isActive = false;
        $this->touch();
    }

    public function verifyPassword(string $plainPassword): bool
    {
        return $this->passwordHash->verify($plainPassword);
    }

    #[\Override]
    public function getRoles(): array
    {
        return ['ROLE_'.\strtoupper($this->role->value())];
    }

    #[\Override]
    public function getPassword(): string
    {
        return $this->passwordHash->value();
    }

    #[\Override]
    public function getUserIdentifier(): string
    {
        return $this->email->value();
    }

    #[\Override]
    public function eraseCredentials(): void
    {
        // No sensitive data to erase
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(\Doctrine\ORM\Event\PreUpdateEventArgs $args): void
    {
        if (!$args->hasChangedField('updatedAt')) {
            $this->touch();
        }
    }

    public function touch(): void
    {
        $this->updatedAt = $this->clockNow();
    }
}
