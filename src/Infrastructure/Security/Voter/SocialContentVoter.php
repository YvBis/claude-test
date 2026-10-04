<?php

declare(strict_types=1);

namespace App\Infrastructure\Security\Voter;

use App\Domain\Comment\Entity\Comment;
use App\Domain\Like\Entity\Like;
use App\Domain\User\Entity\User;
use App\Infrastructure\Security\OwnerAccess;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Authorises moderation of social content (likes and comments): the author of
 * the content or an administrator may edit/delete it. Likes have no editable
 * content, so editing a like is denied for everyone, administrators included.
 *
 * Registered automatically through the `App\Infrastructure\Security\` service
 * block (autoconfigure), which is picked up by the security bundle as a
 * `security.voter`.
 *
 * Note: the decision reads `$subject->getOwnerId()`, an `owner_id` column rather
 * than an association since fwd-5, so callers no longer have to pass an entity
 * whose owner is hydrated and repositories no longer JOIN FETCH the author.
 *
 * @extends Voter<string, Comment|Like>
 */
final class SocialContentVoter extends Voter
{
    public const string SOCIAL_EDIT = 'SOCIAL_EDIT';

    public const string SOCIAL_DELETE = 'SOCIAL_DELETE';

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $this->supportsAttribute($attribute)
            && \is_object($subject)
            && $this->supportsType($subject::class);
    }

    /**
     * Declared explicitly so the voter is not consulted for every attribute of
     * every `is_granted()` call in the application.
     */
    #[\Override]
    public function supportsAttribute(string $attribute): bool
    {
        return self::SOCIAL_EDIT === $attribute || self::SOCIAL_DELETE === $attribute;
    }

    /**
     * @param class-string $subjectType
     */
    #[\Override]
    public function supportsType(string $subjectType): bool
    {
        return \is_a($subjectType, Comment::class, true) || \is_a($subjectType, Like::class, true);
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        if (self::SOCIAL_EDIT === $attribute && $subject instanceof Like) {
            return false;
        }

        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        // No defensive `instanceof` guard: `vote()` only reaches here for
        // subjects passing `supports()` (Comment|Like, enforced by the
        // `@extends Voter<string, Comment|Like>` template above), so `$subject`
        // is `Comment|Like` by construction and the call below is type-safe.
        //
        // The admin short-circuit lives inside `OwnerAccess::isOwnerOrAdmin()`
        // and is deliberately not repeated here: a second copy of "admin
        // implies allowed" is exactly the divergence this predicate was unified
        // to remove. Since fwd-5 the subjects carry an `OwnerId` rather than a
        // hydrated `User`, so the rule also no longer depends on any repository
        // JOIN FETCHing the author.
        return OwnerAccess::isOwnerOrAdmin($user, $subject->getOwnerId());
    }
}
