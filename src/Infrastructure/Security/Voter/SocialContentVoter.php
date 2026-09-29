<?php

declare(strict_types=1);

namespace App\Infrastructure\Security\Voter;

use App\Domain\Comment\Entity\Comment;
use App\Domain\Like\Entity\Like;
use App\Domain\User\Entity\User;
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
 * Note: the decision reads `$subject->getOwner()`, so callers must pass an
 * entity whose owner association is already hydrated (the repositories do this
 * via JOIN FETCH — final entities cannot be lazy ghost proxies in ORM 3).
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

        if ($user->getRole()->isAdmin()) {
            return true;
        }

        // No defensive `instanceof` guard: `vote()` only reaches here for
        // subjects passing `supports()` (Comment|Like, enforced by the
        // `@extends Voter<string, Comment|Like>` template above), so `$subject`
        // is `Comment|Like` by construction and the call below is type-safe.
        return $user->getId()->toString() === $subject->getOwner()->getId()->toString();
    }
}
