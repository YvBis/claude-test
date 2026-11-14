<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Api;

use App\Application\Common\Validation\StrictEmail;
use PHPUnit\Framework\TestCase;

/**
 * `Assert\Email(mode: 'html5')` accepts addresses the domain `filter_var`
 * rejects, so a DTO carrying it lets the request reach the service and end as
 * a bare 500 (10.2). The live gate is `StrictEmail`, which delegates to the
 * domain value object.
 */
final class ValidationEmailConstraintTest extends TestCase
{
    /** Output DTOs serialize a stored address and are never validated. */
    private const array RESPONSE_DTO_ALLOWLIST = [
        'App\Application\User\DTO\UserDTO',
    ];

    public function testNoDtoUsesTheFrameworkEmailConstraint(): void
    {
        $offenders = [];

        foreach ($this->applicationFiles() as $file) {
            $source = (string) \file_get_contents($file);

            if (1 === \preg_match('/(?:Assert\\\\Email|Constraints\\\\Email)\b/', $source)) {
                $offenders[] = $file;
            }
        }

        self::assertSame([], $offenders, "Use App\\Application\\Common\\Validation\\StrictEmail instead of the framework Email constraint:\n".\implode("\n", $offenders));
    }

    public function testEveryEmailPropertyCarriesStrictEmail(): void
    {
        $checked = 0;

        foreach ($this->applicationClasses() as $class) {
            if (\in_array($class, self::RESPONSE_DTO_ALLOWLIST, true)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);

            if (!$reflection->hasProperty('email')) {
                continue;
            }

            $property = $reflection->getProperty('email');

            if ($property->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            ++$checked;

            self::assertCount(1, $property->getAttributes(StrictEmail::class), $class.'::$email must carry StrictEmail');
        }

        self::assertGreaterThan(0, $checked, 'No request class declares $email - the scan lost its target.');
    }

    /**
     * @return list<string>
     */
    private function applicationFiles(): array
    {
        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base(), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }

        \sort($files);

        return $files;
    }

    /**
     * @return list<class-string>
     */
    private function applicationClasses(): array
    {
        $base = $this->base();
        $classes = [];

        foreach ($this->applicationFiles() as $file) {
            $relative = \substr($file, \strlen($base) + 1, -4);
            $class = 'App\\Application\\'.\str_replace('/', '\\', $relative);

            if (\class_exists($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    private function base(): string
    {
        return \dirname(__DIR__, 3).'/src/Application';
    }
}
