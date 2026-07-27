<?php

// Force APP_ENV=test BEFORE anything else loads
$_SERVER['APP_ENV'] = 'test';
$_ENV['APP_ENV'] = 'test';

use Symfony\Component\Dotenv\Dotenv;

require \dirname(__DIR__).'/vendor/autoload.php';

// In test environment, do NOT load .env - let phpunit.xml.dist control env vars
if (($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? '') !== 'test') {
    if (\method_exists(Dotenv::class, 'bootEnv')) {
        (new Dotenv())->bootEnv(\dirname(__DIR__).'/.env');
    }
}

if ($_SERVER['APP_DEBUG'] ?? false) {
    \umask(0o000);
}
