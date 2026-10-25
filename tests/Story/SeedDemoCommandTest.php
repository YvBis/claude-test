<?php

declare(strict_types=1);

namespace App\Tests\Story;

use Doctrine\ORM\EntityManagerInterface;
use Meilisearch\Client;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\StoryRegistry;

/**
 * The seed command is the only supported way to produce the demo dataset, so it
 * is tested through the console application: registration (the command lives in
 * `tests/`, not `src/`), the environment guard and the index rebuild are all
 * part of what it promises.
 *
 * One seeding test instead of three: every seed writes ~1250 rows and waits for
 * the engine, so the suite pays for it in wall time. Idempotency, tag purging and
 * ghost removal are all observed on the same second run.
 *
 * `StoryRegistry` memoises a built story per process, so the second run would
 * purge the database and skip `build()`. The registry is reset between runs -
 * what a fresh CLI process does implicitly - instead of depending on ordering.
 */
final class SeedDemoCommandTest extends KernelTestCase
{
    public function testTheCommandIsRegistered(): void
    {
        self::bootKernel();

        $application = new Application(self::$kernel);

        self::assertTrue($application->has('app:seed:demo'));
        self::assertTrue($application->has('search:reindex'));
    }

    public function testItRefusesToRunOutsideDevAndTest(): void
    {
        self::assertTrue(SeedDemoCommand::allowsEnvironment('dev'));
        self::assertTrue(SeedDemoCommand::allowsEnvironment('test'));
        self::assertFalse(SeedDemoCommand::allowsEnvironment('prod'));
    }

    public function testSeedingTwiceIsIdempotentAndRemovesGhostDocuments(): void
    {
        self::bootKernel();

        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:seed:demo'));

        self::assertSame(Command::SUCCESS, $tester->execute(['--batch-size' => '500']), $tester->getDisplay());
        $first = $this->snapshot();

        // A document whose row does not exist stands in for the real hazard:
        // rows deleted after they were indexed. Only a wipe before the rebuild
        // removes it, and a count-only check would not notice.
        $client = self::getContainer()->get(Client::class);
        $index = self::itemsIndexName();
        $task = $client->index($index)->addDocuments([['id' => 'ghost-probe', 'name' => 'Ghost Probe']], 'id');
        $client->waitForTask($task['taskUid'], 30000);
        self::assertNotEmpty($client->index($index)->search('Ghost Probe')->getHits(), 'The probe document must be searchable before the second run.');

        StoryRegistry::reset();

        self::assertSame(Command::SUCCESS, $tester->execute(['--batch-size' => '500']), $tester->getDisplay());
        $second = $this->snapshot();

        self::assertSame($first, $second, 'Seeding twice must produce the same dataset.');
        self::assertSame(DemoCatalog::TAGS, $second['tags'], 'Tags are global and must be purged, not accumulated.');
        self::assertSame([], $client->index($index)->search('Ghost Probe')->getHits(), 'The rebuild must wipe documents of deleted rows.');
        self::assertSame(
            (int) self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne('SELECT COUNT(*) FROM items'),
            $second['documents'],
            'The index must end up holding exactly one document per item.',
        );
    }

    /**
     * The `search.items_index` container parameter is only the fallback: in the
     * test environment the `MEILISEARCH_ITEMS_INDEX` env variable overrides it
     * and the adapter writes to `items_test`. Reading the parameter would point
     * these assertions at the development index - which is exactly how a probe
     * test can "prove" that wiping works while checking the wrong index.
     */
    private static function itemsIndexName(): string
    {
        return (string) ($_ENV['MEILISEARCH_ITEMS_INDEX'] ?? $_SERVER['MEILISEARCH_ITEMS_INDEX'] ?? 'items');
    }

    /**
     * @return array{fingerprint: string, tags: int, documents: int}
     */
    private function snapshot(): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $users = $em->createQuery('SELECT u FROM App\Domain\User\Entity\User u')->getResult();
        $collections = $em->createQuery('SELECT c FROM App\Domain\Collection\Entity\Collection c')->getResult();
        $items = $em->createQuery('SELECT i FROM App\Domain\Item\Entity\Item i')->getResult();
        $likes = $em->createQuery('SELECT l FROM App\Domain\Like\Entity\Like l')->getResult();
        $comments = $em->createQuery('SELECT cm FROM App\Domain\Comment\Entity\Comment cm')->getResult();
        $tags = $em->createQuery('SELECT t FROM App\Domain\Tag\Entity\Tag t')->getResult();

        return [
            'fingerprint' => DemoFingerprint::of($users, $collections, $items, $likes, $comments, $tags, $em),
            'tags' => \count($tags),
            'documents' => (int) self::getContainer()->get(Client::class)->index(self::itemsIndexName())->stats()['numberOfDocuments'],
        ];
    }
}
