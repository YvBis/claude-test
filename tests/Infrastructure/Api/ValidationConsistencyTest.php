<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Api;

use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\ValueObject\CollectionName;
use App\Domain\Collection\ValueObject\FieldType;
use App\Domain\Collection\ValueObject\Theme;
use App\Domain\Comment\ValueObject\CommentContent;
use App\Domain\Common\Constant\SlotLimits;
use App\Domain\Item\Entity\Item;
use App\Domain\User\Entity\User;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\PasswordHash;
use App\Domain\User\ValueObject\Role;
use App\Infrastructure\Api\Controller\AbstractApiController;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Pins every validation bound the generated spec documents to its domain
 * source (task 8.6, variant A).
 *
 * Background: the spec is hand-assembled from controller `OA\*` attributes and
 * `config/packages/dev/api_doc.yaml` — Nelmio never reads the DTO `Assert`
 * bounds, and YAML cannot reference PHP constants. After the 8.6 tidy-up the
 * numbers live once (domain constants, referenced from DTOs and from the
 * `OA\*` integer attributes), but two copies remain literals by necessity: the
 * `OA\*` `enum` arrays (attribute arguments cannot call `values()`) and the
 * YAML file. This test is the mechanical link for those: it walks the
 * *generated* spec, collects every request-side bound and every component
 * bound, and requires each to equal the domain value it documents.
 *
 * The key-set comparison is the point, not just the values: a new bound added
 * to an annotation or to the YAML without an entry here fails with "unknown",
 * so the table cannot silently go stale. A value that drifts from its domain
 * source fails with the pointer that names it.
 *
 * Out of scope on purpose: `default:` pagination values (controller-local, not
 * domain bounds), response schemas (nothing to enforce there), prose.
 */
#[CoversNothing]
final class ValidationConsistencyTest extends TestCase
{
    public function testEverySpecBoundMatchesItsDomainSource(): void
    {
        $diffs = self::diffBounds(self::expectedBounds(), $this->specBounds());

        self::assertSame(
            [],
            $diffs,
            "The spec documents bounds this table does not know, lost some it knew, or drifted from a domain source:\n".\implode("\n", $diffs),
        );
    }

    public function testBoundComparisonRejectsDrift(): void
    {
        // Negative probe: the comparison the guard above relies on must actually
        // fail on a wrong value, an unknown pointer and a missing entry —
        // otherwise the guard proves nothing.
        $expected = [
            'p/maxLength' => ['scalar', 100],
            'p/enum' => ['enum', ['a', 'b']],
        ];

        self::assertSame([], self::diffBounds($expected, ['p/maxLength' => 100, 'p/enum' => ['b', 'a']]));
        self::assertNotSame([], self::diffBounds($expected, ['p/maxLength' => 101, 'p/enum' => ['a', 'b']]));
        self::assertNotSame([], self::diffBounds($expected, ['p/maxLength' => 100, 'p/enum' => ['a', 'c']]));
        self::assertNotSame([], self::diffBounds($expected, ['p/maxLength' => 100, 'p/enum' => ['a', 'b'], 'q/maxLength' => 5]));
        self::assertNotSame([], self::diffBounds($expected, ['p/maxLength' => 100]));
    }

    /**
     * @param array<string, array{0: string, 1: mixed}> $expected pointer => [kind, wanted]
     * @param array<string, mixed>                      $actual   pointer => got
     *
     * @return list<string> human-readable differences; empty means "same set, same values"
     */
    private static function diffBounds(array $expected, array $actual): array
    {
        $diffs = [];

        foreach ($expected as $pointer => $reference) {
            if (!\array_key_exists($pointer, $actual)) {
                $diffs[] = $pointer.' is expected but missing';
                continue;
            }

            [$kind, $wanted] = $reference;
            $got = $actual[$pointer];

            if ('enum' === $kind) {
                $wantedList = (array) $wanted;
                $gotList = (array) $got;
                \sort($wantedList, \SORT_STRING);
                \sort($gotList, \SORT_STRING);

                if ($wantedList !== $gotList) {
                    $diffs[] = $pointer.' documents enum values the domain no longer has';
                }
                continue;
            }

            if ($wanted !== $got) {
                $diffs[] = $pointer.' differs';
            }
        }

        foreach ($actual as $pointer => $_) {
            if (!\array_key_exists($pointer, $expected)) {
                $diffs[] = $pointer.' is unknown';
            }
        }

        return $diffs;
    }

