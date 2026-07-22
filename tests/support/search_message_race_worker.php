<?php

declare(strict_types=1);

use plugin\saimulti\service\module\SearchService;
use support\think\Db;

$ready = (string) ($argv[1] ?? '');
$organization = (int) ($argv[2] ?? 0);
$userId = (string) ($argv[3] ?? '');
$keyword = (string) ($argv[4] ?? '');
$conversationId = (string) ($argv[5] ?? '');
$page = (int) ($argv[6] ?? 1);
$limit = (int) ($argv[7] ?? 20);
$candidatePageBarrier = (string) ($argv[8] ?? '');
$finalCountRelease = (string) ($argv[9] ?? '');
if ($ready === ''
    || $organization <= 0
    || $userId === ''
    || $keyword === ''
    || $page <= 0
    || $limit <= 0
    || (($candidatePageBarrier === '') !== ($finalCountRelease === ''))) {
    fwrite(STDERR, "Invalid search race worker input.\n");
    exit(2);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/support/bootstrap.php';

$connection = Db::query('SELECT CONNECTION_ID() AS id')[0] ?? null;
$connectionId = (int) ($connection['id'] ?? 0);
if ($connectionId <= 0 || file_put_contents($ready, (string) $connectionId) === false) {
    fwrite(STDERR, "Search race worker cannot publish its connection identity.\n");
    exit(3);
}

$evidence = [
    'candidate_page_selects' => 0,
    'final_count_selects' => 0,
    'candidate_page_barrier_released' => false,
];
if ($candidatePageBarrier !== '') {
    Db::listen(static function (string $sql) use (
        &$evidence,
        $candidatePageBarrier,
        $finalCountRelease,
    ): void {
        $normalized = strtolower((string) preg_replace('/\s+/', ' ', trim($sql)));
        $isCandidatePage = preg_match(
            '/\bfrom\s+\x60?sm_search_doc(?:\x60|\b)/',
            $normalized,
        ) === 1
            && str_contains($normalized, 'order by')
            && preg_match('/\blimit\s+[0-9]+\s*,\s*[0-9]+\b/', $normalized) === 1
            && !str_contains($normalized, 'count(*) over');
        if ($isCandidatePage) {
            ++$evidence['candidate_page_selects'];
        }
        if ($isCandidatePage && $evidence['candidate_page_selects'] === 1) {
            $stage = json_encode([
                'stage' => 'candidate_page_select_complete',
                'candidate_page_selects' => $evidence['candidate_page_selects'],
                'sql_sha256' => hash('sha256', $normalized),
            ], JSON_THROW_ON_ERROR);
            if (file_put_contents($candidatePageBarrier, $stage, LOCK_EX) === false) {
                throw new RuntimeException('Search race worker cannot publish candidate-page stage.');
            }
            $deadline = microtime(true) + 10;
            while (!is_file($finalCountRelease)) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Search race worker final-count release timed out.');
                }
                usleep(10000);
            }
            $evidence['candidate_page_barrier_released'] = true;
        }
        if (preg_match(
            '/\bfrom\s+\x60?sm_search_index(?:\x60\s+|\s+)final_index\b/',
            $normalized,
        ) === 1
            && preg_match(
                '/\bleft\s+join\s+\x60?sm_search_doc(?:\x60|\b)/',
                $normalized,
            ) === 1
            && str_contains($normalized, '__verified_total')) {
            ++$evidence['final_count_selects'];
        }
    });
}

try {
    $filters = [
        'q' => $keyword,
        'page' => $page,
        'limit' => $limit,
    ];
    if ($conversationId !== '') {
        $filters['conversation_id'] = $conversationId;
    }
    $result = (new SearchService())->searchMessages(
        $organization,
        $userId,
        $filters,
    );
    fwrite(STDOUT, json_encode([
        'ok' => true,
        'total' => $result['total'],
        'evidence' => $evidence,
    ], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    fwrite(STDOUT, json_encode([
        'ok' => false,
        'class' => $exception::class,
        'code' => $exception->getCode(),
        'message' => $exception->getMessage(),
        'evidence' => $evidence,
    ], JSON_THROW_ON_ERROR));
}
