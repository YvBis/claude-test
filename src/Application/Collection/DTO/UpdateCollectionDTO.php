<?php

declare(strict_types=1);

namespace App\Application\Collection\DTO;

use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\ValueObject\CollectionName;
use App\Domain\Collection\ValueObject\Theme;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * PATCH payload for a collection.
 *
 * Presence matters as much as value: an absent key means "leave unchanged",
 * while an explicit `null` clears `description`/`image`. The serializer cannot
 * tell the two apart (both arrive as null), so the controller hands the decoded
 * key set to {@see self::withProvidedFields()}.
 *
 * Validation runs once, after that enrichment: constraints, including the
 * presence callback below, see the final object.
 */
final readonly class UpdateCollectionDTO
{
    #[Assert\Length(
        min: CollectionName::MIN_NAME_LENGTH,
        max: CollectionName::MAX_NAME_LENGTH,
        minMessage: 'Collection name must be at least {{ limit }} characters',
        maxMessage: 'Collection name cannot exceed {{ limit }} characters',
    )]
    public ?string $name;

    #[Assert\Choice(
        callback: [Theme::class, 'values'],
        message: 'Invalid theme: {{ value }}. Allowed: books, games, movies, drinks',
    )]
    public ?string $theme;

    #[Assert\Length(max: Collection::MAX_DESCRIPTION_LENGTH, maxMessage: 'Collection description cannot exceed {{ limit }} characters')]
    public ?string $description;

    #[Assert\Length(max: Collection::MAX_IMAGE_LENGTH, maxMessage: 'Collection image cannot exceed {{ limit }} characters')]
    public ?string $image;

    /**
     * Whether the corresponding key was present in the PATCH body. Stored as
     * booleans so the DTO exposes no array property (request collections are
     * bounded by Assert\Count, see ValidationCardinalityTest).
     */
    private bool $nameProvided;

    private bool $themeProvided;

    private bool $descriptionProvided;

    private bool $imageProvided;

    /**
     * @param array<string> $provided
     */
    public function __construct(
        ?string $name = null,
        ?string $description = null,
        ?string $image = null,
        ?string $theme = null,
        array $provided = [],
    ) {
        $this->name = $this->normalizeName($name);
        $this->description = $this->normalizeText($description);
        $this->image = $this->normalizeText($image);
        $this->theme = null !== $theme ? \strtolower(\trim($theme)) : null;
        $this->nameProvided = \in_array('name', $provided, true);
        $this->themeProvided = \in_array('theme', $provided, true);
        $this->descriptionProvided = \in_array('description', $provided, true);
        $this->imageProvided = \in_array('image', $provided, true);
    }

    /**
     * @param array<string> $keys
     */
    public function withProvidedFields(array $keys): self
    {
        return new self($this->name, $this->description, $this->image, $this->theme, $keys);
    }

    public function hasName(): bool
    {
        return $this->nameProvided || null !== $this->name;
    }

    public function hasTheme(): bool
    {
        return $this->themeProvided || null !== $this->theme;
    }

    public function hasDescription(): bool
    {
        return $this->descriptionProvided || null !== $this->description;
    }

    public function hasImage(): bool
    {
        return $this->imageProvided || null !== $this->image;
    }

    public function hasChanges(): bool
    {
        return $this->hasName() || $this->hasTheme() || $this->hasDescription() || $this->hasImage();
    }

    #[Assert\Callback]
    public function validateNonNullablePresence(ExecutionContextInterface $context): void
    {
        if ($this->hasName() && null === $this->name) {
            $context->buildViolation('Collection name cannot be null')->atPath('name')->addViolation();
        }

        if ($this->hasTheme() && null === $this->theme) {
            $context->buildViolation('Theme cannot be null')->atPath('theme')->addViolation();
        }
    }

    private function normalizeName(?string $name): ?string
    {
        if (null === $name) {
            return null;
        }

        $name = \trim($name);

        return '' === $name ? null : $name;
    }

    private function normalizeText(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = \trim($value);

        return '' === $value ? null : $value;
    }
}
