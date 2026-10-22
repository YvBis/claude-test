<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\ValueObject\CollectionId;
use App\Domain\Collection\ValueObject\CollectionName;
use App\Domain\Collection\ValueObject\Theme;
use App\Domain\Common\ValueObject\OwnerId;
use Zenstruck\Foundry\LazyValue;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Ownership is a column, not an association (fwd-5), so `ownerId` cannot be a
 * factory reference: Foundry would try to persist a `User` where an `OwnerId`
 * is expected.
 *
 * The owner is wrapped in `LazyValue` rather than computed inline: `defaults()`
 * is evaluated before attribute overrides are merged, so an inline
 * `UserFactory::createOne()` would mint a throwaway user even when the caller
 * passed `['ownerId' => ...]` - exactly what a 100-collection seed does.
 *
 * @extends PersistentObjectFactory<Collection>
 */
final class CollectionFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Collection::class;
    }

    protected function defaults(): array
    {
        return [
            'id' => CollectionId::generate()->toBytes(),
            'ownerId' => LazyValue::new(static fn (): OwnerId => OwnerId::fromBytes(
                UserFactory::createOne()->getId()->toBytes(),
            )),
            'name' => CollectionName::fromString(self::faker()->words(2, true)),
            'theme' => Theme::books(),
            'description' => self::faker()->optional()->sentence(),
            'image' => null,
        ];
    }
}
