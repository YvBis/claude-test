<?php

declare(strict_types=1);

namespace App\Domain\Collection\ValueObject;

use App\Domain\Common\ValueObject\UuidBinaryValue;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
final readonly class CollectionId
{
    use UuidBinaryValue;

    #[ORM\Column(name: 'id', type: 'binary', length: 16)]
    private string $uuid;

    private function __construct(string $uuid)
    {
        $this->uuid = $uuid;
    }
}
