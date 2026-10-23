<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Domain\Collection\Entity\Collection;
use App\Domain\Item\Entity\Item;
use App\Domain\Item\ValueObject\ItemId;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * `collection` is a real association, so a factory reference is enough and
 * Foundry persists the collection first. The name is generated from faker
 * words: the entity's whitelist allows letters, digits, space, underscore,
 * hyphen, dot and forward slash, and `words()` stays inside it - a sentence
 * with punctuation would throw.
 *
 * Slots are not filled here: they belong to the story that knows which demo
 * values make sense (task 9.3), not to a generic factory.
 *
 * @extends PersistentObjectFactory<Item>
 */
final class ItemFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Item::class;
    }

    public function inCollection(Collection $collection): static
    {
        return $this->with(['collection' => $collection]);
    }

    protected function defaults(): array
    {
        return [
            'id' => ItemId::generate()->toBytes(),
            'collection' => CollectionFactory::new(),
            'name' => self::faker()->words(2, true),
        ];
    }
}
