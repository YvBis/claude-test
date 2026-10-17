<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Api;

use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * Guards the completeness of the generated OpenAPI spec (task 8.5).
 *
 * Two existing guards stop short of this, and each gap is real:
 *
 * - `OpenApiSpecTest` freezes the contract that must be *reviewed* (operations,
 *   response codes, schemas, Error refs), but only in one direction: it reads the
 *   spec, it never asks the router what exists. A route added without an
 *   `#[OA\...]` annotation never enters the spec, and the freshness gate cannot
 *   see it either - the dump simply omits it and the committed file stays byte-
 *   fresh. Set equality with the router is the only check that catches that.
 * - `scripts/check-openapi-fresh.sh` proves the committed file equals a fresh
 *   dump. That says nothing about whether the dump describes the API the router
 *   serves.
 *
 * What is asserted here, and why each is a contract rather than a formality:
 *
 * 1. Every route the kernel exposes is documented, and every documented operation
 *    exists - both directions, exact paths and placeholder names included.
 *    Placeholder names are NOT normalized: the OA path is hand-written next to
 *    `#[Route]` and the freshness gate compares the dump against itself, so a
 *    typo like `{itemId}` -> `{id}` in an annotation would ship silently.
 * 2. Deprecation is an absolute statement, not a parity check: parity with the
 *    annotation is already guaranteed by freshness (both sides regenerate
 *    together), so only a frozen absolute value has signal. A client reading the
 *    spec must be able to see that the flat comment DELETE is going away.
 * 3. Security is per operation and reviewable: the operations callable without a
 *    token are an explicit, reasoned list, and everything else must carry Bearer.
 *    A new endpoint that forgets its token requirement cannot pass silently.
 * 4. Body-bearing operations declare a schema, and the body-less ones are pinned
 *    with their reason - a new POST that silently loses its body schema fails.
 *
 * Not asserted here: `enum`/`format` values (the freshness gate covers them) and
 * prose quality (summaries and descriptions only have to be present, not good).
 */
#[CoversNothing]
final class OpenApiCompletenessTest extends KernelTestCase
{
    /**
     * Keys a path item or an operation may carry that are not operations.
     * Shared by every loop below, so a future path-level `$ref`/`servers` (or a
     * new OpenAPI keyword) is skipped once, not in three places.
     */
    private const NON_OPERATION_KEYS = ['parameters', 'summary', 'description', '$ref', 'servers'];

    /**
     * Routes deliberately kept out of the API contract.
     *
     * Empty on purpose: `/health` and all 34 `/api*` routes are documented today.
     * Symfony-internal routes (names starting with `_`, e.g. `_preview_error` in
     * a debug kernel) are skipped structurally instead - see `routerOperations()`.
     *
     * @var array<string, string> "METHOD /path" => reason
     */
    private const NOT_DOCUMENTED = [];

    /**
     * Operations that legitimately declare no request body.
     *
     * @var array<string, string> "METHOD /path" => reason
     */
    private const BODY_LESS = [
        'POST /api/logout' => 'stateless JWT: the client drops the token, nothing is sent',
        'POST /api/items/{itemId}/likes' => 'the like is the request; the item id is in the path',
    ];

    /**
     * Operations callable without a token at all (`security: []`).
     *
     * @var array<string, string> "METHOD /path" => reason
     */
    private const PUBLIC_OPERATIONS = [
        'GET /health' => 'infrastructure probe, no user context',
        'POST /api/login' => 'issues the token',
        'POST /api/register' => 'creates the account',
    ];

    /**
     * Operations that work anonymously but accept a token when one is sent
     * (`security: [{Bearer: []}, []]`) - the fwd-7 guest-readable surface.
     *
     * @var array<string, string> "METHOD /path" => reason
     */
    private const OPTIONAL_AUTH_OPERATIONS = [
        'GET /api/collections' => 'fwd-7: guest may browse collections',
        'GET /api/collections/{id}' => 'fwd-7: guest may read one collection',
        'GET /api/collections/{collectionId}/items' => 'fwd-7: guest may browse a collection items',
        'GET /api/items/{id}' => 'fwd-7: guest may read one item',
        'GET /api/items/{itemId}/comments' => 'fwd-7: guest may read comments',
        'GET /api/items/{itemId}/likes' => 'fwd-7: guest may read likes',
        'GET /api/search/items' => 'fwd-7: guest may search items',
        'GET /api/search/collections' => 'fwd-7: guest may search collections',
    ];

