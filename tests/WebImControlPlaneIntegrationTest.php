<?php

declare(strict_types=1);

use Phinx\Config\Config;
use Phinx\Migration\Manager;
use OpenTelemetry\SDK\Trace\TracerProvider;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\trace\Telemetry;
use plugin\saimulti\service\web\S3WebImAssetUrlSigner;
use plugin\saimulti\service\web\ThinkOrmWebImUploadAssetStore;
use plugin\saimulti\service\web\ThinkOrmWebImControlStore;
use plugin\saimulti\service\web\ThinkOrmWebImAssetForwardStore;
use plugin\saimulti\service\web\WebImAssetForwardService;
use plugin\saimulti\service\web\WebImAssetUrlSignerInterface;
use plugin\saimulti\service\web\WebImAssetUrlService;
use plugin\saimulti\service\web\WebImAvatarService;
use plugin\saimulti\service\web\WebImControlService;
use plugin\saimulti\service\web\WebImRealtimePublisherInterface;
use plugin\saimulti\service\web\WebImUploadService;
use plugin\saimulti\service\web\WebImUploadStorageInterface;
use support\think\Db;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

$database = trim((string) (getenv('WEB_IM_TEST_DB_NAME') ?: 'nb8im_web_test'));
if (preg_match('/^[A-Za-z0-9_]+_web_test$/', $database) !== 1) {
    throw new RuntimeException('WebImControlPlaneIntegrationTest 只允许使用 *_web_test 临时库。');
}
foreach ([
    'DB_NAME' => $database,
    'IM_MESSAGE_SHARD_BUCKETS' => '1',
] as $key => $value) {
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
    putenv($key . '=' . $value);
}

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/support/bootstrap.php';

foreach ([
    'DB_NAME' => $database,
    'IM_MESSAGE_SHARD_BUCKETS' => '1',
] as $key => $value) {
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
    putenv($key . '=' . $value);
}

