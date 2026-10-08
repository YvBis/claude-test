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
 *
 * The frozen constants below are the BLOCK set from the 5.35 spec-diff
 * review: a bump of `nelmio/api-doc-bundle` or a regenerated spec must not
 * silently drop an operation, a response code, a schema, a required field,
 * an operationId or a parameter. Any change here is a contract change and
 * has to be reviewed as one — the point is that the diff surfaces as a
 * failing test naming the exact key, instead of as an unreviewable
 * one-line JSON blob.
 *
 * Not frozen here: `enum` and `format` values inside schemas. Those are
 * covered by the byte-freshness gate (scripts/check-openapi-fresh.sh): any
 * change to them fails CI, because the committed spec stops matching a fresh
 * dump. The constants cover what must be reviewed *deliberately*; the gate
 * covers what must not change unnoticed.
 */
#[CoversNothing]
final class OpenApiSpecTest extends TestCase
{
    private const ERROR_REF = '#/components/schemas/Error';

    private const SCHEMA_REF_PREFIX = '#/components/schemas/';

    private const SECURITY_SCHEMES = [
        'Bearer' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT'],
    ];

    private const RESPONSE_CODES = [
        'DELETE /api/collections/{id}' => ['204', '401', '403', '404'],
        'DELETE /api/comments/{id}' => ['204', '401', '403', '404'],
        'DELETE /api/items/{id}' => ['204', '401', '403', '404'],
        'DELETE /api/items/{itemId}/comments/{id}' => ['204', '401', '403', '404'],
        'DELETE /api/items/{itemId}/likes' => ['204', '401', '404'],
        'DELETE /api/likes/{id}' => ['204', '401', '403', '404'],
        'GET /api/collections' => ['200', '400'],
        'GET /api/collections/{collectionId}/items' => ['200', '400', '404'],
        'GET /api/collections/{id}' => ['200', '404'],
        'GET /api/comments' => ['200', '400', '401'],
        'GET /api/items' => ['200', '400', '401'],
        'GET /api/items/{id}' => ['200', '404'],
        'GET /api/items/{itemId}/comments' => ['200', '400', '404'],
        'GET /api/items/{itemId}/likes' => ['200', '400', '404'],
        'GET /api/likes' => ['200', '400', '401'],
        'GET /api/search/collections' => ['200', '400', '401'],
        'GET /api/search/items' => ['200', '400', '401'],
        'GET /api/tags' => ['200', '400', '401'],
        'GET /health' => ['200'],
        'PATCH /api/collections/{id}' => ['200', '400', '401', '403', '404', '422'],
        'PATCH /api/comments/{id}' => ['200', '400', '401', '403', '404', '422'],
        'PATCH /api/items/{id}' => ['200', '400', '401', '403', '404', '422'],
        'POST /api/collections' => ['201', '400', '401', '422'],
        'POST /api/collections/{collectionId}/items' => ['201', '400', '401', '403', '404', '422'],
        'POST /api/items/{itemId}/comments' => ['201', '400', '401', '404', '422'],
        'POST /api/items/{itemId}/likes' => ['200', '401', '404'],
        'POST /api/login' => ['200', '400', '401', '403', '422'],
        'POST /api/logout' => ['204', '401'],
        'POST /api/register' => ['201', '400', '409', '422'],
    ];

    private const SCHEMA_REQUIRED_PATHS = [
        'schemas.CollectionSearchHit' => ['description', 'id', 'name', 'owner_id', 'theme'],
        'schemas.Comment' => ['content', 'created_at', 'id', 'item_id', 'owner_id', 'owner_name', 'updated_at'],
        'schemas.Error' => ['error'],
        'schemas.Item' => ['collection_id', 'created_at', 'id', 'name', 'slots', 'tags', 'updated_at'],
        'schemas.Item.properties.slots.items' => ['slot', 'type', 'value'],
        'schemas.Item.properties.tags.items' => ['id', 'name'],
        'schemas.ItemDetail.allOf.1' => ['comments_count', 'liked_by_me', 'likes_count'],
        'schemas.ItemSearchHit' => ['collection_id', 'collection_name', 'id', 'name', 'owner_id', 'tags'],
        'schemas.Like' => ['created_at', 'id', 'item_id', 'owner_id', 'owner_name'],
        'schemas.Tag' => ['id', 'name'],
    ];

