<?php

declare(strict_types=1);

namespace App\Tests\Application\User\DTO;

use App\Application\Common\Validation\StrictEmail;
use App\Application\DTO\RegisterUserDTO;
use App\Application\User\DTO\CreateUserDTO;
use App\Application\User\DTO\LoginUserDTO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The DTO contract must reject exactly what the domain value object rejects,
 * otherwise the request passes validation and the service throws a domain
 * exception the controllers do not catch (10.2).
 */
final class StrictEmailConstraintTest extends TestCase
{
    public function testDivergentAddressesAreRejectedByEveryDto(): void
    {
        $validator = $this->validator();

        foreach (['a.@example.com', '.a@example.com'] as $email) {
            foreach ($this->dtos($email) as $name => $dto) {
                $violations = $validator->validate($dto);

                self::assertCount(1, $violations, $name.' must reject '.$email);
                self::assertSame('Invalid email format', $violations[0]->getMessage(), $name);
            }
        }
    }

    public function testTrimmedAddressIsAcceptedByRegisterDto(): void
    {
        $dto = new RegisterUserDTO('John', '  john@example.com  ', 'password123');

        self::assertCount(0, $this->validator()->validate($dto));
    }

    public function testEmptyValueIsLeftToNotBlank(): void
    {
        $violations = $this->validator()->validate(new RegisterUserDTO('John', '', 'password123'));

        self::assertSame(['Email cannot be empty'], \array_map(
            static fn ($violation): string => (string) $violation->getMessage(),
            \iterator_to_array($violations),
        ));
    }

    public function testCustomMessageOverridesTheDefault(): void
    {
        $object = new class () {
            #[StrictEmail(message: 'Custom email gate')]
            public string $email = 'a.@example.com';
        };

        $violations = $this->validator()->validate($object);

        self::assertCount(1, $violations);
        self::assertSame('Custom email gate', $violations[0]->getMessage());
    }

    public function testConstraintHonoursGroups(): void
    {
        $object = new class () {
            #[StrictEmail(groups: ['strict'])]
            public string $email = 'a.@example.com';
        };

        $validator = $this->validator();

        self::assertCount(0, $validator->validate($object), 'must stay silent outside its group');
        self::assertCount(1, $validator->validate($object, null, ['strict']));
    }

    public function testConstraintIsDeclaredOnEveryEmailProperty(): void
    {
        foreach ([RegisterUserDTO::class, CreateUserDTO::class, LoginUserDTO::class] as $class) {
            $property = new \ReflectionProperty($class, 'email');
            $attributes = $property->getAttributes(StrictEmail::class);

            self::assertCount(1, $attributes, $class.' must carry StrictEmail');
        }
    }

    /**
     * @return array<string, object>
     */
    private function dtos(string $email): array
    {
        return [
            'RegisterUserDTO' => new RegisterUserDTO('John', $email, 'password123'),
            'CreateUserDTO' => new CreateUserDTO('John', $email, 'password123'),
            'LoginUserDTO' => new LoginUserDTO($email, 'password123'),
        ];
    }

    private function validator(): ValidatorInterface
    {
        static $validator = null;

        return $validator ??= Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }
}
