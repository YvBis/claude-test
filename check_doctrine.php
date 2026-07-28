<?php

require 'vendor/autoload.php';

$classes = [
    'Doctrine\Bundle\DoctrineBundle\Test\DoctrineTransactionalTestCase',
    'Doctrine\Bundle\DoctrineBundle\Test\DoctrineTestCase',
    'Doctrine\Bundle\DoctrineBundle\Test\DoctrineTestTrait',
];

foreach ($classes as $class) {
    echo $class.': '.(class_exists($class) ? 'EXISTS' : 'MISSING')."\n";
}

if (class_exists('Doctrine\Bundle\DoctrineBundle\Test\DoctrineTestCase')) {
    $r = new ReflectionClass('Doctrine\Bundle\DoctrineBundle\Test\DoctrineTestCase');
    echo "\nDoctrineTestCase methods:\n";
    foreach ($r->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED) as $m) {
        $name = $m->getName();
        if (false !== stripos($name, 'transaction') || false !== stripos($name, 'tearDown') || false !== stripos($name, 'setUp')) {
            echo "  - $name\n";
        }
    }
}
