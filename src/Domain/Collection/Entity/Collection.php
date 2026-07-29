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
    ) {
        $this->id = $id;
        $this->owner = $owner;
        $this->name = $name;
        $this->theme = $theme;
        $this->description = $description;
        $this->image = $image;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public static function create(
        User $owner,
        CollectionName $name,
        Theme $theme,
        ?string $description = null,
        ?string $image = null,
    ): self {
        return new self(
            id: CollectionId::generate()->toBytes(),
            owner: $owner,
            name: $name,
            theme: $theme,
            description: $description,
            image: $image,
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

    public function changeName(CollectionName $name): void
    {
        $this->name = $name;
        $this->touch();
    }

    public function changeTheme(Theme $theme): void
    {
        $this->theme = $theme;
        $this->touch();
    }

    public function changeDescription(?string $description): void
    {
        if (null !== $description) {
            $description = \trim($description);
            if ('' === $description) {
                $description = null;
            }
        }

        $this->description = $description;
        $this->touch();
    }

    public function changeImage(?string $image): void
    {
        $this->image = null === $image ? null : \trim($image);
        $this->touch();
    }

    public function reassignOwner(User $owner): void
    {
        $this->owner = $owner;
        $this->touch();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