    /**
     * Every bound the spec may document, with the domain value it must equal.
     * Pointers use the walker's format: "paths <METHOD> <path> requestBody/…",
     * "paths <METHOD> <path> parameter[<name>]/…", "components <Schema>/…".
     *
     * @return array<string, array{0: string, 1: mixed}>
     */
    private static function expectedBounds(): array
    {
        // Pagination is controller-local mechanics (AbstractApiController), not
        // a domain bound — pinned here so a changed default/maximum is a
        // conscious edit, not drift.
        $pagination = [
            'parameter[limit]/minimum' => ['scalar', AbstractApiController::MIN_LIMIT],
            'parameter[limit]/maximum' => ['scalar', AbstractApiController::MAX_LIMIT],
            'parameter[offset]/minimum' => ['scalar', AbstractApiController::DEFAULT_OFFSET],
        ];

        $bounds = [];

        foreach (self::paginatedOperations() as $operation) {
            foreach ($pagination as $suffix => $reference) {
                $bounds['paths '.$operation.' '.$suffix] = $reference;
            }
        }

        return $bounds + [
            'paths POST /api/admin/users requestBody/properties/name/minLength' => ['scalar', User::MIN_NAME_LENGTH],
            'paths POST /api/admin/users requestBody/properties/name/maxLength' => ['scalar', User::MAX_NAME_LENGTH],
            // The committed spec used to omit maxLength here while the DTO has
            // always enforced it: adding the property is the one deliberate
            // documentation fix of the 8.6 tidy-up (proved by the spec diff).
            'paths POST /api/admin/users requestBody/properties/email/maxLength' => ['scalar', Email::MAX_LENGTH],
            'paths POST /api/admin/users requestBody/properties/password/minLength' => ['scalar', PasswordHash::MIN_PLAIN_LENGTH],
            'paths POST /api/admin/users requestBody/properties/password/maxLength' => ['scalar', PasswordHash::MAX_PLAIN_LENGTH],
            'paths POST /api/admin/users requestBody/properties/role/enum' => ['enum', Role::values()],
            'paths PATCH /api/admin/users/{id} requestBody/properties/role/enum' => ['enum', Role::values()],
            'paths POST /api/collections requestBody/properties/name/minLength' => ['scalar', CollectionName::MIN_NAME_LENGTH],
            'paths POST /api/collections requestBody/properties/name/maxLength' => ['scalar', CollectionName::MAX_NAME_LENGTH],
            'paths POST /api/collections requestBody/properties/theme/enum' => ['enum', Theme::values()],
            'paths POST /api/collections requestBody/properties/description/maxLength' => ['scalar', Collection::MAX_DESCRIPTION_LENGTH],
            'paths POST /api/collections requestBody/properties/image/maxLength' => ['scalar', Collection::MAX_IMAGE_LENGTH],
            'paths PATCH /api/collections/{id} requestBody/properties/name/minLength' => ['scalar', CollectionName::MIN_NAME_LENGTH],
            'paths PATCH /api/collections/{id} requestBody/properties/name/maxLength' => ['scalar', CollectionName::MAX_NAME_LENGTH],
            'paths PATCH /api/collections/{id} requestBody/properties/description/maxLength' => ['scalar', Collection::MAX_DESCRIPTION_LENGTH],
            'paths PATCH /api/collections/{id} requestBody/properties/image/maxLength' => ['scalar', Collection::MAX_IMAGE_LENGTH],
            'paths POST /api/items/{itemId}/comments requestBody/properties/content/maxLength' => ['scalar', CommentContent::MAX_LENGTH],
            'paths PATCH /api/comments/{id} requestBody/properties/content/maxLength' => ['scalar', CommentContent::MAX_LENGTH],
            'paths POST /api/collections/{collectionId}/items requestBody/properties/name/maxLength' => ['scalar', Item::MAX_NAME_LENGTH],
            'paths POST /api/collections/{collectionId}/items requestBody/properties/tags/maxItems' => ['scalar', Item::MAX_TAGS_PER_ITEM],
            'paths POST /api/collections/{collectionId}/items requestBody/properties/slots/maxItems' => ['scalar', SlotLimits::MAX_SLOTS_PER_ITEM],
            'paths POST /api/collections/{collectionId}/items requestBody/properties/slots/items/properties/type/enum' => ['enum', FieldType::values()],
            // Slot indexes are 1-based by structure, not by a business bound:
            // the only domain number here is the maximum.
            'paths POST /api/collections/{collectionId}/items requestBody/properties/slots/items/properties/slot/minimum' => ['scalar', 1],
            'paths POST /api/collections/{collectionId}/items requestBody/properties/slots/items/properties/slot/maximum' => ['scalar', SlotLimits::MAX_SLOTS_PER_TYPE],
            'paths PATCH /api/items/{id} requestBody/properties/name/maxLength' => ['scalar', Item::MAX_NAME_LENGTH],
            'paths PATCH /api/items/{id} requestBody/properties/tags/maxItems' => ['scalar', Item::MAX_TAGS_PER_ITEM],
            'paths PATCH /api/items/{id} requestBody/properties/slots/maxItems' => ['scalar', SlotLimits::MAX_SLOTS_PER_ITEM],
            'paths POST /api/login requestBody/properties/email/maxLength' => ['scalar', Email::MAX_LENGTH],
            'paths POST /api/login requestBody/properties/password/minLength' => ['scalar', PasswordHash::MIN_PLAIN_LENGTH],
            'paths POST /api/login requestBody/properties/password/maxLength' => ['scalar', PasswordHash::MAX_PLAIN_LENGTH],
            'paths POST /api/register requestBody/properties/name/minLength' => ['scalar', User::MIN_NAME_LENGTH],
            'paths POST /api/register requestBody/properties/name/maxLength' => ['scalar', User::MAX_NAME_LENGTH],
            'paths POST /api/register requestBody/properties/email/maxLength' => ['scalar', Email::MAX_LENGTH],
            'paths POST /api/register requestBody/properties/password/minLength' => ['scalar', PasswordHash::MIN_PLAIN_LENGTH],
            'paths POST /api/register requestBody/properties/password/maxLength' => ['scalar', PasswordHash::MAX_PLAIN_LENGTH],
            'components User/properties/role/enum' => ['enum', Role::values()],
            'components Item/properties/slots/items/properties/type/enum' => ['enum', FieldType::values()],
            'components Item/properties/slots/items/properties/slot/minimum' => ['scalar', 1],
            'components Item/properties/slots/items/properties/slot/maximum' => ['scalar', SlotLimits::MAX_SLOTS_PER_TYPE],
            'components CollectionSearchHit/properties/theme/enum' => ['enum', Theme::values()],
            'components Comment/properties/content/maxLength' => ['scalar', CommentContent::MAX_LENGTH],
        ];
    }

