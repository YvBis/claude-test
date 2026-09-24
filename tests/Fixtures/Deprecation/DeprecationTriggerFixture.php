<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Deprecation;

use PHPUnit\Framework\TestCase;

/**
 * A probe fixture with one deliberate E_USER_DEPRECATED, run by
 * scripts/deprecation-format-probe.sh as a detached child PHPUnit process.
 *
 * The class name must end with the file basename: PHPUnit derives the class
 * name from the file name (TestSuiteLoader::classNameFromFileName) and only
 * accepts a class whose short name ends with it. No `Test` suffix on purpose:
 * with one the file would match the default `Test.php` suffix and risk
 * collection by a future suite that scans tests/ recursively.
 *
 * The trigger must not be silenced with `@`: PHPUnit does not see suppressed
 * deprecations (fixed in 5.24).
 */
final class DeprecationTriggerFixture extends TestCase
{
    public function testTriggerOneDeprecation(): void
    {
        \trigger_error('Deprecation format probe', \E_USER_DEPRECATED);

        self::assertTrue(true);
    }
}
