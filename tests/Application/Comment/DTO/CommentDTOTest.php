<?php

declare(strict_types=1);

namespace App\Tests\Application\Comment\DTO;

use App\Application\Comment\DTO\CommentDTO;
use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\ValueObject\CollectionName;
use App\Domain\Collection\ValueObject\Theme;
use App\Domain\Comment\Entity\Comment;
use App\Domain\Comment\ValueObject\CommentContent;
use App\Domain\Common\ValueObject\OwnerId;
use App\Domain\Item\Entity\Item;
use PHPUnit\Framework\TestCase;

final class CommentDTOTest extends TestCase
{
    private function createComment(string $content = 'Great game!'): Comment
    {
        $collection = Collection::create(
            ownerId: OwnerId::generate(),
            name: CollectionName::fromString('Comment DTO Collection'),
            theme: Theme::games(),
        );
        $item = Item::create($collection, 'Halo 3');

        return Comment::create(OwnerId::generate(), $item, CommentContent::fromString($content));
    }

    public function testFromEntityMapsAllFields(): void
    {
        $comment = $this->createComment();
        $dto = CommentDTO::fromEntity($comment, 'Comment Author');

        $this->assertSame($comment->getId()->toString(), $dto->id);
        $this->assertSame($comment->getOwnerId()->toString(), $dto->ownerId);
        $this->assertSame($comment->getItem()->getId()->toString(), $dto->itemId);
        $this->assertSame('Great game!', $dto->content);
        $this->assertSame($comment->getCreatedAt(), $dto->createdAt);
        $this->assertSame($comment->getUpdatedAt(), $dto->updatedAt);
    }

    public function testFromEntityTakesOwnerNameFromRelatedUser(): void
    {
        $this->assertSame('Comment Author', CommentDTO::fromEntity($this->createComment(), 'Comment Author')->ownerName);
    }

    public function testToArrayUsesSnakeCaseKeysAndAtomTimestamps(): void
    {
        $dto = CommentDTO::fromEntity($this->createComment(), 'Comment Author');

        $this->assertSame([
            'id' => $dto->id,
            'owner_id' => $dto->ownerId,
            'owner_name' => $dto->ownerName,
            'item_id' => $dto->itemId,
            'content' => 'Great game!',
            'created_at' => $dto->createdAt->format(\DateTimeInterface::ATOM),
            'updated_at' => $dto->updatedAt->format(\DateTimeInterface::ATOM),
        ], $dto->toArray());
    }

    public function testToArrayPreservesMarkdownLineBreaks(): void
    {
        $markdown = "First line\n\n- item one\n- item two";
        $dto = CommentDTO::fromEntity($this->createComment($markdown), 'Comment Author');

        $this->assertSame($markdown, $dto->content);
        $this->assertSame($markdown, $dto->toArray()['content']);
    }

    public function testUpdatedAtReflectsEdit(): void
    {
        $comment = $this->createComment();
        $comment->changeContent(CommentContent::fromString('Edited body'));

        $dto = CommentDTO::fromEntity($comment, 'Comment Author');

        $this->assertSame('Edited body', $dto->content);
        $this->assertSame($comment->getUpdatedAt(), $dto->updatedAt);
    }
}
