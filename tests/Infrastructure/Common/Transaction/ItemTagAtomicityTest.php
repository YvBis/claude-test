<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Common\Transaction;

use App\Application\Common\Transaction\UnitOfWorkInterface;
use App\Application\Item\DTO\CreateItemDTO;
use App\Application\Item\Service\ItemService;
use App\Application\Item\Service\ItemSlotMapper;
use App\Application\Tag\Service\TagService;
use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\ValueObject\CollectionName;
use App\Domain\Collection\ValueObject\Theme;
use App\Domain\Item\Repository\ItemRepositoryInterface;
use App\Domain\Tag\Repository\TagRepositoryInterface;
use App\Domain\Tag\ValueObject\TagName;
use App\Domain\User\Entity\User;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\PasswordHash;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ItemTagAtomicityTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private TagRepositoryInterface $tagRepo;
    private UnitOfWorkInterface $uow;

    protected function setUp(): void
    {
        parent::setUp();

        $doctrine = self::getContainer()->get('doctrine');
        $this->em = $doctrine->getManager();
        $this->connection = $doctrine->getConnection();
        $this->tagRepo = self::getContainer()->get(TagRepositoryInterface::class);
        $this->uow = self::getContainer()->get(UnitOfWorkInterface::class);
    }

    public function testCreateItemWithNewTagPersistsBoth(): void
    {
        $user = User::register(
            name: 'Atomic User',
            email: Email::fromString('atomic_'.\uniqid().'@example.com'),
            passwordHash: PasswordHash::createFromPlain('Pass123!'),
        );
        $this->em->persist($user);
        $collection = Collection::create($user, CollectionName::fromString('Atomic Coll'), Theme::books());
        $this->em->persist($collection);
        $this->em->flush();

        $tagName = 'AtomicTag_'.\uniqid();
        $itemService = new ItemService(
            self::getContainer()->get(ItemRepositoryInterface::class),
            $this->uow,
            new TagService(self::getContainer()->get(TagRepositoryInterface::class)),
            new ItemSlotMapper(),
        );
        $item = $itemService->create(new CreateItemDTO('Atomic Item', tags: [$tagName]), $collection);

        $itemId = $this->connection->executeQuery(
            'SELECT id FROM items WHERE id = :id',
            ['id' => $item->getId()->toBytes()],
        )->fetchOne();
        $this->assertNotFalse($itemId, 'Item must be persisted');

        $tagId = $this->connection->executeQuery(
            'SELECT id FROM tags WHERE name = :name',
            ['name' => $tagName],
        )->fetchOne();
        $this->assertNotFalse($tagId, 'New tag must be persisted');
    }

    public function testRollsBackRawTagInsertWhenTransactionFails(): void
    {
        $name = 'Atomic_'.\uniqid();
        $exception = null;

        try {
            $this->uow->transactional(function () use ($name): void {
                $this->tagRepo->getOrCreate(TagName::fromString($name));
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException $e) {
            $exception = $e;
        }

        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertSame('boom', $exception->getMessage());

        $found = $this->connection->executeQuery(
            'SELECT id FROM tags WHERE name = :name',
            ['name' => $name],
        )->fetchOne();

        $this->assertFalse($found, 'Raw tag upsert inside a failed transaction must be rolled back');
    }
}
