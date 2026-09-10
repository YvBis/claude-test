<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Api\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CollectionControllerTest extends WebTestCase
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

        // Delete created test user
        if (!empty($this->userId)) {
            $conn = $em->getConnection();
            $conn->executeStatement(
                'DELETE FROM users WHERE id = UNHEX(REPLACE(?, "-", ""))',
                [\preg_replace('/-/', '', $this->userId)]
            );
        }

        $em->clear();
        parent::tearDown();
    }

    private function registerAndLogin(): void
    {
        $unique = \uniqid('', true);
        $email = 'coll_'.\str_replace('.', '', $unique).'@example.com';

        $this->client->request('POST', '/api/register', [], [], ['CONTENT_TYPE' => 'application/json'], \json_encode([
            'name' => 'Collection Tester',
            'email' => $email,
            'password' => 'password123',
        ], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(201);

        $regResponse = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->userId = $regResponse['id'];

        $this->client->request('POST', '/api/login', [], [], ['CONTENT_TYPE' => 'application/json'], \json_encode([
            'email' => $email,
            'password' => 'password123',
        ], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(200);

        $loginResponse = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->token = $loginResponse['access_token'];
    }

    private function authHeaders(): array
    {
        return [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
            'CONTENT_TYPE' => 'application/json',
        ];
    }

    public function testCreateReturns201(): void
    {
        $payload = [
            'name' => 'My Books',
            'theme' => 'books',
            'description' => 'A test collection',
            'image' => 'https://example.com/cover.jpg',
        ];

        $this->client->request('POST', '/api/collections', [], [], $this->authHeaders(), \json_encode($payload, \JSON_THROW_ON_ERROR));

        $this->assertResponseStatusCodeSame(201);
        $response = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame('My Books', $response['name']);
        $this->assertSame('books', $response['theme']);
        $this->assertSame('A test collection', $response['description']);
        $this->assertSame('https://example.com/cover.jpg', $response['image']);
        $this->assertSame($this->userId, $response['owner_id']);
    }

    public function testCreateReturns400WhenInvalid(): void
    {
        $this->client->request('POST', '/api/collections', [], [], $this->authHeaders(), \json_encode([
            'name' => 'No',
            'theme' => 'unknown-theme',
        ], \JSON_THROW_ON_ERROR));

        $this->assertResponseStatusCodeSame(400);
        $response = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame('Validation failed', $response['error']);
        $this->assertNotEmpty($response['details']);
    }

    public function testCreateReturns401WithoutToken(): void
    {
        $this->client->request('POST', '/api/collections', [], [], ['CONTENT_TYPE' => 'application/json'], \json_encode([
            'name' => 'Anonymous',
            'theme' => 'books',
        ], \JSON_THROW_ON_ERROR));

        $this->assertResponseStatusCodeSame(401);
    }

    public function testListReturns200AndArray(): void
    {
        $this->client->request('POST', '/api/collections', [], [], $this->authHeaders(), \json_encode([
            'name' => 'Listed 1',
            'theme' => 'books',
        ], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(201);

        $this->client->request('POST', '/api/collections', [], [], $this->authHeaders(), \json_encode([
            'name' => 'Listed 2',
            'theme' => 'games',
        ], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(201);

        $this->client->request('GET', '/api/collections', [], [], $this->authHeaders());

        $this->assertResponseStatusCodeSame(200);
        $response = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($response);
        $this->assertGreaterThanOrEqual(2, \count($response));
    }

    public function testGetReturns200AndData(): void
    {
        $this->client->request('POST', '/api/collections', [], [], $this->authHeaders(), \json_encode([
            'name' => 'To Fetch',
            'theme' => 'movies',
        ], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(201);

        $created = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $id = $created['id'];

        $this->client->request('GET', '/api/collections/'.$id, [], [], $this->authHeaders());

        $this->assertResponseStatusCodeSame(200);
        $response = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame($id, $response['id']);
        $this->assertSame('To Fetch', $response['name']);
    }

    public function testGetReturns404ForMissing(): void
    {
        $missingId = '00000000-0000-0000-0000-000000000000';
        $this->client->request('GET', '/api/collections/'.$missingId, [], [], $this->authHeaders());

        $this->assertResponseStatusCodeSame(404);
    }

    public function testUpdateReturns200(): void
    {
        $this->client->request('POST', '/api/collections', [], [], $this->authHeaders(), \json_encode([
            'name' => 'Original',
            'theme' => 'books',
        ], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(201);

        $created = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $id = $created['id'];

        $this->client->request('PATCH', '/api/collections/'.$id, [], [], $this->authHeaders(), \json_encode([
            'name' => 'Updated Name',
            'description' => 'New description',
        ], \JSON_THROW_ON_ERROR));

        $this->assertResponseStatusCodeSame(200);
        $response = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame('Updated Name', $response['name']);
        $this->assertSame('New description', $response['description']);
        $this->assertSame('books', $response['theme']);
    }

    public function testUpdateReturns400WhenNoChanges(): void
    {
        $this->client->request('POST', '/api/collections', [], [], $this->authHeaders(), \json_encode([
            'name' => 'Empty PATCH',
            'theme' => 'books',
        ], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(201);
        $created = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $id = $created['id'];

        $this->client->request('PATCH', '/api/collections/'.$id, [], [], $this->authHeaders(), '{}');

        $this->assertResponseStatusCodeSame(400);
    }

    public function testDeleteReturns204(): void
    {
        $this->client->request('POST', '/api/collections', [], [], $this->authHeaders(), \json_encode([
            'name' => 'Disposable',
            'theme' => 'books',
        ], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(201);
        $created = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $id = $created['id'];

        $this->client->request('DELETE', '/api/collections/'.$id, [], [], $this->authHeaders());
        $this->assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/collections/'.$id, [], [], $this->authHeaders());
        $this->assertResponseStatusCodeSame(404);
    }
}
