<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Command;

use App\Application\Search\ItemDocument;
use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\ValueObject\CollectionName;
use App\Domain\Collection\ValueObject\Theme;
use App\Domain\Common\ValueObject\OwnerId;
use App\Domain\Item\Entity\Item;
use App\Domain\Tag\Entity\Tag;
use App\Domain\Tag\ValueObject\TagName;
use App\Domain\User\Entity\User;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\PasswordHash;
use App\Tests\Infrastructure\Search\EngineBackedSearchTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * End-to-end proof of `search:reindex` against the real engine: rows written
 * straight to the DB (bypassing ItemService, so nothing was indexed on write)
 * appear in the index after the command — the repair path for fail-open.
 */
final class SearchReindexCommandTest extends EngineBackedSearchTestCase
{
    private function tester(): CommandTester
    {
        $kernel = self::$kernel;
        \assert(null !== $kernel);

        return new CommandTester((new Application($kernel))->find('search:reindex'));
    }

    public function testRebuildsTheItemsIndexFromTheDatabase(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        $user = User::register(
            name: 'Reindex User',
            email: Email::fromString('reindex_'.\uniqid().'@example.com'),
            passwordHash: PasswordHash::createFromPlain('Pass123!'),
        );
        $em->persist($user);
        $collection = Collection::create(
            OwnerId::fromBytes($user->getId()->toBytes()),
            CollectionName::fromString('Reindex Collection'),
            Theme::games(),
        );
        $em->persist($collection);
        $tag = Tag::create(TagName::fromString('Reindex_'.\uniqid()));
        $em->persist($tag);
        $item = Item::create($collection, 'Written behind the service');
        $item->addTag($tag);
        $em->persist($item);
        $em->flush();
        $expected = ItemDocument::fromEntity($item)->toArray();

        $tester = $this->tester();
        $tester->execute(['--batch-size' => '1']);

        $tester->assertCommandIsSuccessful();
        self::assertMatchesRegularExpression('/Indexed [1-9]\d* item\(s\)\./', $tester->getDisplay());
        self::assertEquals($expected, $this->waitForDocument($this->itemsIndex, $expected['id']));
    }

    public function testRejectsANonPositiveBatchSize(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::INVALID, $tester->execute(['--batch-size' => '0']));
        self::assertStringContainsString('positive integer', $tester->getDisplay());
    }
}
