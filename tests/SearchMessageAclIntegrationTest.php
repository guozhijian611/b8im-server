<?php

declare(strict_types=1);

$database = trim((string) getenv('SEARCH_ACL_TEST_DB_NAME'));
if (preg_match('/^nb8im_[a-f0-9]{8,24}_search_acl_test$/D', $database) !== 1) {
    throw new RuntimeException('Search ACL integration requires a random *_search_acl_test database.');
}
putenv('DB_NAME=' . $database);
$_ENV['DB_NAME'] = $database;
$_SERVER['DB_NAME'] = $database;
putenv('APP_DEBUG=true');
$_ENV['APP_DEBUG'] = 'true';
$_SERVER['APP_DEBUG'] = 'true';

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/support/bootstrap.php';

putenv('DB_NAME=' . $database);
$_ENV['DB_NAME'] = $database;
$_SERVER['DB_NAME'] = $database;

use B8im\ImShared\Support\MessageShardIdentity;
use plugin\saimulti\app\controller\app\SearchController as AppSearchController;
use plugin\saimulti\app\controller\web\SearchController as WebSearchController;
use plugin\saimulti\basic\WebController;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\exception\SearchProjectionIntegrityException;
use plugin\saimulti\service\module\SearchService;
use support\Request;
use support\think\Db;

