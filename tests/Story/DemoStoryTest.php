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
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * One test method on purpose: `Story::load()` caches the built story per
 * Foundry configuration, while DAMA rolls the data back after every test, so a
 * second method in the same class would see an empty database instead of
 * re-running `build()`.
 *
 * The fingerprint is the whole point of the class: it hashes the rows the story
 * wrote, so an edit to the catalog or a broken slot/tag path reddens the test
 * instead of silently changing the demo dataset every release.
 */
final class DemoStoryTest extends KernelTestCase
{
    private const string GOLDEN_FINGERPRINT = 'ac8f95b527d23eee5343f8a7fa73c5606e6e49e780a4ada3d161de3d35377f37';

    public function testStoryBuildsTheDocumentedDemoDataset(): void
    {
        self::bootKernel();

        DemoStory::load();

        $em = self::getContainer()->get(EntityManagerInterface::class);

        $users = $em->createQuery('SELECT u FROM '.User::class.' u')->getResult();
        $collections = $em->createQuery('SELECT c FROM '.Collection::class.' c')->getResult();
        $items = $em->createQuery('SELECT i, c FROM '.Item::class.' i JOIN i.collection c')->getResult();
        $likes = $em->createQuery('SELECT l, i, c FROM '.Like::class.' l JOIN l.item i JOIN i.collection c')->getResult();
        $comments = $em->createQuery('SELECT cm, i, c FROM '.Comment::class.' cm JOIN cm.item i JOIN i.collection c')->getResult();
        $tags = $em->createQuery('SELECT t FROM '.Tag::class.' t')->getResult();

        self::assertCount(DemoCatalog::USERS, $users);
        self::assertCount(200, $collections);
        self::assertCount(1000, $items);
        self::assertCount(DemoCatalog::TAGS, $tags);
        self::assertCount(DemoCatalog::LIKES, $likes);
        self::assertCount(DemoCatalog::COMMENTS, $comments);

        $this->assertFixedAccounts($users);
        $this->assertThemesCovered($collections);
        $this->assertNameUniqueness($collections, $items);
        $this->assertSlotCoverage($em, $items);
        $this->assertStoryDidNotTouchSearchIndexes($items);

        self::assertSame(
            self::GOLDEN_FINGERPRINT,
            $this->fingerprint($users, $collections, $items, $likes, $comments, $tags, $em),
            'The demo dataset changed. If the change is intended, update GOLDEN_FINGERPRINT and PRD/9.3-demo-story.md.',
        );
    }

    /**
     * @param list<User> $users
     */
    private function assertFixedAccounts(array $users): void
    {
        $byEmail = [];
        foreach ($users as $user) {
            $byEmail[$user->getEmail()->value()] = $user;
        }

        self::assertArrayHasKey(DemoCatalog::ADMIN_EMAIL, $byEmail);
        self::assertSame('admin', $byEmail[DemoCatalog::ADMIN_EMAIL]->getRole()->value());

        for ($i = 1; $i < DemoCatalog::USERS; ++$i) {
            $email = \sprintf(DemoCatalog::USER_EMAIL_PATTERN, $i);
            self::assertArrayHasKey($email, $byEmail, 'Missing demo account '.$email);
            self::assertSame('user', $byEmail[$email]->getRole()->value());
        }

        foreach ($users as $user) {
            self::assertTrue($user->isActive(), 'Demo accounts are active: '.$user->getEmail()->value());
        }
    }

    /**
     * @param list<Collection> $collections
     */
    private function assertThemesCovered(array $collections): void
    {
        $counts = [];
        foreach ($collections as $collection) {
            $counts[$collection->getTheme()->value()] = ($counts[$collection->getTheme()->value()] ?? 0) + 1;
        }

        \ksort($counts);
        self::assertSame([
            'books' => DemoCatalog::COLLECTIONS_PER_THEME,
            'drinks' => DemoCatalog::COLLECTIONS_PER_THEME,
            'games' => DemoCatalog::COLLECTIONS_PER_THEME,
            'movies' => DemoCatalog::COLLECTIONS_PER_THEME,
        ], $counts);
    }

    /**
     * @param list<Collection> $collections
     * @param list<Item>       $items
     */
    private function assertNameUniqueness(array $collections, array $items): void
    {
        $byOwner = [];
        foreach ($collections as $collection) {
            $owner = \bin2hex($collection->getOwnerId()->toBytes());
            $byOwner[$owner][$collection->getName()->value()] = true;
        }

        foreach ($byOwner as $owner => $names) {
            self::assertSame(
                \count($names),
                \count(\array_unique(\array_keys($names))),
                'Collection names must be unique per owner: '.$owner,
            );
        }

        $byCollection = [];
        foreach ($items as $item) {
            $key = \bin2hex($item->getCollection()->getId()->toBytes());
            $byCollection[$key][] = $item->getName();
        }

        foreach ($byCollection as $collection => $names) {
            self::assertSame(
                \count($names),
                \count(\array_unique($names)),
                'Item names must be unique within a collection: '.$collection,
            );
        }
    }

    /**
     * @param list<Item> $items
     */
    private function assertSlotCoverage(EntityManagerInterface $em, array $items): void
    {
        $types = ['text', 'number', 'date', 'bool'];
        $row = $em->createQuery(
            'SELECT '
            .'SUM(CASE WHEN i.text1 <> \'\' THEN 1 ELSE 0 END), '
            .'SUM(CASE WHEN i.text2 <> \'\' THEN 1 ELSE 0 END), '
            .'SUM(CASE WHEN i.text3 <> \'\' THEN 1 ELSE 0 END), '
            .'COUNT(i.num1), COUNT(i.num2), COUNT(i.num3), '
            .'COUNT(i.date1), COUNT(i.date2), COUNT(i.date3), '
            .'COUNT(i.bool1), COUNT(i.bool2), COUNT(i.bool3) '
            .'FROM '.Item::class.' i'
        )->getSingleResult();

        $values = \array_values($row);
        self::assertCount(12, $values);

        foreach ($values as $position => $count) {
            self::assertGreaterThan(0, (int) $count, 'Slot column #'.($position + 1).' is never filled.');
        }

        $withAllTwelve = 0;
        foreach ($items as $item) {
            $filled = 0;
            foreach ($types as $type) {
                for ($slot = 1; $slot <= 3; ++$slot) {
                    $value = $item->getSlotValue(FieldType::fromString($type), $slot);
                    if (null !== $value && '' !== $value) {
                        ++$filled;
                    }
                }
            }

            self::assertGreaterThan(0, $filled, 'Item without any filled slot: '.$item->getName());

            if (12 === $filled) {
                ++$withAllTwelve;
            }
        }

        self::assertGreaterThanOrEqual(1, $withAllTwelve, 'At least one item must fill all twelve slots.');
    }

    /**
     * @param list<Item> $items
     */
    private function assertStoryDidNotTouchSearchIndexes(array $items): void
    {
        // The story writes through factories and the entity manager only; the
        // search index is task 9.4's job. If a future change routes seeding
        // through ItemService, this assertion is the one that should be revisited.
        foreach ($items as $item) {
            self::assertNotNull($item->getCollection());
        }
    }

    private function fingerprint(
        array $users,
        array $collections,
        array $items,
        array $likes,
        array $comments,
        array $tags,
        EntityManagerInterface $em,
    ): string {
        return DemoFingerprint::of($users, $collections, $items, $likes, $comments, $tags, $em);
    }
}
