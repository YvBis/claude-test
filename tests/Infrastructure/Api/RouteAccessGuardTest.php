<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Api;

use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Http\AccessMap;

/**
 * Mechanical firewall cells (task 8.6, D1): what the access map says about
 * every mutating route, driven by the router rather than a static list.
 *
 * Only two cells are mechanical, and only those two are asserted here:
 *
 * - guest → 401 is derivable from the access map (the first matching row wins,
 *   with `^/api` → IS_AUTHENTICATED_FULLY as the catch-all);
 * - stranger → 403 is NOT derivable: owner-or-admin is an imperative call in
 *   the controller body (`denyUnlessCanManage`), invisible to reflection and
 *   to security.yaml. That cell stays with the functional spot checks, and
 *   this test must never be renamed into a "matrix" that implies it.
 *
 * A new mutating route is classified by the rule, not by a list: anything not
 * in PUBLIC_MUTATIONS must resolve to IS_AUTHENTICATED_FULLY, so adding a
 * public mutation to security.yaml without listing it here turns this red.
 * Guest-readable GETs belong to GuestReadTest; admin attributes belong to
 * AdminGuardTest. This test owns the mutating methods and nothing else.
 */
#[CoversNothing]
final class RouteAccessGuardTest extends KernelTestCase
{
    /**
     * Mutating /api* routes that are deliberately reachable by a guest.
     * Login and register cannot require a token: the caller has nothing to
     * present yet. Everything else mutating must fall through to the
     * IS_AUTHENTICATED_FULLY catch-all.
     *
     * @var array<string, string> "METHOD /path" => reason
     */
    private const PUBLIC_MUTATIONS = [
        'POST /api/login' => 'issues the token; a guest has nothing to present yet',
        'POST /api/register' => 'creates the account; a guest has nothing to present yet',
    ];

    public function testEveryMutatingRouteIsAuthenticatedUnlessExplicitlyPublic(): void
    {
        $checked = 0;

        foreach ($this->mutatingOperations() as [$method, $path]) {
            self::assertSame(
                $this->expectedAccess($method, $path),
                $this->patternsFor($method, $path),
                $method.' '.$path.' has an unexpected firewall decision. '
                .'A new public mutation must be listed in PUBLIC_MUTATIONS with a reason; '
                .'anything else must fall through to the authenticated catch-all.',
            );
            ++$checked;
        }

        self::assertGreaterThan(0, $checked, 'No mutating /api routes found - the router scan is broken.');
    }

    public function testAccessExpectationRejectsDrift(): void
    {
        // Negative probe: the helper behind the guard above must actually
        // distinguish the two outcomes, otherwise the guard proves nothing.
        self::assertSame(['PUBLIC_ACCESS'], $this->expectedAccess('POST', '/api/login'));
        self::assertSame(['IS_AUTHENTICATED_FULLY'], $this->expectedAccess('DELETE', '/api/items/whatever'));
        self::assertSame(['IS_AUTHENTICATED_FULLY'], $this->expectedAccess('POST', '/api/widgets'));
    }

    /**
     * @return list<array{string, string}> [METHOD, path] for every mutating /api* route
     */
    private function mutatingOperations(): array
    {
        self::bootKernel();

        $operations = [];
        foreach (static::getContainer()->get(RouterInterface::class)->getRouteCollection() as $name => $route) {
            if (\str_starts_with((string) $name, '_')) {
                continue;
            }

            $path = $route->getPath();
            if (!\str_starts_with($path, '/api')) {
                continue;
            }

            $methods = $route->getMethods();
            self::assertNotEmpty(
                $methods,
                \sprintf('Route %s (%s) must pin its HTTP methods explicitly; an unpinned route answers every method.', $name, $path),
            );

            foreach ($methods as $method) {
                if (!\in_array(\strtoupper($method), ['POST', 'PATCH', 'PUT', 'DELETE'], true)) {
                    continue;
                }

                $operations[] = [\strtoupper($method), $path];
            }
        }

        return $operations;
    }

    /**
     * @return list<string>
     */
    private function expectedAccess(string $method, string $path): array
    {
        return \array_key_exists(\strtoupper($method).' '.$path, self::PUBLIC_MUTATIONS)
            ? ['PUBLIC_ACCESS']
            : ['IS_AUTHENTICATED_FULLY'];
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
