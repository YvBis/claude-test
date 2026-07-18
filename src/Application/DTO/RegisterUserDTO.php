<?php

declare(strict_types=1);

namespace App\Application\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class RegisterUserDTO
{
    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 100)]
    public string $name;

    #[Assert\NotBlank]
    #[Assert\Email(mode: 'html5')]
    #[Assert\Length(max: 255)]
    public string $email;

    #[Assert\NotBlank]
    #[Assert\Length(min: 8, max: 255)]
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
