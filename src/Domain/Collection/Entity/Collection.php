<?php

declare(strict_types=1);

namespace App\Domain\Collection\Entity;

use App\Domain\Collection\ValueObject\CollectionId;
use App\Domain\Collection\ValueObject\CollectionName;
use App\Domain\Collection\ValueObject\Theme;
use App\Domain\User\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'collections', indexes: [
    new ORM\Index(name: 'idx_collection_owner', columns: ['owner_id']),
    new ORM\Index(name: 'idx_collection_theme', columns: ['theme']),
])]
#[ORM\HasLifecycleCallbacks]
final class Collection
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'binary', length: 16)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: User::class, fetch: 'LAZY')]
    #[ORM\JoinColumn(name: 'owner_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private User $owner;

    #[ORM\Embedded(class: CollectionName::class, columnPrefix: false)]
    #[Assert\Valid]
    private CollectionName $name;

    #[ORM\Embedded(class: Theme::class, columnPrefix: false)]
    #[Assert\Valid]
    private Theme $theme;

    #[ORM\Column(name: 'image', type: Types::STRING, length: 500, nullable: true)]
    #[Assert\Length(max: 500)]
    private ?string $image = null;

    #[ORM\Column(name: 'description', type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /**
     * @internal This constructor is public to allow test object creation.
     * Use Collection::create() for production code.
     */
    public function __construct(
        string $id,
        User $owner,
        CollectionName $name,
        Theme $theme,
        ?string $description = null,
        ?string $image = null,
        ?\DateTimeImmutable $createdAt = null,
        ?\DateTimeImmutable $updatedAt = null,
    ) {
        $this->id = $id;
        $this->owner = $owner;
        $this->name = $name;
        $this->theme = $theme;
        $this->description = $this->normalizeDescription($description);
        $this->image = $this->normalizeImage($image);
        $now = new \DateTimeImmutable();
        $this->createdAt = $createdAt ?? $now;
        $this->updatedAt = $updatedAt ?? $now;
    }

    public static function create(
        User $owner,
        CollectionName $name,
        Theme $theme,
        ?string $description = null,
        ?string $image = null,
        ?\DateTimeImmutable $at = null,
    ): self {
        return new self(
            id: CollectionId::generate()->toBytes(),
            owner: $owner,
            name: $name,
            theme: $theme,
            description: $description,
            image: $image,
            createdAt: $at,
            updatedAt: $at,
        );
    }

    public function getId(): CollectionId
    {
        return CollectionId::fromBytes($this->id);
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getName(): CollectionName
    {
        return $this->name;
    }

    public function getTheme(): Theme
    {
        return $this->theme;
    }

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function changeName(CollectionName $name, ?\DateTimeImmutable $at = null): void
    {
        $this->name = $name;
        $this->touch($at);
    }

    public function changeTheme(Theme $theme, ?\DateTimeImmutable $at = null): void
    {
        $this->theme = $theme;
        $this->touch($at);
    }

    public function changeDescription(?string $description, ?\DateTimeImmutable $at = null): void
    {
        $this->description = $this->normalizeDescription($description);
        $this->touch($at);
    }

    public function changeImage(?string $image, ?\DateTimeImmutable $at = null): void
    {
        $this->image = $this->normalizeImage($image);
        $this->touch($at);
    }

    private function normalizeDescription(?string $description): ?string
    {
        if (null === $description) {
            return null;
        }

        $description = \trim($description);

        return '' === $description ? null : $description;
    }

    private function normalizeImage(?string $image): ?string
    {
        if (null === $image) {
            return null;
        }

        $image = \trim($image);

        return '' === $image ? null : $image;
    }

    public function reassignOwner(User $owner, ?\DateTimeImmutable $at = null): void
    {
        $this->owner = $owner;
        $this->touch($at);
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(\Doctrine\ORM\Event\PreUpdateEventArgs $args): void
    {
        if (!$args->hasChangedField('updatedAt')) {
            $this->touch();
        }
    }

    public function touch(?\DateTimeImmutable $at = null): void
    {
        $this->updatedAt = $at ?? new \DateTimeImmutable();
    }
}
