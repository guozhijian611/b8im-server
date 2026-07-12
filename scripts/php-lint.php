<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$targets = ['app', 'config', 'database', 'plugin', 'scripts', 'support', 'tests'];
$failed = [];
$count = 0;

foreach ($targets as $target) {
    $path = $root . DIRECTORY_SEPARATOR . $target;
    if (!is_dir($path)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $count++;
        $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname()) . ' 2>&1';
        exec($command, $output, $status);
        if ($status !== 0) {
            $failed[] = implode("\n", $output);
        }
        $output = [];
    }
}

if ($failed !== []) {
    fwrite(STDERR, implode("\n", $failed) . "\n");
    exit(1);
}

fwrite(STDOUT, "PHP lint passed: {$count} files\n");
