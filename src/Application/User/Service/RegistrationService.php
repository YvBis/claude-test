<?php

declare(strict_types=1);

namespace App\Application\User\Service;

use App\Application\Common\Transaction\UnitOfWorkInterface;
use App\Application\DTO\RegisterUserDTO;
use App\Domain\User\Entity\User;
use App\Domain\User\Exception\UserAlreadyExistsException;
use App\Domain\User\Repository\UserRepositoryInterface;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\PasswordHash;
use App\Domain\User\ValueObject\Role;

final readonly class RegistrationService
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private UnitOfWorkInterface $unitOfWork,
    ) {
    }

    /**
     * @param Role|null $role Admin creation (7.3) passes a role explicitly;
     *                        public registration keeps the default user role.
     */
    public function register(RegisterUserDTO $dto, ?Role $role = null): User
    {
        $email = Email::fromString($dto->email);

        if ($this->userRepository->existsByEmail($email)) {
            throw UserAlreadyExistsException::withEmail($email->value());
        }

        $passwordHash = PasswordHash::createFromPlain($dto->password);
        $user = User::register($dto->name, $email, $passwordHash, $role);

        $this->userRepository->save($user);
        $this->unitOfWork->flush();

        return $user;
    }
}
