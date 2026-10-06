<?php

declare(strict_types=1);

namespace App\Application\Search;

use App\Domain\Collection\ValueObject\CollectionId;
use App\Domain\Item\ValueObject\ItemId;

/**
 * Write side of the search index.
 *
 * Intent only: each method asks for a document to be indexed or removed. It
 * does NOT promise failure handling. The chosen implementation (Meilisearch)
 * swallows engine errors, but that is a property of that adapter, not of this
 * contract — a future queue-backed implementation must be allowed to fail
 * loudly. See ADR/0001-search-engine.md.
 *
 * Callers must invoke these AFTER the transaction commits. Indexing before the
 * commit would leave ghost documents behind on rollback, which is exactly the
 * flaw that got the official Doctrine bundle rejected. There is no universal
 * post-commit seam in this codebase (ItemService::create/update are wrapped in
 * transactional(), CollectionService has no transaction at all), so placing the
 * call is the caller's job — 6.4 and 6.5.
 *
 * The read side (query) is not part of this interface; endpoint work is 6.6.
 */
interface SearchIndexerInterface
{
    public function indexItem(ItemDocument $document): void;

    public function removeItem(ItemId $id): void;

    public function indexCollection(CollectionDocument $document): void;

    public function removeCollection(CollectionId $id): void;
}
