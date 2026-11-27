<?php

declare(strict_types=1);

namespace App\Tests\Application\Search;

use App\Application\Search\CollectionSearchHit;
use App\Application\Search\InvalidSearchDocumentException;
use App\Application\Search\ItemSearchHit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SearchHitContractTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function itemDocument(): array
    {
        return [
            'id' => '0192f0a0-0000-7000-8000-000000000001',
            'name' => 'Dune',
            'tags' => ['sci-fi', 'classic'],
            'collection_id' => '0192f0a0-0000-7000-8000-000000000002',
            'collection_name' => 'Books',
            'owner_id' => '0192f0a0-0000-7000-8000-000000000003',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function collectionDocument(): array
    {
        return [
            'id' => '0192f0a0-0000-7000-8000-000000000002',
            'name' => 'Books',
            'theme' => 'books',
            'description' => '',
            'owner_id' => '0192f0a0-0000-7000-8000-000000000003',
        ];
    }

    public function testItemHitRoundTripsAValidDocument(): void
    {
        $document = self::itemDocument();

        self::assertSame($document, ItemSearchHit::fromArray($document)->toArray());
    }

    public function testCollectionHitRoundTripsAValidDocument(): void
    {
        $document = self::collectionDocument();

        self::assertSame($document, CollectionSearchHit::fromArray($document)->toArray());
    }

    public function testItemHitAcceptsAnEmptyTagList(): void
    {
        $document = self::itemDocument();
        $document['tags'] = [];

        self::assertSame([], ItemSearchHit::fromArray($document)->tags);
    }

    public function testItemHitTreatsAbsentTagsAsNoTags(): void
    {
        $document = self::itemDocument();
        unset($document['tags']);

        self::assertSame([], ItemSearchHit::fromArray($document)->tags);
    }

    public function testItemHitTreatsNullTagsAsNoTags(): void
    {
        $document = self::itemDocument();
        $document['tags'] = null;

        self::assertSame([], ItemSearchHit::fromArray($document)->tags);
    }

    public function testItemHitAcceptsAnEmptyTagValue(): void
    {
        $document = self::itemDocument();
        $document['tags'] = [''];

        self::assertSame([''], ItemSearchHit::fromArray($document)->tags);
    }

    public function testCollectionHitKeepsAnEmptyDescription(): void
    {
        $document = self::collectionDocument();
        $document['description'] = '';

        self::assertSame('', CollectionSearchHit::fromArray($document)->description);
    }

    /**
     * @param array<string, mixed> $document
     */
    #[DataProvider('invalidItemDocuments')]
    public function testItemHitRejectsABrokenDocument(array $document, string $field): void
    {
        $this->expectException(InvalidSearchDocumentException::class);
        $this->expectExceptionMessage(\sprintf('App\Application\Search\ItemSearchHit received a document without a valid "%s" field', $field));

        ItemSearchHit::fromArray($document);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidItemDocuments(): iterable
    {
        foreach (['id', 'name', 'collection_id', 'collection_name', 'owner_id'] as $field) {
            $missing = self::itemDocument();
            unset($missing[$field]);
            yield 'missing '.$field => [$missing, $field];

            $empty = self::itemDocument();
            $empty[$field] = '';
            yield 'empty '.$field => [$empty, $field];

            $wrongType = self::itemDocument();
            $wrongType[$field] = 42;
            yield 'non-string '.$field => [$wrongType, $field];
        }

        $scalarTags = self::itemDocument();
        $scalarTags['tags'] = 'sci-fi';
        yield 'scalar tags' => [$scalarTags, 'tags'];

        $nestedTags = self::itemDocument();
        $nestedTags['tags'] = ['sci-fi', ['classic']];
        yield 'non-string tag' => [$nestedTags, 'tags'];
    }

    /**
     * @param array<string, mixed> $document
     */
    #[DataProvider('invalidCollectionDocuments')]
    public function testCollectionHitRejectsABrokenDocument(array $document, string $field): void
    {
        $this->expectException(InvalidSearchDocumentException::class);
        $this->expectExceptionMessage(\sprintf('App\Application\Search\CollectionSearchHit received a document without a valid "%s" field', $field));

        CollectionSearchHit::fromArray($document);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidCollectionDocuments(): iterable
    {
        foreach (['id', 'name', 'theme', 'owner_id'] as $field) {
            $missing = self::collectionDocument();
            unset($missing[$field]);
            yield 'missing '.$field => [$missing, $field];

            $empty = self::collectionDocument();
            $empty[$field] = '';
            yield 'empty '.$field => [$empty, $field];

            $wrongType = self::collectionDocument();
            $wrongType[$field] = 42;
            yield 'non-string '.$field => [$wrongType, $field];
        }

        $absentDescription = self::collectionDocument();
        unset($absentDescription['description']);
        yield 'missing description' => [$absentDescription, 'description'];

        $numericDescription = self::collectionDocument();
        $numericDescription['description'] = 0;
        yield 'non-string description' => [$numericDescription, 'description'];
    }
}
