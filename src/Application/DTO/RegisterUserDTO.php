<?php

declare(strict_types=1);

namespace App\Application\DTO;

use App\Domain\User\Entity\User;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\PasswordHash;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class RegisterUserDTO
{
    #[Assert\NotBlank(message: 'Name cannot be empty')]
    #[Assert\Length(min: User::MIN_NAME_LENGTH, max: User::MAX_NAME_LENGTH, minMessage: 'Name must be at least {{ limit }} characters', maxMessage: 'Name cannot exceed {{ limit }} characters')]
    public string $name;

    #[Assert\NotBlank(message: 'Email cannot be empty')]
    #[Assert\Email(mode: 'html5', message: 'Invalid email format')]
    #[Assert\Length(max: Email::MAX_LENGTH, maxMessage: 'Email cannot exceed {{ limit }} characters')]
    public string $email;

    #[Assert\NotBlank(message: 'Password cannot be empty')]
    #[Assert\Length(min: PasswordHash::MIN_PLAIN_LENGTH, max: PasswordHash::MAX_PLAIN_LENGTH, minMessage: 'Password must be at least {{ limit }} characters', maxMessage: 'Password cannot exceed {{ limit }} characters')]
    public string $password;

    public function __construct(
        string $name,
        string $email,
        string $password
    ) {
        $this->name = \trim($name);
        $this->email = \strtolower(\trim($email));
        $this->password = $password;
    }
}
