<?php

declare(strict_types=1);

namespace App\Tests\Story;

use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\ValueObject\FieldType;
use App\Domain\Comment\Entity\Comment;
use App\Domain\Item\Entity\Item;
use App\Domain\Like\Entity\Like;
use App\Domain\Tag\Entity\Tag;
use App\Domain\User\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Golden fingerprint of the demo dataset (9.3).
 *
 * Extracted in 9.4 so the seed command test compares the same thing as the
 * story test: it hashes rows, not catalog constants, so an edit to either side
 * shows up as a mismatch. Ids and timestamps are excluded on purpose - uuid7
 * differs per run and would make the hash meaningless.
 */
final class DemoFingerprint
{
    /**
     * @param list<User>       $users
     * @param list<Collection> $collections
     * @param list<Item>       $items
     * @param list<Like>       $likes
     * @param list<Comment>    $comments
     * @param list<Tag>        $tags
     */
    public static function of(
        array $users,
        array $collections,
        array $items,
        array $likes,
        array $comments,
        array $tags,
        EntityManagerInterface $em,
    ): string {
        $emailById = [];
        foreach ($users as $user) {
            $emailById[\bin2hex($user->getId()->toBytes())] = $user->getEmail()->value();
        }

        $collectionById = [];
        $records = [];

        foreach ($users as $user) {
            $records[] = \json_encode([
                'email' => $user->getEmail()->value(),
                'role' => $user->getRole()->value(),
                'active' => $user->isActive(),
            ], \JSON_THROW_ON_ERROR);
        }

        foreach ($collections as $collection) {
            $ownerEmail = $emailById[\bin2hex($collection->getOwnerId()->toBytes())] ?? 'missing';
            $collectionById[\bin2hex($collection->getId()->toBytes())] = [
                'ownerEmail' => $ownerEmail,
                'name' => $collection->getName()->value(),
            ];

            $records[] = \json_encode([
                'ownerEmail' => $ownerEmail,
                'name' => $collection->getName()->value(),
                'theme' => $collection->getTheme()->value(),
                'descriptionIsNull' => null === $collection->getDescription(),
                'imageIsNull' => null === $collection->getImage(),
            ], \JSON_THROW_ON_ERROR);
        }

        $tagsByItem = [];
        foreach ($em->createQuery('SELECT i, t FROM '.Item::class.' i LEFT JOIN i.tags t')->getArrayResult() as $row) {
            $id = \bin2hex($row['id']);
            $tagsByItem[$id] ??= [];
            if (isset($row['tags']['name']['value'])) {
                $tagsByItem[$id][] = $row['tags']['name']['value'];
            }
        }

        foreach ($items as $item) {
            $slots = [];
            foreach (['text', 'number', 'date', 'bool'] as $type) {
                for ($slot = 1; $slot <= 3; ++$slot) {
                    $value = $item->getSlotValue(FieldType::fromString($type), $slot);
                    $slots[] = $value instanceof \DateTimeImmutable ? $value->format('Y-m-d\TH:i:s') : $value;
                }
            }

            $itemTags = $tagsByItem[\bin2hex($item->getId()->toBytes())] ?? [];
            \sort($itemTags, \SORT_STRING);

            $collectionMeta = $collectionById[\bin2hex($item->getCollection()->getId()->toBytes())];

            $records[] = \json_encode([
                'collectionOwnerEmail' => $collectionMeta['ownerEmail'],
                'collectionName' => $collectionMeta['name'],
                'itemName' => $item->getName(),
                'slots' => $slots,
                'tags' => $itemTags,
            ], \JSON_THROW_ON_ERROR);
        }

        foreach ($likes as $like) {
            $collection = $like->getItem()->getCollection();
            $records[] = \json_encode([
                'ownerEmail' => $emailById[\bin2hex($like->getOwnerId()->toBytes())] ?? 'missing',
                'collectionOwnerEmail' => $collectionById[\bin2hex($collection->getId()->toBytes())]['ownerEmail'],
                'collectionName' => $collection->getName()->value(),
                'itemName' => $like->getItem()->getName(),
            ], \JSON_THROW_ON_ERROR);
        }

        foreach ($comments as $comment) {
            $collection = $comment->getItem()->getCollection();
            $records[] = \json_encode([
                'ownerEmail' => $emailById[\bin2hex($comment->getOwnerId()->toBytes())] ?? 'missing',
                'collectionOwnerEmail' => $collectionById[\bin2hex($collection->getId()->toBytes())]['ownerEmail'],
                'collectionName' => $collection->getName()->value(),
                'itemName' => $comment->getItem()->getName(),
                'content' => $comment->getContent()->value(),
            ], \JSON_THROW_ON_ERROR);
        }

        foreach ($tags as $tag) {
            $records[] = \json_encode(['tag' => $tag->getName()->value()], \JSON_THROW_ON_ERROR);
        }

        \sort($records, \SORT_STRING);

        return \hash('sha256', \implode("\n", $records));
    }
}
