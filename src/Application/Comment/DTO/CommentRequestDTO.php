<?php

declare(strict_types=1);

namespace App\Application\Comment\DTO;

final readonly class CommentRequestDTO
{
    public function __construct(
        public string $content = '',
    ) {
    }
}
