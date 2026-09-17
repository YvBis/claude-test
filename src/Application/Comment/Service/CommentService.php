<?php

declare(strict_types=1);

namespace App\Application\Comment\Service;

use App\Application\Comment\DTO\CommentDTO;
use App\Application\Common\Transaction\UnitOfWorkInterface;
use App\Domain\Collection\ValueObject\OwnerId;
use App\Domain\Comment\Entity\Comment;
use App\Domain\Comment\Repository\CommentRepositoryInterface;
use App\Domain\Comment\ValueObject\CommentContent;
use App\Domain\Comment\ValueObject\CommentId;
use App\Domain\Item\Entity\Item;
use App\Domain\Item\ValueObject\ItemId;
use App\Domain\User\Entity\User;

final readonly class CommentService
{
    public function __construct(
        private CommentRepositoryInterface $commentRepository,
        private UnitOfWorkInterface $unitOfWork,
    ) {
    }

    /**
     * Creates a comment authored by $owner on $item. The raw string is
     * normalised by CommentContent, which throws \InvalidArgumentException on
     * invalid content (empty or over the length limit) — the caller maps that
     * to 422.
     */
    public function create(User $owner, Item $item, string $content): Comment
    {
        $comment = Comment::create($owner, $item, CommentContent::fromString($content));
        $this->commentRepository->save($comment);
        $this->unitOfWork->flush();

        return $comment;
    }

    /**
     * Returns a comment by id, or null when it does not exist. Unlike
     * ItemService::getById() this does not throw a not-found exception: the
     * caller (5.5) maps null to 404 (edit/delete paths, administrative included).
     *
     * Note for the API layer: a malformed id makes CommentId::fromString() throw
     * \InvalidArgumentException — the same type CommentContent throws for invalid
     * content. The two must be mapped differently (bad id → 400/404,
     * invalid content → 422), so do not blanket-catch this exception type.
     */
    public function getById(string $id): ?Comment
    {
        return $this->commentRepository->findById(CommentId::fromString($id));
    }

    /**
     * Applies an edit and returns the aggregate. When the new content is
     * normalisation-equal to the current one the domain change would be a no-op,
     * so nothing is saved and no flush is issued. Throws
     * \InvalidArgumentException on invalid content — the caller maps that to 422.
     */
    public function changeContent(Comment $comment, string $content): Comment
    {
        $newContent = CommentContent::fromString($content);
        if ($comment->getContent()->equals($newContent)) {
            return $comment;
        }

        $comment->changeContent($newContent);
        $this->commentRepository->save($comment);
        $this->unitOfWork->flush();

        return $comment;
    }

    /**
     * Removes a comment. Authorization (author or administrator) is enforced by
     * the caller.
     */
    public function delete(Comment $comment): void
    {
        $this->commentRepository->remove($comment);
        $this->unitOfWork->flush();
    }

    /**
     * Lists the comments of an item, oldest first.
     *
     * @return array<Comment>
     */
    public function listByItem(ItemId $itemId, int $limit = 50, int $offset = 0): array
    {
        return $this->commentRepository->findByItemId($itemId, $limit, $offset);
    }

    /**
     * Lists the comments authored by an owner (the "my comments" listing),
     * oldest first.
     *
     * @return array<Comment>
     */
    public function listByOwner(OwnerId $ownerId, int $limit = 50, int $offset = 0): array
    {
        return $this->commentRepository->findByOwnerId($ownerId, $limit, $offset);
    }

    public function countByItem(ItemId $itemId): int
    {
        return $this->commentRepository->countByItemId($itemId);
    }

    public function countByOwner(OwnerId $ownerId): int
    {
        return $this->commentRepository->countByOwnerId($ownerId);
    }

    public function toDTO(Comment $comment): CommentDTO
    {
        return CommentDTO::fromEntity($comment);
    }

    /**
     * @param array<Comment> $comments
     *
     * @return array<CommentDTO>
     */
    public function toDTOList(array $comments): array
    {
        return \array_map(
            fn (Comment $comment): CommentDTO => $this->toDTO($comment),
            $comments,
        );
    }
}
