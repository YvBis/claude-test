<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Ci;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the authorization strategy declared in `config/packages/security.yaml`
 * (fwd-15). Kept in `Ci/` alongside the other configuration guards rather than
 * next to the behavioural tests in
 * `tests/Infrastructure/Security/Voter/AccessDecisionStrategyTest.php`: this
 * file checks wiring, that one checks how a strategy aggregates votes.
 */
final class SecurityConfigTest extends TestCase
{
    public function testAccessDecisionStrategyIsExplicitlyUnanimous(): void
    {
        $config = Yaml::parseFile($this->projectRoot().'/config/packages/security.yaml');

        self::assertIsArray($config);
        self::assertSame(
            'unanimous',
            $config['security']['access_decision_manager']['strategy'] ?? null,
            'security.yaml must set security.access_decision_manager.strategy explicitly. Without it the '
            .'bundle falls back to AffirmativeStrategy (AccessDecisionManager:49), where a second voter '
            .'granting access silently overrides SocialContentVoter\'s DENY for editing a like.',
        );
    }

    public function testAllAbstainIsNotRelaxed(): void
    {
        $config = Yaml::parseFile($this->projectRoot().'/config/packages/security.yaml');

        self::assertIsArray($config);
        // The effective value, not the YAML shape: writing the key out as
        // `false` is behaviourally identical to leaving it absent, and a guard
        // that fails on a harmless rewrite teaches people to ignore it.
        self::assertFalse(
            $config['security']['access_decision_manager']['allow_if_all_abstain'] ?? false,
            'allow_if_all_abstain must remain false (the default). Relaxing it would hand anonymous '
            .'access to any attribute nobody votes on.',
        );
    }

    private function projectRoot(): string
    {
        return \dirname(__DIR__, 3);
    }
}
