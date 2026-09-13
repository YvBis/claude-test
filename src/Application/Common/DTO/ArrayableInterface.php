<?php

declare(strict_types=1);

namespace App\Application\Common\DTO;

interface ArrayableInterface
{
    /** @return array<string, mixed> */
    public function toArray(): array;
}
