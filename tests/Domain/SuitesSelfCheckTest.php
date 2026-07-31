<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use PHPUnit\Framework\TestCase;

final class SuitesSelfCheckTest extends TestCase
{
    public function testDomainSuiteIsWired(): void
    {
        // Marker: ensures Domain testsuite is non-empty and runnable.
        $this->assertTrue(true, 'Domain testsuite non-empty marker');
    }
}
