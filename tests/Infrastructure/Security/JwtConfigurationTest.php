<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Security;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the Lexik JWT encoder configuration.
 *
 * The `encoder.crypto_engine` key is deprecated since Lexik 2.5 (built-in
 * encoders support OpenSSL only and "openssl" is the default), so it must not
 * come back into the application configuration. A static check is the only
 * regression net for this class of finding: the symfony-lsp checker reports
 * config deprecations only in its runtime mode, which CI does not run yet
 * (source-only pilot — see Roadmap 5.13).
 *
 * Scope note: only the YAML files under `config/packages/` are application
 * configuration. The tracked `config/reference.php` is a generated dump of the
 * bundles' configuration *schemas*, so deprecated keys still listed there are
 * upstream (bundle) concerns — see Roadmap fwd-11.
 */
final class JwtConfigurationTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $config;

    protected function setUp(): void
    {
        // __DIR__ is tests/Infrastructure/Security, so three levels up is the repository root.
        $this->config = Yaml::parseFile(\dirname(__DIR__, 3).'/config/packages/lexik_jwt_authentication.yaml');
    }

    public function testEncoderDoesNotSetDeprecatedCryptoEngine(): void
    {
        $encoder = $this->config['lexik_jwt_authentication']['encoder'] ?? null;
        self::assertIsArray($encoder);

        self::assertArrayNotHasKey(
            'crypto_engine',
            $encoder,
            'encoder.crypto_engine is deprecated since Lexik 2.5 (built-in encoders support OpenSSL only and '
            .'"openssl" is the default) — drop the key instead of restoring it.',
        );
    }

    public function testEncoderKeepsSignatureAlgorithm(): void
    {
        self::assertSame(
            'RS256',
            $this->config['lexik_jwt_authentication']['encoder']['signature_algorithm'] ?? null,
            'Removing the deprecated crypto_engine key must not drop the signature algorithm.',
        );
    }

    public function testKeysAndPassPhraseRemainConfigured(): void
    {
        $jwt = $this->config['lexik_jwt_authentication'] ?? [];

        self::assertSame('%kernel.project_dir%/config/jwt/private.pem', $jwt['secret_key'] ?? null);
        self::assertSame('%kernel.project_dir%/config/jwt/public.pem', $jwt['public_key'] ?? null);
        self::assertSame('%env(JWT_PASSPHRASE)%', $jwt['pass_phrase'] ?? null);
    }
}
