<?php

declare(strict_types=1);

namespace App\Tests\Application\Collection\DTO;

use App\Application\Collection\DTO\UpdateCollectionDTO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UpdateCollectionDTO::class)]
final class UpdateCollectionDTOTest extends TestCase
{
    /**
     * Presence is wired by hand for every patchable field (property, flag,
     * accessor, hasChanges). A fifth field added without the wiring would
     * silently never apply, so the pairing is asserted mechanically.
     */
    public function testEveryStringFieldHasAPresenceAccessorWiredIntoHasChanges(): void
    {
        $reflection = new \ReflectionClass(UpdateCollectionDTO::class);
        $fields = [];

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $type = $property->getType();
            if (!$type instanceof \ReflectionNamedType || 'string' !== $type->getName()) {
                continue;
            }

            $fields[] = $property->getName();
            $accessor = 'has'.\ucfirst($property->getName());

            self::assertTrue(
                $reflection->hasMethod($accessor),
                \sprintf('%s needs a %s() accessor', $property->getName(), $accessor),
            );

            $provided = (new UpdateCollectionDTO())->withProvidedFields([$property->getName()]);
            self::assertTrue($provided->{$accessor}(), $accessor.'() must report a provided key');
            self::assertTrue($provided->hasChanges(), 'A provided key must count as a change');

            $absent = new UpdateCollectionDTO();
            self::assertFalse($absent->{$accessor}(), $accessor.'() must be false when nothing is provided');
        }

        self::assertSame(['name', 'theme', 'description', 'image'], $fields, 'Unexpected patchable field set');
    }

    public function testDirectConstructionUsesValuesAsPresence(): void
    {
        $dto = new UpdateCollectionDTO(name: 'Renamed', description: 'Text');

        self::assertTrue($dto->hasName());
        self::assertTrue($dto->hasDescription());
        self::assertFalse($dto->hasTheme());
        self::assertFalse($dto->hasImage());
        self::assertTrue($dto->hasChanges());
    }

    public function testProvidedKeyWithNullValueCountsAsChange(): void
    {
        $dto = (new UpdateCollectionDTO())->withProvidedFields(['description']);

        self::assertTrue($dto->hasDescription());
        self::assertNull($dto->description);
    }

    public function testThemeIsTrimmedAndLowercased(): void
    {
        $dto = new UpdateCollectionDTO(theme: ' Games ');

        self::assertSame('games', $dto->theme);
        self::assertTrue($dto->hasTheme());
    }

    public function testUnknownKeysAreIgnored(): void
    {
        $dto = (new UpdateCollectionDTO())->withProvidedFields(['unknown', 'provided']);

        self::assertFalse($dto->hasChanges());
    }
}
