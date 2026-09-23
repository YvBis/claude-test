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
    /** @var list<string> */
    private array $extraUserIds = [];

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

        foreach ($this->extraUserIds as $extraId) {
            $conn = $em->getConnection();
            $conn->executeStatement(
                'DELETE FROM users WHERE id = UNHEX(REPLACE(?, "-", ""))',
                [\preg_replace('/-/', '', $extraId)]
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

    /** @return array{token: string, id: string} */
    private function registerUser(): array
    {
        $unique = \uniqid('', true);
        $email = 'coll_'.\str_replace('.', '', $unique).'_x@example.com';

        $this->client->request('POST', '/api/register', [], [], ['CONTENT_TYPE' => 'application/json'], \json_encode([
            'name' => 'Collection Tester 2',
            'email' => $email,
            'password' => 'password123',
        ], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(201);

        $id = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['id'];
        $this->extraUserIds[] = $id;

        $this->client->request('POST', '/api/login', [], [], ['CONTENT_TYPE' => 'application/json'], \json_encode([
            'email' => $email,
            'password' => 'password123',
        ], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(200);

        $token = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['access_token'];

        return ['token' => $token, 'id' => $id];
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

    public function testCreateReturns422WhenInvalid(): void
    {
        $this->client->request('POST', '/api/collections', [], [], $this->authHeaders(), \json_encode([
            'name' => 'No',
            'theme' => 'unknown-theme',
        ], \JSON_THROW_ON_ERROR));

        $this->assertResponseStatusCodeSame(422);
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

    public function testListWithoutTokenReturns401WithControllerEnvelope(): void
    {
        // GET /api/collections is PUBLIC_ACCESS at the firewall, so the request
        // reaches the controller and the shared guard answers 401 with the
        // {error, message?} envelope (protected paths are rejected earlier by
        // the JWT entry point and answer with its own {code, message} body).
        $this->client->request('GET', '/api/collections');

        $this->assertResponseStatusCodeSame(401);
        $this->assertSame(
            ['error' => 'Unauthorized'],
            \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)
        );
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

    public function testListOthersReturns200AndIsolated(): void
    {
        $this->client->request('POST', '/api/collections', [], [], $this->authHeaders(), \json_encode([
            'name' => 'Own 1',
            'theme' => 'books',
        ], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(201);

        $other = $this->registerUser();
        $this->client->request('POST', '/api/collections', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$other['token'],
            'CONTENT_TYPE' => 'application/json',
        ], \json_encode([
            'name' => 'Others 1',
            'theme' => 'games',
        ], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(201);

        $this->client->request('GET', '/api/collections', [], [], $this->authHeaders());
        $this->assertResponseStatusCodeSame(200);
        $ownList = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $ownNames = \array_column($ownList, 'name');
        $this->assertContains('Own 1', $ownNames);
        $this->assertNotContains('Others 1', $ownNames);

        $this->client->request('GET', '/api/collections?owner='.$other['id'], [], [], $this->authHeaders());
        $this->assertResponseStatusCodeSame(200);
        $otherList = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $otherNames = \array_column($otherList, 'name');
        $this->assertContains('Others 1', $otherNames);
        $this->assertNotContains('Own 1', $otherNames);
    }

    public function testListInvalidOwnerReturns400(): void
    {
        $this->client->request('GET', '/api/collections?owner=not-a-uuid', [], [], $this->authHeaders());

        $this->assertResponseStatusCodeSame(400);
        $this->assertJsonStringEqualsJsonString(
            '{"error":"Bad Request","message":"Invalid query parameters","details":["Invalid owner id"]}',
            $this->client->getResponse()->getContent()
        );
    }

    public function testListWithInvalidLimitReturns400(): void
    {
        $this->client->request('GET', '/api/collections?limit=abc', [], [], $this->authHeaders());

        $this->assertResponseStatusCodeSame(400);
        $response = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame('Bad Request', $response['error']);
        $this->assertSame('Invalid query parameters', $response['message']);
        $this->assertNotEmpty($response['details']);
    }

    public function testListWithInvalidOffsetReturns400(): void
    {
        $this->client->request('GET', '/api/collections?offset=abc', [], [], $this->authHeaders());

        $this->assertResponseStatusCodeSame(400);
        $response = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame('Bad Request', $response['error']);
        $this->assertSame('Invalid query parameters', $response['message']);
    }

    public function testListWithZeroLimitReturns400(): void
    {
        // (int) '0' used to pass straight through as LIMIT 0 and answer 200 with
        // an empty list; the shared helper enforces MIN_LIMIT = 1.
        $this->client->request('GET', '/api/collections?limit=0', [], [], $this->authHeaders());

        $this->assertResponseStatusCodeSame(400);
    }

    public function testListWithLimitAboveMaximumReturns400(): void
    {
        $this->client->request('GET', '/api/collections?limit=101', [], [], $this->authHeaders());

        $this->assertResponseStatusCodeSame(400);
    }

    public function testListWithNegativeOffsetReturns400(): void
    {
        $this->client->request('GET', '/api/collections?offset=-1', [], [], $this->authHeaders());

        $this->assertResponseStatusCodeSame(400);
    }

    public function testListWithNegativeLimitReturns400(): void
    {
        $this->client->request('GET', '/api/collections?limit=-5', [], [], $this->authHeaders());

        $this->assertResponseStatusCodeSame(400);
    }

    public function testListWithMaximumLimitReturns200(): void
    {
        $this->client->request('GET', '/api/collections?limit=100', [], [], $this->authHeaders());

        $this->assertResponseStatusCodeSame(200);
        $this->assertIsArray(\json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR));
    }

    public function testListPaginates(): void
    {
        foreach (['Page 1', 'Page 2', 'Page 3'] as $name) {
            $this->client->request('POST', '/api/collections', [], [], $this->authHeaders(), \json_encode([
                'name' => $name,
                'theme' => 'books',
            ], \JSON_THROW_ON_ERROR));
            $this->assertResponseStatusCodeSame(201);
        }

        $this->client->request('GET', '/api/collections?limit=2', [], [], $this->authHeaders());
        $this->assertResponseStatusCodeSame(200);
        $firstPage = \array_column(\json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR), 'name');
        $this->assertCount(2, $firstPage);

        $this->client->request('GET', '/api/collections?limit=2&offset=2', [], [], $this->authHeaders());
        $this->assertResponseStatusCodeSame(200);
        $secondPage = \array_column(\json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR), 'name');
        $this->assertCount(1, $secondPage);

        // The offset must really skip the first page and the two pages must
        // together cover every collection, whatever the repository ordering is.
        $this->assertEmpty(\array_intersect($firstPage, $secondPage));
        $this->assertCount(3, \array_unique(\array_merge($firstPage, $secondPage)));
    }

    public function testListWithInvalidLimitAndOwnerReportsPaginationFirst(): void
    {
        // Pagination is parsed before the owner id, so a request that is wrong
        // in both ways answers with the pagination reason.
        $this->client->request('GET', '/api/collections?limit=abc&owner=not-a-uuid', [], [], $this->authHeaders());

        $this->assertResponseStatusCodeSame(400);
        $response = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame('Invalid query parameters', $response['message']);
        $this->assertStringContainsString('limit', (string) $response['details'][0]);
        $this->assertStringNotContainsString('owner', \implode(' ', $response['details']));
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

    public function testGetForeignReturns200(): void
    {
        $this->client->request('POST', '/api/collections', [], [], $this->authHeaders(), \json_encode([
            'name' => 'Foreign Collection',
            'theme' => 'movies',
        ], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(201);

        $created = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $id = $created['id'];

        $foreign = $this->registerUser();

        $this->client->request('GET', '/api/collections/'.$id, [], [], $this->authHeaders($foreign['token']));

        $this->assertResponseStatusCodeSame(200);
        $response = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame($id, $response['id']);
        $this->assertSame('Foreign Collection', $response['name']);
    }

    public function testGetReturns404ForInvalidUuid(): void
    {
        $this->client->request('GET', '/api/collections/not-a-uuid', [], [], $this->authHeaders());

        $this->assertResponseStatusCodeSame(404);
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

    public function testUpdateReturns422WhenNoChanges(): void
    {
        $this->client->request('POST', '/api/collections', [], [], $this->authHeaders(), \json_encode([
            'name' => 'Empty PATCH',
            'theme' => 'books',
        ], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(201);
        $created = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $id = $created['id'];

        $this->client->request('PATCH', '/api/collections/'.$id, [], [], $this->authHeaders(), '{}');

        $this->assertResponseStatusCodeSame(422);
        $data = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame('Unprocessable Entity', $data['error']);
        $this->assertSame('At least one field must be provided for update', $data['message']);
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

    public function testCreateWithMalformedBodyReturns400(): void
    {
        $this->client->request('POST', '/api/collections', [], [], $this->authHeaders(), '{not-json');

        $this->assertResponseStatusCodeSame(400);
        $data = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame('Bad Request', $data['error']);
    }

    public function testUpdateWithMalformedBodyReturns400(): void
    {
        $this->client->request('POST', '/api/collections', [], [], $this->authHeaders(), \json_encode([
            'name' => 'Malformed body target',
            'theme' => 'books',
        ], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(201);
        $id = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['id'];

        $this->client->request('PATCH', '/api/collections/'.$id, [], [], $this->authHeaders(), '{not-json');

        $this->assertResponseStatusCodeSame(400);
        $data = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame('Bad Request', $data['error']);
    }

    public function testUpdateWithMalformedIdReturns404(): void
    {
        $this->client->request('PATCH', '/api/collections/not-a-uuid', [], [], $this->authHeaders(), \json_encode([
            'name' => 'Ghost update',
        ], \JSON_THROW_ON_ERROR));

        $this->assertResponseStatusCodeSame(404);
    }

    public function testDeleteWithMalformedIdReturns404(): void
    {
        $this->client->request('DELETE', '/api/collections/not-a-uuid', [], [], $this->authHeaders());

        $this->assertResponseStatusCodeSame(404);
    }
}
