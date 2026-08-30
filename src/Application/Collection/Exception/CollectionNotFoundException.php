<?php

declare(strict_types=1);

namespace App\Application\Collection\Exception;

use App\Domain\Collection\ValueObject\CollectionId;

final class CollectionNotFoundException extends \DomainException
{
    public static function withId(CollectionId $id): self
    {
        return new self(\sprintf('Collection with id "%s" not found', $id->toString()));
    }
}
