<?php

declare(strict_types=1);

use plugin\saimulti\service\module\SearchService;

$barrier = (string) ($argv[1] ?? '');
$ready = (string) ($argv[2] ?? '');
$organization = (int) ($argv[3] ?? 0);
if ($barrier === '' || $ready === '' || $organization <= 0) {
    fwrite(STDERR, "Invalid search index concurrency worker input.\n");
    exit(2);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/support/bootstrap.php';

if (!touch($ready)) {
    fwrite(STDERR, "Search index concurrency worker cannot signal readiness.\n");
    exit(3);
}

$deadline = microtime(true) + 10;
while (!is_file($barrier)) {
    if (microtime(true) >= $deadline) {
        fwrite(STDERR, "Search index concurrency barrier timed out.\n");
        exit(4);
    }
    usleep(10000);
}

$row = (new SearchService())->indexRead($organization, true);
if (($row['organization'] ?? null) !== $organization) {
    fwrite(STDERR, "Search index concurrency worker received the wrong row.\n");
    exit(5);
}

fwrite(STDOUT, (string) $organization);
