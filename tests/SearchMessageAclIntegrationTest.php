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

use plugin\saimulti\app\controller\app\SearchController as AppSearchController;
use plugin\saimulti\app\controller\web\SearchController as WebSearchController;
use plugin\saimulti\basic\WebController;
use plugin\saimulti\exception\ApiException;
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
 sender_user_id varchar(64) NOT NULL, message_type int unsigned NOT NULL,
 message_seq bigint unsigned NOT NULL, content text NOT NULL, visibility tinyint unsigned NOT NULL,
 sent_at datetime NULL, create_time datetime NULL, update_time datetime NULL,
 UNIQUE KEY uni_org_message (organization, message_id), FULLTEXT KEY ft_content (content)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE im_message_index (
 id bigint unsigned AUTO_INCREMENT PRIMARY KEY, organization int unsigned NOT NULL,
 global_seq bigint unsigned NOT NULL, message_id varchar(64) NOT NULL,
 conversation_id varchar(64) NOT NULL, message_seq bigint unsigned NOT NULL,
 sender_id varchar(64) NOT NULL, sender_organization int unsigned NOT NULL,
 create_time datetime NULL,
 UNIQUE KEY uni_org_message (organization, message_id)
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
        . '(organization,message_id,conversation_id,sender_user_id,message_type,message_seq,'
        . 'content,visibility,sent_at) VALUES (?, ?, ?, "sender", 1, ?, ?, ?, ?)',
    );
    $messageIndex = $pdo->prepare(
        'INSERT INTO im_message_index '
        . '(organization,global_seq,message_id,conversation_id,message_seq,sender_id,'
        . 'sender_organization,create_time) VALUES (?,?,?,?,?,"sender",?,?)',
    );
    $docNo = 0;
    $addDoc = static function (
        int $organization,
        string $conversationId,
        int $messageSeq,
        string $label,
        int $visibility = 1,
    ) use ($document, $messageIndex, &$docNo): string {
        ++$docNo;
        $messageId = 'search-acl-' . $docNo;
        $sentAt = sprintf('2026-07-20 12:%02d:00', $docNo);
        $document->execute([
            $organization, $messageId, $conversationId, $messageSeq,
            'x searchable ' . $label, $visibility, $sentAt,
        ]);
        $messageIndex->execute([
            $organization, $docNo, $messageId, $conversationId, $messageSeq,
            $organization, $sentAt,
        ]);
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

    $service = new SearchService();
    $assert(
        $service->indexList(['page'=>1,'limit'=>20])['total'] === 2,
        'Platform index management was incorrectly scoped as an end user.',
    );
    $assert(
        $service->indexRead(202, false)['organization'] === 202,
        'Tenant index management was incorrectly scoped by the Web user ACL.',
    );
    $expected = [$activeFirst,$activeLater,$historyFirst,$historySecond,$sameSingleMessage,$crossMessage];
    $actual = [];
    for ($page = 1; $page <= 3; ++$page) {
        $result = $service->searchMessages(101,'shared',['q'=>'searchable','page'=>$page,'limit'=>2]);
        $assert($result['total'] === 6, 'SQL ACL total included unauthorized documents.');
        $assert(count($result['data']) === 2, 'SQL ACL returned a short authorized page.');
        array_push($actual, ...array_column($result['data'], 'message_id'));
    }
    sort($expected, SORT_STRING);
    sort($actual, SORT_STRING);
    $assert($actual === $expected, 'SQL ACL pages leaked or omitted message documents.');
    $assert(
        $service->searchMessages(101,'Shared',['q'=>'searchable'])['total'] === 0,
        'Case-insensitive collation matched a different current composite identity.',
    );
    $assert(
        $service->searchMessages(101,'shared',['q'=>'x','conversation_id'=>'GROUP_ACTIVE'])['total'] === 0,
        'Case-insensitive collation matched a different conversation filter.',
    );
    $assert(
        $service->searchMessages(101,'shared',['q'=>'searchable','sender_user_id'=>'Sender'])['total'] === 0,
        'Case-insensitive collation matched a different sender identity filter.',
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
