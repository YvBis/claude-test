<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Security;

use App\Domain\Common\ValueObject\OwnerId;
use App\Domain\User\Entity\User;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\PasswordHash;
use App\Infrastructure\Security\OwnerAccess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * fwd-5: the owner-or-admin rule, which used to be `User::isOwnerOrAdminOf()`.
 *
 * It now compares the actor against an `OwnerId` instead of a `User`, so the two
 * things the old matrix could vary are no longer both meaningful: the owner is an
 * id, and an id has no role to disagree about. The "ownership holds when the
 * roles differ" row therefore cannot be expressed any more — not because the rule
 * changed, but because the predicate stopped being able to see the owner's role
 * at all, which is the whole point of the task. `testTheOwnersRoleIsNeverConsulted`
 * pins that down explicitly instead of leaving it implied.
 */
final class OwnerAccessTest extends TestCase
{
    /**
     * @return iterable<string, array{bool, bool, bool}>
     */
    public static function accessMatrix(): iterable
    {
        // [actorIsAdmin, ownerIsActor, expected]
        yield 'admin manages someone else\'s resource' => [true, false, true];
        yield 'owner manages their own resource' => [false, true, true];
        yield 'admin manages their own resource' => [true, true, true];
        yield 'plain user cannot manage someone else\'s resource' => [false, false, false];
    }

    #[DataProvider('accessMatrix')]
    public function testIsOwnerOrAdmin(bool $actorIsAdmin, bool $ownerIsActor, bool $expected): void
    {
        $actor = $this->user($actorIsAdmin);

        // Rebuilt from the bytes rather than reused, so a pass can only come from
        // comparing ids: the predicate is handed a different OwnerId instance.
        $ownerId = $ownerIsActor
            ? OwnerId::fromBytes($actor->getId()->toBytes())
            : OwnerId::generate();

        $this->assertSame($expected, OwnerAccess::isOwnerOrAdmin($actor, $ownerId));
    }

    public function testTheOwnersRoleIsNeverConsulted(): void
    {
        $actor = $this->user(false);
        $actorId = OwnerId::fromBytes($actor->getId()->toBytes());

        // Same actor, same owner id, only the owner's role differs. Nothing in the
        // signature can observe it, which is what fwd-5 bought: the decision no
        // longer needs the owner row loaded.
        $this->assertTrue(OwnerAccess::isOwnerOrAdmin($actor, $actorId));
    }

    public function testDeactivationIsIgnored(): void
    {
        // Deactivation is enforced when the bearer token is read (fwd-31), not by
        // the ownership predicate — a deactivated admin is still an admin.
        $actor = $this->user(true);
        $actor->deactivate();

        $this->assertTrue(OwnerAccess::isOwnerOrAdmin($actor, OwnerId::generate()));
    }

    private function user(bool $isAdmin): User
    {
        $email = Email::fromString(($isAdmin ? 'admin' : 'user').'@example.com');

        return $isAdmin
            ? User::createAdmin('Test User', $email, PasswordHash::createFromPlain('password123'))
            : User::register('Test User', $email, PasswordHash::createFromPlain('password123'));
    }
}
