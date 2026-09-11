<?php

declare(strict_types=1);

namespace App\Domain\Collection\Entity;

use App\Domain\Collection\ValueObject\CollectionFieldId;
use App\Domain\Collection\ValueObject\FieldName;
use App\Domain\Collection\ValueObject\FieldType;
use App\Domain\Common\Constant\SlotLimits;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Clock\ClockAwareTrait;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'collection_fields')]
#[ORM\UniqueConstraint(name: 'uniq_collection_field_slot', columns: ['collection_id', 'field_type', 'slot_index'])]
#[ORM\Index(name: 'idx_collection_field_collection', columns: ['collection_id'])]
#[ORM\HasLifecycleCallbacks]
final class CollectionField
{
    use ClockAwareTrait {
        now as protected clockNow;
    }

    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'binary', length: 16)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Collection::class, fetch: 'LAZY')]
    #[ORM\JoinColumn(name: 'collection_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private Collection $collection;

    #[ORM\Embedded(class: FieldName::class, columnPrefix: false)]
    #[Assert\Valid]
    private FieldName $name;

    #[ORM\Embedded(class: FieldType::class, columnPrefix: false)]
    #[Assert\Valid]
    private FieldType $type;

    #[ORM\Column(name: 'slot_index', type: Types::SMALLINT)]
    #[Assert\Range(min: 1, max: SlotLimits::MAX_SLOTS_PER_TYPE)]
    private int $slotIndex;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /**
     * @internal This constructor is public to allow test object creation.
     * Use CollectionField::create() for production code.
     */
    public function __construct(
        string $id,
        Collection $collection,
        FieldName $name,
        FieldType $type,
        int $slotIndex,
    ) {
        if ($slotIndex < 1 || $slotIndex > SlotLimits::MAX_SLOTS_PER_TYPE) {
            throw new \InvalidArgumentException(
                \sprintf('Slot index must be between 1 and %d', SlotLimits::MAX_SLOTS_PER_TYPE)
            );
        }

        $this->id = $id;
        $this->collection = $collection;
        $this->name = $name;
        $this->type = $type;
        $this->slotIndex = $slotIndex;
        $this->createdAt = $this->clockNow();
        $this->updatedAt = $this->createdAt;
    }

    public static function create(
        Collection $collection,
        FieldName $name,
        FieldType $type,
        int $slotIndex,
    ): self {
        return new self(
            id: CollectionFieldId::generate()->toBytes(),
            collection: $collection,
            name: $name,
            type: $type,
            slotIndex: $slotIndex,
        );
    }

    public function getId(): CollectionFieldId
    {
        return CollectionFieldId::fromBytes($this->id);
    }

    public function getCollection(): Collection
    {
        return $this->collection;
    }

    public function getName(): FieldName
    {
        return $this->name;
    }

    public function getType(): FieldType
    {
        return $this->type;
    }

    public function getSlotIndex(): int
    {
        return $this->slotIndex;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** Domain rule #3: only rename allowed post-creation. type/slot/collection immutable. */
    public function rename(FieldName $name): void
    {
        $this->name = $name;
        $this->touch();
    }

    /**
     * Edge case: admin reorg only.
     * Moves the field to another collection but keeps slot_index unchanged.
     * Caller (use-case/service) is responsible for ensuring slot_index does not
     * collide with an existing field in the target collection — domain does not
     * query the repository here to avoid coupling entity to persistence.
     */
    public function reassignToCollection(Collection $collection): void
    {
        $this->collection = $collection;
        $this->touch();
    }

    // Note: updatedAt is updated via explicit touch() calls from domain mutators
    // (rename(), reassignToCollection()). Doctrine @ORM\PreUpdate is NOT used
    // because the changeset is computed before preUpdate fires — touch() there
    // would never persist.

    public function touch(): void
    {
        $this->updatedAt = $this->clockNow();
    }
}
