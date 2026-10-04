<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Security\Voter;

use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\ValueObject\CollectionId;
use App\Domain\Collection\ValueObject\CollectionName;
use App\Domain\Collection\ValueObject\Theme;
use App\Domain\Common\ValueObject\OwnerId;
use App\Domain\Item\Entity\Item;
use App\Domain\Like\Entity\Like;
use App\Domain\User\Entity\User;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\PasswordHash;
use App\Infrastructure\Security\Voter\SocialContentVoter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\Strategy\AccessDecisionStrategyInterface;
use Symfony\Component\Security\Core\Authorization\Strategy\AffirmativeStrategy;
use Symfony\Component\Security\Core\Authorization\Strategy\UnanimousStrategy;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Pins fwd-15: the denial `SocialContentVoter` issues for editing a like has
 * to survive a second voter granting access.
 *
 * Built by hand rather than by registering a second voter in the test
 * container: what is under test is how the strategy aggregates votes, and a
 * hand-built manager states the scenario in one readable block. A global
 * grant-all voter registered in the test environment would instead break every
 * existing 403 expectation in the Like and Comment controller tests, which
 * costs far more than it proves.
 *
 * These tests are green before and after the config change — they pin the
 * semantics of the strategy, not the wiring. The wiring is pinned separately
 * by `tests/Infrastructure/Ci/SecurityConfigTest.php`, which was red before the
 * change. Neither check duplicates the other.
 */
final class AccessDecisionStrategyTest extends TestCase
{
    private SocialContentVoter $socialVoter;
    private User $admin;
    private Like $like;
    private TokenInterface $token;

    protected function setUp(): void
    {
        $this->socialVoter = new SocialContentVoter();

        $this->admin = User::createAdmin(
            'Admin',
            Email::fromString('admin@example.com'),
            PasswordHash::createFromPlain('password123'),
        );

        $collection = new Collection(
            id: CollectionId::generate()->toBytes(),
            ownerId: OwnerId::fromBytes($this->admin->getId()->toBytes()),
            name: CollectionName::fromString('My Books'),
            theme: Theme::books(),
        );

        $this->like = Like::create(OwnerId::fromBytes($this->admin->getId()->toBytes()), Item::create($collection, '1984'));
        $this->token = new UsernamePasswordToken($this->admin, 'main', $this->admin->getRoles());
    }

    /**
     * The case the strategy decides: the social voter DENIES editing a like
     * (for an administrator too), and a second voter grants it.
     */
    #[DataProvider('strategyMatrix')]
    public function testOnlyUnanimousKeepsTheSocialDenyAgainstASecondGrant(
        AccessDecisionStrategyInterface $strategy,
        bool $expectedGranted,
    ): void {
        self::assertSame(
            $expectedGranted,
            $this->decide($strategy, SocialContentVoter::SOCIAL_EDIT, $this->grantEverythingVoter()),
        );
    }

    /**
     * @return iterable<string, array{AccessDecisionStrategyInterface, bool}>
     */
    public static function strategyMatrix(): iterable
    {
        yield 'unanimous: the denial wins over the other grant' => [new UnanimousStrategy(), false];
        yield 'affirmative: the other grant wins — the reason it is not the configured strategy' => [new AffirmativeStrategy(), true];
    }

    public function testUnanimousStillAllowsAnUnanimousGrant(): void
    {
        // The strategy must not be a blanket denial: with both voters granting,
        // the decision stays granted, so the second voter of the future does not
        // lock every social endpoint by accident.
        self::assertTrue(
            $this->decide(new UnanimousStrategy(), SocialContentVoter::SOCIAL_DELETE, $this->grantEverythingVoter()),
            'Deleting an own like is granted by both voters and must stay granted.',
        );
    }

    public function testAnAbstainingSecondVoterDoesNotTurnAGrantIntoADenial(): void
    {
        // The substance of the Roadmap row's trigger: whatever the first
        // Item/Collection voter does on social attributes, abstaining there must
        // not break an existing grant. Only DENY blocks under unanimous, never
        // an abstention (UnanimousStrategy:37-43).
        self::assertTrue(
            $this->decide(new UnanimousStrategy(), SocialContentVoter::SOCIAL_DELETE, $this->abstainingVoter()),
            'An abstaining second voter must not turn a legitimate grant into a denial.',
        );
    }

    private function decide(
        AccessDecisionStrategyInterface $strategy,
        string $attribute,
        VoterInterface $secondVoter,
    ): bool {
        $manager = new AccessDecisionManager([$this->socialVoter, $secondVoter], $strategy);

        return $manager->decide($this->token, [$attribute], $this->like);
    }

    private function grantEverythingVoter(): VoterInterface
    {
        return new class () implements VoterInterface {
            #[\Override]
            public function vote(
                TokenInterface $token,
                mixed $subject,
                array $attributes,
                ?Vote $vote = null,
            ): int {
                return VoterInterface::ACCESS_GRANTED;
            }
        };
    }

    private function abstainingVoter(): VoterInterface
    {
        return new class () implements VoterInterface {
            #[\Override]
            public function vote(
                TokenInterface $token,
                mixed $subject,
                array $attributes,
                ?Vote $vote = null,
            ): int {
                return VoterInterface::ACCESS_ABSTAIN;
            }
        };
    }
}
