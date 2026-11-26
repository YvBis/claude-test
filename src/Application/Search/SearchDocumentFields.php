<?php

declare(strict_types=1);

namespace App\Application\Search;

/**
 * Field rules shared by the search hits. Documents come from the engine, not
 * from our writers, so this is the untrusted boundary: the indexers always
 * emit every scalar field, which makes absence or a wrong type a broken index
 * rather than a value to paper over.
 *
 * @internal
 */
final class SearchDocumentFields
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $document
     */
    public static function nonEmptyString(array $document, string $hitClass, string $field): string
    {
        $value = $document[$field] ?? null;

        if (!\is_string($value) || '' === $value) {
            throw InvalidSearchDocumentException::forField($hitClass, $field);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $document
     */
    public static function string(array $document, string $hitClass, string $field): string
    {
        $value = $document[$field] ?? null;

        if (!\is_string($value)) {
            throw InvalidSearchDocumentException::forField($hitClass, $field);
        }

        return $value;
    }

    /**
     * Absent tags mean "no tags" and stay valid; a non-list is the same broken
     * document as a missing id, because coercion would turn garbage into data.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string>
     */
    public static function tags(array $document, string $hitClass): array
    {
        $value = $document['tags'] ?? [];

        if (!\is_array($value)) {
            throw InvalidSearchDocumentException::forField($hitClass, 'tags');
        }

        $tags = [];

        foreach ($value as $tag) {
            if (!\is_string($tag)) {
                throw InvalidSearchDocumentException::forField($hitClass, 'tags');
            }

            $tags[] = $tag;
        }

        return $tags;
    }
}
