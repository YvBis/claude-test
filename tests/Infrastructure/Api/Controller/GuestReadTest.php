<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Api\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\AccessMap;

/**
 * Guest reads (fwd-7, PRD story 37): an anonymous client may read collections,
 * items and their social content, but nothing else.
 *
 * This lives in one file rather than being scattered across the four controller
 * tests because the behaviour under test is a cross-cutting firewall decision,
 * not the business logic of any single controller.
 */
final class GuestReadTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $token;
    private string $userId;
    private string $collectionId;
    private string $itemId;
    /** @var list<string> */
    private array $createdUserIds = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        [$this->token, $this->userId] = $this->registerAndLogin('guest_owner');
        $this->collectionId = $this->createCollection();
        $this->itemId = $this->createItem();
        $this->createLike();
        $this->createComment();
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $conn = $em->getConnection();

        foreach (\array_merge([$this->userId], $this->createdUserIds) as $id) {
            $conn->executeStatement(
                'DELETE FROM users WHERE id = UNHEX(REPLACE(?, "-", ""))',
                [\preg_replace('/-/', '', $id)],
            );
        }

        $em->clear();
        parent::tearDown();
    }

    /**
     * @return array{string, string} token and user id
     */
    private function registerAndLogin(string $prefix): array
    {
        $email = $prefix.'_'.\str_replace('.', '', \uniqid('', true)).'@example.com';

        $this->client->request('POST', '/api/register', [], [], ['CONTENT_TYPE' => 'application/json'], \json_encode([
            'name' => 'Guest Fixture',
            'email' => $email,
            'password' => 'password123',
        ], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(201);
        $id = $this->decoded()['id'];

        $this->client->request('POST', '/api/login', [], [], ['CONTENT_TYPE' => 'application/json'], \json_encode([
            'email' => $email,
            'password' => 'password123',
        ], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(200);

        return [$this->decoded()['access_token'], $id];
    }

    private function authHeaders(): array
    {
        return [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
            'CONTENT_TYPE' => 'application/json',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decoded(): array
    {
        return \json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    private function createCollection(): string
    {
        $this->client->request('POST', '/api/collections', [], [], $this->authHeaders(), \json_encode([
            'name' => 'Guest Visible Collection',
            'theme' => 'books',
        ], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(201);

        return $this->decoded()['id'];
    }

    private function createItem(): string
    {
        $this->client->request('POST', '/api/collections/'.$this->collectionId.'/items', [], [], $this->authHeaders(), \json_encode([
            'name' => 'Guest Visible Item',
            'tags' => ['scifi'],
        ], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(201);

        return $this->decoded()['id'];
    }

    private function createLike(): void
    {
        $this->client->request('POST', '/api/items/'.$this->itemId.'/likes', [], [], $this->authHeaders());
        $this->assertResponseStatusCodeSame(200);
    }

    private function createComment(): void
    {
        $this->client->request('POST', '/api/items/'.$this->itemId.'/comments', [], [], $this->authHeaders(), \json_encode([
            'content' => 'Guest visible comment body',
        ], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(201);
    }

    // ---------------------------------------------------------------- reads

    public function testGuestCanReadCollectionsOfAnOwner(): void
    {
        $this->client->request('GET', '/api/collections?owner='.$this->userId);

        $this->assertResponseIsSuccessful();
        $data = $this->decoded();
        $this->assertCount(1, $data);
        $this->assertSame($this->collectionId, $data[0]['id']);
        $this->assertSame('Guest Visible Collection', $data[0]['name']);
        $this->assertSame($this->userId, $data[0]['owner_id']);
    }

    public function testGuestCollectionsWithoutOwnerReturns400(): void
    {
        $this->client->request('GET', '/api/collections');

        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('Bad Request', $this->decoded()['error']);
    }

    public function testGuestCanReadOneCollection(): void
    {
        $this->client->request('GET', '/api/collections/'.$this->collectionId);

        $this->assertResponseIsSuccessful();
        $data = $this->decoded();
        $this->assertSame($this->collectionId, $data['id']);
        $this->assertSame('books', $data['theme']);
    }

    public function testGuestCanReadItemsOfACollection(): void
    {
        $this->client->request('GET', '/api/collections/'.$this->collectionId.'/items');

        $this->assertResponseIsSuccessful();
        $data = $this->decoded();
        $this->assertCount(1, $data);
        $this->assertSame($this->itemId, $data[0]['id']);
        $this->assertSame('Guest Visible Item', $data[0]['name']);
        $this->assertCount(1, $data[0]['tags']);
    }

    public function testGuestCanReadOneItemWithFalseLikedByMe(): void
    {
        $this->client->request('GET', '/api/items/'.$this->itemId);

        $this->assertResponseIsSuccessful();
        $data = $this->decoded();
        $this->assertSame($this->itemId, $data['id']);
        $this->assertSame('Guest Visible Item', $data['name']);
        $this->assertSame(1, $data['likes_count']);
        $this->assertSame(1, $data['comments_count']);
        // The fixture owner DID like the item, so a hardcoded false would hide a
        // bug where liked_by_me leaks the authenticated branch.
        $this->assertFalse($data['liked_by_me']);
    }

    public function testGuestCanReadLikesOfAnItem(): void
    {
        $this->client->request('GET', '/api/items/'.$this->itemId.'/likes');

        $this->assertResponseIsSuccessful();
        $data = $this->decoded();
        $this->assertCount(1, $data);
        $this->assertSame($this->userId, $data[0]['owner_id']);
        $this->assertSame('Guest Fixture', $data[0]['owner_name']);
        $this->assertSame($this->itemId, $data[0]['item_id']);
    }

    public function testGuestCanReadCommentsOfAnItem(): void
    {
        $this->client->request('GET', '/api/items/'.$this->itemId.'/comments');

        $this->assertResponseIsSuccessful();
        $data = $this->decoded();
        $this->assertCount(1, $data);
        $this->assertSame('Guest visible comment body', $data[0]['content']);
        $this->assertSame($this->userId, $data[0]['owner_id']);
        $this->assertSame('Guest Fixture', $data[0]['owner_name']);
    }

    public function testGuestStillGets404ForAMissingItem(): void
    {
        $this->client->request('GET', '/api/items/018f0a1b-2c3d-4e5f-6789-0123456789ab');

        $this->assertResponseStatusCodeSame(404);
    }

    // ------------------------------------------------------ HEAD and bad Bearer
    // Both are claimed in security.yaml comments and in the PRD, so both are
    // pinned here rather than left as prose. A refactor that treats Bearer as
    // optional for PUBLIC_ACCESS rows would silently turn the second block green.

    /**
     * @return array<string, array{0: string}>
     */
    public static function guestReadEndpoints(): array
    {
        $itemId = '018f0a1b-2c3d-4e5f-6789-0123456789ab';
        $collectionId = '018f0a1b-2c3d-4e5f-6789-0123456789ab';

        return [
            'collections by owner' => ['/api/collections?owner=x'],
            'collections' => ['/api/collections'],
            'one collection' => ['/api/collections/'.$collectionId],
            'items of a collection' => ['/api/collections/'.$collectionId.'/items'],
            'one item' => ['/api/items/'.$itemId],
            'likes of an item' => ['/api/items/'.$itemId.'/likes'],
            'comments of an item' => ['/api/items/'.$itemId.'/comments'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('guestReadEndpoints')]
    public function testHeadMirrorsGetOnPublicReads(string $path): void
    {
        $this->client->request('GET', $path);
        $get = $this->client->getResponse()->getStatusCode();

        $this->client->request('HEAD', $path);
        $head = $this->client->getResponse()->getStatusCode();

        $this->assertSame($get, $head, 'HEAD must not answer 401 where GET answers '.$get);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('guestReadEndpoints')]
    public function testPublicReadsRejectAnInvalidBearerToken(string $path): void
    {
        // A client that presents a broken token is told so instead of being
        // silently downgraded to an anonymous read: the JWT authenticator runs
        // before the access listener and rejects the credential.
        $this->client->request('GET', $path, [], [], ['HTTP_AUTHORIZATION' => 'Bearer not-a-jwt']);

        $this->assertResponseStatusCodeSame(401);
        $this->assertSame('Unauthorized', $this->decoded()['error']);
    }

    // ------------------------------------------------------------ regressions
    // Opening reads must not open anything else. Each of these asserts 401 with
    // NO Authorization header at all.

    public function testGuestCannotListOwnItems(): void
    {
        $this->client->request('GET', '/api/items');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testGuestCannotListOwnLikes(): void
    {
        $this->client->request('GET', '/api/likes');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testGuestCannotListOwnComments(): void
    {
        $this->client->request('GET', '/api/comments');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testGuestCannotListTags(): void
    {
        $this->client->request('GET', '/api/tags');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testGuestCannotCreateCollection(): void
    {
        $this->client->request('POST', '/api/collections', [], [], ['CONTENT_TYPE' => 'application/json'], \json_encode([
            'name' => 'Guest Attempt',
            'theme' => 'books',
        ], \JSON_THROW_ON_ERROR));

        $this->assertResponseStatusCodeSame(401);
    }

    public function testGuestCannotCreateItemInACollection(): void
    {
        $this->client->request('POST', '/api/collections/'.$this->collectionId.'/items', [], [], ['CONTENT_TYPE' => 'application/json'], \json_encode([
            'name' => 'Guest Attempt',
        ], \JSON_THROW_ON_ERROR));

        $this->assertResponseStatusCodeSame(401);
    }

    public function testGuestCannotLikeAnItem(): void
    {
        $this->client->request('POST', '/api/items/'.$this->itemId.'/likes');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testGuestCannotUnlikeAnItem(): void
    {
        $this->client->request('DELETE', '/api/items/'.$this->itemId.'/likes');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testGuestCannotCommentOnAnItem(): void
    {
        $this->client->request('POST', '/api/items/'.$this->itemId.'/comments', [], [], ['CONTENT_TYPE' => 'application/json'], \json_encode([
            'content' => 'Guest attempt',
        ], \JSON_THROW_ON_ERROR));

        $this->assertResponseStatusCodeSame(401);
    }

    public function testGuestCannotUpdateACollection(): void
    {
        $this->client->request('PATCH', '/api/collections/'.$this->collectionId, [], [], ['CONTENT_TYPE' => 'application/json'], \json_encode([
            'name' => 'Guest Attempt',
        ], \JSON_THROW_ON_ERROR));

        $this->assertResponseStatusCodeSame(401);
    }

    public function testGuestCannotDeleteACollection(): void
    {
        $this->client->request('DELETE', '/api/collections/'.$this->collectionId);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testGuestCannotUpdateAnItem(): void
    {
        $this->client->request('PATCH', '/api/items/'.$this->itemId, [], [], ['CONTENT_TYPE' => 'application/json'], \json_encode([
            'name' => 'Guest Attempt',
        ], \JSON_THROW_ON_ERROR));

        $this->assertResponseStatusCodeSame(401);
    }

    public function testGuestCannotDeleteAnItem(): void
    {
        $this->client->request('DELETE', '/api/items/'.$this->itemId);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testGuestCannotLogout(): void
    {
        $this->client->request('POST', '/api/logout');

        $this->assertResponseStatusCodeSame(401);
    }

    // --------------------------------------------------- the firewall itself
    // The 14 tests above each pass even if the firewall were fully open,
    // because every one of those endpoints also checks the user in its own
    // controller. They prove defence in depth, not the boundary. These assert
    // the access map directly, so flipping the catch-all to PUBLIC_ACCESS — the
    // exact mutation that would silently expose every write endpoint — fails
    // here.

    public function testAccessMapOpensTheSixGuestReads(): void
    {
        $itemId = '018f0a1b-2c3d-4e5f-6789-0123456789ab';

        $opened = [
            ['GET', '/api/collections?owner=x'],
            // Already public at the firewall before fwd-7; the controller answers
            // 400 when a guest omits ?owner=, so the firewall row is not new here.
            ['GET', '/api/collections'],
            ['GET', '/api/collections/'.$this->collectionId],
            ['GET', '/api/collections/'.$this->collectionId.'/items'],
            ['GET', '/api/items/'.$itemId],
            ['GET', '/api/items/'.$itemId.'/likes'],
            ['GET', '/api/items/'.$itemId.'/comments'],
        ];

        foreach ($opened as [$method, $path]) {
            $this->assertSame(
                ['PUBLIC_ACCESS'],
                $this->patternsFor($method, $path),
                $method.' '.$path.' must stay readable by a guest',
            );
        }
    }

    public function testAccessMapKeepsEverythingElseAuthenticated(): void
    {
        $itemId = '018f0a1b-2c3d-4e5f-6789-0123456789ab';

        $closed = [
            // Own listings have no segment after the collection name.
            ['GET', '/api/items'],
            ['GET', '/api/likes'],
            ['GET', '/api/comments'],
            ['GET', '/api/tags'],
            // Every mutation, including on the two paths whose GET is public.
            ['POST', '/api/collections'],
            ['PATCH', '/api/collections/'.$this->collectionId],
            ['DELETE', '/api/collections/'.$this->collectionId],
            ['POST', '/api/collections/'.$this->collectionId.'/items'],
            ['PATCH', '/api/items/'.$itemId],
            ['DELETE', '/api/items/'.$itemId],
            ['POST', '/api/items/'.$itemId.'/likes'],
            ['DELETE', '/api/items/'.$itemId.'/likes'],
            ['POST', '/api/items/'.$itemId.'/comments'],
            // The reason the /api/items rows are anchored rather than one
            // namespace row: an unknown sub-resource must NOT inherit the
            // public read of its parent. This path has no route today, so the
            // assertion is about the access map, not about a response.
            ['GET', '/api/items/'.$itemId.'/reports'],
            ['POST', '/api/logout'],
        ];

        foreach ($closed as [$method, $path]) {
            $this->assertSame(
                ['IS_AUTHENTICATED_FULLY'],
                $this->patternsFor($method, $path),
                $method.' '.$path.' must require authentication',
            );
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private function patternsFor(string $method, string $path): array
    {
        $accessMap = static::getContainer()->get('security.access_map');
        \assert($accessMap instanceof AccessMap);

        $patterns = $accessMap->getPatterns(Request::create($path, $method));

        return (array) $patterns[0];
    }
}
