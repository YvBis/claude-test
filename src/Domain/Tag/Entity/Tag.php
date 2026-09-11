<?php

declare(strict_types=1);

namespace App\Domain\Tag\Entity;

use App\Domain\Tag\ValueObject\TagId;
use App\Domain\Tag\ValueObject\TagName;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Clock\ClockAwareTrait;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'tags')]
#[ORM\UniqueConstraint(name: 'uniq_tag_name', columns: ['name'])]
#[ORM\HasLifecycleCallbacks]
final class Tag
{
    use ClockAwareTrait {
        now as protected clockNow;
    }

    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'binary', length: 16)]
    private string $id;

    #[ORM\Embedded(class: TagName::class, columnPrefix: false)]
    #[Assert\Valid]
    private TagName $name;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /**
     * @internal This constructor is public to allow test object creation.
     * Use Tag::create() for production code.
     */
    public function __construct(
        string $id,
        TagName $name,
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->createdAt = $this->clockNow();
        $this->updatedAt = $this->createdAt;
    }

    public static function create(TagName $name): self
    {
        return new self(
            id: TagId::generate()->toBytes(),
            name: $name,
        );
    }

    public function getId(): TagId
    {
        return TagId::fromBytes($this->id);
    }

    public function getName(): TagName
    {
        return $this->name;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