    private const OPERATION_IDS = [
        'DELETE /api/collections/{id}' => 'delete_api_collection_delete',
        'DELETE /api/comments/{id}' => 'delete_api_comment_delete',
        'DELETE /api/items/{id}' => 'delete_api_item_delete',
        'DELETE /api/items/{itemId}/comments/{id}' => 'delete_api_comment_delete_in_item',
        'DELETE /api/items/{itemId}/likes' => 'delete_api_like_delete_own',
        'DELETE /api/likes/{id}' => 'delete_api_like_delete',
        'GET /api/collections' => 'get_api_collection_list',
        'GET /api/collections/{collectionId}/items' => 'get_api_item_list_by_collection',
        'GET /api/collections/{id}' => 'get_api_collection_get',
        'GET /api/comments' => 'get_api_comment_list_own',
        'GET /api/items' => 'get_api_item_list_own',
        'GET /api/items/{id}' => 'get_api_item_get',
        'GET /api/items/{itemId}/comments' => 'get_api_comment_list_by_item',
        'GET /api/items/{itemId}/likes' => 'get_api_like_list_by_item',
        'GET /api/likes' => 'get_api_like_list_own',
        'GET /api/search/collections' => 'get_api_search_collections',
        'GET /api/search/items' => 'get_api_search_items',
        'GET /api/tags' => 'get_api_tag_list',
        'GET /health' => 'get_health',
        'PATCH /api/collections/{id}' => 'patch_api_collection_update',
        'PATCH /api/comments/{id}' => 'patch_api_comment_update',
        'PATCH /api/items/{id}' => 'patch_api_item_update',
        'POST /api/collections' => 'post_api_collection_create',
        'POST /api/collections/{collectionId}/items' => 'post_api_item_create',
        'POST /api/items/{itemId}/comments' => 'post_api_comment_create',
        'POST /api/items/{itemId}/likes' => 'post_api_like_create',
        'POST /api/login' => 'post_api_login',
        'POST /api/logout' => 'post_api_logout',
        'POST /api/register' => 'post_api_register',
    ];

    private const PARAMETERS = [
        'DELETE /api/collections/{id}' => ['id in=path req=true'],
        'DELETE /api/comments/{id}' => ['id in=path req=true'],
        'DELETE /api/items/{id}' => ['id in=path req=true'],
        'DELETE /api/items/{itemId}/comments/{id}' => ['id in=path req=true', 'itemId in=path req=true'],
        'DELETE /api/items/{itemId}/likes' => ['itemId in=path req=true'],
        'DELETE /api/likes/{id}' => ['id in=path req=true'],
        'GET /api/collections' => ['limit in=query req=false', 'offset in=query req=false', 'owner in=query req=false'],
        'GET /api/collections/{collectionId}/items' => ['collectionId in=path req=true', 'limit in=query req=false', 'name in=query req=false', 'offset in=query req=false', 'tags[] in=query req=false'],
        'GET /api/collections/{id}' => ['id in=path req=true'],
        'GET /api/comments' => ['limit in=query req=false', 'offset in=query req=false'],
        'GET /api/items' => ['limit in=query req=false', 'name in=query req=false', 'offset in=query req=false', 'tags[] in=query req=false'],
        'GET /api/items/{id}' => ['id in=path req=true'],
        'GET /api/items/{itemId}/comments' => ['itemId in=path req=true', 'limit in=query req=false', 'offset in=query req=false'],
        'GET /api/items/{itemId}/likes' => ['itemId in=path req=true', 'limit in=query req=false', 'offset in=query req=false'],
        'GET /api/likes' => ['limit in=query req=false', 'offset in=query req=false'],
        'GET /api/search/collections' => ['limit in=query req=false', 'offset in=query req=false', 'owner in=query req=false', 'q in=query req=true', 'theme in=query req=false'],
        'GET /api/search/items' => ['collection_id in=query req=false', 'limit in=query req=false', 'offset in=query req=false', 'owner in=query req=false', 'q in=query req=true', 'tags[] in=query req=false'],
        'GET /api/tags' => ['limit in=query req=false', 'offset in=query req=false', 'search in=query req=false'],
        'GET /health' => [],
        'PATCH /api/collections/{id}' => ['id in=path req=true'],
        'PATCH /api/comments/{id}' => ['id in=path req=true'],
        'PATCH /api/items/{id}' => ['id in=path req=true'],
        'POST /api/collections' => [],
        'POST /api/collections/{collectionId}/items' => ['collectionId in=path req=true'],
        'POST /api/items/{itemId}/comments' => ['itemId in=path req=true'],
        'POST /api/items/{itemId}/likes' => ['itemId in=path req=true'],
        'POST /api/login' => [],
        'POST /api/logout' => [],
        'POST /api/register' => [],
    ];

