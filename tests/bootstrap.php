<?php

// Force APP_ENV=test BEFORE anything else loads
$_SERVER['APP_ENV'] = 'test';
$_ENV['APP_ENV'] = 'test';
$_SERVER['APP_DEBUG'] = '0';
$_ENV['APP_DEBUG'] = '0';

use Symfony\Component\Dotenv\Dotenv;

require \dirname(__DIR__).'/vendor/autoload.php';

// In test environment, do NOT load .env - let phpunit.xml.dist control env vars
if (($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? '') !== 'test') {
    if (\method_exists(Dotenv::class, 'bootEnv')) {
        (new Dotenv())->bootEnv(\dirname(__DIR__).'/.env');
    }
}

// Point the test suite at the dedicated test database. The docker container exports
// DATABASE_URL (dev `taskflow`) via env_file, which would otherwise shadow .env.test.
// Override only when the current value is not already the test database, so CI (which
// provides its own .../taskflow_test URL) is left untouched.
$currentDatabaseUrl = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? (\getenv('DATABASE_URL') ?: '');
if (!\str_contains((string) $currentDatabaseUrl, 'taskflow_test')) {
    $testEnvFile = \dirname(__DIR__).'/.env.test';
    $testEnvContent = \is_file($testEnvFile) ? \file_get_contents($testEnvFile) : false;
    if (false !== $testEnvContent) {
        $testDatabaseUrl = (new Dotenv())->parse($testEnvContent, $testEnvFile)['DATABASE_URL'] ?? null;
        if (\is_string($testDatabaseUrl)) {
            $_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = $testDatabaseUrl;
            \putenv('DATABASE_URL='.$testDatabaseUrl);
        }
    }
}

if ($_SERVER['APP_DEBUG'] ?? false) {
    \umask(0o000);
}
