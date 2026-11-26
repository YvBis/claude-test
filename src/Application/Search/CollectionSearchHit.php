<?php

declare(strict_types=1);

namespace App\Application\Search;

/**
 * One collection hit, exactly as the engine returned it. See ItemSearchHit
 * for why this is a named wrapper, not DB hydration.
 */
final readonly class CollectionSearchHit
{
    public function __construct(
        public string $id,
        public string $name,
        public string $theme,
        public string $description,
        public string $ownerId,
    ) {
    }

    /**
     * Validates the untrusted engine document: every scalar written by
     * CollectionDocument is required, so absence or a wrong type means the
     * index drifted and the read must fail instead of serving filler values.
     * `description` is the exception — the write path normalises null to the
     * empty string, so an empty description is a legitimate document.
     *
     * @param array<string, mixed> $document
     */
    public static function fromArray(array $document): self
    {
        return new self(
            SearchDocumentFields::nonEmptyString($document, self::class, 'id'),
            SearchDocumentFields::nonEmptyString($document, self::class, 'name'),
            SearchDocumentFields::nonEmptyString($document, self::class, 'theme'),
            SearchDocumentFields::string($document, self::class, 'description'),
            SearchDocumentFields::nonEmptyString($document, self::class, 'owner_id'),
        );
    }

    /**
     * @return array{id: string, name: string, theme: string, description: string, owner_id: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'theme' => $this->theme,
            'description' => $this->description,
            'owner_id' => $this->ownerId,
        ];
    }
}