    private const INTENTIONAL_CHANGE = 'Intended contract change: update the frozen constant in the same commit that regenerates the spec.';

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

    public function testEverySchemaRefResolvesToADeclaredSchema(): void
    {
        $spec = self::spec();
        $declared = \array_keys($spec['components']['schemas'] ?? []);
        self::assertNotEmpty($declared, 'components.schemas must not be empty');

        $checked = 0;
        foreach (self::collectRefs($spec) as $ref) {
            ++$checked;
            self::assertStringStartsWith(self::SCHEMA_REF_PREFIX, $ref, \sprintf('unexpected non-schema $ref "%s"', $ref));
            $name = \substr($ref, \strlen(self::SCHEMA_REF_PREFIX));
            self::assertContains($name, $declared, \sprintf('$ref "%s" points at an undeclared schema', $ref));
        }

        self::assertGreaterThan(0, $checked, 'spec must contain at least one $ref');
    }

    public function testOperationsAndResponseCodesAreFrozen(): void
    {
        [$codes] = self::operationMaps();

        self::assertSame(self::RESPONSE_CODES, $codes, self::INTENTIONAL_CHANGE);
    }

    public function testOperationIdsAreFrozen(): void
    {
        [, $operationIds] = self::operationMaps();

        self::assertSame(self::OPERATION_IDS, $operationIds, self::INTENTIONAL_CHANGE);
    }

    public function testParametersAreFrozen(): void
    {
        self::assertSame(self::PARAMETERS, self::operationMaps()[2], self::INTENTIONAL_CHANGE);
    }

    public function testEveryRequiredArrayIsFrozen(): void
    {
        $actual = [];
        self::collectRequired(self::spec()['components']['schemas'] ?? [], 'schemas', $actual);
        \ksort($actual);

        self::assertSame(self::SCHEMA_REQUIRED_PATHS, $actual, self::INTENTIONAL_CHANGE);
    }

    public function testSecuritySchemesAreFrozen(): void
    {
        $actual = [];
        foreach (self::spec()['components']['securitySchemes'] ?? [] as $name => $scheme) {
            $actual[(string) $name] = [
                'type' => $scheme['type'] ?? null,
                'scheme' => $scheme['scheme'] ?? null,
                'bearerFormat' => $scheme['bearerFormat'] ?? null,
            ];
        }
        \ksort($actual);

        self::assertSame(self::SECURITY_SCHEMES, $actual, self::INTENTIONAL_CHANGE);
    }

    /**
     * @return array{0: array<string, list<string>>, 1: array<string, string>, 2: array<string, list<string>>}
     */
    private static function operationMaps(): array
    {
        $codes = [];
        $operationIds = [];
        $parameters = [];
        foreach (self::spec()['paths'] ?? [] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                if (!\is_array($operation)) {
                    continue;
                }
                $key = \strtoupper((string) $method).' '.$path;
                $codes[$key] = \array_map(\strval(...), \array_keys($operation['responses'] ?? []));
                \sort($codes[$key]);
                $operationIds[$key] = (string) ($operation['operationId'] ?? '');

                $flat = [];
                foreach ($operation['parameters'] ?? [] as $parameter) {
                    $flat[] = \sprintf(
                        '%s in=%s req=%s',
                        $parameter['name'] ?? '?',
                        $parameter['in'] ?? '?',
                        true === ($parameter['required'] ?? false) ? 'true' : 'false',
                    );
                }
                \sort($flat);
                $parameters[$key] = $flat;
            }
        }
        \ksort($codes);
        \ksort($operationIds);
        \ksort($parameters);

        return [$codes, $operationIds, $parameters];
    }

    /**
     * @param array<string, mixed>        $node
     * @param array<string, list<string>> $acc
     */
    private static function collectRequired(array $node, string $path, array &$acc): void
    {
        if (isset($node['required']) && \is_array($node['required'])) {
            $list = \array_map(\strval(...), $node['required']);
            \sort($list);
            $acc[$path] = $list;
        }
        foreach ($node as $key => $value) {
            if (\is_array($value)) {
                self::collectRequired($value, $path.'.'.$key, $acc);
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function collectRefs(mixed $node, array $acc = []): array
    {
        if (\is_array($node)) {
            foreach ($node as $key => $value) {
                if ('$ref' === $key && \is_string($value)) {
                    $acc[] = $value;
                    continue;
                }
                $acc = self::collectRefs($value, $acc);
            }
        }

        return $acc;
    }
}
