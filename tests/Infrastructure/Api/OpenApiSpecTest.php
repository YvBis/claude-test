<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Api;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Guards the generated OpenAPI spec (public/api/openapi.json, fwd-23).
 *
 * Every 4xx/5xx response must reference the single shared Error schema —
 * no inline error objects, no schema-less error responses. Regenerate the
 * spec with `composer openapi:generate` after touching annotations.
 */
#[CoversNothing]
final class OpenApiSpecTest extends TestCase
{
    private const ERROR_REF = '#/components/schemas/Error';

    /**
     * @return array<string, mixed>
     */
    private static function spec(): array
    {
        $path = \dirname(__DIR__, 3).'/public/api/openapi.json';
        self::assertFileExists($path);

        $decoded = \json_decode((string) \file_get_contents($path), true);
        self::assertIsArray($decoded, 'openapi.json must decode to an array');

        /* @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function testErrorSchemaExistsWithRequiredError(): void
    {
        $schemas = self::spec()['components']['schemas'] ?? null;
        self::assertIsArray($schemas, 'components.schemas must exist');

        $error = $schemas['Error'] ?? null;
        self::assertIsArray($error, 'components.schemas.Error must exist');
        self::assertSame(['error'], $error['required'] ?? null, 'Error requires exactly [error]');
        self::assertSame('string', $error['properties']['error']['type'] ?? null);
        self::assertSame('string', $error['properties']['message']['type'] ?? null);
        self::assertSame('array', $error['properties']['details']['type'] ?? null);
        self::assertSame('string', $error['properties']['details']['items']['type'] ?? null);
    }

    public function testEveryErrorResponseReferencesSharedErrorSchema(): void
    {
        $paths = self::spec()['paths'] ?? null;
        self::assertIsArray($paths, 'paths must exist');
        self::assertNotEmpty($paths, 'spec must document at least one path');

        $checked = 0;
        foreach ($paths as $path => $operations) {
            self::assertIsArray($operations);
            foreach ($operations as $method => $operation) {
                foreach ($operation['responses'] ?? [] as $code => $response) {
                    if ((int) $code < 400) {
                        continue;
                    }
                    ++$checked;
                    $schema = $response['content']['application/json']['schema'] ?? null;
                    self::assertIsArray($schema);
                    self::assertSame(
                        self::ERROR_REF,
                        $schema['$ref'] ?? null,
                        \sprintf('%s %s %s must reference the shared Error schema', $method, $path, $code),
                    );
                    self::assertArrayNotHasKey('properties', $schema, 'error responses must not carry inline object schemas');
                    self::assertArrayNotHasKey('type', $schema, 'error responses must not carry inline type schemas');
                }
            }
        }

        self::assertGreaterThan(0, $checked, 'spec must contain at least one error response');
    }
}
