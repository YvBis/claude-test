<?php

declare(strict_types=1);

namespace App\Tests\Story;

use App\Application\Item\DTO\ItemSlotDTO;
use App\Application\Item\Service\ItemSlotMapper;
use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\ValueObject\CollectionName;
use App\Domain\Common\ValueObject\OwnerId;
use App\Domain\Item\Entity\Item;
use App\Domain\Tag\Entity\Tag;
use App\Tests\Factory\CollectionFactory;
use App\Tests\Factory\CommentFactory;
use App\Tests\Factory\ItemFactory;
use App\Tests\Factory\LikeFactory;
use App\Tests\Factory\TagFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Zenstruck\Foundry\Story;

/**
 * Demo data for frontend development (task 9.3).
 *
 * Every value comes from `DemoCatalog`; faker is not consulted for anything the
 * fingerprint hashes, so the resulting database content is reproducible without
 * pinning `FOUNDRY_FAKER_SEED`.
 *
 * The story is a service (see `config/packages/fixtures.yaml`): it needs the
 * entity manager for the final flush and the slot mapper, which is the only
 * place that knows how to turn raw catalog values into typed slots. Registering
 * it is not optional - `StoryRegistry` falls back to `new $class()` and throws.
 */
final class DemoStory extends Story
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ItemSlotMapper $slotMapper,
    ) {
    }

    public function build(): void
    {
        $users = $this->createUsers();
        $collections = $this->createCollections($users);
        [$items] = $this->createItemsAndTags($collections);

        $this->createLikes($users, $items);
        $this->createComments($users, $items);

        // Defensive: factory creation already flushes pending changes, so the
        // tail of the story would survive without this call (verified - removing
        // it leaves the suite green). It stays so a future reordering cannot
        // silently drop the last mutations.
        $this->entityManager->flush();
    }

    /**
     * @return list<\App\Domain\User\Entity\User>
     */
    private function createUsers(): array
    {
        $users = [
            UserFactory::new([
                'name' => DemoCatalog::ADMIN_NAME,
                'email' => \App\Domain\User\ValueObject\Email::fromString(DemoCatalog::ADMIN_EMAIL),
                'role' => \App\Domain\User\ValueObject\Role::admin(),
            ])->create(),
        ];

        for ($i = 1; $i < DemoCatalog::USERS; ++$i) {
            $users[] = UserFactory::new([
                'name' => \sprintf(DemoCatalog::USER_NAME_PATTERN, $i),
                'email' => \App\Domain\User\ValueObject\Email::fromString(\sprintf(DemoCatalog::USER_EMAIL_PATTERN, $i)),
            ])->create();
        }

        return $users;
    }

    /**
     * @param list<\App\Domain\User\Entity\User> $users
     *
     * @return list<Collection>
     */
    private function createCollections(array $users): array
    {
        $bases = DemoCatalog::collectionBases();
        $collections = [];
        $index = 0;

        foreach (DemoCatalog::themes() as $theme) {
            for ($i = 0; $i < DemoCatalog::COLLECTIONS_PER_THEME; ++$i) {
                $owner = $users[$index % DemoCatalog::USERS];
                $name = \sprintf('%s %02d', $bases[$theme->value()][$i % 10], \intdiv($i, 10) + 1);

                $collections[] = CollectionFactory::new([
                    'ownerId' => OwnerId::fromBytes($owner->getId()->toBytes()),
                    'name' => CollectionName::fromString($name),
                    'theme' => $theme,
                    // Some collections have neither description nor image: the
                    // frontend must render the empty state, so it has to exist.
                    'description' => 0 === $index % 3 ? null : 'Demo collection '.$name,
                    'image' => 0 === $index % 2 ? null : 'https://picsum.photos/seed/'.$index.'/600/400',
                ])->create();

                ++$index;
            }
        }

        return $collections;
    }

    /**
     * @param list<Collection> $collections
     *
     * @return array{0: list<Item>, 1: list<Tag>}
     */
    private function createItemsAndTags(array $collections): array
    {
        $tags = [];

        foreach (DemoCatalog::TAG_NAMES as $tagName) {
            $tags[] = TagFactory::new([
                'name' => \App\Domain\Tag\ValueObject\TagName::fromString($tagName),
            ])->create();
        }

        $names = DemoCatalog::itemNames();
        $texts = DemoCatalog::textValues();
        $items = [];
        $index = 0;

        foreach ($collections as $collectionIndex => $collection) {
            $theme = $collection->getTheme()->value();
            $namesForTheme = $names[$theme];

            for ($i = 0; $i < DemoCatalog::ITEMS_PER_COLLECTION; ++$i) {
                $itemName = $namesForTheme[($collectionIndex * DemoCatalog::ITEMS_PER_COLLECTION + $i) % \count($namesForTheme)];

                $item = ItemFactory::new([
                    'collection' => $collection,
                    'name' => $itemName,
                ])->create();

                $this->slotMapper->applySlots($item, $this->slotsFor($index, $theme, $texts[$theme]));
                $this->attachTags($item, $tags, $index);

                $items[] = $item;
                ++$index;
            }
        }

        return [$items, $tags];
    }

    /**
     * Every item gets at least one filled slot; every hundredth item gets all
     * twelve, which is what guarantees full column coverage in the aggregate
     * assertion.
     *
     * @param list<string> $texts
     *
     * @return list<ItemSlotDTO>
     */
    private function slotsFor(int $index, string $theme, array $texts): array
    {
        if (0 === $index % 100) {
            $slots = [];
            foreach (['text', 'number', 'date', 'bool'] as $type) {
                for ($slot = 1; $slot <= 3; ++$slot) {
                    $slots[] = new ItemSlotDTO($type, $slot, $this->valueFor($type, $index + $slot, $texts));
                }
            }

            return $slots;
        }

        $type = ['text', 'number', 'date', 'bool'][$index % 4];

        return [new ItemSlotDTO($type, 1, $this->valueFor($type, $index, $texts))];
    }

    /**
     * @param list<string> $texts
     */
    private function valueFor(string $type, int $index, array $texts): string|int|float|bool
    {
        return match ($type) {
            'text' => $texts[$index % \count($texts)],
            'number' => DemoCatalog::NUMBERS[$index % \count(DemoCatalog::NUMBERS)],
            'date' => DemoCatalog::DATES[$index % \count(DemoCatalog::DATES)],
            'bool' => DemoCatalog::BOOLS[$index % \count(DemoCatalog::BOOLS)],
            default => throw new \InvalidArgumentException('Unknown slot type '.$type),
        };
    }

    /**
     * @param list<Tag> $tags
     */
    private function attachTags(Item $item, array $tags, int $index): void
    {
        $count = $index % 4;

        for ($t = 0; $t < $count; ++$t) {
            $item->addTag($tags[($index + $t) % \count($tags)]);
        }
    }

    /**
     * @param list<\App\Domain\User\Entity\User> $users
     * @param list<Item>                         $items
     */
    private function createLikes(array $users, array $items): void
    {
        // 30 items per owner, and the windows of different owners never overlap,
        // so all 1500 pairs are distinct by construction - no rejection loop,
        // which would spin forever once it exhausted the combinations.
        $attributes = [];
        $perOwner = \intdiv(DemoCatalog::LIKES, DemoCatalog::USERS);

        for ($ownerIndex = 0; $ownerIndex < DemoCatalog::USERS; ++$ownerIndex) {
            for ($k = 0; $k < $perOwner; ++$k) {
                $itemIndex = ($ownerIndex * $perOwner + $k) % \count($items);
                $attributes[] = [
                    'ownerId' => OwnerId::fromBytes($users[$ownerIndex]->getId()->toBytes()),
                    'item' => $items[$itemIndex],
                ];
            }
        }

        LikeFactory::new()->sequence($attributes)->create();
    }

    /**
     * @param list<\App\Domain\User\Entity\User> $users
     * @param list<Item>                         $items
     */
    private function createComments(array $users, array $items): void
    {
        $texts = DemoCatalog::COMMENT_TEXTS;
        $attributes = [];

        for ($i = 0; $i < DemoCatalog::COMMENTS; ++$i) {
            $attributes[] = [
                'ownerId' => OwnerId::fromBytes($users[($i * 11) % DemoCatalog::USERS]->getId()->toBytes()),
                'item' => $items[($i * 53) % \count($items)],
                'content' => \App\Domain\Comment\ValueObject\CommentContent::fromString($texts[$i % \count($texts)]),
            ];
        }

        CommentFactory::new()->sequence($attributes)->create();
    }
}
