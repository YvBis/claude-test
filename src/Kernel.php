<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        $bundles = [
            new \Symfony\Bundle\FrameworkBundle\FrameworkBundle(),
            new \Symfony\Bundle\TwigBundle\TwigBundle(),
            new \Symfony\Bundle\SecurityBundle\SecurityBundle(),
            new \Doctrine\Bundle\DoctrineBundle\DoctrineBundle(),
            new \Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle(),
            new \Lexik\Bundle\JWTAuthenticationBundle\LexikJWTAuthenticationBundle(),
        ];

        if ('dev' === $this->environment || 'test' === $this->environment) {
            $bundles[] = new \Nelmio\ApiDocBundle\NelmioApiDocBundle();
        }

        if ('test' === $this->environment) {
            $bundles[] = new \DAMA\DoctrineTestBundle\DAMADoctrineTestBundle();
        }

        foreach ($bundles as $bundle) {
            yield $bundle;
        }
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $configDir = $this->getProjectDir().'/config';
        $container->import($configDir.'/packages/*.yaml');
        \file_put_contents('/tmp/kernel_debug.log', "Before env import, env={$this->environment}, dir={$configDir}/packages/{$this->environment}\n", FILE_APPEND);

        $envPackagesDir = $configDir.'/packages/'.$this->environment;
        if (\is_dir($envPackagesDir)) {
            $container->import($envPackagesDir.'/*.yaml');
            \file_put_contents('/tmp/kernel_debug.log', 'After env import, files: '.\json_encode(\glob($envPackagesDir.'/*.yaml'))."\n", FILE_APPEND);
        } else {
            \file_put_contents('/tmp/kernel_debug.log', "Env dir does not exist: $envPackagesDir\n", FILE_APPEND);
        }

        if (\is_file($this->getConfigDir().'/services.yaml')) {
            $container->import($configDir.'/services.yaml');
            $container->import($configDir.'/{services}_'.$this->environment.'.yaml');
        } else {
            $container->import($configDir.'/{services}.php');
            $container->import($configDir.'/{services}_'.$this->environment.'.php');
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $configDir = $this->getProjectDir().'/config';
        if ('test' !== $this->environment) {
            \error_log("[KERNEL DEBUG] configureRoutes: env={$this->environment}");
        }
        $routes->import($configDir.'/{routes}/'.$this->environment.'/*.{php,yaml}');
        $routes->import($configDir.'/{routes}/*.{php,yaml}');
        // Import attribute routes from Controllers
        $routes->import($this->getProjectDir().'/src/Infrastructure/Api/Controller/', 'attribute');
    }

    public function isDebug(): bool
    {
        return 'test' !== $this->environment && parent::isDebug();
    }
}