    public function testSpecCoversExactlyTheRouterSurface(): void
    {
        $router = $this->routerOperations();
        $spec = $this->specOperations();

        $documented = \array_values(\array_diff($router, \array_keys(self::NOT_DOCUMENTED)));

        self::assertSame(
            [],
            self::missingFrom($documented, $spec),
            'These routes exist but are not in the spec. Add the OA annotation, or add the route to NOT_DOCUMENTED with a reason.',
        );
        self::assertSame(
            [],
            self::missingFrom($spec, $documented),
            'The spec documents operations the router does not serve. The annotation outlived its route.',
        );
    }

    public function testDeprecationIsStatedExplicitly(): void
    {
        $spec = $this->spec();

        // fwd-18 made the nested DELETE canonical and left the flat one deprecated.
        self::assertTrue(
            $spec['paths']['/api/comments/{id}']['delete']['deprecated'] ?? false,
            'The flat comment DELETE must carry deprecated: true so clients see it is going away.',
        );
        self::assertFalse(
            $spec['paths']['/api/items/{itemId}/comments/{id}']['delete']['deprecated'] ?? false,
            'The canonical nested DELETE must not be marked deprecated.',
        );

        $deprecated = [];
        foreach ($spec['paths'] as $path => $item) {
            foreach ($item as $method => $operation) {
                if (\is_array($operation) && true === ($operation['deprecated'] ?? false)) {
                    $deprecated[] = \strtoupper((string) $method).' '.$path;
                }
            }
        }

        self::assertSame(
            ['DELETE /api/comments/{id}'],
            $deprecated,
            'Exactly one operation is deprecated. A new one is a contract change and has to be stated here.',
        );
    }

    public function testSecurityIsDeclaredPerOperation(): void
    {
        $spec = $this->spec();

        $public = [];
        $optional = [];
        foreach ($spec['paths'] as $path => $item) {
            foreach ($item as $method => $operation) {
                if (!\is_array($operation) || \in_array($method, self::NON_OPERATION_KEYS, true)) {
                    continue;
                }

                $id = \strtoupper((string) $method).' '.$path;
                self::assertArrayHasKey(
                    'security',
                    $operation,
                    \sprintf('%s declares no security at all; say who may call it.', $id),
                );

                $security = $operation['security'];
                if ([] === $security) {
                    $public[] = $id;
                } elseif ([[], ['Bearer' => []]] === $security || [['Bearer' => []], []] === $security) {
                    $optional[] = $id;
                } else {
                    self::assertSame(
                        [['Bearer' => []]],
                        $security,
                        \sprintf('%s must require Bearer, or be listed as public/optional with a reason.', $id),
                    );
                }
            }
        }

        \sort($public, \SORT_STRING);
        \sort($optional, \SORT_STRING);
        $expectedPublic = \array_keys(self::PUBLIC_OPERATIONS);
        $expectedOptional = \array_keys(self::OPTIONAL_AUTH_OPERATIONS);
        \sort($expectedPublic, \SORT_STRING);
        \sort($expectedOptional, \SORT_STRING);

        self::assertSame($expectedPublic, $public, 'The token-free surface changed. Update PUBLIC_OPERATIONS with a reason.');
        self::assertSame($expectedOptional, $optional, 'The optional-auth surface changed. Update OPTIONAL_AUTH_OPERATIONS with a reason.');
    }

