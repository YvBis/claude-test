<?php

declare(strict_types=1);

namespace App\Application\Tag\Service;

use App\Domain\Tag\Entity\Tag;
use App\Domain\Tag\Repository\TagRepositoryInterface;
use App\Domain\Tag\ValueObject\TagName;

final readonly class TagService
{
    public function __construct(
        private TagRepositoryInterface $tagRepository,
    ) {
    }

    /**
     * Resolve raw tag names to unique Tag entities (case-insensitive identity).
     * Existing tags are reused, missing ones are created atomically by the
     * repository. The caller is responsible for flushing.
     *
     * @param array<string> $names
     *
     * @throws \InvalidArgumentException if any name is invalid
     *
     * @return array<Tag> unique tags, in order of first occurrence
     */
    public function resolveByNames(array $names): array
    {
        $uniqueNames = [];
        foreach ($names as $rawName) {
            $tagName = TagName::fromString($rawName);
            $uniqueNames[$tagName->value()] = $tagName;
        }

        $seen = [];
        $result = [];
        foreach ($uniqueNames as $tagName) {
            $tag = $this->tagRepository->getOrCreate($tagName);

            $key = $tag->getId()->toBytes();
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $result[] = $tag;
        }

        return $result;
    }

    /**
     * Lists tags whose name contains the term (case-insensitive substring),
     * ordered by name ASC. The search term is free-form (trimmed, control
     * characters stripped) and is NOT validated through TagName, so a single
     * character is a valid query. Empty term lists all tags.
     *
     * @return array<Tag>
     */
    public function listTags(?string $term, int $limit = 50, int $offset = 0): array
    {
        $term = $this->normalizeTerm($term);

        return $this->tagRepository->search($term, $limit, $offset);
    }

    private function normalizeTerm(?string $term): ?string
    {
        if (null === $term) {
            return null;
        }

        $term = \trim((string) \preg_replace('/[\p{Cc}]+/u', '', $term));
        $term = (string) \preg_replace('/\s+/u', ' ', $term);

        return '' === $term ? null : $term;
    }
}
