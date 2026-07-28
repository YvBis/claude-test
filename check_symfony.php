<?php
require 'vendor/autoload.php';

$classes = [
    'Symfony\Bridge\Doctrine\Test\DoctrineTestCase',
    'Symfony\Bridge\Doctrine\Test\DoctrineTestTrait',
];

foreach ($classes as $class) {
    echo $class . ': ' . (class_exists($class) ? 'EXISTS' : 'MISSING') . "\n";
}