<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Api\Controller;

use App\Domain\Tag\Entity\Tag;
use App\Domain\Tag\ValueObject\TagName;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TagControllerTest extends WebTestCase
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
        $conn = static::getContainer()->get('doctrine.orm.entity_manager')->getConnection();
        $conn->executeStatement(
            'DELETE FROM users WHERE id = UNHEX(REPLACE(?, "-", ""))',
            [\preg_replace('/-/', '', $this->userId)],
        );

        parent::tearDown();
    }

    private function registerAndLogin(): void
    {
        $unique = \uniqid('', true);
        $email = 'tag_'.\str_replace('.', '', $unique).'@example.com';

        $this->client->request('POST', '/api/register', [], [], ['CONTENT_TYPE' => 'application/json'], \json_encode([
            'name' => 'Tag Tester',
            'email' => $email,
            'password' => 'password123',
        ], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(201);

        $this->userId = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['id'];

        $this->client->request('POST', '/api/login', [], [], ['CONTENT_TYPE' => 'application/json'], \json_encode([
            'email' => $email,
            'password' => 'password123',
        ], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(200);

        $this->token = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['access_token'];
    }

    /**
     * @param list<string> $names
     */
    private function seedTags(array $names): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        foreach ($names as $name) {
            $em->persist(Tag::create(TagName::fromString($name)));
        }
        $em->flush();
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token];
    }

    public function testListRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/tags');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testListReturnsTagsOrderedByName(): void
    {
        $this->seedTags(['Books', 'Games', 'Movies']);

        $this->client->request('GET', '/api/tags', [], [], $this->authHeaders());

        $this->assertResponseStatusCodeSame(200);
        $tags = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        $this->assertSame(['Books', 'Games', 'Movies'], \array_column($tags, 'name'));
        $this->assertArrayHasKey('id', $tags[0]);
    }

    public function testSearchIsCaseInsensitiveSubstring(): void
    {
        $this->seedTags(['Books', 'eBooks', 'Games']);

        $this->client->request('GET', '/api/tags', ['search' => 'ook'], [], $this->authHeaders());

        $this->assertResponseStatusCodeSame(200);
        $tags = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        $this->assertSame(['Books', 'eBooks'], \array_column($tags, 'name'));
    }

    public function testSearchEscapesWildcards(): void
    {
        $this->seedTags(['_private', 'Books']);

        $this->client->request('GET', '/api/tags', ['search' => '_'], [], $this->authHeaders());

        $this->assertResponseStatusCodeSame(200);
        $tags = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        $this->assertSame(['_private'], \array_column($tags, 'name'));
    }

    public function testNonScalarSearchReturns400WithEnvelope(): void
    {
        $this->client->request('GET', '/api/tags?search%5B%5D=q', [], [], $this->authHeaders());

        $this->assertResponseStatusCodeSame(400);
        $this->assertJsonStringEqualsJsonString(
            '{"error":"Bad Request","message":"Invalid query parameters","details":["Invalid search: must be a string"]}',
            $this->client->getResponse()->getContent()
        );
        $this->assertSame('application/json', $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testNonScalarSearchAndInvalidLimitReportsPaginationFirst(): void
    {
        $this->client->request('GET', '/api/tags?search%5B%5D=q&limit=abc', [], [], $this->authHeaders());

        $this->assertResponseStatusCodeSame(400);
        $data = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame('Invalid query parameters', $data['message']);
        $this->assertStringContainsString('limit', (string) $data['details'][0]);
        $this->assertStringNotContainsString('search', \implode(' ', $data['details']));
    }

    public function testArrayLimitReturns400WithEnvelope(): void
    {
        $this->client->request('GET', '/api/tags?limit%5B%5D=1', [], [], $this->authHeaders());

        $this->assertResponseStatusCodeSame(400);
        $this->assertJsonStringEqualsJsonString(
            '{"error":"Bad Request","message":"Invalid query parameters","details":["Invalid limit: must be a non-negative integer"]}',
            $this->client->getResponse()->getContent()
        );
        $this->assertSame('application/json', $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testListPagination(): void
    {
        $this->seedTags(['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon']);

        $this->client->request('GET', '/api/tags', ['limit' => 2, 'offset' => 1], [], $this->authHeaders());

        $this->assertResponseStatusCodeSame(200);
        $tags = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        $this->assertSame(['Beta', 'Delta'], \array_column($tags, 'name'));
    }

    public function testInvalidLimitReturns400(): void
    {
        $this->client->request('GET', '/api/tags', ['limit' => 'abc'], [], $this->authHeaders());

        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('Bad Request', \json_decode($this->client->getResponse()->getContent(), true)['error']);
    }

    public function testLimitOutOfRangeReturns400(): void
    {
        $this->client->request('GET', '/api/tags', ['limit' => '101'], [], $this->authHeaders());

        $this->assertResponseStatusCodeSame(400);
    }

    public function testLimitZeroReturns400(): void
    {
        $this->client->request('GET', '/api/tags', ['limit' => '0'], [], $this->authHeaders());

        $this->assertResponseStatusCodeSame(400);
    }

    public function testInvalidOffsetReturns400(): void
    {
        $this->client->request('GET', '/api/tags', ['offset' => '-1'], [], $this->authHeaders());

        $this->assertResponseStatusCodeSame(400);
    }
}