$config = config('think-orm');
$connectionName = (string) ($config['default'] ?? 'mysql');
$connection = $config['connections'][$connectionName] ?? null;
if (!is_array($connection)) {
    throw new RuntimeException('ThinkORM MySQL connection is unavailable.');
}
$dsn = sprintf(
    'mysql:host=%s;port=%d;charset=%s',
    (string) $connection['hostname'],
    (int) $connection['hostport'],
    (string) $connection['charset'],
);
$admin = new PDO($dsn, (string) $connection['username'], (string) $connection['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$quotedDatabase = '`' . $database . '`';
register_shutdown_function(static function () use ($admin, $quotedDatabase): void {
    try {
        $admin->exec('DROP DATABASE IF EXISTS ' . $quotedDatabase);
    } catch (Throwable) {
    }
});
$admin->exec('DROP DATABASE IF EXISTS ' . $quotedDatabase);
$admin->exec('CREATE DATABASE ' . $quotedDatabase . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$pdo = new PDO(
    str_replace(';charset=', ';dbname=' . $database . ';charset=', $dsn),
    (string) $connection['username'],
    (string) $connection['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
);
$config['connections'][$connectionName]['database'] = $database;
Db::setConfig($config);

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$assertions;
};
$expectApiCode = static function (int $code, callable $callback, string $message) use ($assert): void {
    try {
        $callback();
    } catch (ApiException $exception) {
        $assert($exception->getCode() === $code, $message . ' returned the wrong status.');
        return;
    }
    throw new RuntimeException($message . ' did not fail closed.');
};
$expectRuntime = static function (callable $callback, string $message) use ($assert): void {
    try {
        $callback();
    } catch (RuntimeException) {
        $assert(true, $message);
        return;
    }
    throw new RuntimeException($message . ' did not fail closed.');
};
$expectIntegrity = static function (callable $callback, string $message) use ($assert): void {
    try {
        $callback();
    } catch (SearchProjectionIntegrityException $exception) {
        $assert(
            $exception::class === SearchProjectionIntegrityException::class,
            $message . ' returned the wrong integrity exception type.',
        );
        $assert(
            $exception->getCode() === 503,
            $message . ' returned the wrong integrity status.',
        );
        return;
    } catch (Throwable $exception) {
        throw new RuntimeException(
            $message . ' returned ' . $exception::class . ' instead of a reportable integrity exception.',
            previous: $exception,
        );
    }
    throw new RuntimeException($message . ' did not fail closed.');
};
$expectWrappedIntegrity = static function (callable $callback, string $message) use ($assert): void {
    try {
        $callback();
    } catch (SearchProjectionIntegrityException $exception) {
        $assert(
            $exception->getCode() === 503 && $exception->getPrevious() instanceof Throwable,
            $message . ' was not wrapped as a reportable 503 with its database cause.',
        );
        return;
    } catch (Throwable $exception) {
        throw new RuntimeException(
            $message . ' returned ' . $exception::class . ' instead of a wrapped integrity exception.',
            previous: $exception,
        );
    }
    throw new RuntimeException($message . ' did not fail closed.');
};
$singleId = static function (int $leftOrg, string $leftUser, int $rightOrg, string $rightUser): string {
    $identities = [$leftOrg . ':' . $leftUser, $rightOrg . ':' . $rightUser];
    sort($identities, SORT_STRING);
    return 'single_' . sha1(implode('|', $identities));
};

try {
    $pdo->exec(<<<'SQL'
CREATE TABLE sm_system_organization (
 id int unsigned PRIMARY KEY, status tinyint unsigned NOT NULL, delete_time datetime NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE sm_system_config_group (
 id int unsigned AUTO_INCREMENT PRIMARY KEY, code varchar(100) NOT NULL, delete_time datetime NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE sm_system_config (
 id int unsigned AUTO_INCREMENT PRIMARY KEY, group_id int unsigned NOT NULL,
 `key` varchar(100) NOT NULL, `value` text NULL, delete_time datetime NULL,
 UNIQUE KEY uni_group_key (group_id, `key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE sm_search_index (
 id bigint unsigned AUTO_INCREMENT PRIMARY KEY, organization int unsigned NOT NULL,
 backend varchar(32) NOT NULL, status varchar(20) NOT NULL, doc_count bigint unsigned NOT NULL,
 last_built_at datetime NULL, last_error varchar(500) NOT NULL DEFAULT '',
 created_by int unsigned NULL, updated_by int unsigned NULL,
 create_time datetime NULL, update_time datetime NULL, delete_time datetime NULL,
 UNIQUE KEY uni_org (organization)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE sm_search_doc (
 id bigint unsigned AUTO_INCREMENT PRIMARY KEY, organization int unsigned NOT NULL,
 message_id varchar(64) NOT NULL, conversation_id varchar(64) NOT NULL,
 sender_organization int unsigned NOT NULL, sender_user_id varchar(64) NOT NULL,
 message_type int unsigned NOT NULL,
 message_seq bigint unsigned NOT NULL,
 source_change_seq bigint unsigned NOT NULL DEFAULT 0,
 content text NOT NULL, visibility tinyint unsigned NOT NULL,
 sent_at datetime NULL, create_time datetime NULL, update_time datetime NULL,
 UNIQUE KEY uni_org_message (organization, message_id), FULLTEXT KEY ft_content (content)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE im_message_index (
 id bigint unsigned AUTO_INCREMENT PRIMARY KEY, organization int unsigned NOT NULL,
 global_seq bigint unsigned NOT NULL, message_id varchar(64) NOT NULL,
 conversation_id varchar(64) NOT NULL, message_seq bigint unsigned NOT NULL,
 sender_id varchar(64) NOT NULL, sender_organization int unsigned NOT NULL,
 client_msg_id varchar(80) NOT NULL DEFAULT '', storage_node varchar(64) NOT NULL DEFAULT '',
 shard_table varchar(64) NOT NULL DEFAULT '', create_time datetime NULL,
 UNIQUE KEY uni_org_message (organization, message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE im_runtime_config (
 config_key varchar(100) PRIMARY KEY, config_value varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE im_conversation (
 id bigint unsigned AUTO_INCREMENT PRIMARY KEY, organization int unsigned NOT NULL,
 conversation_id varchar(64) NOT NULL, conversation_type tinyint unsigned NOT NULL,
 status tinyint unsigned NOT NULL, delete_time datetime NULL,
 UNIQUE KEY uni_org_conversation (organization, conversation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE im_cross_organization_conversation (
 conversation_id varchar(64) PRIMARY KEY, left_organization int unsigned NOT NULL,
 left_user_id varchar(64) NOT NULL, right_organization int unsigned NOT NULL,
 right_user_id varchar(64) NOT NULL, status tinyint unsigned NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE im_conversation_member (
 id bigint unsigned AUTO_INCREMENT PRIMARY KEY, organization int unsigned NOT NULL,
 conversation_id varchar(64) NOT NULL, member_organization int unsigned NOT NULL,
 user_id varchar(64) NOT NULL, status tinyint unsigned NOT NULL,
 access_state varchar(20) NOT NULL, delete_time datetime NULL,
 UNIQUE KEY uni_member (organization, conversation_id, member_organization, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE im_conversation_membership_period (
 id bigint unsigned AUTO_INCREMENT PRIMARY KEY, organization int unsigned NOT NULL,
 conversation_id varchar(64) NOT NULL, member_organization int unsigned NOT NULL,
 user_id varchar(64) NOT NULL, period_no int unsigned NOT NULL,
 visible_from_message_seq bigint unsigned NOT NULL, visible_until_message_seq bigint unsigned NULL,
 status tinyint unsigned NOT NULL,
 UNIQUE KEY uni_period (organization, conversation_id, member_organization, user_id, period_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE im_message_user_delete (
 id bigint unsigned AUTO_INCREMENT PRIMARY KEY, organization int unsigned NOT NULL,
 conversation_id varchar(64) NOT NULL, message_id varchar(64) NOT NULL,
 user_organization int unsigned NOT NULL, user_id varchar(64) NOT NULL,
 create_time datetime NULL,
 UNIQUE KEY uni_user_delete (
   organization, conversation_id, message_id, user_organization, user_id
 )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE im_message_change (
 id bigint unsigned AUTO_INCREMENT PRIMARY KEY, organization int unsigned NOT NULL,
 conversation_id varchar(64) NOT NULL, change_seq bigint unsigned NOT NULL,
 message_id varchar(64) NOT NULL, message_seq bigint unsigned NOT NULL,
 change_type varchar(32) NOT NULL, actor_organization int unsigned NOT NULL,
 actor_user_id varchar(64) NOT NULL, target_organization int unsigned NULL,
 target_user_id varchar(64) NULL, payload_json longtext NULL, create_time datetime NULL,
 UNIQUE KEY uni_change (organization, conversation_id, change_seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
SQL);
    $pdo->exec('INSERT INTO sm_system_organization VALUES (101,1,NULL),(202,1,NULL),(303,1,NULL)');
    $pdo->exec('INSERT INTO sm_system_config_group VALUES (1,"social_config",NULL)');
    $pdo->exec(
        'INSERT INTO sm_system_config (group_id,`key`,`value`) VALUES '
        . '(1,"cross_org_social_enabled","1"),(1,"cross_org_access_snapshot_id","1")',
    );
    $pdo->exec(
        'INSERT INTO sm_search_index (organization,backend,status,doc_count) '
        . 'VALUES (101,"mysql","ready",0),(202,"mysql","ready",0)',
    );
    $pdo->exec(
        'INSERT INTO im_runtime_config (config_key,config_value) '
        . 'VALUES ("message_shard_buckets","8")',
    );

    $conversation = $pdo->prepare(
        'INSERT INTO im_conversation (organization,conversation_id,conversation_type,status) '
        . 'VALUES (?,?,?,?)',
    );
    $member = $pdo->prepare(
        'INSERT INTO im_conversation_member '
        . '(organization,conversation_id,member_organization,user_id,status,access_state) '
        . 'VALUES (?,?,?,?,?,?)',
    );
    $period = $pdo->prepare(
        'INSERT INTO im_conversation_membership_period '
        . '(organization,conversation_id,member_organization,user_id,period_no,'
        . 'visible_from_message_seq,visible_until_message_seq,status) VALUES (?,?,?,?,?,?,?,?)',
    );
    $document = $pdo->prepare(
        'INSERT INTO sm_search_doc '
        . '(organization,message_id,conversation_id,sender_organization,sender_user_id,'
        . 'message_type,message_seq,content,visibility,sent_at) '
        . 'VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?)',
    );
    $messageIndex = $pdo->prepare(
        'INSERT INTO im_message_index '
        . '(organization,global_seq,message_id,conversation_id,message_seq,sender_id,'
        . 'sender_organization,client_msg_id,storage_node,shard_table,create_time) '
        . 'VALUES (?,?,?,?,?,?,?,?,?,?,?)',
    );
    $docNo = 0;
    $createdShards = [];
    $shardForMessage = [];
    $addDoc = static function (
        int $organization,
        string $conversationId,
        int $messageSeq,
        string $label,
        int $visibility = 1,
        int $senderOrganization = 0,
        string $senderUserId = 'sender',
    ) use (
        $pdo,
        $document,
        $messageIndex,
        &$docNo,
        &$createdShards,
        &$shardForMessage,
    ): string {
        ++$docNo;
        $messageId = 'search-acl-' . $docNo;
        $sentAt = sprintf('2026-07-20 12:%02d:00', $docNo);
        $content = 'x searchable ' . $label;
        $clientMessageId = 'search-acl-client-' . $docNo;
        $shard = MessageShardIdentity::tableName(
            $organization,
            $conversationId,
            $sentAt,
            8,
        );
        if (!isset($createdShards[$shard])) {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS `' . $shard . '` (
                  id bigint unsigned AUTO_INCREMENT PRIMARY KEY,
                  organization int unsigned NOT NULL,
                  conversation_id varchar(64) NOT NULL,
                  conversation_type tinyint unsigned NOT NULL,
                  message_id varchar(64) NOT NULL,
                  message_seq bigint unsigned NOT NULL,
                  client_msg_id varchar(80) NOT NULL,
                  sender_id varchar(64) NOT NULL,
                  sender_organization int unsigned NOT NULL,
                  message_type tinyint unsigned NOT NULL,
                  content longtext NULL,
                  status tinyint unsigned NOT NULL,
                  create_time datetime NULL,
                  delete_time datetime NULL,
                  UNIQUE KEY uni_org_message (organization,message_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
            );
            $createdShards[$shard] = true;
        }
        $typeStatement = $pdo->prepare(
            'SELECT conversation_type FROM im_conversation
              WHERE organization=? AND BINARY conversation_id=BINARY ? LIMIT 1',
        );
        $typeStatement->execute([$organization, $conversationId]);
        $conversationType = (int) ($typeStatement->fetchColumn() ?: 2);
        $document->execute([
            $organization, $messageId, $conversationId,
            $senderOrganization > 0 ? $senderOrganization : $organization,
            $senderUserId, $messageSeq,
            $content, $visibility, $sentAt,
        ]);
        $messageIndex->execute([
            $organization, $docNo, $messageId, $conversationId, $messageSeq,
            $senderUserId,
            $senderOrganization > 0 ? $senderOrganization : $organization,
            $clientMessageId,
            'mysql-primary',
            $shard,
            $sentAt,
        ]);
        $body = $pdo->prepare(
            'INSERT INTO `' . $shard . '`
                (organization,conversation_id,conversation_type,message_id,message_seq,
                 client_msg_id,sender_id,sender_organization,message_type,content,status,
                 create_time,delete_time)
             VALUES (?,?,?,?,?,?,?,?,1,?,1,?,NULL)',
        );
        $body->execute([
            $organization,
            $conversationId,
            $conversationType,
            $messageId,
            $messageSeq,
            $clientMessageId,
            $senderUserId,
            $senderOrganization > 0 ? $senderOrganization : $organization,
            $content,
            $sentAt,
        ]);
        $shardForMessage[$messageId] = $shard;
        return $messageId;
    };

    $activeGroup = 'group_active';
    $conversation->execute([101,$activeGroup,2,1]);
    $member->execute([101,$activeGroup,101,'shared',1,'active']);
    $period->execute([101,$activeGroup,101,'shared',1,2,null,1]);
    $addDoc(101,$activeGroup,1,'active-before');
    $activeFirst = $addDoc(101,$activeGroup,2,'active-first 中 短词');
    $activeLater = $addDoc(101,$activeGroup,5,'active-later');
    $addDoc(101,$activeGroup,6,'active-hidden',0);
    $addDoc(101,'GROUP_ACTIVE',2,'case-collision-conversation');

    $historyGroup = 'group_history';
    $conversation->execute([101,$historyGroup,2,1]);
    $member->execute([101,$historyGroup,101,'shared',2,'history_only']);
    $period->execute([101,$historyGroup,101,'shared',1,2,3,1]);
    $period->execute([101,$historyGroup,101,'shared',2,7,8,1]);
    $historyFirst = $addDoc(101,$historyGroup,2,'history-first');
    $addDoc(101,$historyGroup,4,'history-gap');
    $historySecond = $addDoc(101,$historyGroup,7,'history-second');
    $addDoc(101,$historyGroup,9,'history-after');

    $invalidHistoryStatus = 'group_history_invalid_status';
    $conversation->execute([101,$invalidHistoryStatus,2,1]);
    $member->execute([101,$invalidHistoryStatus,101,'shared',99,'history_only']);
    $period->execute([101,$invalidHistoryStatus,101,'shared',1,1,5,1]);
    $addDoc(101,$invalidHistoryStatus,2,'history-invalid-status');

    foreach ([
        ['group_revoked', 2, 'revoked', 0],
        ['group_corrupt_state', 2, 'active', 1],
    ] as [$groupId, $status, $state, $periodStatus]) {
        $conversation->execute([101,$groupId,2,1]);
        $member->execute([101,$groupId,101,'shared',$status,$state]);
        $period->execute([101,$groupId,101,'shared',1,1,$state === 'active' ? null : 5,$periodStatus]);
        $addDoc(101,$groupId,2,$groupId);
    }
    $absentGroup = 'group_absent';
    $conversation->execute([101,$absentGroup,2,1]);
    $member->execute([101,$absentGroup,101,'someone_else',1,'active']);
    $period->execute([101,$absentGroup,101,'someone_else',1,1,null,1]);
    $addDoc(101,$absentGroup,2,'absent 短词');

    $sameSingle = $singleId(101,'shared',101,'peer');
    $conversation->execute([101,$sameSingle,1,1]);
    $member->execute([101,$sameSingle,101,'shared',1,'active']);
    $member->execute([101,$sameSingle,101,'peer',1,'active']);
    $sameSingleMessage = $addDoc(101,$sameSingle,1,'same-single');
    $invalidSingle = 'single_invalid_topology';
    $conversation->execute([101,$invalidSingle,1,1]);
    $member->execute([101,$invalidSingle,101,'shared',1,'active']);
    $addDoc(101,$invalidSingle,1,'invalid-single');

    $crossSingle = $singleId(101,'shared',202,'remote');
    $pdo->prepare(
        'INSERT INTO im_cross_organization_conversation VALUES (?,101,"shared",202,"remote",1)',
    )->execute([$crossSingle]);
    foreach ([101,202] as $home) {
        $conversation->execute([$home,$crossSingle,1,1]);
        $member->execute([$home,$crossSingle,101,'shared',1,'active']);
        $member->execute([$home,$crossSingle,202,'remote',1,'active']);
    }
    $crossMessage = $addDoc(101,$crossSingle,1,'cross-single');
    $addDoc(202,$crossSingle,1,'cross-other-home');

    $sameBareCross = $singleId(101,'shared',202,'shared');
    $pdo->prepare(
        'INSERT INTO im_cross_organization_conversation VALUES (?,101,"shared",202,"shared",1)',
    )->execute([$sameBareCross]);
    foreach ([101,202] as $home) {
        $conversation->execute([$home,$sameBareCross,1,1]);
        $member->execute([$home,$sameBareCross,101,'shared',1,'active']);
        $member->execute([$home,$sameBareCross,202,'shared',1,'active']);
    }
    $sameBareSender101 = $addDoc(101,$sameBareCross,1,'same-bare-sender-home',1,101,'shared');
    $sameBareSender202 = $addDoc(101,$sameBareCross,2,'same-bare-sender-peer',1,202,'shared');

    $otherOrgGroup = 'group_other_org';
    $conversation->execute([202,$otherOrgGroup,2,1]);
    $member->execute([202,$otherOrgGroup,202,'shared',1,'active']);
    $period->execute([202,$otherOrgGroup,202,'shared',1,1,null,1]);
    $otherOrgMessage = $addDoc(202,$otherOrgGroup,1,'same-bare-id-other-org');

    $forgedConversation = $addDoc(101,$absentGroup,2,'甲-forged-conversation');
    $pdo->prepare('UPDATE sm_search_doc SET conversation_id=? WHERE organization=101 AND message_id=?')
        ->execute([$activeGroup,$forgedConversation]);
    $forgedSequence = $addDoc(101,$activeGroup,2,'乙-forged-sequence');
    $pdo->prepare('UPDATE sm_search_doc SET message_seq=3 WHERE organization=101 AND message_id=?')
        ->execute([$forgedSequence]);
    $missingIndex = $addDoc(101,$activeGroup,2,'丙-missing-index');
    $pdo->prepare('DELETE FROM im_message_index WHERE organization=101 AND message_id=?')
        ->execute([$missingIndex]);
    $forgedSender = $addDoc(101,$activeGroup,2,'丁-forged-sender');
    $pdo->prepare('UPDATE sm_search_doc SET sender_user_id="other" WHERE organization=101 AND message_id=?')
        ->execute([$forgedSender]);
    $forgedSenderOrganization = $addDoc(101,$activeGroup,2,'戊-forged-sender-org');
    $pdo->prepare(
        'UPDATE sm_search_doc SET sender_organization=202 '
        . 'WHERE organization=101 AND message_id=?',
    )->execute([$forgedSenderOrganization]);
    $forgedSentAt = $addDoc(101,$activeGroup,2,'己-forged-sent-at');
    $pdo->prepare(
        'UPDATE sm_search_doc SET sent_at="2026-07-21 00:00:00" '
        . 'WHERE organization=101 AND message_id=?',
    )->execute([$forgedSentAt]);

    $service = new SearchService();
    $databaseDsn = str_replace(';charset=', ';dbname=' . $database . ';charset=', $dsn);
    $newRaceConnection = static fn (): PDO => new PDO(
        $databaseDsn,
        (string) $connection['username'],
        (string) $connection['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
    );
    $raceWorker = __DIR__ . '/support/search_message_race_worker.php';
    $startRaceWorker = static function (
        string $keyword,
        string $conversationId,
        array $options = [],
    ) use ($raceWorker): array {
        $ready = sys_get_temp_dir() . '/b8im-search-race-' . bin2hex(random_bytes(8));
        $pipes = [];
        $process = proc_open(
            [
                PHP_BINARY,
                $raceWorker,
                $ready,
                '101',
                'shared',
                $keyword,
                $conversationId,
                (string) ($options['page'] ?? 1),
                (string) ($options['limit'] ?? 20),
                (string) ($options['candidate_page_barrier'] ?? ''),
                (string) ($options['final_count_release'] ?? ''),
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start search race worker.');
        }
        $deadline = microtime(true) + 10;
        do {
            $connectionId = is_file($ready) ? (int) trim((string) file_get_contents($ready)) : 0;
            if ($connectionId > 0) {
                return [$process, $pipes, $ready, $connectionId];
            }
            if (microtime(true) >= $deadline) {
                proc_terminate($process);
                proc_close($process);
                @unlink($ready);
                throw new RuntimeException('Search race worker did not publish its connection identity.');
            }
            usleep(10000);
        } while (true);
    };
    $waitForTableWait = static function (
        PDO $observer,
        int $connectionId,
        string $queryNeedle,
    ): void {
        $statement = $observer->prepare(
            'SELECT STATE,INFO FROM information_schema.PROCESSLIST WHERE ID=?',
        );
        $deadline = microtime(true) + 10;
        do {
            $statement->execute([$connectionId]);
            $row = $statement->fetch();
            $state = strtolower((string) ($row['STATE'] ?? ''));
            $info = strtolower((string) ($row['INFO'] ?? ''));
            if (str_contains($state, 'waiting for table')
                && str_contains($info, strtolower($queryNeedle))) {
                return;
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException(
                    'Search race worker did not wait on ' . $queryNeedle
                    . ': ' . json_encode($row, JSON_UNESCAPED_SLASHES),
                );
            }
            usleep(10000);
        } while (true);
    };
    $finishRaceWorker = static function (array $worker, string $message) use ($assert): void {
        [$process, $pipes, $ready] = $worker;
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        @unlink($ready);
        $payload = json_decode((string) $stdout, true);
        $assert(
            $status === 0
            && is_array($payload)
            && ($payload['ok'] ?? true) === false
            && ($payload['class'] ?? '') === SearchProjectionIntegrityException::class
            && (int) ($payload['code'] ?? 0) === 503,
            $message . ': ' . trim((string) $stderr) . ' / ' . trim((string) $stdout),
        );
    };
    $waitForCandidatePageBarrier = static function (string $barrier) use ($assert): array {
        $deadline = microtime(true) + 10;
        do {
            $payload = is_file($barrier)
                ? json_decode((string) file_get_contents($barrier), true)
                : null;
            if (is_array($payload)) {
                $assert(
                    ($payload['stage'] ?? '') === 'candidate_page_select_complete'
                    && (int) ($payload['candidate_page_selects'] ?? 0) === 1
                    && preg_match('/^[0-9a-f]{64}$/D', (string) ($payload['sql_sha256'] ?? '')) === 1,
                    'Empty-page race barrier was not emitted after exactly one candidate page SELECT.',
                );

                return $payload;
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException(
                    'Search race worker did not reach the post-candidate-page barrier.',
                );
            }
            usleep(10000);
        } while (true);
    };
    $finishFinalCountRaceWorker = static function (
        array $worker,
        string $message,
    ) use ($assert): void {
        [$process, $pipes, $ready] = $worker;
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        @unlink($ready);
        $payload = json_decode((string) $stdout, true);
        $evidence = is_array($payload['evidence'] ?? null) ? $payload['evidence'] : [];
        $assert(
            $status === 0
            && is_array($payload)
            && ($payload['ok'] ?? true) === false
            && ($payload['class'] ?? '') === SearchProjectionIntegrityException::class
            && (int) ($payload['code'] ?? 0) === 503,
            $message . ': ' . trim((string) $stderr) . ' / ' . trim((string) $stdout),
        );
        $assert(
            (int) ($evidence['candidate_page_selects'] ?? 0) === 1
            && ($evidence['candidate_page_barrier_released'] ?? false) === true,
            'Empty-page revoke was not committed strictly between candidate page SELECT and final count.',
        );
        $assert(
            (int) ($evidence['final_count_selects'] ?? 0) === 1,
            'Final count SQL was not executed exactly once; deleting finalAccessibleCount must fail this race.',
        );
    };
    $abortRaceWorker = static function (?array $worker): void {
        if (!is_array($worker)) {
            return;
        }
        [$process, $pipes, $ready] = $worker;
        if (is_resource($process)) {
            proc_terminate($process);
        }
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (is_resource($process)) {
            proc_close($process);
        }
        @unlink($ready);
    };
    $writerWorker = __DIR__ . '/support/search_message_writer_worker.php';
    $startWriterWorker = static function (
        int $organization,
        string $messageId,
    ) use ($writerWorker): array {
        $ready = sys_get_temp_dir() . '/b8im-search-writer-' . bin2hex(random_bytes(8));
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, $writerWorker, $ready, (string) $organization, $messageId],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start search writer worker.');
        }
        $deadline = microtime(true) + 10;
        do {
            $connectionId = is_file($ready) ? (int) trim((string) file_get_contents($ready)) : 0;
            if ($connectionId > 0) {
                return [$process, $pipes, $ready, $connectionId];
            }
            if (microtime(true) >= $deadline) {
                proc_terminate($process);
                proc_close($process);
                @unlink($ready);
                throw new RuntimeException('Search writer worker did not publish its connection identity.');
            }
            usleep(10000);
        } while (true);
    };
    $waitForWriterLockWindow = static function (
        PDO $observer,
        array $connectionIds,
        string $shard,
    ): void {
        $statement = $observer->prepare(
            'SELECT ID,STATE,INFO FROM information_schema.PROCESSLIST WHERE ID IN (?,?)',
        );
        $deadline = microtime(true) + 10;
        do {
            $statement->execute(array_values($connectionIds));
            $rows = $statement->fetchAll();
            $bodyWait = false;
            $indexWait = false;
            foreach ($rows as $row) {
                $info = strtolower((string) ($row['INFO'] ?? ''));
                $bodyWait = $bodyWait || str_contains($info, strtolower($shard));
                $indexWait = $indexWait
                    || (str_contains($info, 'from sm_search_index')
                        && str_contains($info, 'for update'));
            }
            if ($bodyWait && $indexWait) {
                return;
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException(
                    'Concurrent search writers did not enter both lock waits: '
                    . json_encode($rows, JSON_UNESCAPED_SLASHES),
                );
            }
            usleep(10000);
        } while (true);
    };
    $finishWriterWorker = static function (array $worker, string $message) use ($assert): array {
        [$process, $pipes, $ready] = $worker;
        $deadline = microtime(true) + 15;
        $exitCode = -1;
        do {
            $status = proc_get_status($process);
            if (!($status['running'] ?? false)) {
                $exitCode = (int) ($status['exitcode'] ?? -1);
                break;
            }
            if (microtime(true) >= $deadline) {
                proc_terminate($process);
                break;
            }
            usleep(10000);
        } while (true);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closedStatus = proc_close($process);
        @unlink($ready);
        $status = $closedStatus >= 0 ? $closedStatus : $exitCode;
        $payload = json_decode((string) $stdout, true);
        $assert(
            $status === 0 && is_array($payload) && ($payload['ok'] ?? false) === true,
            $message . ': ' . trim((string) $stderr) . ' / ' . trim((string) $stdout),
        );

        return $payload;
    };
    $assert(
        !(new ReflectionClass(SearchService::class))->hasMethod('upsertDocument'),
        'Search service retained the caller-controlled document writer.',
    );
    $writerMethod = new ReflectionMethod(SearchService::class, 'upsertMessageDocument');
    $assert(
        array_map(
            static fn (ReflectionParameter $parameter): string => $parameter->getName(),
            $writerMethod->getParameters(),
        ) === ['homeOrganization', 'messageId'],
        'Internal search writer accepted caller-controlled message identity fields.',
    );
    $writerConversation = 'single_writer_authoritative';
    $writerSentAt = '2026-07-20 16:00:00';
    $writerShard = MessageShardIdentity::tableName(101, $writerConversation, $writerSentAt, 8);
    $quotedWriterShard = '`' . $writerShard . '`';
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS ' . $quotedWriterShard . ' (
          id bigint unsigned AUTO_INCREMENT PRIMARY KEY,
          organization int unsigned NOT NULL,
          conversation_id varchar(64) NOT NULL,
          conversation_type tinyint unsigned NOT NULL,
          message_id varchar(64) NOT NULL,
          message_seq bigint unsigned NOT NULL,
          client_msg_id varchar(80) NOT NULL,
          sender_id varchar(64) NOT NULL,
          sender_organization int unsigned NOT NULL,
          message_type tinyint unsigned NOT NULL,
          content longtext NULL,
          status tinyint unsigned NOT NULL,
          create_time datetime NULL,
          delete_time datetime NULL,
          UNIQUE KEY uni_org_message (organization,message_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
    );
    $writerIndex = $pdo->prepare(
        'INSERT INTO im_message_index
         (organization,global_seq,message_id,conversation_id,message_seq,sender_id,
          sender_organization,client_msg_id,storage_node,shard_table,create_time)
         VALUES (101,?,?,?,?,?,?,?,?,?,?)',
    );
    $writerBody = $pdo->prepare(
        'INSERT INTO ' . $quotedWriterShard . '
         (organization,conversation_id,conversation_type,message_id,message_seq,client_msg_id,
          sender_id,sender_organization,message_type,content,status,create_time,delete_time)
         VALUES (101,?,1,?,?,?,?,?,1,?,1,?,NULL)',
    );
    $writerIndex->execute([
        501,
        'writer-valid',
        $writerConversation,
        1,
        'same_sender',
        202,
        'writer-client-1',
        'mysql-primary',
        $writerShard,
        $writerSentAt,
    ]);
    $writerBody->execute([
        $writerConversation,
        'writer-valid',
        1,
        'writer-client-1',
        'same_sender',
        202,
        '{"text":"writer authoritative searchable"}',
        $writerSentAt,
    ]);
    $written = $service->upsertMessageDocument(101, 'writer-valid');
    $assert(
        $written['conversation_id'] === $writerConversation
        && $written['sender_organization'] === 202
        && $written['sender_user_id'] === 'same_sender'
        && $written['message_seq'] === 1
        && $written['message_type'] === 1
        && $written['content'] === '{"text":"writer authoritative searchable"}'
        && $written['visibility'] === 1
        && $written['sent_at'] === $writerSentAt,
        'Internal writer did not derive the document from the exact index/body pair.',
    );
    $pdo->exec(
        'UPDATE sm_search_doc SET conversation_id="forged",sender_organization=101,
          sender_user_id="forged",message_seq=99,content="forged",visibility=0
         WHERE organization=101 AND message_id="writer-valid"',
    );
    $rewritten = $service->upsertMessageDocument(101, 'writer-valid');
    $assert(
        $rewritten['conversation_id'] === $writerConversation
        && $rewritten['sender_organization'] === 202
        && $rewritten['sender_user_id'] === 'same_sender'
        && $rewritten['message_seq'] === 1
        && $rewritten['content'] === '{"text":"writer authoritative searchable"}'
        && $rewritten['visibility'] === 1,
        'Internal writer preserved caller-forged search document fields.',
    );
    $pdo->exec(
        'UPDATE ' . $quotedWriterShard . ' SET status=2 WHERE message_id="writer-valid"',
    );
    $assert(
        $service->upsertMessageDocument(101, 'writer-valid')['visibility'] === 0,
        'Internal writer did not derive recalled visibility from the body status.',
    );
    $pdo->prepare(
        'UPDATE im_message_index SET conversation_id=?
          WHERE organization=101 AND message_id="writer-valid"',
    )->execute(["bad\tconversation"]);
    $expectIntegrity(
        static fn () => $service->upsertMessageDocument(101, 'writer-valid'),
        'Writer with an invalid authoritative conversation identity',
    );
    $pdo->prepare(
        'UPDATE im_message_index SET conversation_id=?
          WHERE organization=101 AND message_id="writer-valid"',
    )->execute([$writerConversation]);
    $pdo->prepare(
        'UPDATE im_message_index SET sender_id=?
          WHERE organization=101 AND message_id="writer-valid"',
    )->execute(["bad\tsender"]);
    $pdo->prepare(
        'UPDATE ' . $quotedWriterShard . ' SET sender_id=?
          WHERE organization=101 AND message_id="writer-valid"',
    )->execute(["bad\tsender"]);
    $expectIntegrity(
        static fn () => $service->upsertMessageDocument(101, 'writer-valid'),
        'Writer with an invalid authoritative sender identity',
    );
    $pdo->exec(
        'UPDATE im_message_index SET sender_id="same_sender"
          WHERE organization=101 AND message_id="writer-valid"',
    );
    $pdo->exec(
        'UPDATE ' . $quotedWriterShard . ' SET sender_id="same_sender"
          WHERE organization=101 AND message_id="writer-valid"',
    );
    $expectRuntime(
        static fn () => $service->upsertMessageDocument(101, 'writer-missing-index'),
        'Writer without authoritative index',
    );
    $writerIndex->execute([
        502,
        'writer-missing-body',
        $writerConversation,
        2,
        'same_sender',
        202,
        'writer-client-2',
        'mysql-primary',
        $writerShard,
        $writerSentAt,
    ]);
    $expectRuntime(
        static fn () => $service->upsertMessageDocument(101, 'writer-missing-body'),
        'Writer without authoritative body',
    );
    $writerIndex->execute([
        503,
        'writer-mismatch',
        $writerConversation,
        3,
        'same_sender',
        202,
        'writer-client-3',
        'mysql-primary',
        $writerShard,
        $writerSentAt,
    ]);
    $writerBody->execute([
        $writerConversation,
        'writer-mismatch',
        3,
        'writer-client-3',
        'same_sender',
        101,
        '{"text":"mismatched sender"}',
        $writerSentAt,
    ]);
    $expectRuntime(
        static fn () => $service->upsertMessageDocument(101, 'writer-mismatch'),
        'Writer with mismatched index/body identity',
    );
    $writerIndex->execute([
        504,
        'writer-wrong-route',
        $writerConversation,
        4,
        'same_sender',
        202,
        'writer-client-4',
        'mysql-primary',
        'im_message_202607_999999',
        $writerSentAt,
    ]);
    $expectRuntime(
        static fn () => $service->upsertMessageDocument(101, 'writer-wrong-route'),
        'Writer with a mutable forged shard route',
    );
    $missingTableMessage = 'writer-missing-table';
    $missingTableContent = 'missingphysicalshardtarget';
    $missingTableSentAt = '2027-01-20 16:00:00';
    $missingTableShard = MessageShardIdentity::tableName(
        101,
        $activeGroup,
        $missingTableSentAt,
        8,
    );
    $writerIndex->execute([
        505,
        $missingTableMessage,
        $activeGroup,
        31,
        'shared',
        101,
        'writer-client-missing-table',
        'mysql-primary',
        $missingTableShard,
        $missingTableSentAt,
    ]);
    $document->execute([
        101,
        $missingTableMessage,
        $activeGroup,
        101,
        'shared',
        31,
        $missingTableContent,
        1,
        $missingTableSentAt,
    ]);
    $expectWrappedIntegrity(
        static fn () => $service->upsertMessageDocument(101, $missingTableMessage),
        'Writer with a valid route to a missing physical shard table',
    );
    $expectWrappedIntegrity(
        static fn () => $service->searchMessages(101, 'shared', [
            'q'=>$missingTableContent,
            'conversation_id'=>$activeGroup,
        ]),
        'Search hit with a valid route to a missing physical shard table',
    );
    $pdo->prepare('UPDATE sm_search_doc SET visibility=0 WHERE message_id=?')
        ->execute([$missingTableMessage]);
    $nullTimeMessage = 'writer-null-time';
    $nullTimeContent = 'nullsentattarget';
    $nullTimeShard = MessageShardIdentity::tableName(
        101,
        $activeGroup,
        '2026-07-01 00:00:00',
        8,
    );
    $writerIndex->execute([
        506,
        $nullTimeMessage,
        $activeGroup,
        30,
        'shared',
        101,
        'writer-client-null-time',
        'mysql-primary',
        $nullTimeShard,
        null,
    ]);
    $nullTimeBody = $pdo->prepare(
        'INSERT INTO ' . $nullTimeShard . '
         (organization,conversation_id,conversation_type,message_id,message_seq,client_msg_id,
          sender_id,sender_organization,message_type,content,status,create_time,delete_time)
         VALUES (101,?,2,?,?,?,?,?,1,?,1,?,NULL)',
    );
    $nullTimeBody->execute([
        $activeGroup,
        $nullTimeMessage,
        30,
        'writer-client-null-time',
        'shared',
        101,
        $nullTimeContent,
        null,
    ]);
    $expectIntegrity(
        static fn () => $service->upsertMessageDocument(101, $nullTimeMessage),
        'Writer with a non-derivable NULL shard month',
    );
    $document->execute([
        101,
        $nullTimeMessage,
        $activeGroup,
        101,
        'shared',
        30,
        $nullTimeContent,
        1,
        null,
    ]);
    $expectIntegrity(
        static fn () => $service->searchMessages(101, 'shared', [
            'q'=>$nullTimeContent,
            'conversation_id'=>$activeGroup,
        ]),
        'Search hit with a non-derivable NULL shard month',
    );
    $relocatedNullTimeShard = MessageShardIdentity::tableName(
        101,
        $activeGroup,
        '2026-08-01 00:00:00',
        8,
    );
    $pdo->exec(
        'CREATE TABLE ' . $relocatedNullTimeShard . ' LIKE ' . $nullTimeShard,
    );
    $pdo->exec(
        'INSERT INTO ' . $relocatedNullTimeShard . '
         SELECT * FROM ' . $nullTimeShard . '
          WHERE organization=101 AND message_id="' . $nullTimeMessage . '"',
    );
    $pdo->exec(
        'UPDATE im_message_index SET shard_table="' . $relocatedNullTimeShard . '"
          WHERE organization=101 AND message_id="' . $nullTimeMessage . '"',
    );
    $expectIntegrity(
        static fn () => $service->upsertMessageDocument(101, $nullTimeMessage),
        'Writer with a cloned NULL-time body in another valid month',
    );
    $expectIntegrity(
        static fn () => $service->searchMessages(101, 'shared', [
            'q'=>$nullTimeContent,
            'conversation_id'=>$activeGroup,
        ]),
        'Search hit with a cloned NULL-time body in another valid month',
    );
    $pdo->exec(
        'UPDATE im_message_index SET shard_table="' . $nullTimeShard . '"
          WHERE organization=101 AND message_id="' . $nullTimeMessage . '"',
    );
    $pdo->exec('DROP TABLE ' . $relocatedNullTimeShard);
    $assert(
        $service->indexList(['page'=>1,'limit'=>20])['total'] === 2,
        'Platform index management was incorrectly scoped as an end user.',
    );
    $assert(
        $service->indexRead(202, false)['organization'] === 202,
        'Tenant index management was incorrectly scoped by the Web user ACL.',
    );
    $barrier = sys_get_temp_dir() . '/b8im-search-index-' . bin2hex(random_bytes(8));
    $worker = __DIR__ . '/support/search_index_create_worker.php';
    $processes = [];
    $readyFiles = [];
    try {
        for ($i = 0; $i < 2; ++$i) {
            $ready = $barrier . '.ready-' . $i;
            $pipes = [];
            $process = proc_open(
                [PHP_BINARY, $worker, $barrier, $ready, '303'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                null,
                ['bypass_shell' => true],
            );
            if (!is_resource($process)) {
                throw new RuntimeException('Unable to start search index concurrency worker.');
            }
            $processes[] = [$process, $pipes];
            $readyFiles[] = $ready;
        }
        $readyDeadline = microtime(true) + 10;
        while (count(array_filter($readyFiles, 'is_file')) !== 2) {
            if (microtime(true) >= $readyDeadline) {
                throw new RuntimeException(
                    'Search index concurrency workers did not become ready.',
                );
            }
            usleep(10000);
        }
        if (!touch($barrier)) {
            throw new RuntimeException('Unable to release search index concurrency workers.');
        }
        foreach ($processes as [$process, $pipes]) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($process);
            $assert(
                $status === 0 && trim((string) $stdout) === '303',
                'Concurrent first index creation failed: ' . trim((string) $stderr),
            );
        }
        $processes = [];
    } finally {
        @unlink($barrier);
        foreach ($readyFiles as $ready) {
            @unlink($ready);
        }
        foreach ($processes as [$process, $pipes]) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
    }
    $assert(
        (int) $pdo->query('SELECT COUNT(*) FROM sm_search_index WHERE organization=303')->fetchColumn() === 1,
        'Concurrent first index creation did not converge to exactly one row.',
    );
    $expected = [
        $activeFirst,
        $activeLater,
        $historyFirst,
        $historySecond,
        $sameSingleMessage,
        $crossMessage,
        $sameBareSender101,
        $sameBareSender202,
    ];
    $actual = [];
    for ($page = 1; $page <= 4; ++$page) {
        $result = $service->searchMessages(101,'shared',['q'=>'searchable','page'=>$page,'limit'=>2]);
        $assert($result['total'] === 8, 'SQL ACL total included unauthorized documents.');
        $assert(count($result['data']) === 2, 'SQL ACL returned a short authorized page.');
        array_push($actual, ...array_column($result['data'], 'message_id'));
    }
    sort($expected, SORT_STRING);
    sort($actual, SORT_STRING);
    $assert($actual === $expected, 'SQL ACL pages leaked or omitted message documents.');
    $indexRaceLock = $newRaceConnection();
    $indexRaceControl = $newRaceConnection();
    $indexRaceWorker = null;
    $indexRaceLocked = false;
    try {
        $indexRaceLock->exec('LOCK TABLES sm_search_doc WRITE');
        $indexRaceLocked = true;
        $indexRaceWorker = $startRaceWorker('nomatchingindexfence', $activeGroup);
        $waitForTableWait($admin, $indexRaceWorker[3], 'sm_search_doc');
        $indexRaceControl->exec(
            'UPDATE sm_search_index SET status="building",update_time="2026-07-20 19:30:00"
              WHERE organization=101',
        );
        $indexRaceLock->exec('UNLOCK TABLES');
        $indexRaceLocked = false;
        $finishRaceWorker(
            $indexRaceWorker,
            'Empty search page accepted a ready-to-building index transition',
        );
        $indexRaceWorker = null;
    } finally {
        if ($indexRaceLocked) {
            $indexRaceLock->exec('UNLOCK TABLES');
        }
        $abortRaceWorker($indexRaceWorker);
    }
    $indexRaceControl->exec(
        'UPDATE sm_search_index SET status="ready",update_time="2026-07-20 19:30:01"
          WHERE organization=101',
    );
    $indexRaceWorker = null;
    $indexRaceLocked = false;
    try {
        $indexRaceLock->exec('LOCK TABLES sm_search_doc WRITE');
        $indexRaceLocked = true;
        $indexRaceWorker = $startRaceWorker('nomatchingindexidentity', $activeGroup);
        $waitForTableWait($admin, $indexRaceWorker[3], 'sm_search_doc');
        $indexRaceControl->exec(
            'UPDATE sm_search_index
                SET status="ready",last_built_at="2026-07-20 19:31:00",
                    update_time="2026-07-20 19:31:00"
              WHERE organization=101',
        );
        $indexRaceLock->exec('UNLOCK TABLES');
        $indexRaceLocked = false;
        $finishRaceWorker(
            $indexRaceWorker,
            'Empty search page accepted a changed ready-index identity fence',
        );
        $indexRaceWorker = null;
    } finally {
        if ($indexRaceLocked) {
            $indexRaceLock->exec('UNLOCK TABLES');
        }
        $abortRaceWorker($indexRaceWorker);
    }
    $pdo->exec('UPDATE sm_search_index SET status="building" WHERE organization=101');
    $expectApiCode(
        503,
        static fn () => $service->searchMessages(101, 'shared', ['q'=>'searchable']),
        'Search against a not-ready projection',
    );
    $pdo->exec('UPDATE sm_search_index SET status="ready" WHERE organization=101');
    $expectApiCode(
        503,
        static fn () => $service->rebuild(101, 1),
        'Placeholder search rebuild without a real shard worker',
    );
    $changeNo = 1000;
    $addMutation = static function (
        int $organization,
        string $conversationId,
        string $messageId,
        int $messageSeq,
        string $changeType,
    ) use ($pdo, &$changeNo): string {
        ++$changeNo;
        $statement = $pdo->prepare(
            'INSERT INTO im_message_change
                (organization,conversation_id,change_seq,message_id,message_seq,change_type,
                 actor_organization,actor_user_id,payload_json,create_time)
             VALUES (?,?,?,?,?,?,?,\'shared\',\'{}\',\'2026-07-20 18:00:00\')',
        );
        $statement->execute([
            $organization,
            $conversationId,
            $changeNo,
            $messageId,
            $messageSeq,
            $changeType,
            $organization,
        ]);
        return (string) $changeNo;
    };
    $writerRaceMessage = $addDoc(101, $activeGroup, 32, 'writerparalleloldtarget');
    $writerRaceShard = $shardForMessage[$writerRaceMessage];
    $writerRaceControl = $newRaceConnection();
    $writerRaceWorkers = [];
    try {
        $writerRaceControl->beginTransaction();
        $bodyLock = $writerRaceControl->prepare(
            'SELECT id FROM `' . $writerRaceShard . '`
              WHERE organization=101 AND BINARY message_id=BINARY ? FOR UPDATE',
        );
        $bodyLock->execute([$writerRaceMessage]);
        for ($i = 0; $i < 2; ++$i) {
            $writerRaceWorkers[] = $startWriterWorker(101, $writerRaceMessage);
        }
        $waitForWriterLockWindow(
            $admin,
            array_column($writerRaceWorkers, 3),
            $writerRaceShard,
        );

        ++$changeNo;
        $writerRaceControl->prepare(
            'UPDATE `' . $writerRaceShard . '`
                SET content="writerparallelcurrent"
              WHERE organization=101 AND BINARY message_id=BINARY ?',
        )->execute([$writerRaceMessage]);
        $writerRaceControl->prepare(
            'INSERT INTO im_message_change
                (organization,conversation_id,change_seq,message_id,message_seq,change_type,
                 actor_organization,actor_user_id,payload_json,create_time)
             VALUES (101,?,?,?,?,"edit",101,"shared","{}","2026-07-20 19:50:00")',
        )->execute([$activeGroup, $changeNo, $writerRaceMessage, 32]);
        $writerEditSeq = (string) $changeNo;
        $writerRaceControl->commit();

        foreach ($writerRaceWorkers as $writerRaceWorker) {
            $payload = $finishWriterWorker(
                $writerRaceWorker,
                'Concurrent identity-only search writer timed out or failed',
            );
            $assert(
                ($payload['message_id'] ?? null) === $writerRaceMessage
                && ($payload['content'] ?? null) === 'writerparallelcurrent'
                && (int) ($payload['visibility'] ?? -1) === 1
                && (string) ($payload['source_change_seq'] ?? '') === $writerEditSeq,
                'Concurrent search writers did not converge to the committed edit version: '
                . json_encode($payload, JSON_UNESCAPED_SLASHES),
            );
        }
        $writerRaceWorkers = [];
    } finally {
        if ($writerRaceControl->inTransaction()) {
            $writerRaceControl->rollBack();
        }
        foreach ($writerRaceWorkers as $writerRaceWorker) {
            $abortRaceWorker($writerRaceWorker);
        }
    }
    $readWriterSource = static function (string $messageId) use ($pdo): string {
        $statement = $pdo->prepare(
            'SELECT CAST(source_change_seq AS CHAR) FROM sm_search_doc
              WHERE organization=101 AND BINARY message_id=BINARY ?',
        );
        $statement->execute([$messageId]);

        return (string) $statement->fetchColumn();
    };
    $pdo->prepare(
        'UPDATE `' . $writerRaceShard . '` SET status=2
          WHERE organization=101 AND BINARY message_id=BINARY ?',
    )->execute([$writerRaceMessage]);
    $writerRecallSeq = $addMutation(101, $activeGroup, $writerRaceMessage, 32, 'recall');
    $writerRecallProjection = $service->upsertMessageDocument(101, $writerRaceMessage);
    $assert(
        $writerRecallProjection['visibility'] === 0
        && $readWriterSource($writerRaceMessage) === $writerRecallSeq,
        'Identity-only writer did not converge to the recall version.',
    );
    // Replaying a late old edit event only supplies identity, so the writer
    // must reread current facts and retain the newer recall sequence.
    $lateEditReplay = $service->upsertMessageDocument(101, $writerRaceMessage);
    $assert(
        $lateEditReplay['visibility'] === 0
        && $readWriterSource($writerRaceMessage) === $writerRecallSeq,
        'A late old writer event regressed the shared source change sequence.',
    );
    $pdo->prepare(
        'UPDATE `' . $writerRaceShard . '`
            SET status=3,delete_time="2026-07-20 19:51:00"
          WHERE organization=101 AND BINARY message_id=BINARY ?',
    )->execute([$writerRaceMessage]);
    $writerDeleteSeq = $addMutation(101, $activeGroup, $writerRaceMessage, 32, 'delete_both');
    $writerDeleteProjection = $service->upsertMessageDocument(101, $writerRaceMessage);
    $assert(
        $writerDeleteProjection['visibility'] === 0
        && $readWriterSource($writerRaceMessage) === $writerDeleteSeq,
        'Identity-only writer did not converge to the delete_both version.',
    );
    $addMutation(101, $activeGroup, $writerRaceMessage, 32, 'deleted_self');
    $writerSelfDeleteProjection = $service->upsertMessageDocument(101, $writerRaceMessage);
    $assert(
        $writerSelfDeleteProjection['visibility'] === 0
        && $readWriterSource($writerRaceMessage) === $writerDeleteSeq,
        'deleted_self incorrectly advanced the shared search projection sequence.',
    );
    $selfDeleteRaceMessage = $addDoc(101, $activeGroup, 33, 'selfdeletefinalwindow');
    $selfDeleteRaceShard = $shardForMessage[$selfDeleteRaceMessage];
    $selfDeleteBodyLock = $newRaceConnection();
    $selfDeleteDocLock = $newRaceConnection();
    $selfDeleteControl = $newRaceConnection();
    $selfDeleteWorker = null;
    $selfDeleteBodyLocked = false;
    $selfDeleteDocLocked = false;
    try {
        $selfDeleteBodyLock->exec('LOCK TABLES ' . $selfDeleteRaceShard . ' WRITE');
        $selfDeleteBodyLocked = true;
        $selfDeleteWorker = $startRaceWorker('selfdeletefinalwindow', $activeGroup);
        $waitForTableWait($admin, $selfDeleteWorker[3], $selfDeleteRaceShard);
        $selfDeleteDocLock->exec('LOCK TABLES sm_search_doc WRITE');
        $selfDeleteDocLocked = true;
        $selfDeleteBodyLock->exec('UNLOCK TABLES');
        $selfDeleteBodyLocked = false;
        $waitForTableWait($admin, $selfDeleteWorker[3], 'sm_search_doc');
        $selfDeleteControl->prepare(
            'INSERT INTO im_message_user_delete
                (organization,conversation_id,message_id,user_organization,user_id,create_time)
             VALUES (101,?,?,101,"shared","2026-07-20 19:52:00")',
        )->execute([$activeGroup, $selfDeleteRaceMessage]);
        $selfDeleteDocLock->exec('UNLOCK TABLES');
        $selfDeleteDocLocked = false;
        $finishRaceWorker(
            $selfDeleteWorker,
            'Self-delete committed before final ACL verification was accepted',
        );
        $selfDeleteWorker = null;
    } finally {
        if ($selfDeleteBodyLocked) {
            $selfDeleteBodyLock->exec('UNLOCK TABLES');
        }
        if ($selfDeleteDocLocked) {
            $selfDeleteDocLock->exec('UNLOCK TABLES');
        }
        $abortRaceWorker($selfDeleteWorker);
    }

    $finalRevokeGroup = 'group_final_revoke_race';
    $conversation->execute([101, $finalRevokeGroup, 2, 1]);
    $member->execute([101, $finalRevokeGroup, 101, 'shared', 1, 'active']);
    $period->execute([101, $finalRevokeGroup, 101, 'shared', 1, 1, null, 1]);
    $finalRevokeMessage = $addDoc(101, $finalRevokeGroup, 1, 'memberrevokefinalwindow');
    $finalRevokeShard = $shardForMessage[$finalRevokeMessage];
    $finalRevokeBodyLock = $newRaceConnection();
    $finalRevokeDocLock = $newRaceConnection();
    $finalRevokeControl = $newRaceConnection();
    $finalRevokeWorker = null;
    $finalRevokeBodyLocked = false;
    $finalRevokeDocLocked = false;
    try {
        $finalRevokeBodyLock->exec('LOCK TABLES ' . $finalRevokeShard . ' WRITE');
        $finalRevokeBodyLocked = true;
        $finalRevokeWorker = $startRaceWorker('memberrevokefinalwindow', $finalRevokeGroup);
        $waitForTableWait($admin, $finalRevokeWorker[3], $finalRevokeShard);
        $finalRevokeDocLock->exec('LOCK TABLES sm_search_doc WRITE');
        $finalRevokeDocLocked = true;
        $finalRevokeBodyLock->exec('UNLOCK TABLES');
        $finalRevokeBodyLocked = false;
        $waitForTableWait($admin, $finalRevokeWorker[3], 'sm_search_doc');
        $finalRevokeControl->prepare(
            'UPDATE im_conversation_member SET status=2,access_state="revoked"
              WHERE organization=101 AND BINARY conversation_id=BINARY ?
                AND member_organization=101 AND BINARY user_id=BINARY "shared"',
        )->execute([$finalRevokeGroup]);
        $finalRevokeDocLock->exec('UNLOCK TABLES');
        $finalRevokeDocLocked = false;
        $finishRaceWorker(
            $finalRevokeWorker,
            'Membership revoke committed before final ACL verification was accepted',
        );
        $finalRevokeWorker = null;
    } finally {
        if ($finalRevokeBodyLocked) {
            $finalRevokeBodyLock->exec('UNLOCK TABLES');
        }
        if ($finalRevokeDocLocked) {
            $finalRevokeDocLock->exec('UNLOCK TABLES');
        }
        $abortRaceWorker($finalRevokeWorker);
    }

    $countRevokeGroup = 'group_count_revoke_race';
    $conversation->execute([101, $countRevokeGroup, 2, 1]);
    $member->execute([101, $countRevokeGroup, 101, 'shared', 1, 'active']);
    $period->execute([101, $countRevokeGroup, 101, 'shared', 1, 1, null, 1]);
    $addDoc(101, $countRevokeGroup, 1, 'memberrevokecountwindow');
    $countRevokeControl = $newRaceConnection();
    $countRevokeWorker = null;
    $countRevokeBarrier = sys_get_temp_dir()
        . '/b8im-search-count-candidate-' . bin2hex(random_bytes(8));
    $countRevokeRelease = $countRevokeBarrier . '.release-final-count';
    try {
        $countRevokeWorker = $startRaceWorker(
            'memberrevokecountwindow',
            $countRevokeGroup,
            [
                'page' => 2,
                'limit' => 1,
                'candidate_page_barrier' => $countRevokeBarrier,
                'final_count_release' => $countRevokeRelease,
            ],
        );
        $waitForCandidatePageBarrier($countRevokeBarrier);
        $countRevokeControl->prepare(
            'UPDATE im_conversation_member SET status=2,access_state="revoked"
              WHERE organization=101 AND BINARY conversation_id=BINARY ?
                AND member_organization=101 AND BINARY user_id=BINARY "shared"',
        )->execute([$countRevokeGroup]);
        if (!touch($countRevokeRelease)) {
            throw new RuntimeException('Unable to release the empty-page final-count worker.');
        }
        $finishFinalCountRaceWorker(
            $countRevokeWorker,
            'Empty-page final count accepted a membership revoke after candidate page SELECT',
        );
        $countRevokeWorker = null;
    } finally {
        if ($countRevokeWorker !== null && !is_file($countRevokeRelease)) {
            @touch($countRevokeRelease);
        }
        $abortRaceWorker($countRevokeWorker);
        @unlink($countRevokeBarrier);
        @unlink($countRevokeRelease);
    }
    $editedMessage = $addDoc(101, $activeGroup, 9, 'oldedittarget');
    $pdo->prepare(
        'UPDATE `' . $shardForMessage[$editedMessage] . '` SET content=?
          WHERE organization=101 AND BINARY message_id=BINARY ?',
    )->execute(['physicalcurrentbody', $editedMessage]);
    $editChangeSeq = $addMutation(101, $activeGroup, $editedMessage, 9, 'edit');
    $assert(
        $service->searchMessages(101, 'shared', [
            'q'=>'oldedittarget',
            'conversation_id'=>$activeGroup,
        ])['total'] === 0,
        'Search with an unconverged edit version did not fail closed.',
    );
    $editedProjection = $service->upsertMessageDocument(101, $editedMessage);
    $projectedChangeSeq = $pdo->prepare(
        'SELECT CAST(source_change_seq AS CHAR) FROM sm_search_doc
          WHERE organization=101 AND BINARY message_id=BINARY ?',
    );
    $projectedChangeSeq->execute([$editedMessage]);
    $assert(
        $editedProjection['content'] === 'physicalcurrentbody'
        && $projectedChangeSeq->fetchColumn() === $editChangeSeq,
        'Writer did not converge the edited body and exact shared change version.',
    );
    $assert(
        $service->searchMessages(101, 'shared', [
            'q'=>'oldedittarget',
            'conversation_id'=>$activeGroup,
        ])['total'] === 0,
        'An old keyword matched after the edit projection converged.',
    );
    $currentBodyResult = $service->searchMessages(101, 'shared', [
        'q'=>'physicalcurrentbody',
        'conversation_id'=>$activeGroup,
    ]);
    $currentBodyRows = array_values(array_filter(
        $currentBodyResult['data'],
        static fn (array $row): bool => $row['message_id'] === $editedMessage,
    ));
    $assert(
        count($currentBodyRows) === 1
        && $currentBodyRows[0]['content'] === 'physicalcurrentbody',
        'Search returned stale projection content instead of the physical body.',
    );
    $addMutation(101, $activeGroup, $editedMessage, 9, 'deleted_self');
    $assert(
        $service->searchMessages(101, 'shared', [
            'q'=>'physicalcurrentbody',
            'conversation_id'=>$activeGroup,
        ])['total'] === 1,
        'A deleted_self change incorrectly advanced the shared projection version.',
    );
    $editRaceMessage = $addDoc(101, $activeGroup, 12, 'editwindowoldtarget');
    $editRaceShard = $shardForMessage[$editRaceMessage];
    $editRaceBodyLock = $newRaceConnection();
    $editRaceDocLock = $newRaceConnection();
    $editRaceControl = $newRaceConnection();
    $editRaceWorker = null;
    $editRaceBodyLocked = false;
    $editRaceDocLocked = false;
    try {
        $editRaceBodyLock->exec('LOCK TABLES ' . $editRaceShard . ' WRITE');
        $editRaceBodyLocked = true;
        $editRaceWorker = $startRaceWorker('editwindowoldtarget', $activeGroup);
        $waitForTableWait($admin, $editRaceWorker[3], $editRaceShard);
        $editRaceDocLock->exec('LOCK TABLES sm_search_doc WRITE');
        $editRaceDocLocked = true;
        $editRaceBodyLock->exec('UNLOCK TABLES');
        $editRaceBodyLocked = false;
        $waitForTableWait($admin, $editRaceWorker[3], 'sm_search_doc');

        ++$changeNo;
        $editRaceControl->beginTransaction();
        $editBody = $editRaceControl->prepare(
            'UPDATE ' . $editRaceShard . ' SET content="physicalracecurrentbody"
              WHERE organization=101 AND BINARY message_id=BINARY ?',
        );
        $editBody->execute([$editRaceMessage]);
        $editChange = $editRaceControl->prepare(
            'INSERT INTO im_message_change
                (organization,conversation_id,change_seq,message_id,message_seq,change_type,
                 actor_organization,actor_user_id,payload_json,create_time)
             VALUES (101,?,?,?,?,"edit",101,"shared","{}","2026-07-20 19:40:00")',
        );
        $editChange->execute([$activeGroup, $changeNo, $editRaceMessage, 12]);
        $editRaceControl->commit();

        $editRaceDocLock->exec('UNLOCK TABLES');
        $editRaceDocLocked = false;
        $finishRaceWorker(
            $editRaceWorker,
            'Edit committed after body read but before final ACL verification',
        );
        $editRaceWorker = null;
    } finally {
        if ($editRaceControl->inTransaction()) {
            $editRaceControl->rollBack();
        }
        if ($editRaceBodyLocked) {
            $editRaceBodyLock->exec('UNLOCK TABLES');
        }
        if ($editRaceDocLocked) {
            $editRaceDocLock->exec('UNLOCK TABLES');
        }
        $abortRaceWorker($editRaceWorker);
    }
    $pdo->prepare('UPDATE sm_search_doc SET visibility=0 WHERE message_id=?')
        ->execute([$editRaceMessage]);
    $recalledMessage = $addDoc(101, $activeGroup, 10, 'recalltarget');
    $pdo->prepare(
        'UPDATE `' . $shardForMessage[$recalledMessage] . '` SET status=2
          WHERE organization=101 AND BINARY message_id=BINARY ?',
    )->execute([$recalledMessage]);
    $addMutation(101, $activeGroup, $recalledMessage, 10, 'recall');
    $assert(
        $service->searchMessages(101, 'shared', [
            'q'=>'recalltarget',
            'conversation_id'=>$activeGroup,
        ])['total'] === 0,
        'A recalled physical message remained searchable.',
    );
    $bothDeletedMessage = $addDoc(101, $activeGroup, 11, 'bothdeletetarget');
    $pdo->prepare(
        'UPDATE `' . $shardForMessage[$bothDeletedMessage] . '`
            SET status=3,delete_time=\'2026-07-20 18:00:00\'
          WHERE organization=101 AND BINARY message_id=BINARY ?',
    )->execute([$bothDeletedMessage]);
    $addMutation(101, $activeGroup, $bothDeletedMessage, 11, 'delete_both');
    $assert(
        $service->searchMessages(101, 'shared', [
            'q'=>'bothdeletetarget',
            'conversation_id'=>$activeGroup,
        ])['total'] === 0,
        'A both-deleted physical message remained searchable.',
    );
    $selfDeletedMessage = $addDoc(
        101,
        $sameBareCross,
        3,
        'selfdeletetarget',
        1,
        202,
        'shared',
    );
    $deleteForViewer = $pdo->prepare(
        'INSERT INTO im_message_user_delete
            (organization,conversation_id,message_id,user_organization,user_id,create_time)
         VALUES (101,?,?,?,?,\'2026-07-20 18:00:00\')',
    );
    $deleteForViewer->execute([$sameBareCross, $selfDeletedMessage, 202, 'shared']);
    $assert(
        $service->searchMessages(101, 'shared', [
            'q'=>'selfdeletetarget',
            'conversation_id'=>$sameBareCross,
        ])['total'] === 1,
        'A same bare user ID in another organization hid the message.',
    );
    $deleteForViewer->execute(['corrupt_conversation', $selfDeletedMessage, 101, 'shared']);
    $assert(
        $service->searchMessages(101, 'shared', [
            'q'=>'selfdeletetarget',
            'conversation_id'=>$sameBareCross,
        ])['total'] === 0,
        'A self-delete with a damaged conversation field failed open.',
    );
    $missingBodyMessage = $addDoc(101, $activeGroup, 20, 'missingbodytarget');
    $pdo->prepare(
        'DELETE FROM `' . $shardForMessage[$missingBodyMessage] . '`
          WHERE organization=101 AND BINARY message_id=BINARY ?',
    )->execute([$missingBodyMessage]);
    $expectIntegrity(
        static fn () => $service->searchMessages(101, 'shared', [
            'q'=>'missingbodytarget',
            'conversation_id'=>$activeGroup,
        ]),
        'Search hit without a physical body',
    );
    $pdo->prepare('UPDATE sm_search_doc SET visibility=0 WHERE message_id=?')
        ->execute([$missingBodyMessage]);
    $wrongRouteMessage = $addDoc(101, $activeGroup, 21, 'wrongroutetarget');
    $pdo->prepare('UPDATE im_message_index SET shard_table=? WHERE message_id=?')
        ->execute(['im_message_202607_999999', $wrongRouteMessage]);
    $expectIntegrity(
        static fn () => $service->searchMessages(101, 'shared', [
            'q'=>'wrongroutetarget',
            'conversation_id'=>$activeGroup,
        ]),
        'Search hit with a forged shard route',
    );
    $pdo->prepare('UPDATE sm_search_doc SET visibility=0 WHERE message_id=?')
        ->execute([$wrongRouteMessage]);
    $wrongBindingMessage = $addDoc(101, $activeGroup, 22, 'wrongbindingtarget');
    $pdo->prepare(
        'UPDATE `' . $shardForMessage[$wrongBindingMessage] . '` SET sender_organization=202
          WHERE organization=101 AND BINARY message_id=BINARY ?',
    )->execute([$wrongBindingMessage]);
    $expectIntegrity(
        static fn () => $service->searchMessages(101, 'shared', [
            'q'=>'wrongbindingtarget',
            'conversation_id'=>$activeGroup,
        ]),
        'Search hit with a mismatched physical body',
    );
    $pdo->prepare('UPDATE sm_search_doc SET visibility=0 WHERE message_id=?')
        ->execute([$wrongBindingMessage]);
    $wrongConversationTypeMessage = $addDoc(
        101,
        $activeGroup,
        24,
        'wrongconversationtypetarget',
    );
    $pdo->prepare(
        'UPDATE ' . $shardForMessage[$wrongConversationTypeMessage] . '
            SET conversation_type=1
          WHERE organization=101 AND BINARY message_id=BINARY ?',
    )->execute([$wrongConversationTypeMessage]);
    $expectIntegrity(
        static fn () => $service->searchMessages(101, 'shared', [
            'q'=>'wrongconversationtypetarget',
            'conversation_id'=>$activeGroup,
        ]),
        'Search hit with a body conversation type detached from the authoritative conversation',
    );
    $pdo->prepare('UPDATE sm_search_doc SET visibility=0 WHERE message_id=?')
        ->execute([$wrongConversationTypeMessage]);
    $corruptContentMessage = $addDoc(101, $activeGroup, 23, 'corruptcontenttarget');
    $pdo->prepare(
        'UPDATE `' . $shardForMessage[$corruptContentMessage] . '` SET content=\'forged\'
          WHERE organization=101 AND BINARY message_id=BINARY ?',
    )->execute([$corruptContentMessage]);
    $addMutation(101, $absentGroup, $corruptContentMessage, 23, 'edit');
    $expectIntegrity(
        static fn () => $service->searchMessages(101, 'shared', [
            'q'=>'corruptcontenttarget',
            'conversation_id'=>$activeGroup,
        ]),
        'Search hit with unaccounted content drift',
    );
    $pdo->prepare('UPDATE sm_search_doc SET visibility=0 WHERE message_id=?')
        ->execute([$corruptContentMessage]);
    $searchSource = file_get_contents(
        dirname(__DIR__) . '/plugin/saimulti/service/module/SearchService.php',
    );
    $methodStart = strpos($searchSource, 'public function searchMessages');
    $methodEnd = strpos($searchSource, 'private function endUserMessageAccessSql');
    $searchMethodSource = substr($searchSource, $methodStart, $methodEnd - $methodStart);
    $readerSource = file_get_contents(
        dirname(__DIR__) . '/plugin/saimulti/service/module/SearchMessageFactReader.php',
    );
    $assert(
        !str_contains($searchMethodSource, 'FOR UPDATE')
        && !str_contains($searchMethodSource, 'Db::transaction')
        && !str_contains($readerSource, 'FOR UPDATE')
        && !str_contains($readerSource, 'Db::transaction'),
        'Authoritative search reused a locking or repeatable-read transaction.',
    );
    $assert(
        $service->searchMessages(101,'Shared',['q'=>'searchable'])['total'] === 0,
        'Case-insensitive collation matched a different current composite identity.',
    );
    $assert(
        $service->searchMessages(101,'shared',['q'=>'x','conversation_id'=>'GROUP_ACTIVE'])['total'] === 0,
        'Case-insensitive collation matched a different conversation filter.',
    );
    $assert(
        $service->searchMessages(101,'shared',[
            'q'=>'searchable',
            'sender_organization'=>101,
            'sender_user_id'=>'Sender',
        ])['total'] === 0,
        'Case-insensitive collation matched a different sender identity filter.',
    );
    $sender101 = $service->searchMessages(101,'shared',[
        'q'=>'same-bare-sender',
        'conversation_id'=>$sameBareCross,
        'sender_organization'=>101,
        'sender_user_id'=>'shared',
    ]);
    $assert(
        $sender101['total'] === 1
        && $sender101['data'][0]['message_id'] === $sameBareSender101
        && $sender101['data'][0]['sender_organization'] === 101,
        'Composite sender filter did not isolate the home sender identity.',
    );
    $sender202 = $service->searchMessages(101,'shared',[
        'q'=>'same-bare-sender',
        'conversation_id'=>$sameBareCross,
        'sender_organization'=>202,
        'sender_user_id'=>'shared',
    ]);
    $assert(
        $sender202['total'] === 1
        && $sender202['data'][0]['message_id'] === $sameBareSender202
        && $sender202['data'][0]['sender_organization'] === 202,
        'Composite sender filter did not isolate the peer sender identity.',
    );
    foreach ([
        ['sender_organization'=>101],
        ['sender_user_id'=>'shared'],
        ['sender_organization'=>0,'sender_user_id'=>'shared'],
        ['sender_organization'=>'','sender_user_id'=>'shared'],
        ['sender_organization'=>101,'sender_user_id'=>''],
        ['sender_organization'=>101,'sender_user_id'=>"bad\tidentity"],
        ['sender_organization'=>101,'sender_user_id'=>"bad\nidentity"],
        ['sender_organization'=>101,'sender_user_id'=>"bad\videntity"],
        ['sender_organization'=>101,'sender_user_id'=>"bad\ridentity"],
        ['sender_organization'=>101,'sender_user_id'=>str_repeat('界', 22)],
    ] as $invalidSenderFilter) {
        $expectApiCode(
            422,
            static fn () => $service->searchMessages(
                101,
                'shared',
                ['q'=>'searchable'] + $invalidSenderFilter,
            ),
            'Partial or invalid composite sender filter',
        );
    }
    $assert(
        $service->searchMessages(101, 'shared', [
            'q'=>'searchable',
            'sender_organization'=>101,
            'sender_user_id'=>str_repeat('界', 21),
        ])['total'] === 0,
        'A canonical 63-byte UTF-8 sender identity was rejected.',
    );
    $assert(
        $service->searchMessages(101,'shared',['q'=>'中','conversation_id'=>$activeGroup])['total'] === 1,
        'Conversation-scoped one-character CJK LIKE search was rejected or broadened.',
    );
    $twoCharacter = $service->searchMessages(101,'shared',['q'=>'短词']);
    $assert(
        $twoCharacter['total'] === 1
            && $twoCharacter['data'][0]['message_id'] === $activeFirst,
        'Two-character search missed an authorized document or leaked a denied conversation.',
    );
    foreach ([
        ['甲', 'Forged search conversation detached from the authoritative message index.'],
        ['乙', 'Forged search message_seq detached from the authoritative message index.'],
        ['丙', 'Search document without an authoritative message index row was accepted.'],
        ['丁', 'Forged search sender detached from the authoritative message index.'],
        ['戊', 'Forged search sender organization detached from the authoritative message index.'],
        ['己', 'Forged search sent_at detached from the authoritative message index.'],
    ] as [$keyword, $message]) {
        $assert(
            $service->searchMessages(
                101,
                'shared',
                ['q'=>$keyword,'conversation_id'=>$activeGroup],
            )['total'] === 0,
            $message,
        );
    }

    $pdo->prepare(
        'UPDATE im_conversation_membership_period SET user_id="Shared" '
        . 'WHERE organization=101 AND conversation_id=? AND user_id="shared"',
    )->execute([$activeGroup]);
    $assert(
        $service->searchMessages(101,'shared',['q'=>'x','conversation_id'=>$activeGroup])['total'] === 0,
        'Case-insensitive membership-period identity matched the current member.',
    );
    $pdo->prepare(
        'UPDATE im_conversation_membership_period SET user_id="shared" '
        . 'WHERE organization=101 AND conversation_id=? AND user_id="Shared"',
    )->execute([$activeGroup]);

    $history = $service->searchMessages(101,'shared',['q'=>'x','conversation_id'=>$historyGroup]);
    $assert(
        $history['total'] === 2 && array_column($history['data'], 'message_seq') === [7,2],
        'history_only did not enforce disjoint membership periods.',
    );
    $assert(
        $service->searchMessages(
            101,
            'shared',
            ['q'=>'gap','conversation_id'=>$historyGroup],
        )['total'] === 0,
        'Closed history period leaked a message from the gap before rejoin.',
    );
    $assert(
        $service->searchMessages(
            101,
            'shared',
            ['q'=>'after','conversation_id'=>$historyGroup],
        )['total'] === 0,
        'Closed history period leaked a message after the final leave watermark.',
    );
    foreach ([
        'group_revoked',
        'group_corrupt_state',
        $invalidHistoryStatus,
        $absentGroup,
        $invalidSingle,
    ] as $deniedId) {
        $assert(
            $service->searchMessages(101,'shared',['q'=>'x','conversation_id'=>$deniedId])['total'] === 0,
            $deniedId . ' did not fail closed.',
        );
    }
    $otherOrg = $service->searchMessages(202,'shared',['q'=>'x','conversation_id'=>$otherOrgGroup]);
    $assert(
        $otherOrg['total'] === 1 && $otherOrg['data'][0]['message_id'] === $otherOrgMessage,
        'Same bare user_id was not isolated by organization.',
    );

    $pdo->prepare(
        'UPDATE im_conversation_member SET status=2 WHERE organization=101 '
        . 'AND conversation_id=? AND member_organization=101 AND user_id="peer"',
    )->execute([$sameSingle]);
    $assert(
        $service->searchMessages(101,'shared',['q'=>'x','conversation_id'=>$sameSingle])['total'] === 0,
        'Inactive same-home single peer remained searchable.',
    );
    $pdo->prepare(
        'UPDATE im_conversation_member SET status=1 WHERE organization=101 '
        . 'AND conversation_id=? AND member_organization=101 AND user_id="peer"',
    )->execute([$sameSingle]);

    $crossFilters = ['q'=>'x','conversation_id'=>$crossSingle];
    $assert($service->searchMessages(101,'shared',$crossFilters)['total'] === 1, 'Valid cross chat denied.');
    $pdo->exec('UPDATE sm_system_config SET `value`="0" WHERE `key`="cross_org_social_enabled"');
    $assert($service->searchMessages(101,'shared',$crossFilters)['total'] === 0, 'Disabled policy leaked.');
    $assert(
        $service->searchMessages(101,'shared',['q'=>'x','conversation_id'=>$activeGroup])['total'] === 2,
        'Cross policy incorrectly restricted a same-home group.',
    );
    $pdo->exec('UPDATE sm_system_config SET `value`="1" WHERE `key`="cross_org_social_enabled"');
    $pdo->exec('UPDATE sm_system_config SET `value`="0" WHERE `key`="cross_org_access_snapshot_id"');
    $assert($service->searchMessages(101,'shared',$crossFilters)['total'] === 0, 'Invalid epoch leaked.');
    $pdo->exec('UPDATE sm_system_config SET `value`="2" WHERE `key`="cross_org_access_snapshot_id"');
    $pdo->exec('UPDATE sm_system_organization SET status=2 WHERE id=202');
    $assert($service->searchMessages(101,'shared',$crossFilters)['total'] === 0, 'Inactive peer org leaked.');
    $pdo->exec('UPDATE sm_system_organization SET status=1 WHERE id=202');
    $pdo->prepare('UPDATE im_conversation SET status=2 WHERE organization=202 AND conversation_id=?')
        ->execute([$crossSingle]);
    $assert($service->searchMessages(101,'shared',$crossFilters)['total'] === 0, 'Missing peer home leaked.');
    $pdo->prepare('UPDATE im_conversation SET status=1 WHERE organization=202 AND conversation_id=?')
        ->execute([$crossSingle]);
    $pdo->prepare(
        'UPDATE im_conversation_member SET user_id="Remote" WHERE organization=202 '
        . 'AND conversation_id=? AND member_organization=202 AND user_id="remote"',
    )->execute([$crossSingle]);
    $assert(
        $service->searchMessages(101,'shared',$crossFilters)['total'] === 0,
        'Case-insensitive peer-home member identity matched the canonical identity.',
    );
    $pdo->prepare(
        'UPDATE im_conversation_member SET user_id="remote" WHERE organization=202 '
        . 'AND conversation_id=? AND member_organization=202 AND user_id="Remote"',
    )->execute([$crossSingle]);
    $pdo->prepare(
        'UPDATE im_conversation_member SET status=2 WHERE organization=202 '
        . 'AND conversation_id=? AND member_organization=202 AND user_id="remote"',
    )->execute([$crossSingle]);
    $assert($service->searchMessages(101,'shared',$crossFilters)['total'] === 0, 'Incomplete peer members leaked.');
    $pdo->prepare(
        'UPDATE im_conversation_member SET status=1 WHERE organization=202 '
        . 'AND conversation_id=? AND member_organization=202 AND user_id="remote"',
    )->execute([$crossSingle]);
    $assert($service->searchMessages(101,'shared',$crossFilters)['total'] === 1, 'Restored cross chat denied.');

    $expectApiCode(403, static fn () => $service->searchMessages(101,' shared',['q'=>'x']), 'Invalid user');
    $expectApiCode(
        422,
        static fn () => $service->searchMessages(101,'shared',['q'=>'x']),
        'Global one-character keyword',
    );
    $expectApiCode(
        422,
        static fn () => $service->searchMessages(101,'shared',['q'=>'searchable','page'=>502,'limit'=>20]),
        'Deep search page',
    );
    $expectApiCode(422, static fn () => $service->searchMessages(
        101,
        'shared',
        ['q'=>'x','conversation_id'=>' group_active'],
    ), 'Invalid conversation');

    $controllerResult = static function (string $class, string $userId) use ($assert): array {
        $controller = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(WebController::class, 'organization'))->setValue($controller, 101);
        (new ReflectionProperty(WebController::class, 'webIdentity'))->setValue($controller, [
            'organization'=>101, 'user_id'=>$userId, 'deployment_id'=>'search-acl-test',
        ]);
        $request = new Request(
            "GET /saimulti/web/search/messages?q=x&conversation_id=group_active HTTP/1.1\r\n"
            . "Host: api.example.test\r\n\r\n",
        );
        $payload = json_decode($controller->messages($request)->rawBody(), true, 512, JSON_THROW_ON_ERROR);
        $assert(($payload['code'] ?? 0) === 200, $class . ' returned an invalid envelope.');
        return $payload['data'];
    };
    $assert($controllerResult(WebSearchController::class,'shared')['total'] === 2, 'Web lost user_id.');
    $assert($controllerResult(WebSearchController::class,'someone_else')['total'] === 0, 'Web used org only.');
    $assert($controllerResult(AppSearchController::class,'shared')['total'] === 2, 'App lost user_id.');

    echo sprintf("SearchMessageAclIntegrationTest: %d assertions passed\n", $assertions);
} finally {
    $pdo = null;
    $admin->exec('DROP DATABASE IF EXISTS ' . $quotedDatabase);
}
