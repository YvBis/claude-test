<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Api\Controller;

use App\Domain\Common\Constant\SlotLimits;
use App\Domain\Item\Entity\Item;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * review-14: the bounds must be enforced by DTO validation, not by the domain
 * throw that the controller happens to catch. Both paths answer 422, so each
 * test asserts the `Validation failed` message: that is what distinguishes
 * "the DTO refused the payload" from "the domain refused it after the DTO let
 * it through".
 */
final class ItemLimitsTest extends WebTestCase
{
    private KernelBrowser $client;

    private string $token;

    private string $userId;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->registerAndLogin();
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $em->getConnection()->executeStatement(
            'DELETE FROM users WHERE id = UNHEX(REPLACE(?, "-", ""))',
            [\preg_replace('/-/', '', $this->userId)]
        );
        $em->clear();

        parent::tearDown();
    }

    public function testCreatingAnItemWithTooManyTagsIsRejectedByDtoValidation(): void
    {
        $tags = \array_map(static fn (int $i): string => 'tag-'.$i, \range(1, Item::MAX_TAGS_PER_ITEM + 1));

        $this->createItem(['name' => 'Overloaded', 'tags' => $tags]);

        self::assertSame('Validation failed', $this->errorPayload()['message'] ?? null);
        self::assertStringContainsString('Too many tags', \implode(' ', $this->errorPayload()['details'] ?? []));
    }

    public function testCreatingAnItemWithTooManySlotsIsRejectedByDtoValidation(): void
    {
        $slots = [];
        for ($i = 0; $i <= SlotLimits::MAX_SLOTS_PER_ITEM; ++$i) {
            $slots[] = ['type' => 'text', 'slot' => 1, 'value' => 'v'.$i];
        }

        $this->createItem(['name' => 'Overloaded', 'slots' => $slots]);

        self::assertSame('Validation failed', $this->errorPayload()['message'] ?? null);
        self::assertStringContainsString('Too many slots', \implode(' ', $this->errorPayload()['details'] ?? []));
    }

    public function testCreatingAnItemWithAnOverlongTextSlotIsRejectedByDtoValidation(): void
    {
        $this->createItem([
            'name' => 'Long text',
            'slots' => [[
                'type' => 'text',
                'slot' => 1,
                'value' => \str_repeat('a', Item::MAX_TEXT_SLOT_LENGTH + 1),
            ]],
        ]);

        self::assertSame('Validation failed', $this->errorPayload()['message'] ?? null);
        self::assertStringContainsString('Slot value cannot exceed', \implode(' ', $this->errorPayload()['details'] ?? []));
    }

    public function testUpdatingAnItemWithTooManyTagsIsRejectedByDtoValidation(): void
    {
        $itemId = $this->createValidItem();
        $tags = \array_map(static fn (int $i): string => 'tag-'.$i, \range(1, Item::MAX_TAGS_PER_ITEM + 1));

        $this->client->request('PATCH', '/api/items/'.$itemId, [], [], $this->headers(), \json_encode(['tags' => $tags], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Validation failed', $this->errorPayload()['message'] ?? null);
        self::assertStringContainsString('Too many tags', \implode(' ', $this->errorPayload()['details'] ?? []));
    }

    public function testUpdatingAnItemWithTooManySlotsIsRejectedByDtoValidation(): void
    {
        $itemId = $this->createValidItem();
        $slots = [];
        for ($i = 0; $i <= SlotLimits::MAX_SLOTS_PER_ITEM; ++$i) {
            $slots[] = ['type' => 'text', 'slot' => 1, 'value' => 'v'.$i];
        }

        $this->client->request('PATCH', '/api/items/'.$itemId, [], [], $this->headers(), \json_encode(['slots' => $slots], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Validation failed', $this->errorPayload()['message'] ?? null);
        self::assertStringContainsString('Too many slots', \implode(' ', $this->errorPayload()['details'] ?? []));
    }

    public function testUpdatingAnItemWithAnOverlongTextSlotIsRejectedByDtoValidation(): void
    {
        $itemId = $this->createValidItem();

        $this->client->request('PATCH', '/api/items/'.$itemId, [], [], $this->headers(), \json_encode([
            'slots' => [[
                'type' => 'text',
                'slot' => 1,
                'value' => \str_repeat('b', Item::MAX_TEXT_SLOT_LENGTH + 1),
            ]],
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Validation failed', $this->errorPayload()['message'] ?? null);
        self::assertStringContainsString('Slot value cannot exceed', \implode(' ', $this->errorPayload()['details'] ?? []));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createItem(array $overrides = []): void
    {
        $collectionId = $this->createCollection();

        $payload = $overrides + ['name' => 'Item'];
        $this->client->request('POST', '/api/collections/'.$collectionId.'/items', [], [], $this->headers(), \json_encode($payload, \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
    }

    private function createValidItem(): string
    {
        $collectionId = $this->createCollection();

        $this->client->request('POST', '/api/collections/'.$collectionId.'/items', [], [], $this->headers(), \json_encode([
            'name' => 'Fine',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        return (string) \json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['id'];
    }

    /**
     * @return array<string, mixed>
     */
    private function errorPayload(): array
    {
        /** @var array<string, mixed> $payload */
        $payload = \json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $payload;
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->token];
    }

    private function createCollection(): string
    {
        $this->client->request('POST', '/api/collections', [], [], $this->headers(), \json_encode([
            'name' => 'Limits '.$this->userId,
            'theme' => 'books',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        return (string) \json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['id'];
    }

    private function registerAndLogin(): void
    {
        $email = 'limits_'.\str_replace('.', '', \uniqid('', true)).'@example.com';

        $this->client->request('POST', '/api/register', [], [], ['CONTENT_TYPE' => 'application/json'], \json_encode([
            'name' => 'Limits Tester',
            'email' => $email,
            'password' => 'password123',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        $this->userId = (string) \json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['id'];

        $this->client->request('POST', '/api/login', [], [], ['CONTENT_TYPE' => 'application/json'], \json_encode([
            'email' => $email,
            'password' => 'password123',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200);

        $this->token = (string) \json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['access_token'];
    }
}
