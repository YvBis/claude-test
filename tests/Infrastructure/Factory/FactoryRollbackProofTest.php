<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Factory;

use App\Domain\User\Entity\User;
use App\Domain\User\ValueObject\Email;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The live proof that DAMA's rollback wraps Foundry writes.
 *
 * Both tests insert a user with the SAME fixed email, which the unique index
 * on the email column plus the entity invariant forbid twice. Under DAMA the
 * first test's insert is rolled back, so the second starts from an empty table;
 * if the rollback ever stops working, the second insert dies on the unique
 * constraint instead of passing quietly.
 *
 * The pair is deliberately not merged into one test: the assertion is about
 * state that survives between two tests, and only two tests can show that.
 * Verified by setting `dama_doctrine_test.enable_static_connection: false` and
 * removing the extension - the second test then goes red (recorded in
 * PRD/9.2-factories.md).
 *
 * Counting is done with DQL rather than the factory repository: `User::$email`
 * is an embedded value object, so a criteria array keyed `email` is not a field
 * path Doctrine understands.
 */
final class FactoryRollbackProofTest extends KernelTestCase
{
    private const string FIXED_EMAIL = 'rollback-proof@example.com';

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testFirstTestLeavesExactlyOneFixedUser(): void
    {
        UserFactory::createOne(['email' => Email::fromString(self::FIXED_EMAIL)]);

        self::assertSame(1, $this->countUsers());
    }

    public function testSecondTestIsNotBlockedByTheFirst(): void
    {
        // Without DAMA this insert collides with the row the previous test left.
        UserFactory::createOne(['email' => Email::fromString(self::FIXED_EMAIL)]);

        self::assertSame(1, $this->countUsers());
    }

    private function countUsers(): int
    {
        return (int) $this->entityManager
            ->createQuery('SELECT COUNT(u.id) FROM '.User::class.' u')
            ->getSingleScalarResult();
    }
}
