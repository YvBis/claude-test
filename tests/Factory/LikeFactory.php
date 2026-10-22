<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Domain\Common\ValueObject\OwnerId;
use App\Domain\Item\Entity\Item;
use App\Domain\Like\Entity\Like;
use App\Domain\Like\ValueObject\LikeId;
use Zenstruck\Foundry\LazyValue;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * `uniq_like_owner_item` makes the (owner, item) pair the identity of a like.
 * Both halves default to fresh objects, so `createMany(2)` cannot collide; a
 * test pinning one half must pin the other too, or the pair repeats and the
 * database rejects it - that is the constraint doing its job, not a factory bug.
 *
 * The helpers are split on purpose: one `forItem($item, $owner)` would make
 * `->many(2)->create()` a guaranteed unique violation, because it would pin the
 * same pair twice.
 *
 * @extends PersistentObjectFactory<Like>
 */
final class LikeFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Like::class;
    }

    public function forItem(Item $item): static
    {
        return $this->with(['item' => $item]);
    }

    public function byOwner(OwnerId $ownerId): static
    {
        return $this->with(['ownerId' => $ownerId]);
    }

    protected function defaults(): array
    {
        return [
            'id' => LikeId::generate()->toBytes(),
            'ownerId' => LazyValue::new(static fn (): OwnerId => OwnerId::fromBytes(
                UserFactory::createOne()->getId()->toBytes(),
            )),
            'item' => ItemFactory::new(),
        ];
    }
}
