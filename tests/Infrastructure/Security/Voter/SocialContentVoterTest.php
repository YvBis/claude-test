<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Security\Voter;

use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\ValueObject\CollectionId;
use App\Domain\Collection\ValueObject\CollectionName;
use App\Domain\Collection\ValueObject\Theme;
use App\Domain\Comment\Entity\Comment;
use App\Domain\Comment\ValueObject\CommentContent;
use App\Domain\Item\Entity\Item;
use App\Domain\Like\Entity\Like;
use App\Domain\User\Entity\User;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\PasswordHash;
use App\Domain\User\ValueObject\Role;
use App\Domain\User\ValueObject\UserId;
use App\Infrastructure\Security\Voter\SocialContentVoter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class SocialContentVoterTest extends TestCase
{
    private User $owner;
    private User $other;
    private User $admin;
    private Comment $comment;
    private Like $like;

    protected function setUp(): void
    {
        $this->owner = $this->createUser('owner@example.com');
        $this->other = $this->createUser('other@example.com');
        $this->admin = User::createAdmin(
            'Admin',
            Email::fromString('admin@example.com'),
            PasswordHash::createFromPlain('password123'),
        );

        $collection = new Collection(
            id: CollectionId::generate()->toBytes(),
            owner: $this->owner,
            name: CollectionName::fromString('My Books'),
            theme: Theme::books(),
        );
        $item = Item::create($collection, '1984');

        $this->comment = Comment::create($this->owner, $item, CommentContent::fromString('Nice'));
        $this->like = Like::create($this->owner, $item);
    }

    /**
     * @return iterable<string, array{string|null, string, string, int}>
     */
    public static function voteMatrix(): iterable
    {
        yield 'owner edit comment' => ['owner', SocialContentVoter::SOCIAL_EDIT, 'comment', VoterInterface::ACCESS_GRANTED];
        yield 'owner delete comment' => ['owner', SocialContentVoter::SOCIAL_DELETE, 'comment', VoterInterface::ACCESS_GRANTED];
        yield 'owner delete like' => ['owner', SocialContentVoter::SOCIAL_DELETE, 'like', VoterInterface::ACCESS_GRANTED];
        yield 'owner edit like' => ['owner', SocialContentVoter::SOCIAL_EDIT, 'like', VoterInterface::ACCESS_DENIED];
        yield 'other edit comment' => ['other', SocialContentVoter::SOCIAL_EDIT, 'comment', VoterInterface::ACCESS_DENIED];
        yield 'other delete comment' => ['other', SocialContentVoter::SOCIAL_DELETE, 'comment', VoterInterface::ACCESS_DENIED];
        yield 'other delete like' => ['other', SocialContentVoter::SOCIAL_DELETE, 'like', VoterInterface::ACCESS_DENIED];
        yield 'other edit like' => ['other', SocialContentVoter::SOCIAL_EDIT, 'like', VoterInterface::ACCESS_DENIED];
        yield 'admin edit comment' => ['admin', SocialContentVoter::SOCIAL_EDIT, 'comment', VoterInterface::ACCESS_GRANTED];
        yield 'admin delete comment' => ['admin', SocialContentVoter::SOCIAL_DELETE, 'comment', VoterInterface::ACCESS_GRANTED];
        yield 'admin delete like' => ['admin', SocialContentVoter::SOCIAL_DELETE, 'like', VoterInterface::ACCESS_GRANTED];
        yield 'admin edit like' => ['admin', SocialContentVoter::SOCIAL_EDIT, 'like', VoterInterface::ACCESS_DENIED];
        yield 'guest edit comment' => [null, SocialContentVoter::SOCIAL_EDIT, 'comment', VoterInterface::ACCESS_DENIED];
        yield 'guest delete comment' => [null, SocialContentVoter::SOCIAL_DELETE, 'comment', VoterInterface::ACCESS_DENIED];
        yield 'guest delete like' => [null, SocialContentVoter::SOCIAL_DELETE, 'like', VoterInterface::ACCESS_DENIED];
        yield 'guest edit like' => [null, SocialContentVoter::SOCIAL_EDIT, 'like', VoterInterface::ACCESS_DENIED];
    }

    #[DataProvider('voteMatrix')]
    public function testVoteMatrix(?string $actor, string $attribute, string $subject, int $expected): void
    {
        $token = $this->tokenFor($actor);
        $subject = 'comment' === $subject ? $this->comment : $this->like;

        self::assertSame(
            $expected,
            $this->voter()->vote($token, $subject, [$attribute]),
        );
    }

    public function testUnknownAttributeAbstains(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter()->vote($this->tokenFor('owner'), $this->comment, ['SOMETHING_ELSE']),
        );
    }

    public function testUnknownSubjectAbstains(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter()->vote($this->tokenFor('owner'), new \stdClass(), [SocialContentVoter::SOCIAL_DELETE]),
        );
    }

    public function testNullSubjectAbstains(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter()->vote($this->tokenFor('owner'), null, [SocialContentVoter::SOCIAL_DELETE]),
        );
    }

    private function voter(): SocialContentVoter
    {
        return new SocialContentVoter();
    }

    private function tokenFor(?string $actor): TokenInterface
    {
        if (null === $actor) {
            return new NullToken();
        }

        $user = match ($actor) {
            'owner' => $this->owner,
            'other' => $this->other,
            'admin' => $this->admin,
            default => throw new \InvalidArgumentException(\sprintf('Unknown actor "%s"', $actor)),
        };

        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }

    private function createUser(string $email): User
    {
        return new User(
            id: UserId::generate()->toBytes(),
            name: 'User',
            email: Email::fromString($email),
            passwordHash: PasswordHash::createFromPlain('password123'),
            role: Role::user(),
        );
    }
}
