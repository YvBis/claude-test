<?php

declare(strict_types=1);

namespace App\Domain\Tag\Repository;

use App\Domain\Tag\Entity\Tag;
use App\Domain\Tag\ValueObject\TagId;
use App\Domain\Tag\ValueObject\TagName;

interface TagRepositoryInterface
{
    /**
     * Schedule the tag for persistence (added to the Unit of Work).
     * The write is deferred and happens later, when a decision is made
     * to commit pending changes.
     */
    public function save(Tag $tag): void;

    /**
     * Schedule the tag for removal (added to the Unit of Work).
     * The removal is deferred and happens later, when a decision is made
     * to commit pending changes.
     */
    public function remove(Tag $tag): void;

    public function findById(TagId $id): ?Tag;

    /**
     * Case-insensitive lookup by name (DB collation utf8mb4_0900_ai_ci):
     * "Books" and "books" resolve to the same tag.
     */
    public function findByName(TagName $name): ?Tag;
}
