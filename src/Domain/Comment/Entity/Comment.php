<?php

declare(strict_types=1);

namespace App\Domain\Comment\Entity;

use App\Domain\Comment\ValueObject\CommentContent;
use App\Domain\Comment\ValueObject\CommentId;
use App\Domain\Common\ValueObject\OwnerId;
use App\Domain\Item\Entity\Item;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Clock\ClockAwareTrait;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'comments')]
// Both listing indexes include created_at and id so the (created_at, id) sort of
// findByItemId/findByOwnerId is served by the index (no filesort). InnoDB appends
// the PK (id) implicitly, but it is listed explicitly so the intent is visible.
#[ORM\Index(name: 'idx_comment_item', columns: ['item_id', 'created_at', 'id'])]
#[ORM\Index(name: 'idx_comment_owner', columns: ['owner_id', 'created_at', 'id'])]
final class Comment
{
    use ClockAwareTrait {
        now as protected clockNow;
    }

    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'binary', length: 16)]
    private string $id;

    /**
     * The author as an id, not as an association.
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

    #[ORM\Embedded(class: CommentContent::class, columnPrefix: false)]
    private CommentContent $content;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /**
     * @internal This constructor is public to allow test object creation.
     * Use Comment::create() for production code.
     */
    public function __construct(
        string $id,
        OwnerId $ownerId,
        Item $item,
        CommentContent $content,
    ) {
        $this->id = $id;
        $this->ownerId = $ownerId->toBytes();
        $this->item = $item;
        $this->content = $content;
        $this->createdAt = $this->clockNow();
        $this->updatedAt = $this->createdAt;
    }

    public static function create(OwnerId $ownerId, Item $item, CommentContent $content): self
    {
        return new self(
            id: CommentId::generate()->toBytes(),
            ownerId: $ownerId,
            item: $item,
            content: $content,
        );
    }

    public function getId(): CommentId
    {
        return CommentId::fromBytes($this->id);
    }

    public function getOwnerId(): OwnerId
    {
        return OwnerId::fromBytes($this->ownerId);
    }

    public function getItem(): Item
    {
        return $this->item;
    }

    public function getContent(): CommentContent
    {
        return $this->content;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function changeContent(CommentContent $content): void
    {
        if ($this->content->equals($content)) {
            return;
        }

        $this->content = $content;
        $this->touch();
    }

    public function touch(): void
    {
        $this->updatedAt = $this->clockNow();
    }
}
