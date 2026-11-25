<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Ci;

use App\Domain\User\Entity\User;
use App\Domain\User\ValueObject\PasswordHash;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
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

    public function testTestEnvironmentDeclaresNoPasswordHashers(): void
    {
        foreach ($this->securityConfigFiles() as $file) {
            $config = Yaml::parseFile($file);
            self::assertIsArray($config);

            foreach (\array_keys($config) as $key) {
                if (!\str_starts_with((string) $key, 'when@')) {
                    continue;
                }

                self::assertNull(
                    $config[$key]['security']['password_hashers'] ?? null,
                    \sprintf(
                        '%s must not override security.password_hashers under %s. The domain hashes passwords itself '
                        .'through PasswordHash::createFromPlain() at BCRYPT_COST, so a framework-hasher override has no '
                        .'effect on any test run and only documents a cost that never applies (10.13).',
                        $file,
                        (string) $key,
                    ),
                );
            }
        }
    }

    public function testUserHasherIsBcryptAtTheDomainCostAndIsSelectedFirst(): void
    {
        $baseFile = $this->projectRoot().'/config/packages/security.yaml';
        $baseConfig = Yaml::parseFile($baseFile);
        self::assertIsArray($baseConfig);
        self::assertArrayHasKey(
            'password_hashers',
            $baseConfig['security'] ?? [],
            'The base security.yaml must declare security.password_hashers. Without it the framework falls back '
            .'to a bundle default and the domain constant stops being mirrored anywhere (10.13).',
        );

        foreach ($this->securityConfigFiles() as $file) {
            $config = Yaml::parseFile($file);
            self::assertIsArray($config);

            $hashers = $config['security']['password_hashers'] ?? null;
            if (!\is_array($hashers)) {
                continue;
            }

            $firstKey = \array_key_first($hashers);
            self::assertSame(
                User::class,
                $firstKey,
                \sprintf(
                    '%s must list %s first under security.password_hashers. PasswordHasherFactory stops at the first '
                    .'matching class (PasswordHasherFactory.php:44-49) and User implements '
                    .'PasswordAuthenticatedUserInterface, so a generic entry listed first would shadow this per-class '
                    .'pin and let YAML key order decide the hashing policy. Today both paths happen to resolve to '
                    .'bcrypt-13, which is exactly why the drift would stay invisible until one side changes (10.13).',
                    $file,
                    User::class,
                ),
            );

            self::assertSame('bcrypt', $hashers[$firstKey]['algorithm'] ?? null);

            self::assertSame(
                PasswordHash::BCRYPT_COST,
                $hashers[$firstKey]['cost'] ?? null,
                \sprintf(
                    'The framework hasher cost in %s must mirror PasswordHash::BCRYPT_COST. Two independent numbers '
                    .'for the same policy drift: needsRehash() compares against the domain constant.',
                    $file,
                ),
            );

            self::assertArrayHasKey(
                PasswordAuthenticatedUserInterface::class,
                $hashers,
                \sprintf(
                    '%s must keep the PasswordAuthenticatedUserInterface fallback. With no matching entry '
                    .'PasswordHasherFactory throws at runtime (PasswordHasherFactory.php:53-55), not at compile time.',
                    $file,
                ),
            );
        }
    }

    /**
     * @return list<string>
     */
    private function securityConfigFiles(): array
    {
        $files = \glob($this->projectRoot().'/config/packages/{,*/}security.yaml', GLOB_BRACE);
        self::assertIsArray($files);
        self::assertNotSame([], $files, 'No security.yaml was found under config/packages.');

        return \array_values($files);
    }

    private function projectRoot(): string
    {
        return \dirname(__DIR__, 3);
    }
}
