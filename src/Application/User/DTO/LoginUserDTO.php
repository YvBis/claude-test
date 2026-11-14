<?php

declare(strict_types=1);

namespace App\Application\User\DTO;

use App\Application\Common\Validation\StrictEmail;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\PasswordHash;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class LoginUserDTO
{
    #[Assert\NotBlank(message: 'Email cannot be empty')]
    #[StrictEmail(message: 'Invalid email format')]
    #[Assert\Length(max: Email::MAX_LENGTH, maxMessage: 'Email cannot exceed {{ limit }} characters')]
    public string $email;

    #[Assert\NotBlank(message: 'Password cannot be empty')]
    #[Assert\Length(min: PasswordHash::MIN_PLAIN_LENGTH, max: PasswordHash::MAX_PLAIN_LENGTH, minMessage: 'Password must be at least {{ limit }} characters', maxMessage: 'Password cannot exceed {{ limit }} characters')]
    #[Assert\Regex(pattern: '/\x00/', match: false, message: 'Password must not contain null bytes')]
    public string $password;

    public function __construct(
        string $email,
        string $password
    ) {
        $this->email = \strtolower(\trim($email));
        $this->password = $password;
    }
}
