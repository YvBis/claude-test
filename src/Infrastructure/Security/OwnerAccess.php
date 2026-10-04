<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\Common\ValueObject\OwnerId;
use App\Domain\User\Entity\User;

/**
 * The single owner-or-admin rule (fwd-5, replacing fwd-14's `User::isOwnerOrAdminOf`).
 *
 * fwd-5 turned `Collection`/`Like`/`Comment` ownership into an `owner_id` column,
 * so the old predicate could not keep its signature: `User::isOwnerOrAdminOf(self
 * $owner)` requires a `User` to compare against, and the resources no longer carry
 * one. It lives here, in Infrastructure, because comparing a session user with a
 * raw owner id is a framework-boundary concern — the domain layer has no business
 * knowing who is signed in, and letting `Domain\User` take an `OwnerId` would have
 * inverted the very dependency this task removes (`Domain\User` → `Domain\Collection`).
 *
 * It exists once, and both consumers use it: `AbstractApiController::canManage` for
 * item and collection mutations, and `SocialContentVoter` for social content. A
 * second inline copy of "admin implies allowed" is exactly the divergence fwd-14
 * unified away and would grow back here the moment a third call site appears.
 *
 * Identity is compared by id bytes, not by object reference, so a rehydrated owner
 * matches its author. Deactivation is deliberately absent: it is enforced when a
 * bearer token is read (fwd-31), and layering it here would make one rule
 * responsible for two things.
 */
final class OwnerAccess
{
    public static function isOwnerOrAdmin(User $actor, OwnerId $ownerId): bool
    {
        return $actor->getRole()->isAdmin()
            || $actor->getId()->toBytes() === $ownerId->toBytes();
    }
}
