<?php

declare(strict_types=1);

namespace App\Application\User\Service;

use App\Domain\User\Entity\User;
use App\Domain\User\Repository\UserRepositoryInterface;

final readonly class UserService
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
    ) {
    }

    /** @return array<User> */
    public function listUsers(int $limit = 50, int $offset = 0): array
    {
        return $this->userRepository->findAll($limit, $offset);
    }
}
