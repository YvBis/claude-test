<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Deprecation;

use PHPUnit\Framework\TestCase;

/**
 * The negative control for scripts/deprecation-format-probe.sh: same shape as
 * DeprecationTriggerFixture but without the deprecation. PHPUnit prints no
 * `triggered ... deprecation` line for it (ResultPrinter::printIssueList
 * returns early on an empty issue list), so a pattern loose enough to match
 * clean output fails this side of the probe.
 */
final class DeprecationCleanFixture extends TestCase
{
    public function testStayClean(): void
    {
        self::assertTrue(true);
    }
}
