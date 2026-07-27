<?php

declare(strict_types=1);

namespace App\Application\User\DTO;

use App\Domain\User\Entity\User;

final readonly class LoginResult
{
    public function __construct(
        public string $accessToken,
        public string $tokenType,
        public int $expiresIn,
        public User $user,
    ) {
    }
}
