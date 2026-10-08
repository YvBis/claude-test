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
     * @param array<string, mixed> $document
     */
    public static function fromArray(array $document): self
    {
        return new self(
            (string) ($document['id'] ?? ''),
            (string) ($document['name'] ?? ''),
            (string) ($document['theme'] ?? ''),
            (string) ($document['description'] ?? ''),
            (string) ($document['owner_id'] ?? ''),
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
