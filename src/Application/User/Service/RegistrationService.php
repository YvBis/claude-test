<?php

declare(strict_types=1);

namespace App\Application\User\Service;

use App\Application\DTO\RegisterUserDTO;
use App\Domain\User\Entity\User;
use App\Domain\User\Exception\UserAlreadyExistsException;
use App\Domain\User\Repository\UserRepositoryInterface;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\PasswordHash;

final readonly class RegistrationService
{
    public function __construct(
        private UserRepositoryInterface $userRepository
    ) {
    }

    public function register(RegisterUserDTO $dto): User
    {
        $email = Email::fromString($dto->email);

        if ($this->userRepository->existsByEmail($email)) {
            throw UserAlreadyExistsException::withEmail($email->value());
        }

        $passwordHash = PasswordHash::createFromPlain($dto->password);
        $user = User::register($dto->name, $email, $passwordHash);

        $this->userRepository->save($user);

        return $user;
    }
}
