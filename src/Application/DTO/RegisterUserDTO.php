<?php

declare(strict_types=1);

namespace App\Application\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class RegisterUserDTO
{
    #[Assert\NotBlank(message: 'Name cannot be empty')]
    #[Assert\Length(min: 2, max: 100, minMessage: 'Name must be at least {{ limit }} characters', maxMessage: 'Name cannot exceed {{ limit }} characters')]
    public string $name;

    #[Assert\NotBlank(message: 'Email cannot be empty')]
    #[Assert\Email(mode: 'html5', message: 'Invalid email format')]
    #[Assert\Length(max: 255, maxMessage: 'Email cannot exceed {{ limit }} characters')]
    public string $email;

    #[Assert\NotBlank(message: 'Password cannot be empty')]
    #[Assert\Length(min: 8, max: 255, minMessage: 'Password must be at least {{ limit }} characters', maxMessage: 'Password cannot exceed {{ limit }} characters')]
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
