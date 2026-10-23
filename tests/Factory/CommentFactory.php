<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Domain\Comment\Entity\Comment;
use App\Domain\Comment\ValueObject\CommentContent;
use App\Domain\Comment\ValueObject\CommentId;
use App\Domain\Common\ValueObject\OwnerId;
use App\Domain\Item\Entity\Item;
use Zenstruck\Foundry\LazyValue;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Unlike likes, comments carry no uniqueness: the same owner may comment on the
 * same item repeatedly, so defaults can be independent without tripping a
 * constraint, and `forItem()` alone is safe for `createMany()`.
 *
 * The content comes from `sentence(6)`: `CommentContent` strips control
 * characters and trims the edges but preserves inner text, so punctuation is
 * fine here (unlike item names).
 *
 * @extends PersistentObjectFactory<Comment>
 */
final class CommentFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Comment::class;
    }

    public function forItem(Item $item): static
    {
        return $this->with(['item' => $item]);
    }

    protected function defaults(): array
    {
        return [
            'id' => CommentId::generate()->toBytes(),
            'ownerId' => LazyValue::new(static fn (): OwnerId => OwnerId::fromBytes(
                UserFactory::createOne()->getId()->toBytes(),
            )),
            'item' => ItemFactory::new(),
            'content' => CommentContent::fromString(self::faker()->sentence(6)),
        ];
    }
}
