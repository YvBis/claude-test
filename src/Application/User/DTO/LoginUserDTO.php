<?php

declare(strict_types=1);

namespace App\Application\User\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class LoginUserDTO
{
    #[Assert\NotBlank(message: 'Email cannot be empty')]
    #[Assert\Email(mode: 'html5', message: 'Invalid email format')]
    #[Assert\Length(max: 255)]
    public string $email;

    #[Assert\NotBlank(message: 'Password cannot be empty')]
    #[Assert\Length(min: 8, max: 255, minMessage: 'Password must be at least {{ limit }} characters')]
    public string $password;

    public function __construct(
        string $email,
        string $password
    ) {
        $this->email = \strtolower(\trim($email));
        $this->password = $password;
    }
}
