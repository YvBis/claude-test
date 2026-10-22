<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Domain\Tag\Entity\Tag;
use App\Domain\Tag\ValueObject\TagId;
use App\Domain\Tag\ValueObject\TagName;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * `tags.name` is UNIQUE, so the name must differ per instance. `lexify()` is
 * used rather than `word()` because the value object requires at least two
 * characters - faker's shortest words sit right on that edge - and because
 * letters plus the literal hyphen stay inside the allowed character set.
 *
 * @extends PersistentObjectFactory<Tag>
 */
final class TagFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Tag::class;
    }

    protected function defaults(): array
    {
        return [
            'id' => TagId::generate()->toBytes(),
            'name' => TagName::fromString(self::faker()->unique()->lexify('tag-??????')),
        ];
    }
}
