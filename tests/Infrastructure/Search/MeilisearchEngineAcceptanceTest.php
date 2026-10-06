<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Search;

use App\Application\Search\CollectionDocument;
use App\Application\Search\ItemDocument;
use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\ValueObject\CollectionName;
use App\Domain\Collection\ValueObject\Theme;
use App\Domain\Common\ValueObject\OwnerId;
use App\Domain\Item\Entity\Item;
use App\Domain\Tag\Entity\Tag;
use App\Domain\Tag\ValueObject\TagName;
use App\Infrastructure\Search\IndexSettings;

/**
 * Proves against the real engine what the mocked adapter tests cannot: that
 * v1.54 accepts the exact `IndexSettings` payloads and the exact
 * `ItemDocument`/`CollectionDocument` shapes, and that a document written
 * through the adapter reads back identical.
 *
 * Documents are built through `fromEntity` from in-memory entities (no DB
 * involved), so this also proves the real pipeline output is engine-accepted,
 * not just hand-written literals.
 */
final class MeilisearchEngineAcceptanceTest extends EngineBackedSearchTestCase
{
    public function testItemsIndexAcceptsTheConfiguredSettings(): void
    {
        $this->waitForSettings($this->itemsIndex, [
            'searchableAttributes' => IndexSettings::ITEMS['searchableAttributes'],
            'filterableAttributes' => IndexSettings::ITEMS['filterableAttributes'],
        ]);

        $settings = $this->client->index($this->itemsIndex)->getSettings();

        self::assertSame(IndexSettings::ITEMS['searchableAttributes'], $settings['searchableAttributes']);
        self::assertSame(IndexSettings::ITEMS['filterableAttributes'], $settings['filterableAttributes']);
    }

    public function testCollectionsIndexAcceptsTheConfiguredSettings(): void
    {
        $this->waitForSettings($this->collectionsIndex, [
            'searchableAttributes' => IndexSettings::COLLECTIONS['searchableAttributes'],
            'filterableAttributes' => IndexSettings::COLLECTIONS['filterableAttributes'],
        ]);

        $settings = $this->client->index($this->collectionsIndex)->getSettings();

        self::assertSame(IndexSettings::COLLECTIONS['searchableAttributes'], $settings['searchableAttributes']);
        self::assertSame(IndexSettings::COLLECTIONS['filterableAttributes'], $settings['filterableAttributes']);
    }

    public function testItemDocumentRoundtripThroughTheAdapter(): void
    {
        $item = $this->createItem();
        $document = ItemDocument::fromEntity($item);

        $this->adapter->indexItem($document);

        // Order-insensitive: the engine returns fields in stored order, which
        // is not part of its contract, so assertSame could red on a reordering.
        self::assertEquals(
            $document->toArray(),
            $this->waitForDocument($this->itemsIndex, $document->id),
        );
    }

    public function testCollectionDocumentRoundtripThroughTheAdapter(): void
    {
        $collection = Collection::create(
            ownerId: OwnerId::generate(),
            name: CollectionName::fromString('Backlog of games'),
            theme: Theme::games(),
            description: 'Everything to play',
        );
        $document = CollectionDocument::fromEntity($collection);

        $this->adapter->indexCollection($document);

        self::assertEquals(
            $document->toArray(),
            $this->waitForDocument($this->collectionsIndex, $document->id),
        );
    }

    private function createItem(): Item
    {
        $collection = Collection::create(
            ownerId: OwnerId::generate(),
            name: CollectionName::fromString('Engine Acceptance'),
            theme: Theme::games(),
        );
        $item = Item::create($collection, 'Halo 3');
        $item->addTag(Tag::create(TagName::fromString('Games')));

        return $item;
    }
}
