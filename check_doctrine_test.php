<?php
require 'vendor/autoload.php';

// Check doctrine bundle test namespace
$namespace = 'Doctrine\Bundle\DoctrineBundle\Test';
$dir = 'vendor/doctrine/doctrine-bundle/Test';

if (is_dir($dir)) {
    echo "Files in {$namespace}:\n";
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            echo "  " . $file->getFilename() . "\n";
        }
    }
} else {
    echo "Directory not found: $dir\n";
}

// Check all doctrine bundle classes
$classes = get_declared_classes();
$doctrineClasses = array_filter($classes, fn($c) => str_starts_with($c, 'Doctrine\Bundle\DoctrineBundle'));
echo "\nLoaded Doctrine\Bundle\DoctrineBundle classes:\n";
foreach ($doctrineClasses as $c) {
    echo "  $c\n";
}