    public function testBodyBearingOperationsDeclareTheirSchema(): void
    {
        $spec = $this->spec();

        $bodyLess = [];
        foreach ($spec['paths'] as $path => $item) {
            foreach ($item as $method => $operation) {
                if (!\is_array($operation) || !\in_array(\strtoupper((string) $method), ['POST', 'PATCH', 'PUT'], true)) {
                    continue;
                }

                $id = \strtoupper((string) $method).' '.$path;
                if (!isset($operation['requestBody'])) {
                    $bodyLess[] = $id;
                    continue;
                }

                $schema = $operation['requestBody']['content']['application/json']['schema'] ?? null;
                self::assertIsArray($schema, \sprintf('%s has a requestBody without a JSON schema.', $id));

                if (isset($schema['$ref'])) {
                    self::assertArrayHasKey(
                        \str_replace('#/components/schemas/', '', (string) $schema['$ref']),
                        $spec['components']['schemas'] ?? [],
                        \sprintf('%s references an undeclared schema.', $id),
                    );
                    continue;
                }

                // Inline object or a resolvable $ref. A composite schema
                // (allOf/oneOf/anyOf) would land here as "no fields" - no such
                // body exists today, so the day one appears this is where the
                // contract gets stated, on purpose.
                self::assertNotEmpty(
                    $schema['properties'] ?? [],
                    \sprintf('%s documents a body but no fields.', $id),
                );
            }
        }

        \sort($bodyLess, \SORT_STRING);
        $expected = \array_keys(self::BODY_LESS);
        \sort($expected, \SORT_STRING);

        self::assertSame(
            $expected,
            $bodyLess,
            'The set of body-less POST/PATCH operations changed. Either add the schema or pin the new one in BODY_LESS with a reason.',
        );
    }

    public function testEveryOperationCarriesItsDocumentation(): void
    {
        $spec = $this->spec();

        foreach ($spec['paths'] as $path => $item) {
            foreach ($item as $method => $operation) {
                if (!\is_array($operation) || \in_array($method, self::NON_OPERATION_KEYS, true)) {
                    continue;
                }

                $id = \strtoupper((string) $method).' '.$path;
                foreach (['operationId', 'summary', 'description', 'tags'] as $field) {
                    self::assertNotEmpty(
                        $operation[$field] ?? null,
                        \sprintf('%s is missing "%s"; a generated client has nothing to go on.', $id, $field),
                    );
                }
            }
        }
    }

    public function testOperationSetComparisonRejectsDrift(): void
    {
        // Negative probe: the helper behind the equality test above must actually
        // report a difference, otherwise that test proves nothing.
        self::assertSame(
            ['POST /api/items'],
            self::missingFrom(['GET /api/items', 'POST /api/items'], ['GET /api/items']),
            'A route the spec does not document must be reported, by name.',
        );
        self::assertSame(
            [],
            self::missingFrom(['GET /api/items'], ['GET /api/items']),
            'Identical sets are not drift.',
        );
        self::assertSame(
            [],
            self::missingFrom(['GET /api/items', 'GET /api/items'], ['GET /api/items']),
            'A duplicated entry is not drift.',
        );
    }

    /**
     * @return list<string> "METHOD /path", Symfony-internal routes excluded
     */
    private function routerOperations(): array
    {
        self::bootKernel();

        $operations = [];
        foreach (static::getContainer()->get(RouterInterface::class)->getRouteCollection() as $name => $route) {
            // `_preview_error` and friends are framework plumbing, not API surface.
            if (\str_starts_with((string) $name, '_')) {
                continue;
            }

            foreach ($route->getMethods() ?: ['GET'] as $method) {
                $operations[] = \strtoupper($method).' '.$route->getPath();
            }
        }

        $operations = \array_values(\array_unique($operations));
        \sort($operations, \SORT_STRING);

        return $operations;
    }

    /**
     * @return list<string> "METHOD /path"
     */
    private function specOperations(): array
    {
        $operations = [];
        foreach ($this->spec()['paths'] as $path => $item) {
            foreach ($item as $method => $operation) {
                if (\is_array($operation) && !\in_array($method, self::NON_OPERATION_KEYS, true)) {
                    $operations[] = \strtoupper((string) $method).' '.$path;
                }
            }
        }

        $operations = \array_values(\array_unique($operations));
        \sort($operations, \SORT_STRING);

        return $operations;
    }

    /**
     * @return array<string, mixed>
     */
    private function spec(): array
    {
        $path = \dirname(__DIR__, 3).'/public/api/openapi.json';
        self::assertFileExists($path);

        $decoded = \json_decode((string) \file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertNotEmpty(
            $decoded['paths'] ?? [],
            'The spec has no paths at all - every assertion below would pass vacuously.',
        );

        return $decoded;
    }

    /**
     * @param list<string> $expected
     * @param list<string> $actual
     *
     * @return list<string> entries of $expected that $actual lacks
     */
    private static function missingFrom(array $expected, array $actual): array
    {
        return \array_values(\array_unique(\array_diff($expected, $actual)));
    }
}
