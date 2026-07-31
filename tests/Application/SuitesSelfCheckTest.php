<?php

declare(strict_types=1);

namespace App\Tests\Application;

use PHPUnit\Framework\TestCase;

final class SuitesSelfCheckTest extends TestCase
{
    public function testApplicationSuiteIsWired(): void
    {
        // Marker: ensures Application testsuite is non-empty and runnable.
        $this->assertTrue(true, 'Application testsuite non-empty marker');
    }
}
