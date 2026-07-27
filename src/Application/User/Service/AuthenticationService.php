<?php

declare(strict_types=1);

namespace App\Application\User\Service;

use App\Application\User\DTO\LoginResult;
use App\Application\User\DTO\LoginUserDTO;
use App\Domain\User\Exception\InvalidCredentialsException;
use App\Domain\User\Exception\UserDeactivatedException;
use App\Domain\User\Repository\UserRepositoryInterface;
use App\Domain\User\ValueObject\Email;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

final readonly class AuthenticationService
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private JWTTokenManagerInterface $jwtManager,
    ) {
    }

    public function authenticate(LoginUserDTO $dto): LoginResult
    {
        $email = Email::fromString($dto->email);

        $user = $this->userRepository->findByEmail($email);

        if (!$user instanceof \App\Domain\User\Entity\User) {
            throw InvalidCredentialsException::forEmail($dto->email);
        }

        if (!$user->isActive()) {
            throw UserDeactivatedException::forUser($user->getId());
        }

        if (!$user->verifyPassword($dto->password)) {
            throw InvalidCredentialsException::forEmail($dto->email);
        }

        $token = $this->jwtManager->create($user);

        return new LoginResult(
            accessToken: $token,
            tokenType: 'Bearer',
            expiresIn: 3600,
            user: $user,
        );
    }
}
