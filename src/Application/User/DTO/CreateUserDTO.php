<?php

declare(strict_types=1);

namespace App\Application\User\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class CreateUserDTO
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

    /**
     * Validated by DTO, never by Role::fromString() on a live path: that
     * throws InvalidArgumentException, which our subscriber maps to a bare
     * 500 instead of 422.
     */
    #[Assert\Choice(choices: ['user', 'admin'], message: 'Invalid role: {{ value }}. Allowed: user, admin')]
    public ?string $role;

    public function __construct(
        string $name,
        string $email,
        string $password,
        ?string $role = null,
    ) {
        $this->name = \trim($name);
        $this->email = \strtolower(\trim($email));
        $this->password = $password;
        $this->role = null === $role ? null : \strtolower(\trim($role));
    }
}
