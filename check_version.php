<?php
$content = file_get_contents('composer.lock');
$data = json_decode($content, true);

foreach ($data['packages'] as $pkg) {
    if ($pkg['name'] === 'doctrine/doctrine-bundle') {
        echo "doctrine/doctrine-bundle: {$pkg['version']}\n";
        break;
    }
}