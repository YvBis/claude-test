<?php

declare(strict_types=1);

namespace App\Domain\Like\Entity;

use App\Domain\Common\ValueObject\OwnerId;
use App\Domain\Item\Entity\Item;
use App\Domain\Like\ValueObject\LikeId;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Clock\ClockAwareTrait;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'likes')]
#[ORM\Index(name: 'idx_like_item', columns: ['item_id'])]
#[ORM\Index(name: 'idx_like_owner', columns: ['owner_id', 'created_at', 'id'])]
#[ORM\UniqueConstraint(name: 'uniq_like_owner_item', columns: ['owner_id', 'item_id'])]
final class Like
{
    use ClockAwareTrait {
        now as protected clockNow;
    }

    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'binary', length: 16)]
    private string $id;

    /**
     * The liker as an id, not as an association.
     *
     * fwd-5, same reasoning as `Collection::$ownerId`: the `users(id)` foreign key
     * and its ON DELETE CASCADE live in the schema (written by hand into the
     * squashed baseline migration), not in this mapping.
     */
    #[ORM\Column(name: 'owner_id', type: 'binary', length: 16)]
    private string $ownerId;

    #[ORM\ManyToOne(targetEntity: Item::class, fetch: 'LAZY')]
    #[ORM\JoinColumn(name: 'item_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private Item $item;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @internal This constructor is public to allow test object creation.
     * Use Like::create() for production code.
     */
    public function __construct(
        string $id,
        OwnerId $ownerId,
        Item $item,
    ) {
        $this->id = $id;
        $this->ownerId = $ownerId->toBytes();
        $this->item = $item;
        $this->createdAt = $this->clockNow();
    }

    public static function create(OwnerId $ownerId, Item $item): self
    {
        return new self(
            id: LikeId::generate()->toBytes(),
            ownerId: $ownerId,
            item: $item,
        );
    }

    public function getId(): LikeId
    {
        return LikeId::fromBytes($this->id);
    }

    public function getOwnerId(): OwnerId
    {
        return OwnerId::fromBytes($this->ownerId);
    }

    public function getItem(): Item
    {
        return $this->item;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
