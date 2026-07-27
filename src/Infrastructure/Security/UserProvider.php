<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\User\Entity\User;
use App\Domain\User\Repository\UserRepositoryInterface;
use App\Domain\User\ValueObject\Email;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * @template TUser of \Symfony\Component\Security\Core\User\UserInterface
 *
 * @implements \Symfony\Component\Security\Core\User\UserProviderInterface<TUser>
 */
final readonly class UserProvider implements UserProviderInterface
{
    public function __construct(private UserRepositoryInterface $userRepository)
    {
    }

    /** @return User */
    #[\Override]
    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $email = Email::fromString($identifier);
        $user = $this->userRepository->findByEmail($email);

        if (!$user instanceof User) {
            throw new UserNotFoundException('User not found');
        }

        return $user;
    }

    #[\Override]
    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(\sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $refreshedUser = $this->userRepository->findByEmail($user->getEmail());

        if (!$refreshedUser instanceof User) {
            throw new UserNotFoundException('User not found');
        }

        return $refreshedUser;
    }

    #[\Override]
    public function supportsClass(string $class): bool
    {
        return User::class === $class || \is_subclass_of($class, User::class);
    }
}
