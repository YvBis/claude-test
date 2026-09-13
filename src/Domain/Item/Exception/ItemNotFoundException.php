<?php

declare(strict_types=1);

namespace App\Domain\Item\Exception;

use App\Domain\Item\ValueObject\ItemId;

final class ItemNotFoundException extends \DomainException
{
    public static function withId(ItemId $id): self
    {
        return new self(\sprintf('Item with id "%s" not found', $id->toString()));
    }
}