$thinkOrmConfig = config('think-orm');
$connectionName = (string) ($thinkOrmConfig['default'] ?? 'mysql');
if (!isset($thinkOrmConfig['connections'][$connectionName])) {
    throw new RuntimeException('ThinkORM 默认连接不存在。');
}
$connection = $thinkOrmConfig['connections'][$connectionName];
$host = (string) $connection['hostname'];
$port = (int) $connection['hostport'];
$charset = (string) $connection['charset'];
$username = (string) $connection['username'];
$password = (string) $connection['password'];
$adminPdo = new PDO(
    sprintf('mysql:host=%s;port=%d;charset=%s', $host, $port, $charset),
    $username,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$quotedDatabase = '`' . $database . '`';
$adminPdo->exec('DROP DATABASE IF EXISTS ' . $quotedDatabase);
$adminPdo->exec(
    'CREATE DATABASE ' . $quotedDatabase . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
);
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $database, $charset),
    $username,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$pdo->exec(<<<'SQL'
CREATE TABLE sm_system_organization (
  id int unsigned NOT NULL PRIMARY KEY,
  organization_name varchar(100) NOT NULL,
  title varchar(100) NOT NULL,
  status tinyint unsigned NOT NULL DEFAULT 1,
  delete_time datetime NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
$pdo->exec(<<<'SQL'
CREATE TABLE sm_system_config_group (
  id int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code varchar(100) NOT NULL,
  delete_time datetime NULL,
  UNIQUE KEY uni_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
$pdo->exec(<<<'SQL'
CREATE TABLE sm_system_config (
  id int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  group_id int unsigned NOT NULL,
  `key` varchar(100) NOT NULL,
  `value` text NULL,
  delete_time datetime NULL,
  UNIQUE KEY uni_group_key (group_id, `key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
$pdo->exec(<<<'SQL'
CREATE TABLE sm_tenant_config (
  id int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization int unsigned NOT NULL,
  group_id int unsigned NOT NULL,
  `value` text NULL,
  delete_time datetime NULL,
  UNIQUE KEY uni_org_group (organization, group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
$pdo->exec('INSERT INTO sm_system_organization (id, organization_name, title, status) VALUES (901, "Web Test 901", "Web Test 901", 1), (902, "Web Test 902", "Web Test 902", 1)');
$pdo->exec('INSERT INTO sm_system_config_group (id, code) VALUES (1, "message_config")');
$pdo->exec(
    'INSERT INTO sm_system_config (group_id, `key`, `value`) VALUES
        (1, "message_delete_single_enabled", "1"),
        (1, "message_delete_both_enabled", "1")',
);
$tenantConfig = $pdo->prepare(
    'INSERT INTO sm_tenant_config (organization, group_id, `value`) VALUES (?, 1, ?)',
);
$tenantConfig->execute([901, json_encode(['message_delete_both_enabled' => '2'], JSON_THROW_ON_ERROR)]);

$thinkOrmConfig['connections'][$connectionName]['database'] = $database;
Db::setConfig($thinkOrmConfig);

$pdoDatabase = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
$thinkOrmDatabase = (string) (Db::query('SELECT DATABASE() AS database_name')[0]['database_name'] ?? '');
if ($pdoDatabase !== $database
    || $thinkOrmDatabase !== $database
    || !str_ends_with($pdoDatabase, '_web_test')
    || !str_ends_with($thinkOrmDatabase, '_web_test')) {
    throw new RuntimeException(sprintf(
        '测试库隔离断言失败: expected=%s, pdo=%s, thinkorm=%s',
        $database,
        $pdoDatabase,
        $thinkOrmDatabase,
    ));
}

$imRoot = trim((string) (getenv('B8IM_IM_ROOT') ?: dirname(__DIR__, 2) . '/b8im-im'));
$configPath = $imRoot . '/phinx.php';
if (!is_file($configPath)) {
    throw new RuntimeException('b8im-im Phinx 配置不存在。');
}
$configValues = require $configPath;
$input = new ArrayInput([]);
$input->setInteractive(false);
$output = new BufferedOutput();
(new Manager(new Config($configValues, $configPath), $input, $output))->migrate('development');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $assertions++;
};
$expectApiCode = static function (int $code, callable $callback) use ($assert): void {
    try {
        $callback();
    } catch (ApiException $exception) {
        $assert($exception->getCode() === $code, 'ApiException code mismatch.');
        return;
    }
    throw new RuntimeException('Expected ApiException was not thrown.');
};
$expectRuntime = static function (callable $callback, string $message) use ($assert): void {
    try {
        $callback();
    } catch (RuntimeException) {
        $assert(true, $message);
        return;
    }
    throw new RuntimeException('Expected RuntimeException was not thrown: ' . $message);
};

final class RecordingWebImRealtimePublisher implements WebImRealtimePublisherInterface
{
    /** @var list<array<string, mixed>> */
    public array $friendEvents = [];

    public function publishFriendRequestCreated(int $organization, array $payload): void
    {
        $this->friendEvents[] = ['organization' => $organization, 'payload' => $payload];
    }

}

final class ReadyWebImUploadStorage implements WebImUploadStorageInterface
{
    public function assertReady(): void
    {
    }

    public function upload(
        int $organization,
        SplFileInfo $file,
        string $extension,
        string $mimeType,
    ): array {
        throw new RuntimeException('Integration test only exercises upload preparation.');
    }

    public function delete(int $organization, string $storagePath): void
    {
        throw new RuntimeException('Integration test only exercises upload preparation.');
    }
}

final class IntegrationAvatarSigner implements WebImAssetUrlSignerInterface
{
    public function sign(int $organization, string $storagePath, int $expiresAt): string
    {
        return sprintf(
            'https://s3.example.test/avatar/%d?expires=%d&X-Amz-Signature=test',
            $organization,
            $expiresAt,
        );
    }
}

$assert($pdoDatabase === $database, 'PDO did not select the isolated Web IM test database.');
$assert($thinkOrmDatabase === $database, 'ThinkORM did not select the isolated Web IM test database.');

$insertUser = $pdo->prepare(
    'INSERT INTO im_user
        (organization, user_id, im_short_no, account, password_hash, nickname, avatar,
         mobile, email, gender, is_system, status, create_time, update_time)
     VALUES (?, ?, ?, ?, ?, ?, "", ?, ?, 0, 2, 1, ?, ?)',
);
$now = '2026-07-10 12:00:00';
$users = [
    [901, 'user_a', '901001', 'alice', 'Alice', '13800000001', 'alice@example.com'],
    [901, 'user_b', '901002', 'bob', 'Bob', '13800000002', 'bob@example.com'],
    [901, 'user_c', '901003', 'carol', 'Carol', '13800000003', 'carol@example.com'],
    [901, 'user_d', '901004', 'dan', 'Dan', '13800000004', 'dan@example.com'],
    [902, 'outsider', '902001', 'outsider', 'Outsider', '13900000001', 'out@example.com'],
];
foreach ($users as [$organization, $userId, $shortNo, $account, $nickname, $mobile, $email]) {
    $insertUser->execute([
        $organization,
        $userId,
        $shortNo,
        $account,
        password_hash('test-password', PASSWORD_DEFAULT),
        $nickname,
        $mobile,
        $email,
        $now,
        $now,
    ]);
    $pdo->prepare(
        'INSERT INTO im_user_profile
            (organization, user_id, signature, status, create_time, update_time)
         VALUES (?, ?, ?, 1, ?, ?)',
    )->execute([$organization, $userId, $nickname . ' signature', $now, $now]);
    $pdo->prepare(
        'INSERT INTO im_user_privacy_setting
            (organization, user_id, allow_add_by_mobile, allow_add_by_short_no,
             allow_add_by_username, create_time, update_time)
         VALUES (?, ?, 1, 1, 1, ?, ?)',
    )->execute([$organization, $userId, $now, $now]);
}

$insertFriend = $pdo->prepare(
    'INSERT INTO im_friend_relation
        (organization, user_id, friend_organization, friend_user_id, add_method, added_at,
         status, create_time, update_time)
     VALUES (901, ?, 901, ?, "username", ?, 1, ?, ?)',
);
foreach ([
    ['user_a', 'user_b'],
    ['user_b', 'user_a'],
    ['user_a', 'user_c'],
    ['user_c', 'user_a'],
] as [$left, $right]) {
    $insertFriend->execute([$left, $right, $now, $now, $now]);
}

$realtime = new RecordingWebImRealtimePublisher();
$service = new WebImControlService(
    new ThinkOrmWebImControlStore(),
    static fn (): int => strtotime('2026-07-10 12:00:00'),
    $realtime,
    new WebImAvatarService(
        new ThinkOrmWebImUploadAssetStore(),
        new IntegrationAvatarSigner(),
        static fn (): int => strtotime('2026-07-10 12:00:00'),
        300,
    ),
);
$friendDirectionMethod = (new ReflectionClass(ThinkOrmWebImControlStore::class))
    ->getMethod('canonicalFriendDirections');
$friendDirectionMethod->setAccessible(true);
$friendDirectionsForward = $friendDirectionMethod->invoke(
    null,
    901,
    'user_d',
    901,
    'user_a',
);
$friendDirectionsReverse = $friendDirectionMethod->invoke(
    null,
    901,
    'user_a',
    901,
    'user_d',
);
$assert(
    $friendDirectionsForward === $friendDirectionsReverse
    && array_column($friendDirectionsForward, 'user_id') === ['user_a', 'user_d'],
    'Friend relation/request lock and upsert directions are observable and caller-independent.',
);
$alice = ['organization' => 901, 'user_id' => 'user_a', 'client_family' => 'web'];
$bob = ['organization' => 901, 'user_id' => 'user_b'];
$carol = ['organization' => 901, 'user_id' => 'user_c'];
$dan = ['organization' => 901, 'user_id' => 'user_d'];
$outsider = ['organization' => 902, 'user_id' => 'outsider'];

$bobAvatarFileId = str_repeat('a', 40);
Db::table('im_upload_asset')->insert([
    'organization' => 901,
    'file_id' => $bobAvatarFileId,
    'user_id' => 'user_b',
    'kind' => 'image',
    'name' => 'bob.webp',
    'url' => '',
    'storage_path' => 'private/organizations/901/im/202607/' . str_repeat('a', 48) . '.webp',
    'size_byte' => 120,
    'mime_type' => 'image/webp',
    'extension' => 'webp',
    'status' => 1,
    'create_time' => $now,
    'update_time' => $now,
]);
Db::table('im_user')
    ->where('organization', 901)
    ->where('user_id', 'user_b')
    ->update(['avatar' => $bobAvatarFileId]);

$contacts = $service->contacts($alice, '');
$assert(count($contacts) === 2, 'Contacts did not enforce the current user friend relation.');
$assert(
    array_column($contacts, 'relation_status') === ['friend', 'friend'],
    'Contacts relation status contract is invalid.',
);
$bobContact = array_values(array_filter(
    $contacts,
    static fn (array $contact): bool => ($contact['user_id'] ?? null) === 'user_b',
))[0] ?? null;
$assert(
    is_array($bobContact)
    && !array_key_exists('avatar', $bobContact)
    && $bobContact['avatar_file_id'] === $bobAvatarFileId
    && str_contains((string) $bobContact['avatar_url'], 'X-Amz-Signature=')
    && $bobContact['avatar_expires_at'] === strtotime('2026-07-10 12:05:00'),
    'Contact avatar did not use the file_id plus read-time signed URL contract.',
);
$searchDan = $service->searchUsers($alice, 'dan');
$assert(count($searchDan) === 1 && $searchDan[0]['relation_status'] === 'none', 'User search relation state mismatch.');
$assert($service->searchUsers($alice, 'Outsider') === [], 'Cross-organization user leaked into search.');

Db::table('im_user_privacy_setting')
    ->where('organization', 901)
    ->where('user_id', 'user_d')
    ->update(['allow_add_by_username' => 2]);
$expectApiCode(403, static fn () => $service->sendFriendRequest($alice, 901, 'user_d', 'blocked'));
Db::table('im_user_privacy_setting')
    ->where('organization', 901)
    ->where('user_id', 'user_d')
    ->update(['allow_add_by_username' => 1]);
$sent = $service->sendFriendRequest($alice, 901, 'user_d', 'hello');
$resent = $service->sendFriendRequest($alice, 901, 'user_d', 'hello again');
$assert($sent['status'] === 'pending' && $resent['status'] === 'pending', 'Friend request idempotency failed.');
$assert(
    count($realtime->friendEvents) === 1
    && $realtime->friendEvents[0]['organization'] === 901
    && $realtime->friendEvents[0]['payload']['to_user_id'] === 'user_d',
    'Friend request realtime event was not emitted exactly once.',
);
$requestCount = (int) $pdo->query(
    'SELECT COUNT(*) FROM im_friend_request
      WHERE organization = 901 AND from_user_id = "user_a" AND to_user_id = "user_d"',
)->fetchColumn();
$assert($requestCount === 1, 'Duplicate pending friend request was persisted.');
$danRequests = $service->friendRequests($dan);
$assert(count($danRequests) === 1 && $danRequests[0]['direction'] === 'incoming', 'Friend request list contract mismatch.');
$requestId = (int) $danRequests[0]['id'];
$accepted = $service->handleFriendRequest($dan, $requestId, 'accept');
$acceptedAgain = $service->handleFriendRequest($dan, $requestId, 'accept');
$assert($accepted['status'] === 'accepted' && $acceptedAgain['status'] === 'accepted', 'Friend acceptance is not idempotent.');
$friendPairCount = (int) Db::query(
    'SELECT COUNT(*) AS aggregate FROM im_friend_relation
      WHERE organization = 901
        AND ((user_id = "user_a" AND friend_user_id = "user_d")
          OR (user_id = "user_d" AND friend_user_id = "user_a"))
        AND status = 1 AND delete_time IS NULL',
)[0]['aggregate'];
$assert($friendPairCount === 2, 'Accepted friend request did not create the pair atomically.');
$insertFriend->execute(['user_b', 'user_d', $now, $now, $now]);
$assert($service->contacts($bob, 'Dan') === [], 'A unilateral relation leaked into contacts.');
$bobSearchDan = $service->searchUsers($bob, 'dan');
$assert(
    count($bobSearchDan) === 1 && $bobSearchDan[0]['relation_status'] === 'none',
    'A unilateral relation was projected as a friend.',
);
$expectApiCode(403, static fn () => $service->messages($bob, '', 901, 'user_d', 0, 0, 50));

$messageConfig = $service->messageConfig($alice);
$assert(
    $messageConfig === ['delete_single_enabled' => true, 'delete_both_enabled' => false],
    'Tenant message config override mismatch.',
);
$uploadService = new WebImUploadService(new ReadyWebImUploadStorage());
$preparedUpload = $uploadService->prepare($alice, 'image', 'photo.webp', 1024, 'image/webp');
$assert(
    $preparedUpload['mode'] === 'proxy'
    && $preparedUpload['upload_path'] === '/saimulti/web/im/upload'
    && $preparedUpload['method'] === 'POST',
    'Upload preparation did not expose the real proxy contract.',
);
$expectApiCode(409, static fn () => $uploadService->confirm($alice));

$group = $service->createGroup($alice, 'Project Group', ['user_b', 'user_c']);
$conversationId = (string) $group['conversation_id'];
$assert(
    array_keys($group) === [
        'organization', 'conversation_id', 'conversation_sort_id', 'conversation_type', 'title',
        'description', 'avatar_members', 'peer_user', 'last_message_id', 'last_message_seq',
        'last_message_index_id', 'last_message_summary', 'last_message_time', 'sort_time',
        'unread_count', 'is_pinned', 'is_muted', 'message_group_id', 'message_group_name',
        'avatar_file_id', 'avatar_url', 'avatar_expires_at',
    ],
    'Conversation response contract is not exact.',
);
$assert($group['conversation_type'] === 2 && $group['title'] === 'Project Group', 'Created group response mismatch.');
$groupAvatarFileId = str_repeat('9', 40);
Db::table('im_upload_asset')->insert([
    'organization' => 901,
    'file_id' => $groupAvatarFileId,
    'user_id' => 'user_a',
    'kind' => 'image',
    'name' => 'group.webp',
    'url' => '',
    'storage_path' => 'private/organizations/901/im/202607/' . str_repeat('9', 48) . '.webp',
    'size_byte' => 240,
    'mime_type' => 'image/webp',
    'extension' => 'webp',
    'status' => 1,
    'create_time' => $now,
    'update_time' => $now,
]);
$avatarGroup = $service->updateGroupProfile(
    $alice,
    $conversationId,
    null,
    $groupAvatarFileId,
    null,
    false,
);
$persistedGroupAvatar = (string) Db::table('im_conversation')
    ->where('organization', 901)
    ->where('conversation_id', $conversationId)
    ->value('avatar');
$assert(
    $persistedGroupAvatar === $groupAvatarFileId
    && !array_key_exists('avatar', $avatarGroup)
    && $avatarGroup['avatar_file_id'] === $groupAvatarFileId
    && str_contains((string) $avatarGroup['avatar_url'], 'X-Amz-Signature='),
    'Group avatar did not persist only the owned file_id and sign it on read.',
);
$profile = $pdo->prepare(
    'SELECT group_kind, history_visibility, owner_user_id FROM im_group_profile
      WHERE organization = 901 AND conversation_id = ?',
);
$profile->execute([$conversationId]);
$profileRow = $profile->fetch(PDO::FETCH_ASSOC);
$assert(
    $profileRow !== false
    && $profileRow['group_kind'] === 'normal'
    && $profileRow['history_visibility'] === 'since_join'
    && $profileRow['owner_user_id'] === 'user_a',
    'Final group profile schema was not persisted.',
);
$periodCount = (int) Db::table('im_conversation_membership_period')
    ->where('organization', 901)
    ->where('conversation_id', $conversationId)
    ->where('status', 1)
    ->count();
$assert($periodCount === 3, 'Initial membership periods are incomplete.');

$messageGroup = $service->createMessageGroup($alice, 'Work');
$sameMessageGroup = $service->createMessageGroup($alice, 'Work');
$assert($messageGroup === $sameMessageGroup, 'Message group create is not idempotent.');
$grouped = $service->updateConversationGroup($alice, $conversationId, (int) $messageGroup['id']);
$assert(
    $grouped['message_group_id'] === $messageGroup['id'] && $grouped['message_group_name'] === 'Work',
    'Conversation message group response mismatch.',
);
$settings = $service->updateConversationSetting($alice, $conversationId, true, true);
$assert($settings['is_pinned'] && $settings['is_muted'], 'Conversation settings were not persisted.');
$updatedGroup = $service->updateGroupProfile(
    $alice,
    $conversationId,
    'Renamed Group',
    null,
    'Group description',
    false,
);
$assert(
    $updatedGroup['title'] === 'Renamed Group'
    && $updatedGroup['description'] === 'Group description'
    && array_key_exists('notice_message', $updatedGroup)
    && $updatedGroup['notice_message'] === null,
    'Group profile response mismatch.',
);

$members = $service->updateGroupManagers($alice, $conversationId, ['user_b']);
$roleMap = [];
foreach ($members as $member) {
    $roleMap[$member['user']['user_id']] = $member['role'];
}
$assert($roleMap === ['user_a' => 2, 'user_b' => 3, 'user_c' => 1], 'Final member_role mapping mismatch.');
$expectApiCode(403, static fn () => $service->addGroupMembers($bob, $conversationId, ['user_d']));
$members = $service->updateGroupMemberStatus(
    $bob,
    $conversationId,
    'user_c',
    2,
    '2026-07-11 12:00:00',
);
$carolMember = array_values(array_filter(
    $members,
    static fn (array $member): bool => $member['user']['user_id'] === 'user_c',
))[0];
$assert($carolMember['status'] === 2 && $carolMember['mute_until'] !== '', 'mute_status projection mismatch.');
$service->updateGroupMemberStatus($bob, $conversationId, 'user_c', 1, '');

$messageTable = 'im_message_0000_' . date('Ym');
$insertMessage = static function (int $seq, string $text) use (
    $pdo,
    $conversationId,
    $messageTable,
    $now,
): void {
    $messageId = 'web-test-message-' . $seq;
    $body = $pdo->prepare(
        'INSERT INTO `' . $messageTable . '`
            (organization, conversation_id, conversation_type, message_id, message_seq,
             client_msg_id, sender_id, sender_organization, message_type, content, status, create_time, update_time)
         VALUES (901, ?, 2, ?, ?, ?, "user_a", 901, 1, ?, 1, ?, ?)',
    );
    $body->execute([
        $conversationId,
        $messageId,
        $seq,
        'web-test-client-' . $seq,
        json_encode(['text' => $text], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        $now,
        $now,
    ]);
    $index = $pdo->prepare(
        'INSERT INTO im_message_index
            (organization, global_seq, message_id, conversation_id, message_seq, sender_id,
             sender_organization, client_msg_id, storage_node, shard_table, create_time)
         VALUES (901, ?, ?, ?, ?, "user_a", 901, ?, "mysql-primary", ?, ?)',
    );
    $index->execute([
        $seq,
        $messageId,
        $conversationId,
        $seq,
        'web-test-client-' . $seq,
        $messageTable,
        $now,
    ]);
    $pdo->prepare(
        'UPDATE im_organization_message_sequence
            SET next_global_seq = GREATEST(next_global_seq, ?), update_time = ?
          WHERE organization = 901',
    )->execute([$seq + 1, $now]);
    $conversation = $pdo->prepare(
        'UPDATE im_conversation
            SET next_message_seq = ?, last_message_seq = ?, last_message_id = ?,
                last_message_time = ?, last_message_summary = ?, update_time = ?
          WHERE organization = 901 AND conversation_id = ?',
    );
    $conversation->execute([$seq + 1, $seq, $messageId, $now, $text, $now, $conversationId]);
};

$insertMessage(1, 'visible before leave');
$service->removeGroupMember($bob, $conversationId, 'user_c');
$service->removeGroupMember($bob, $conversationId, 'user_c');
$closedPeriod = $pdo->prepare(
    'SELECT visible_until_message_seq FROM im_conversation_membership_period
      WHERE organization = 901 AND conversation_id = ?
        AND member_organization = 901 AND user_id = "user_c" AND period_no = 1',
);
$closedPeriod->execute([$conversationId]);
$assert((int) $closedPeriod->fetchColumn() === 1, 'Removing a member did not close the visibility period.');
$insertMessage(2, 'hidden while absent');
$service->addGroupMembers($alice, $conversationId, ['user_c']);
$newPeriod = Db::query(
    'SELECT period_no, visible_from_message_seq, visible_until_message_seq
       FROM im_conversation_membership_period
      WHERE organization = 901 AND conversation_id = ? AND user_id = "user_c"
        AND member_organization = 901
   ORDER BY period_no DESC LIMIT 1',
    [$conversationId],
)[0];
$assert(
    (int) $newPeriod['period_no'] === 2
    && (int) $newPeriod['visible_from_message_seq'] === 3
    && $newPeriod['visible_until_message_seq'] === null,
    'Rejoining a since_join group did not open the next visibility period.',
);
$carolConversations = $service->conversations($carol);
$carolConversation = array_values(array_filter(
    $carolConversations,
    static fn (array $conversation): bool => $conversation['conversation_id'] === $conversationId,
))[0];
$assert(
    $carolConversation['last_message_seq'] === 1
    && $carolConversation['last_message_summary'] === 'visible before leave',
    'Conversation preview leaked a message outside the membership visibility periods.',
);
$insertMessage(3, 'visible after rejoin');

$messagePage = $service->messages($carol, $conversationId, 0, '', 0, 0, 50);
$sequences = array_column($messagePage['messages'], 'message_seq');
$assert($sequences === [1, 3], 'Message history ignored membership visibility periods.');
$assert(
    array_keys($messagePage['messages'][0]) === [
        'id', 'organization', 'conversation_id', 'conversation_type', 'message_id', 'message_seq',
        'global_seq', 'client_msg_id', 'sender_organization', 'sender_id', 'sender_user',
        'message_type', 'content', 'status', 'edit_time', 'edit_count', 'create_time',
        'delivery_status',
    ],
    'Message response contract is not exact.',
);
$assert(
    array_column($messagePage['messages'], 'delivery_status') === ['', ''],
    'Incoming message history exposed an outgoing delivery state.',
);
$pdo->prepare(
    'INSERT INTO im_message_receipt
        (organization, conversation_id, message_id, user_id, user_organization, status,
         delivered_time, read_time, create_time, update_time)
     VALUES
        (901, ?, "web-test-message-3", "user_b", 901, 1, NULL, NULL, ?, ?),
        (901, ?, "web-test-message-3", "user_b", 901, 2, ?, NULL, ?, ?),
        (901, ?, "web-test-message-3", "user_c", 901, 1, NULL, NULL, ?, ?),
        (901, ?, "web-test-message-3", "user_c", 901, 2, ?, NULL, ?, ?),
        (901, ?, "web-test-message-3", "user_c", 901, 3, ?, ?, ?, ?)',
)->execute([
    $conversationId, $now, $now,
    $conversationId, $now, $now, $now,
    $conversationId, $now, $now,
    $conversationId, $now, $now, $now,
    $conversationId, $now, $now, $now, $now,
]);
$aliceMessagePage = $service->messages($alice, $conversationId, 0, '', 0, 0, 50);
$aliceMessage3 = array_values(array_filter(
    $aliceMessagePage['messages'],
    static fn (array $message): bool => $message['message_id'] === 'web-test-message-3',
))[0];
$assert(
    $aliceMessage3['delivery_status'] === 'delivered',
    'Outgoing group delivery state did not use the minimum recipient high-water mark.',
);
$assert(
    $service->searchMessages($carol, $conversationId, 'hidden', 0, 50) === [],
    'Message search leaked a hidden membership-period message.',
);
$visibleSearch = $service->searchMessages($carol, $conversationId, 'visible', 0, 50);
$assert(array_column($visibleSearch, 'message_seq') === [3, 1], 'Visible message search ordering mismatch.');

$pdo->prepare(
    'INSERT INTO im_message_user_delete
        (organization, conversation_id, message_id, user_id, user_organization, delete_time, create_time)
     VALUES (901, ?, "web-test-message-1", "user_c", 901, ?, ?)',
)->execute([$conversationId, $now, $now]);
$messagePage = $service->messages($carol, $conversationId, 0, '', 0, 0, 50);
$assert(array_column($messagePage['messages'], 'message_seq') === [3], 'Per-user deleted message was not filtered.');

$pdo->prepare(
    'UPDATE im_conversation_member SET unread_count = 2
      WHERE organization = 901 AND conversation_id = ? AND user_id = "user_c"',
)->execute([$conversationId]);
$read = $service->markRead($carol, $conversationId, false);
$assert(
    $read['updated'] === 1
    && $read['user_organization'] === 901
    && $read['user_id'] === 'user_c',
    'markRead did not return the explicit reader identity.',
);
$sameOrgReadOutbox = Db::query(
    'SELECT event_id, payload_json FROM im_message_outbox
      WHERE organization = 901 AND conversation_id = ?
        AND event_type = "conversation.read" LIMIT 1',
    [$conversationId],
)[0] ?? null;
$sameOrgReadPayload = json_decode(
    (string) ($sameOrgReadOutbox['payload_json'] ?? ''),
    true,
    512,
    JSON_THROW_ON_ERROR,
);
$expectedSameOrgReadEventId = hash('sha256', implode('|', [
    901,
    'conversation.read',
    $conversationId,
    901,
    'user_c',
    3,
]));
$expectedSameOrgReadClientId = 'web-http-read-' . substr(hash(
    'sha256',
    '901|user_c|' . $conversationId . '|3',
), 0, 32);
$assert(
    ($sameOrgReadOutbox['event_id'] ?? '') === $expectedSameOrgReadEventId
    && ($sameOrgReadPayload['event_id'] ?? '') === $expectedSameOrgReadEventId
    && ($sameOrgReadPayload['origin_client_id'] ?? '') === $expectedSameOrgReadClientId
    && !array_key_exists('cross_org_access_snapshot_id', $sameOrgReadPayload)
    && !array_key_exists(
        'cross_org_access_snapshot_id',
        (array) ($sameOrgReadPayload['read_state'] ?? []),
    ),
    'same-organization conversation.read must preserve the pre-epoch event and client ID formula',
);
$service->markRead($carol, $conversationId, false);
$sameOrgReadOutboxCountAfterRetry = (int) Db::query(
    'SELECT COUNT(*) AS aggregate FROM im_message_outbox
      WHERE organization = 901 AND conversation_id = ?
        AND event_type = "conversation.read"',
    [$conversationId],
)[0]['aggregate'];
$assert(
    $sameOrgReadOutboxCountAfterRetry === 1,
    'same-organization repeated markRead must remain outbox-idempotent',
);
$readState = Db::query(
    'SELECT unread_count, last_read_seq FROM im_conversation_member
      WHERE organization = 901 AND conversation_id = ? AND user_id = "user_c"',
    [$conversationId],
)[0];
$assert((int) $readState['unread_count'] === 0 && (int) $readState['last_read_seq'] === 3, 'Read state mismatch.');

$traceProvider = TracerProvider::builder()->build();
Telemetry::setProviderForTesting($traceProvider);
$broadcastGroup = Telemetry::inSpan(
    'test.group-description-notice',
    'test.group-description-notice',
    [],
    fn (): array => $service->updateGroupProfile(
        $alice,
        $conversationId,
        null,
        null,
        'Broadcast description',
        true,
    ),
);
Telemetry::setProviderForTesting(null);
$notice = $broadcastGroup['notice_message'];
$assert(
    is_array($notice)
    && $notice['message_type'] === 5
    && preg_match('/^[1-9][0-9]*$/', (string) ($notice['global_seq'] ?? '')) === 1
    && ($notice['content']['mention_all'] ?? false) === true,
    'notify_all did not return the committed notice to its HTTP origin.',
);
$outbox = $pdo->prepare(
    'SELECT event_id, message_id, payload_json, traceparent, tracestate,
            next_retry_at, locked_until, worker_id, claim_token, published_at
       FROM im_message_outbox
      WHERE organization = 901 AND event_type = "message.created"
      ORDER BY id DESC LIMIT 1',
);
$outbox->execute();
$outboxRow = $outbox->fetch(PDO::FETCH_ASSOC);
$outboxPayload = $outboxRow === false
    ? null
    : json_decode((string) $outboxRow['payload_json'], true);
$assert(
    $outboxRow !== false
    && is_array($outboxPayload)
    && preg_match('/^[0-9a-f]{64}$/', (string) $outboxRow['event_id']) === 1
    && preg_match('/^00-[0-9a-f]{32}-[0-9a-f]{16}-01$/', (string) $outboxRow['traceparent']) === 1
    && $outboxRow['tracestate'] === null
    && $outboxRow['next_retry_at'] === $now
    && $outboxRow['locked_until'] === null
    && $outboxRow['worker_id'] === null
    && $outboxRow['claim_token'] === null
    && $outboxRow['published_at'] === null,
    'Group notice did not use the final outbox trace/lease schema.',
);
$assert(
    is_array($outboxPayload)
    && ($outboxPayload['event_id'] ?? null) === ($outboxRow['event_id'] ?? null)
    && ($outboxPayload['event_type'] ?? null) === 'message.created'
    && ($outboxPayload['actor_user_id'] ?? null) === 'user_a'
    && ($outboxPayload['origin_user_id'] ?? null) === 'user_a'
    && str_starts_with((string) ($outboxPayload['origin_client_id'] ?? ''), 'web-control-n')
    && ($outboxPayload['sender_id'] ?? null) === 'system_notification'
    && ($outboxPayload['sender_organization'] ?? null) === 901
    && ($outboxPayload['actor_organization'] ?? null) === 901
    && ($outboxPayload['origin_organization'] ?? null) === 901
    && ($outboxPayload['recipient_count'] ?? -1) === count($outboxPayload['recipient_identities'] ?? [])
    && in_array(
        ['organization' => 901, 'user_id' => 'user_a'],
        $outboxPayload['recipient_identities'] ?? [],
        true,
    )
    && in_array(
        ['organization' => 901, 'user_id' => 'user_b'],
        $outboxPayload['recipient_identities'] ?? [],
        true,
    )
    && in_array(
        ['organization' => 901, 'user_id' => 'user_c'],
        $outboxPayload['recipient_identities'] ?? [],
        true,
    )
    && ($outboxPayload['message']['organization'] ?? null) === 901
    && ($outboxPayload['message']['message_id'] ?? null) === ($outboxRow['message_id'] ?? null)
    && ($outboxPayload['message']['message_id'] ?? null) === ($notice['message_id'] ?? null)
    && ($outboxPayload['message']['global_seq'] ?? null) === ($outboxPayload['global_seq'] ?? null)
    && ($outboxPayload['message']['status'] ?? null) === 'normal'
    && is_array($outboxPayload['message']['content'] ?? null),
    'Group notice outbox does not satisfy the Rabbit message.created contract.',
);
$assert(count($realtime->friendEvents) === 1, 'Group notice incorrectly used the Redis realtime side channel.');
$broadcastRetry = $service->updateGroupProfile(
    $alice,
    $conversationId,
    null,
    null,
    'Broadcast description',
    true,
);
$noticeCount = (int) $pdo->query(
    'SELECT COUNT(*) FROM im_message_outbox
      WHERE organization = 901 AND event_type = "message.created"',
)->fetchColumn();
$assert(
    $broadcastRetry['notice_message'] === null
    && $noticeCount === 1
    && count($realtime->friendEvents) === 1,
    'Retrying an unchanged notify_all update created a duplicate group notice.',
);

$remark = $service->updateFriendRemark($alice, 901, 'user_d', 'D colleague');
$assert(
    $remark === [
        'friend_organization' => 901,
        'friend_user_id' => 'user_d',
        'remark' => 'D colleague',
    ],
    'Friend remark response mismatch.',
);
$danContact = $service->contacts($alice, 'D colleague');
$assert(count($danContact) === 1 && $danContact[0]['remark'] === 'D colleague', 'Friend remark was not projected.');
$expectApiCode(403, static fn () => $service->groupMembers($outsider, $conversationId));

$sourceFileId = str_repeat('b', 40);
$sourceObjectHash = str_repeat('c', 32);
$sourceStoragePath = 'private/organizations/901/im/202607/' . $sourceObjectHash . '.webp';
$sourceUrl = 'https://cdn.example.test/' . $sourceStoragePath;
$pdo->prepare(
    'INSERT INTO im_upload_asset
        (organization, file_id, user_id, kind, name, url, storage_path, size_byte,
         mime_type, extension, status, create_time, update_time)
     VALUES (901, ?, "user_a", "image", "forward.webp", ?, ?, 4321,
             "image/webp", "webp", 1, ?, ?)',
)->execute([$sourceFileId, $sourceUrl, $sourceStoragePath, $now, $now]);
$assetMessageId = 'web-test-asset-message';
$assetContent = [
    'file_id' => $sourceFileId,
    'url' => $sourceUrl,
    'name' => 'forward.webp',
    'size' => 4321,
    'mime_type' => 'image/webp',
    'extension' => 'webp',
];
$pdo->prepare(
    'INSERT INTO `' . $messageTable . '`
        (organization, conversation_id, conversation_type, message_id, message_seq,
         client_msg_id, sender_id, sender_organization, message_type, content, status, create_time, update_time)
     VALUES (901, ?, 2, ?, 100, "web-test-asset-client", "user_a", 901, 2, ?, 1, ?, ?)',
)->execute([
    $conversationId,
    $assetMessageId,
    json_encode($assetContent, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    $now,
    $now,
]);
$pdo->prepare(
    'INSERT INTO im_message_index
        (organization, global_seq, message_id, conversation_id, message_seq, sender_id,
         sender_organization, client_msg_id, storage_node, shard_table, create_time)
     VALUES (901, 100, ?, ?, 100, "user_a", 901, "web-test-asset-client", "mysql-primary", ?, ?)',
)->execute([$assetMessageId, $conversationId, $messageTable, $now]);

$assetStore = new ThinkOrmWebImAssetForwardStore();
$forwardService = new WebImAssetForwardService(
    $assetStore,
    str_repeat('integration-forward-secret-', 2),
    static fn (): int => strtotime('2026-07-10 13:00:00'),
);
$derived = $forwardService->derive(
    $carol,
    $conversationId,
    $assetMessageId,
    $sourceFileId,
    'image',
);
$derivedAgain = $forwardService->derive(
    $carol,
    $conversationId,
    $assetMessageId,
    $sourceFileId,
    'image',
);
$assert(
    $derived === $derivedAgain
    && preg_match('/^[a-f0-9]{40}$/', $derived['file_id']) === 1
    && $derived['file_id'] !== $sourceFileId,
    'Visible attachment derivation is not new and idempotent.',
);
$derivedRows = Db::query(
    'SELECT user_id, kind, name, url, storage_path, size_byte, mime_type, extension
       FROM im_upload_asset
      WHERE organization = 901 AND file_id = ?',
    [$derived['file_id']],
);
$assert(
    count($derivedRows) === 1
    && $derivedRows[0]['user_id'] === 'user_c'
    && $derivedRows[0]['kind'] === 'image'
    && $derivedRows[0]['storage_path'] === $sourceStoragePath
    && (int) $derivedRows[0]['size_byte'] === 4321,
    'Derived asset was not bound to the forwarding user with canonical metadata.',
);
$derivedCount = (int) Db::query(
    'SELECT COUNT(*) AS aggregate FROM im_upload_asset
      WHERE organization = 901 AND file_id = ?',
    [$derived['file_id']],
)[0]['aggregate'];
$assert($derivedCount === 1, 'Repeated derivation inserted duplicate asset rows.');

// A recipient-home message must resolve the original file from the sender/source organization.
$pdo->exec('INSERT INTO sm_system_config_group (id, code) VALUES (2, "social_config")');
$pdo->exec(
    'INSERT INTO sm_system_config (group_id, `key`, `value`) VALUES
        (2, "cross_org_social_enabled", "1"),
        (2, "cross_org_access_snapshot_id", "1")',
);
$crossAssetConversationId = \plugin\saimulti\service\web\SingleConversationIdentity::conversationId(
    901,
    'user_a',
    902,
    'outsider',
);
$pdo->prepare(
    'INSERT INTO im_cross_organization_conversation
        (conversation_id, left_organization, left_user_id, right_organization, right_user_id,
         next_message_seq, status, create_time, update_time)
     VALUES (?, 901, "user_a", 902, "outsider", 2, 1, ?, ?)',
)->execute([$crossAssetConversationId, $now, $now]);
$crossAssetMessageId = 'web-cross-asset-message';
foreach ([901, 902] as $homeOrganization) {
    $pdo->prepare(
        'INSERT INTO im_conversation
            (organization, conversation_id, conversation_type, title, owner_user_id, owner_organization,
             next_message_seq, last_message_seq, last_message_id, last_message_time,
             last_message_summary, status, create_time, update_time)
         VALUES (?, ?, 1, "", "user_a", 901, 2, 1, ?, ?, "cross asset", 1, ?, ?)',
    )->execute([
        $homeOrganization,
        $crossAssetConversationId,
        $crossAssetMessageId,
        $now,
        $now,
        $now,
    ]);
    foreach ([[901, 'user_a'], [902, 'outsider']] as [$memberOrganization, $memberUserId]) {
        $pdo->prepare(
            'INSERT INTO im_conversation_member
                (organization, conversation_id, user_id, member_organization, member_role, status,
                 join_at, create_time, update_time)
             VALUES (?, ?, ?, ?, "member", 1, ?, ?, ?)',
        )->execute([
            $homeOrganization,
            $crossAssetConversationId,
            $memberUserId,
            $memberOrganization,
            $now,
            $now,
            $now,
        ]);
        $pdo->prepare(
            'INSERT INTO im_conversation_membership_period
                (organization, conversation_id, user_id, member_organization, period_no,
                 visible_from_message_seq, status, join_at, create_time, update_time)
             VALUES (?, ?, ?, ?, 1, 1, 1, ?, ?, ?)',
        )->execute([
            $homeOrganization,
            $crossAssetConversationId,
            $memberUserId,
            $memberOrganization,
            $now,
            $now,
            $now,
        ]);
    }
    $pdo->prepare(
        'INSERT INTO `' . $messageTable . '`
            (organization, conversation_id, conversation_type, message_id, message_seq,
             client_msg_id, sender_id, sender_organization, message_type, content, status,
             create_time, update_time)
         VALUES (?, ?, 1, ?, 1, "web-cross-asset-client", "user_a", 901, 2, ?, 1, ?, ?)',
    )->execute([
        $homeOrganization,
        $crossAssetConversationId,
        $crossAssetMessageId,
        json_encode($assetContent, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        $now,
        $now,
    ]);
    $pdo->prepare(
        'INSERT INTO im_message_index
            (organization, global_seq, message_id, conversation_id, message_seq, sender_id,
             sender_organization, client_msg_id, storage_node, shard_table, create_time)
         VALUES (?, ?, ?, ?, 1, "user_a", 901, "web-cross-asset-client",
                 "mysql-primary", ?, ?)',
    )->execute([
        $homeOrganization,
        $homeOrganization === 901 ? 101 : 1,
        $crossAssetMessageId,
        $crossAssetConversationId,
        $messageTable,
        $now,
    ]);
}
$pdo->prepare(
    'UPDATE im_user SET avatar = ? WHERE organization = 901 AND user_id = "user_a"',
)->execute([$sourceFileId]);

$crossConversation = $service->conversations($outsider)[0] ?? null;
$assert(
    is_array($crossConversation)
    && (int) ($crossConversation['peer_user']['organization'] ?? 0) === 901
    && str_contains((string) ($crossConversation['peer_user']['avatar_url'] ?? ''), '/avatar/901?')
    && str_contains((string) ($crossConversation['avatar_url'] ?? ''), '/avatar/901?'),
    'Cross-org peer and single-conversation avatars were not signed in the peer organization.',
);
if (function_exists('proc_open')) {
    $assetLockDsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $host,
        $port,
        $database,
        $charset,
    );
    $assetLockDerivedFileId = substr(hash(
        'sha256',
        'asset-lock-order|' . $crossAssetConversationId,
    ), 0, 40);
    $assetLockPrefix = sys_get_temp_dir() . '/b8im-asset-lock-' . bin2hex(random_bytes(8));
    $assetLockInsertBarrier = $assetLockPrefix . '-insert';
    $assetLockBlockerState = $assetLockPrefix . '-blocker-state.json';
    $assetLockBlockerResult = $assetLockPrefix . '-blocker-result.json';
    $assetLockReaderResult = $assetLockPrefix . '-reader-result.json';
    $assetLockBlockerCode = <<<'PHP'
$dsn = $argv[1];
$username = $argv[2];
$password = $argv[3];
$conversationId = $argv[4];
$derivedFileId = $argv[5];
$storagePath = $argv[6];
$now = $argv[7];
$statePath = $argv[8];
$insertBarrier = $argv[9];
$resultPath = $argv[10];
$pdo = new PDO($dsn, $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
try {
    $pdo->beginTransaction();
    $canonical = $pdo->prepare(
        'SELECT conversation_id
           FROM im_cross_organization_conversation
          WHERE conversation_id = ?
          LIMIT 1
          FOR UPDATE',
    );
    $canonical->execute([$conversationId]);
    if ($canonical->fetchColumn() !== $conversationId) {
        throw new RuntimeException('Unable to lock the cross-organization canonical row.');
    }
    file_put_contents($statePath, json_encode([
        'connection_id' => (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn(),
        'canonical_locked' => true,
    ], JSON_THROW_ON_ERROR));

    $deadline = microtime(true) + 10;
    while (!is_file($insertBarrier)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Asset insert barrier timeout.');
        }
        usleep(1000);
    }
    $insert = $pdo->prepare(
        'INSERT INTO im_upload_asset
            (organization, file_id, user_id, kind, name, url, storage_path, size_byte,
             mime_type, extension, status, create_time, update_time)
         VALUES (902, ?, "outsider", "image", "forward.webp", "", ?, 4321,
                 "image/webp", "webp", 1, ?, ?)',
    );
    $insert->execute([$derivedFileId, $storagePath, $now, $now]);
    $inserted = $insert->rowCount();
    $pdo->commit();
    file_put_contents($resultPath, json_encode([
        'status' => 'inserted',
        'row_count' => $inserted,
    ], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    file_put_contents($resultPath, json_encode([
        'error' => get_class($exception) . ': ' . $exception->getMessage(),
        'driver_code' => $exception instanceof PDOException
            ? (int) ($exception->errorInfo[1] ?? 0)
            : 0,
    ], JSON_THROW_ON_ERROR));
    exit(1);
}
PHP;
    $assetLockBlocker = proc_open(
        [
            PHP_BINARY,
            '-r',
            $assetLockBlockerCode,
            $assetLockDsn,
            $username,
            $password,
            $crossAssetConversationId,
            $assetLockDerivedFileId,
            $sourceStoragePath,
            $now,
            $assetLockBlockerState,
            $assetLockInsertBarrier,
            $assetLockBlockerResult,
        ],
        [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $assetLockBlockerPipes,
    );
    if (!is_resource($assetLockBlocker)) {
        throw new RuntimeException('Unable to start the canonical-lock asset worker.');
    }

    $assetLockState = null;
    $assetLockDeadline = microtime(true) + 10;
    while (microtime(true) < $assetLockDeadline) {
        if (is_file($assetLockBlockerState)) {
            $candidate = json_decode((string) file_get_contents($assetLockBlockerState), true);
            if (($candidate['canonical_locked'] ?? false) === true
                && (int) ($candidate['connection_id'] ?? 0) > 0) {
                $assetLockState = $candidate;
                break;
            }
        }
        $processState = proc_get_status($assetLockBlocker);
        if (($processState['running'] ?? false) !== true) {
            break;
        }
        usleep(1000);
    }
    $assetLockBlockerConnectionId = (int) ($assetLockState['connection_id'] ?? 0);
    if ($assetLockBlockerConnectionId <= 0) {
        touch($assetLockInsertBarrier);
        $blockerOutput = stream_get_contents($assetLockBlockerPipes[1]);
        $blockerError = stream_get_contents($assetLockBlockerPipes[2]);
        fclose($assetLockBlockerPipes[1]);
        fclose($assetLockBlockerPipes[2]);
        $blockerExit = proc_close($assetLockBlocker);
        throw new RuntimeException(sprintf(
            'Canonical-lock asset worker did not become ready: exit=%d stdout=%s stderr=%s',
            $blockerExit,
            $blockerOutput,
            $blockerError,
        ));
    }

    $assetLockBlockerProbe = $pdo->prepare(
        'SELECT trx_state
           FROM information_schema.innodb_trx
          WHERE trx_mysql_thread_id = ?',
    );
    $assetLockBlockerActive = false;
    $assetLockBlockerDeadline = microtime(true) + 2;
    while (microtime(true) < $assetLockBlockerDeadline) {
        $assetLockBlockerProbe->execute([$assetLockBlockerConnectionId]);
        if (($assetLockBlockerProbe->fetchColumn() ?: '') === 'RUNNING') {
            $assetLockBlockerActive = true;
            break;
        }
        usleep(1000);
    }
    if (!$assetLockBlockerActive) {
        touch($assetLockInsertBarrier);
        throw new RuntimeException('Canonical-lock asset worker transaction is not active.');
    }

    $assetLockReaderCode = <<<'PHP'
$root = $argv[1];
$database = $argv[2];
$sourceFileId = $argv[3];
$conversationId = $argv[4];
$messageId = $argv[5];
$resultPath = $argv[6];
foreach (['DB_NAME' => $database, 'IM_MESSAGE_SHARD_BUCKETS' => '1'] as $key => $value) {
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
    putenv($key . '=' . $value);
}
require $root . '/vendor/autoload.php';
require $root . '/support/bootstrap.php';
try {
    $asset = (new \plugin\saimulti\service\web\ThinkOrmWebImAssetForwardStore())
        ->accessibleAsset(
            902,
            'outsider',
            $sourceFileId,
            $conversationId,
            $messageId,
        );
    file_put_contents($resultPath, json_encode([
        'status' => 'accessible',
        'asset' => $asset,
    ], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    file_put_contents($resultPath, json_encode([
        'error' => get_class($exception) . ': ' . $exception->getMessage(),
    ], JSON_THROW_ON_ERROR));
    exit(1);
}
PHP;
    $assetLockReader = proc_open(
        [
            PHP_BINARY,
            '-r',
            $assetLockReaderCode,
            dirname(__DIR__),
            $database,
            $sourceFileId,
            $crossAssetConversationId,
            $crossAssetMessageId,
            $assetLockReaderResult,
        ],
        [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $assetLockReaderPipes,
    );
    if (!is_resource($assetLockReader)) {
        touch($assetLockInsertBarrier);
        throw new RuntimeException('Unable to start the visible-asset reader worker.');
    }

    $assetLockWaitProbe = $pdo->prepare(
        'SELECT id, state, info
           FROM information_schema.processlist
          WHERE db = ?
            AND id <> ?
            AND id <> CONNECTION_ID()
            AND command IN ("Query", "Execute")
            AND info LIKE "%FROM im_cross_organization_conversation%"
            AND info LIKE "%FOR UPDATE%"
            AND info LIKE ?',
    );
    $assetLockReaderWaiting = false;
    $assetLockDeadline = microtime(true) + 10;
    while (microtime(true) < $assetLockDeadline) {
        $assetLockWaitProbe->execute([
            $database,
            $assetLockBlockerConnectionId,
            '%' . $crossAssetConversationId . '%',
        ]);
        if (is_array($assetLockWaitProbe->fetch(PDO::FETCH_ASSOC))) {
            $assetLockReaderWaiting = true;
            break;
        }
        usleep(1000);
    }
    touch($assetLockInsertBarrier);

    $assetLockBlockerOutput = stream_get_contents($assetLockBlockerPipes[1]);
    $assetLockBlockerError = stream_get_contents($assetLockBlockerPipes[2]);
    fclose($assetLockBlockerPipes[1]);
    fclose($assetLockBlockerPipes[2]);
    $assetLockBlockerExit = proc_close($assetLockBlocker);
    $assetLockReaderOutput = stream_get_contents($assetLockReaderPipes[1]);
    $assetLockReaderError = stream_get_contents($assetLockReaderPipes[2]);
    fclose($assetLockReaderPipes[1]);
    fclose($assetLockReaderPipes[2]);
    $assetLockReaderExit = proc_close($assetLockReader);
    $assetLockBlockerPayload = is_file($assetLockBlockerResult)
        ? json_decode(
            (string) file_get_contents($assetLockBlockerResult),
            true,
            512,
            JSON_THROW_ON_ERROR,
        )
        : ['error' => 'missing blocker result'];
    $assetLockReaderPayload = is_file($assetLockReaderResult)
        ? json_decode(
            (string) file_get_contents($assetLockReaderResult),
            true,
            512,
            JSON_THROW_ON_ERROR,
        )
        : ['error' => 'missing reader result'];
    foreach ([
        $assetLockInsertBarrier,
        $assetLockBlockerState,
        $assetLockBlockerResult,
        $assetLockReaderResult,
    ] as $assetLockPath) {
        @unlink($assetLockPath);
    }

    $assetLockDerivedRows = $pdo->prepare(
        'SELECT user_id, storage_path
           FROM im_upload_asset
          WHERE organization = 902
            AND file_id = ?',
    );
    $assetLockDerivedRows->execute([$assetLockDerivedFileId]);
    $assetLockDerivedRows = $assetLockDerivedRows->fetchAll(PDO::FETCH_ASSOC);
    $assetLockProbePassed = $assetLockReaderWaiting
        && $assetLockBlockerExit === 0
        && $assetLockReaderExit === 0
        && ($assetLockBlockerPayload['status'] ?? '') === 'inserted'
        && (int) ($assetLockBlockerPayload['row_count'] ?? 0) === 1
        && ($assetLockReaderPayload['status'] ?? '') === 'accessible'
        && ($assetLockReaderPayload['asset']['file_id'] ?? '') === $sourceFileId
        && ($assetLockReaderPayload['asset']['user_id'] ?? '') === 'user_a'
        && count($assetLockDerivedRows) === 1
        && ($assetLockDerivedRows[0]['user_id'] ?? '') === 'outsider'
        && ($assetLockDerivedRows[0]['storage_path'] ?? '') === $sourceStoragePath;
    $assert(
        $assetLockProbePassed,
        'Visible asset lookup waits on canonical before a target-home derived insert without deadlock: '
        . ($assetLockProbePassed ? 'ok' : json_encode([
            'reader_waiting' => $assetLockReaderWaiting,
            'blocker_exit' => $assetLockBlockerExit,
            'reader_exit' => $assetLockReaderExit,
            'blocker_output' => $assetLockBlockerOutput,
            'blocker_error' => $assetLockBlockerError,
            'reader_output' => $assetLockReaderOutput,
            'reader_error' => $assetLockReaderError,
            'blocker_payload' => $assetLockBlockerPayload,
            'reader_payload' => $assetLockReaderPayload,
            'derived_rows' => $assetLockDerivedRows,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
    );
} else {
    echo "SKIP proc_open visible-asset lock-order regression\n";
}
$crossDerived = $forwardService->derive(
    $outsider,
    $crossAssetConversationId,
    $crossAssetMessageId,
    $sourceFileId,
    'image',
);
$crossDerivedRow = Db::query(
    'SELECT organization, user_id, storage_path
       FROM im_upload_asset
      WHERE organization = 902 AND file_id = ?',
    [$crossDerived['file_id']],
)[0] ?? null;
$assert(
    is_array($crossDerivedRow)
    && $crossDerivedRow['user_id'] === 'outsider'
    && $crossDerivedRow['storage_path'] === $sourceStoragePath,
    'Cross-org attachment forwarding did not use the sender organization source asset.',
);
$pdo->prepare(
    'UPDATE im_cross_organization_conversation SET status = 2 WHERE conversation_id = ?',
)->execute([$crossAssetConversationId]);
$expectRuntime(
    static fn () => $forwardService->derive(
        $outsider,
        $crossAssetConversationId,
        $crossAssetMessageId,
        $sourceFileId,
        'image',
    ),
    'Cross-org attachment rejects an inactive canonical row.',
);
$pdo->prepare(
    'UPDATE im_cross_organization_conversation SET status = 1 WHERE conversation_id = ?',
)->execute([$crossAssetConversationId]);
$pdo->prepare(
    'UPDATE im_conversation SET status = 2 WHERE organization = 901 AND conversation_id = ?',
)->execute([$crossAssetConversationId]);
$expectRuntime(
    static fn () => $forwardService->derive(
        $outsider,
        $crossAssetConversationId,
        $crossAssetMessageId,
        $sourceFileId,
        'image',
    ),
    'Cross-org attachment rejects a missing peer-home projection.',
);
$pdo->prepare(
    'UPDATE im_conversation SET status = 1 WHERE organization = 901 AND conversation_id = ?',
)->execute([$crossAssetConversationId]);
$pdo->exec('UPDATE sm_system_organization SET status = 2 WHERE id = 901');
$expectApiCode(403, static fn () => $forwardService->derive(
    $outsider,
    $crossAssetConversationId,
    $crossAssetMessageId,
    $sourceFileId,
    'image',
));
$pdo->exec('UPDATE sm_system_organization SET status = 1 WHERE id = 901');

$pdo->prepare(
    'INSERT INTO im_conversation_member
        (organization, conversation_id, user_id, member_organization, member_role, status,
         join_at, create_time, update_time)
     VALUES (901, ?, "outsider", 902, "member", 1, ?, ?, ?)',
)->execute([$conversationId, $now, $now, $now]);
$expectRuntime(
    static fn () => $service->conversations($carol),
    'Group conversation rejects an active member outside its home organization.',
);
$pdo->prepare(
    'DELETE FROM im_conversation_member
      WHERE organization = 901 AND conversation_id = ?
        AND member_organization = 902 AND user_id = "outsider"',
)->execute([$conversationId]);

$pdo->exec(
    'UPDATE sm_system_config
        SET `value` = CASE `key`
            WHEN "cross_org_social_enabled" THEN "0"
            WHEN "cross_org_access_snapshot_id" THEN "2"
            ELSE `value`
        END
      WHERE group_id = 2',
);
\plugin\saimulti\service\web\CrossOrganizationSocialPolicy::clearCache();
$expectApiCode(403, static fn () => $forwardService->derive(
    $outsider,
    $crossAssetConversationId,
    $crossAssetMessageId,
    $sourceFileId,
    'image',
));

$expectApiCode(404, static fn () => $forwardService->derive(
    $carol,
    $conversationId,
    $assetMessageId,
    str_repeat('d', 40),
    'image',
));
$expectApiCode(404, static fn () => $forwardService->derive(
    $carol,
    $conversationId,
    $assetMessageId,
    $sourceFileId,
    'file',
));
$expectApiCode(404, static fn () => $forwardService->derive(
    $outsider,
    $conversationId,
    $assetMessageId,
    $sourceFileId,
    'image',
));

$pdo->prepare(
    'DELETE FROM im_message_user_delete
      WHERE organization = 901 AND conversation_id = ?
        AND message_id = "web-test-message-1" AND user_id = "user_c"',
)->execute([$conversationId]);
$pdo->prepare(
    'UPDATE `' . $messageTable . '`
        SET message_type = 2, content = ?
      WHERE organization = 901 AND conversation_id = ? AND message_id = "web-test-message-1"',
)->execute([
    json_encode($assetContent, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    $conversationId,
]);
$historical = $forwardService->derive(
    $carol,
    $conversationId,
    'web-test-message-1',
    $sourceFileId,
    'image',
);
$assert(
    $historical['file_id'] !== $derived['file_id'],
    'A visible closed membership period was not accepted with message-bound derivation.',
);
$hiddenFileId = str_repeat('e', 40);
$hiddenStoragePath = 'private/organizations/901/im/202607/' . str_repeat('f', 32) . '.webp';
$hiddenUrl = 'https://cdn.example.test/' . $hiddenStoragePath;
$pdo->prepare(
    'INSERT INTO im_upload_asset
        (organization, file_id, user_id, kind, name, url, storage_path, size_byte,
         mime_type, extension, status, create_time, update_time)
     VALUES (901, ?, "user_a", "image", "hidden.webp", ?, ?, 111,
             "image/webp", "webp", 1, ?, ?)',
)->execute([$hiddenFileId, $hiddenUrl, $hiddenStoragePath, $now, $now]);
$hiddenContent = [
    'file_id' => $hiddenFileId,
    'url' => $hiddenUrl,
    'name' => 'hidden.webp',
    'size' => 111,
    'mime_type' => 'image/webp',
    'extension' => 'webp',
];
$pdo->prepare(
    'UPDATE `' . $messageTable . '`
        SET message_type = 2, content = ?
      WHERE organization = 901 AND conversation_id = ? AND message_id = "web-test-message-2"',
)->execute([
    json_encode($hiddenContent, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    $conversationId,
]);
$expectApiCode(404, static fn () => $forwardService->derive(
    $carol,
    $conversationId,
    'web-test-message-2',
    $hiddenFileId,
    'image',
));

$s3Config = [
    'upload_mode' => '5',
    's3_acl' => 'private',
    's3_key' => 'INTEGRATIONACCESSKEY',
    's3_secret' => 'integration-secret-not-for-network-use',
    's3_bucket' => 'private-bucket',
    's3_dirname' => 'private',
    's3_region' => 'us-east-1',
    's3_version' => 'latest',
    's3_use_path_style_endpoint' => '1',
    's3_endpoint' => 'https://s3.example.test',
];
$urlNow = time();
$assetUrlService = new WebImAssetUrlService(
    $assetStore,
    new S3WebImAssetUrlSigner(static fn (): array => $s3Config),
    static fn (): int => $urlNow,
    300,
);
$ownerUrl = $assetUrlService->resolve($carol, (string) $derived['file_id']);
$visibleUrl = $assetUrlService->resolve($bob, $sourceFileId, $conversationId, $assetMessageId);
$assert(
    $ownerUrl['expires_at'] === $urlNow + 300
    && $visibleUrl['expires_at'] === $urlNow + 300
    && str_contains($ownerUrl['url'], 'X-Amz-Signature=')
    && str_contains($visibleUrl['url'], 'X-Amz-Signature='),
    'Owner or visible recipient did not receive a short-lived private URL.',
);
$expectApiCode(404, static fn () => $assetUrlService->resolve($outsider, $sourceFileId, $conversationId, $assetMessageId));
$expectApiCode(404, static fn () => $assetUrlService->resolve($carol, $hiddenFileId, $conversationId, 'web-test-message-2'));
$pdo->prepare(
    'INSERT INTO im_message_user_delete
        (organization, conversation_id, message_id, user_id, user_organization, delete_time, create_time)
     VALUES (901, ?, ?, "user_c", 901, ?, ?)',
)->execute([$conversationId, $assetMessageId, $now, $now]);
$expectApiCode(404, static fn () => $assetUrlService->resolve($carol, $sourceFileId, $conversationId, $assetMessageId));
$persistedAsset = Db::query(
    'SELECT url, storage_path FROM im_upload_asset
      WHERE organization = 901 AND file_id = ?',
    [$sourceFileId],
)[0];
$persistedMessageContent = (string) Db::query(
    'SELECT content FROM `' . $messageTable . '`
      WHERE organization = 901 AND conversation_id = ? AND message_id = ?',
    [$conversationId, $assetMessageId],
)[0]['content'];
$assert(
    !str_contains((string) $persistedAsset['url'], 'X-Amz-')
    && !str_contains((string) $persistedAsset['storage_path'], 'X-Amz-')
    && !str_contains($persistedMessageContent, 'X-Amz-'),
    'A temporary signed URL was persisted in asset metadata or the message body.',
);

$columns = $pdo->query(
    'SELECT COLUMN_NAME FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "im_conversation_member"',
)->fetchAll(PDO::FETCH_COLUMN);
$assert(
    in_array('member_role', $columns, true)
    && in_array('mute_status', $columns, true)
    && in_array('join_at', $columns, true)
    && in_array('conversation_remark', $columns, true)
    && !in_array('role', $columns, true)
    && !in_array('join_time', $columns, true),
    'Integration test is not running against the final IM membership schema.',
);

$adminPdo->exec('DROP DATABASE IF EXISTS ' . $quotedDatabase);

echo sprintf(
    "WebImControlPlaneIntegrationTest: %d assertions passed on %s (PDO + ThinkORM)\n",
    $assertions,
    $database,
);
