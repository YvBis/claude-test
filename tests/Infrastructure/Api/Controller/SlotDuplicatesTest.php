<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Api\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * 10.8: a repeated (type, slot) pair must be refused by the DTO, not silently
 * overwritten by ItemSlotMapper. `details` carries the rendered message, since
 * the violation path is not part of the error envelope.
 */
final class SlotDuplicatesTest extends WebTestCase
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

    public function testCreatingAnItemWithDuplicateSlotsIsRejected(): void
    {
        $collectionId = $this->createCollection();

        $this->client->request('POST', '/api/collections/'.$collectionId.'/items', [], [], $this->headers(), \json_encode([
            'name' => 'Duplicate',
            'slots' => [
                ['type' => 'text', 'slot' => 1, 'value' => 'first'],
                ['type' => 'text', 'slot' => 1, 'value' => 'second'],
            ],
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Validation failed', $this->errorPayload()['message'] ?? null);
        self::assertContains('Duplicate slot: type "text", slot 1.', $this->errorPayload()['details'] ?? []);
    }

    public function testUpdatingAnItemWithDuplicateSlotsIsRejected(): void
    {
        $itemId = $this->createValidItem();

        $this->client->request('PATCH', '/api/items/'.$itemId, [], [], $this->headers(), \json_encode([
            'slots' => [
                ['type' => 'number', 'slot' => 2, 'value' => 1],
                ['type' => 'NUMBER', 'slot' => 2, 'value' => 2],
            ],
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Validation failed', $this->errorPayload()['message'] ?? null);
        self::assertContains('Duplicate slot: type "number", slot 2.', $this->errorPayload()['details'] ?? []);
    }

    public function testSameSlotIndexAcrossTypesIsAccepted(): void
    {
        $collectionId = $this->createCollection();

        $this->client->request('POST', '/api/collections/'.$collectionId.'/items', [], [], $this->headers(), \json_encode([
            'name' => 'Cross type',
            'slots' => [
                ['type' => 'text', 'slot' => 1, 'value' => 'a'],
                ['type' => 'number', 'slot' => 1, 'value' => 7],
                ['type' => 'bool', 'slot' => 1, 'value' => true],
            ],
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
    }

    public function testPatchWithoutSlotsStillSucceeds(): void
    {
        $itemId = $this->createValidItem();

        $this->client->request('PATCH', '/api/items/'.$itemId, [], [], $this->headers(), \json_encode([
            'name' => 'Renamed',
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Renamed', \json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['name']);
    }

    public function testPatchWithEmptySlotListStillSucceeds(): void
    {
        $itemId = $this->createValidItem();

        $this->client->request('PATCH', '/api/items/'.$itemId, [], [], $this->headers(), \json_encode([
            'slots' => [],
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(200);
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
            'name' => 'Slots '.$this->userId,
            'theme' => 'books',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        return (string) \json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['id'];
    }

    private function registerAndLogin(): void
    {
        $email = 'slots_'.\str_replace('.', '', \uniqid('', true)).'@example.com';

        $this->client->request('POST', '/api/register', [], [], ['CONTENT_TYPE' => 'application/json'], \json_encode([
            'name' => 'Slots Tester',
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
