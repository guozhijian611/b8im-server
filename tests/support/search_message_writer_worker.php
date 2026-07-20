<?php

declare(strict_types=1);

use plugin\saimulti\service\module\SearchService;
use support\think\Db;

$ready = (string) ($argv[1] ?? '');
$organization = (int) ($argv[2] ?? 0);
$messageId = (string) ($argv[3] ?? '');
if ($ready === '' || $organization <= 0 || $messageId === '') {
    fwrite(STDERR, "Invalid search writer worker input.\n");
    exit(2);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/support/bootstrap.php';

$connection = Db::query('SELECT CONNECTION_ID() AS id')[0] ?? null;
$connectionId = (int) ($connection['id'] ?? 0);
if ($connectionId <= 0 || file_put_contents($ready, (string) $connectionId) === false) {
    fwrite(STDERR, "Search writer worker cannot publish its connection identity.\n");
    exit(3);
}

try {
    $document = (new SearchService())->upsertMessageDocument($organization, $messageId);
    $source = Db::query(
        'SELECT CAST(source_change_seq AS CHAR) AS source_change_seq
           FROM sm_search_doc
          WHERE organization = ? AND BINARY message_id = BINARY ?',
        [$organization, $messageId],
    )[0] ?? null;
    fwrite(STDOUT, json_encode([
        'ok' => true,
        'message_id' => $document['message_id'] ?? null,
        'content' => $document['content'] ?? null,
        'visibility' => $document['visibility'] ?? null,
        'source_change_seq' => $source['source_change_seq'] ?? null,
    ], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    fwrite(STDOUT, json_encode([
        'ok' => false,
        'class' => $exception::class,
        'code' => $exception->getCode(),
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR));
}