    /**
     * @return list<string> "METHOD /path" for every GET with limit/offset
     */
    private static function paginatedOperations(): array
    {
        return [
            'GET /api/admin/comments',
            'GET /api/admin/likes',
            'GET /api/admin/users',
            'GET /api/collections',
            'GET /api/items/{itemId}/comments',
            'GET /api/comments',
            'GET /api/collections/{collectionId}/items',
            'GET /api/items',
            'GET /api/items/{itemId}/likes',
            'GET /api/likes',
            'GET /api/search/items',
            'GET /api/search/collections',
            'GET /api/tags',
        ];
    }

    /**
     * @return array<string, mixed> pointer => value, same walk as the probe
     */
    private function specBounds(): array
    {
        $path = \dirname(__DIR__, 3).'/public/api/openapi.json';
        self::assertFileExists($path);

        $spec = \json_decode((string) \file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($spec);

        $found = [];
        $walk = function (array $schema, string $pointer) use (&$walk, &$found): void {
            foreach (['maxLength', 'minLength', 'maxItems', 'enum', 'minimum', 'maximum'] as $kind) {
                if (\array_key_exists($kind, $schema)) {
                    $found[$pointer.'/'.$kind] = $schema[$kind];
                }
            }
            foreach ($schema['properties'] ?? [] as $name => $sub) {
                if (\is_array($sub)) {
                    $walk($sub, $pointer.'/properties/'.$name);
                }
            }
            if (isset($schema['items']) && \is_array($schema['items'])) {
                $walk($schema['items'], $pointer.'/items');
            }
        };

        foreach ($spec['paths'] as $route => $item) {
            foreach ($item as $method => $operation) {
                if (!\is_array($operation) || \in_array($method, ['parameters', 'summary', 'description', '$ref', 'servers'], true)) {
                    continue;
                }
                $base = 'paths '.\strtoupper((string) $method).' '.$route;
                $body = $operation['requestBody']['content']['application/json']['schema'] ?? null;
                if (\is_array($body)) {
                    $walk($body, $base.' requestBody');
                }
                foreach ($operation['parameters'] ?? [] as $index => $parameter) {
                    if (isset($parameter['schema']) && \is_array($parameter['schema'])) {
                        $walk($parameter['schema'], $base.' parameter['.($parameter['name'] ?? $index).']');
                    }
                }
            }
        }

        foreach ($spec['components']['schemas'] ?? [] as $name => $schema) {
            if (\is_array($schema)) {
                $walk($schema, 'components '.$name);
            }
        }

        return $found;
    }
}
