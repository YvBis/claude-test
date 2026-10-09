<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Api\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Mechanical cover for the per-action IsGranted decision (7.1): every
 * controller behind /api/admin must carry #[IsGranted('ROLE_ADMIN')] at
 * class or method level. A future admin action that forgets its attribute
 * turns this red at commit time, whatever its HTTP method.
 *
 * Attribute inspection (not HTTP probing): a probe request would need a
 * per-method body fixture and would race argument resolution (guard runs at
 * CONTROLLER_ARGUMENTS, after argument resolving), so probing can 400/404 on
 * a correctly guarded route. The attribute is the guard — assert it directly.
 */
final class AdminGuardTest extends KernelTestCase
{
    public function testEveryAdminControllerRequiresAdminRole(): void
    {
        self::bootKernel();

        $routes = static::getContainer()->get(RouterInterface::class)->getRouteCollection();
        $checked = 0;

        foreach ($routes as $name => $route) {
            if (!\str_starts_with($route->getPath(), '/api/admin')) {
                continue;
            }

            $controller = $route->getDefault('_controller');

            self::assertIsString($controller, \sprintf('route %s must resolve to a controller string', $name));
            self::assertTrue(
                self::requiresAdmin($controller),
                \sprintf('admin route %s (%s) must carry IsGranted ROLE_ADMIN', $name, $controller),
            );
            ++$checked;
        }

        self::assertGreaterThan(0, $checked, 'the admin namespace must contain at least one route');
    }

    private static function requiresAdmin(string $controller): bool
    {
        if (!\str_contains($controller, '::')) {
            return false;
        }

        [$class, $method] = \explode('::', $controller, 2);

        if (!\class_exists($class) || !\method_exists($class, $method)) {
            return false;
        }

        $reflection = new \ReflectionMethod($class, $method);

        foreach ([$reflection, $reflection->getDeclaringClass()] as $target) {
            foreach ($target->getAttributes(IsGranted::class) as $attribute) {
                /** @var IsGranted $instance */
                $instance = $attribute->newInstance();

                if ('ROLE_ADMIN' === $instance->attribute) {
                    return true;
                }
            }
        }

        return false;
    }
}
