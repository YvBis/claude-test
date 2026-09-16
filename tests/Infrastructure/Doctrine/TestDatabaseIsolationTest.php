<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Doctrine;

use DAMA\DoctrineTestBundle\Doctrine\DBAL\StaticDriver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TestDatabaseIsolationTest extends KernelTestCase
{
    public function testSuiteRunsAgainstIsolatedTestDatabase(): void
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get('doctrine')->getManager();

        $database = $em->getConnection()->fetchOne('SELECT DATABASE()');

        self::assertSame(
            'taskflow_test',
            $database,
            'The test suite must run against the isolated `taskflow_test` database, not the dev database.',
        );
    }

    public function testDamaTransactionalIsolationIsActive(): void
    {
        self::assertTrue(
            StaticDriver::isKeepStaticConnections(),
            'DAMADoctrineTestBundle must be active. Its PHPUnit extension is registered as '
            .'<extensions><bootstrap class="DAMA\DoctrineTestBundle\PHPUnit\PHPUnitExtension"/></extensions> — '
            .'a wrong element name is rejected by the XSD and the extension is skipped, silently disabling '
            .'per-test rollback.',
        );
    }
}
