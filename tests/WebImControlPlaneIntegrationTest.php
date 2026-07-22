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

$pdo->exec(<<<'SQL'
CREATE TABLE sm_tenant_quota (
  id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization int unsigned NOT NULL,
  quota_key varchar(64) NOT NULL,
  quota_value bigint unsigned NOT NULL DEFAULT 0,
  used_value bigint unsigned NOT NULL DEFAULT 0,
  status varchar(16) NOT NULL DEFAULT 'active',
  start_at datetime NULL,
  end_at datetime NULL,
  version int unsigned NOT NULL DEFAULT 1,
  update_time datetime NOT NULL,
  delete_time datetime NULL,
  UNIQUE KEY uni_organization_quota_key (organization, quota_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
$pdo->exec(<<<'SQL'
CREATE TABLE sm_im_upload_reservation (
  id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization int unsigned NOT NULL,
  KEY idx_organization (organization)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
$pdo->exec(
    "INSERT INTO sm_tenant_quota
      (organization,quota_key,quota_value,used_value,status,version,update_time)
     VALUES
      (901,'storage_bytes',1000000000,0,'active',1,NOW()),
      (902,'storage_bytes',1000000000,0,'active',1,NOW())",
);

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
$expectThrowable = static function (callable $callback, string $message) use ($assert): void {
    try {
        $callback();
    } catch (Throwable) {
        $assert(true, $message);
        return;
    }
    throw new RuntimeException('Expected Throwable was not thrown: ' . $message);
};

final class ReadyWebImUploadStorage implements WebImUploadStorageInterface
{
    public function assertReady(): void
    {
    }

    public function reservePath(int $organization, string $extension, string $objectId): string
    {
        return "private/organizations/{$organization}/im/202607/{$objectId}.{$extension}";
    }

    public function uploadExact(
        int $organization,
        SplFileInfo $file,
        string $storagePath,
        string $mimeType,
        ?callable $heartbeat = null,
    ): void {
        throw new RuntimeException('Integration test only exercises upload preparation.');
    }

    public function inspect(int $organization, string $storagePath): array
    {
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
$sqlMode = (string) (Db::query('SELECT @@SESSION.sql_mode AS sql_mode')[0]['sql_mode'] ?? '');
$sqlModes = array_values(array_filter(explode(',', $sqlMode)));
if (!in_array('ONLY_FULL_GROUP_BY', $sqlModes, true)) {
    $sqlModes[] = 'ONLY_FULL_GROUP_BY';
    Db::execute('SET SESSION sql_mode = ?', [implode(',', $sqlModes)]);
}

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
    [901, '123', '901005', 'numeric_user', 'Numeric User', '13800000005', 'numeric@example.com'],
    [901, 'user_e', '901006', 'erin', 'Erin', '13800000006', 'erin@example.com'],
    [901, 'user_f', '901007', 'frank', 'Frank', '13800000007', 'frank@example.com'],
    [901, 'user_g', '901008', 'grace', 'Grace', '13800000008', 'grace@example.com'],
    [901, 'user_h', '901009', 'heidi', 'Heidi', '13800000009', 'heidi@example.com'],
    [901, 'user_i', '901010', 'ivan', 'Ivan', '13800000010', 'ivan@example.com'],
    [901, 'user_j', '901011', 'judy', 'Judy', '13800000011', 'judy@example.com'],
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
    $pdo->prepare(
        'INSERT INTO im_user_group_access_state
            (organization, user_id, access_snapshot_id, create_time, update_time)
         VALUES (?, ?, 1, ?, ?)',
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

$service = new WebImControlService(
    new ThinkOrmWebImControlStore(),
    static fn (): int => strtotime('2026-07-10 12:00:00'),
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

$caseCreatedFacts = [
    (int) $pdo->query('SELECT COUNT(*) FROM im_friend_request')->fetchColumn(),
    (int) $pdo->query('SELECT COUNT(*) FROM im_friend_relation')->fetchColumn(),
    (int) $pdo->query('SELECT COUNT(*) FROM im_realtime_control_outbox')->fetchColumn(),
];
$expectApiCode(
    422,
    static fn () => $service->sendFriendRequest($alice, 901, 'User_D', 'case collision'),
);
$assert(
    $caseCreatedFacts === [
        (int) $pdo->query('SELECT COUNT(*) FROM im_friend_request')->fetchColumn(),
        (int) $pdo->query('SELECT COUNT(*) FROM im_friend_relation')->fetchColumn(),
        (int) $pdo->query('SELECT COUNT(*) FROM im_realtime_control_outbox')->fetchColumn(),
    ],
    'Case-colliding target identity created a request, relation or outbox fact.',
);

Db::table('im_user_privacy_setting')
    ->where('organization', 901)
    ->where('user_id', 'user_d')
    ->update(['allow_add_by_username' => 2]);
$expectApiCode(403, static fn () => $service->sendFriendRequest($alice, 901, 'user_d', 'blocked'));
Db::table('im_user_privacy_setting')
    ->where('organization', 901)
    ->where('user_id', 'user_d')
    ->update(['allow_add_by_username' => 1]);
$pdo->exec(
    "CREATE TRIGGER fail_friend_created_outbox
     BEFORE INSERT ON im_realtime_control_outbox
     FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced friend outbox failure'",
);
try {
    $expectThrowable(
        static fn () => $service->sendFriendRequest($alice, 901, 'user_i', 'rollback'),
        'Created friend request must roll back when its outbox insert fails.',
    );
} finally {
    $pdo->exec('DROP TRIGGER IF EXISTS fail_friend_created_outbox');
}
$assert(
    (int) $pdo->query(
        'SELECT COUNT(*) FROM im_friend_request
          WHERE organization = 901 AND from_user_id = "user_a" AND to_user_id = "user_i"',
    )->fetchColumn() === 0,
    'Outbox failure committed a new friend request.',
);
$sent = $service->sendFriendRequest($alice, 901, 'user_d', 'hello');
$resent = $service->sendFriendRequest($alice, 901, 'user_d', 'hello again');
$assert($sent['status'] === 'pending' && $resent['status'] === 'pending', 'Friend request idempotency failed.');
$requestCount = (int) $pdo->query(
    'SELECT COUNT(*) FROM im_friend_request
      WHERE organization = 901 AND from_user_id = "user_a" AND to_user_id = "user_d"',
)->fetchColumn();
$assert($requestCount === 1, 'Duplicate pending friend request was persisted.');
$createdOutbox = $pdo->query(
    'SELECT * FROM im_realtime_control_outbox
      WHERE aggregate_type = "friend_request" AND event_type = "friend_request.created"
        AND organization = 901 AND BINARY target_user_id = BINARY "user_d"',
)->fetch(PDO::FETCH_ASSOC);
$createdPayload = is_array($createdOutbox)
    ? json_decode((string) $createdOutbox['payload_json'], true, flags: JSON_THROW_ON_ERROR)
    : null;
$expectedCreatedId = hash('sha256', json_encode(
    [
        'friend_request.v1', (int) ($createdOutbox['aggregate_id'] ?? 0),
        'friend_request.created', '901', 'user_a', '901', 'user_d',
        '901', 'user_d', null,
    ],
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
));
$assert(
    is_array($createdOutbox)
    && $createdOutbox['event_id'] === $expectedCreatedId
    && (int) $createdOutbox['status'] === 1
    && (int) $createdOutbox['retry_count'] === 0
    && $createdOutbox['next_retry_at'] === null
    && $createdOutbox['locked_until'] === null
    && $createdOutbox['worker_id'] === null
    && $createdOutbox['claim_token'] === null
    && $createdOutbox['published_at'] === null
    && $createdOutbox['last_error'] === null,
    'Created friend request did not persist the pending outbox row.',
);
$assert(
    is_array($createdPayload)
    && array_keys($createdPayload) === ['event_id', 'type', 'organization', 'data']
    && $createdPayload['type'] === 'friend_request'
    && $createdPayload['organization'] === '901'
    && $createdPayload['data']['event'] === 'created'
    && $createdPayload['data']['status'] === 1
    && $createdPayload['data']['target_user_id'] === 'user_d'
    && $createdPayload['data']['actor_user_id'] === 'user_a'
    && array_key_exists('cross_org_access_snapshot_id', $createdPayload['data'])
    && $createdPayload['data']['cross_org_access_snapshot_id'] === null
    && $createdPayload['data']['handle_time'] === null,
    'Created friend request immutable envelope is invalid.',
);
$danRequests = $service->friendRequests($dan);
$assert(count($danRequests) === 1 && $danRequests[0]['direction'] === 'incoming', 'Friend request list contract mismatch.');
$requestId = (int) $danRequests[0]['id'];
$caseTerminalFacts = [
    (int) $pdo->query('SELECT status FROM im_friend_request WHERE id = ' . $requestId)->fetchColumn(),
    (int) $pdo->query('SELECT COUNT(*) FROM im_friend_relation')->fetchColumn(),
    (int) $pdo->query('SELECT COUNT(*) FROM im_realtime_control_outbox')->fetchColumn(),
];
$caseDan = ['organization' => 901, 'user_id' => 'User_D'];
$expectApiCode(422, static fn () => $service->handleFriendRequest($caseDan, $requestId, 'accept'));
$expectApiCode(422, static fn () => $service->handleFriendRequest($caseDan, $requestId, 'reject'));
$assert(
    $caseTerminalFacts === [
        (int) $pdo->query('SELECT status FROM im_friend_request WHERE id = ' . $requestId)->fetchColumn(),
        (int) $pdo->query('SELECT COUNT(*) FROM im_friend_relation')->fetchColumn(),
        (int) $pdo->query('SELECT COUNT(*) FROM im_realtime_control_outbox')->fetchColumn(),
    ],
    'Case-colliding recipient identity changed terminal friend facts.',
);
$pdo->exec(
    "CREATE TRIGGER fail_friend_terminal_outbox
     BEFORE INSERT ON im_realtime_control_outbox
     FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced friend outbox failure'",
);
try {
    $expectThrowable(
        static fn () => $service->handleFriendRequest($dan, $requestId, 'accept'),
        'Accepted friend request must roll back when its outbox insert fails.',
    );
} finally {
    $pdo->exec('DROP TRIGGER IF EXISTS fail_friend_terminal_outbox');
}
$rolledBackStatus = (int) $pdo->query(
    'SELECT status FROM im_friend_request WHERE id = ' . $requestId,
)->fetchColumn();
$rolledBackRelations = (int) $pdo->query(
    'SELECT COUNT(*) FROM im_friend_relation
      WHERE organization = 901
        AND ((user_id = "user_a" AND friend_user_id = "user_d")
          OR (user_id = "user_d" AND friend_user_id = "user_a"))',
)->fetchColumn();
$assert(
    $rolledBackStatus === 1 && $rolledBackRelations === 0,
    'Outbox failure committed friend status or relation facts.',
);
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
$caseRelationFacts = [
    (int) $pdo->query('SELECT status FROM im_friend_request WHERE id = ' . $requestId)->fetchColumn(),
    (int) $pdo->query('SELECT COUNT(*) FROM im_friend_relation')->fetchColumn(),
    (int) $pdo->query('SELECT COUNT(*) FROM im_realtime_control_outbox')->fetchColumn(),
];
$expectApiCode(422, static fn () => $service->messages($alice, '', 901, 'User_D', 0, 0, 50));
$assert(
    $caseRelationFacts === [
        (int) $pdo->query('SELECT status FROM im_friend_request WHERE id = ' . $requestId)->fetchColumn(),
        (int) $pdo->query('SELECT COUNT(*) FROM im_friend_relation')->fetchColumn(),
        (int) $pdo->query('SELECT COUNT(*) FROM im_realtime_control_outbox')->fetchColumn(),
    ],
    'Case-colliding relation lookup changed friend facts.',
);
$terminalRows = $pdo->query(
    'SELECT event_type, organization, target_user_id, payload_json
       FROM im_realtime_control_outbox
      WHERE aggregate_type = "friend_request" AND aggregate_id = ' . $requestId . '
      ORDER BY id ASC',
)->fetchAll(PDO::FETCH_ASSOC);
$acceptedPayload = json_decode((string) ($terminalRows[1]['payload_json'] ?? ''), true);
$assert(
    count($terminalRows) === 2
    && $terminalRows[1]['event_type'] === 'friend_request.accepted'
    && (int) $terminalRows[1]['organization'] === 901
    && $terminalRows[1]['target_user_id'] === 'user_a'
    && ($acceptedPayload['data']['event'] ?? null) === 'accepted'
    && ($acceptedPayload['data']['status'] ?? null) === 2
    && ($acceptedPayload['data']['actor_user_id'] ?? null) === 'user_d'
    && ($acceptedPayload['data']['handle_time'] ?? null) === $now,
    'Accepted friend request outbox direction or idempotency is invalid.',
);
$appendFriendEvent = (new ReflectionClass(ThinkOrmWebImControlStore::class))
    ->getMethod('appendFriendRequestControlEvent');
$appendFriendEvent->setAccessible(true);
$requestRow = Db::query(
    'SELECT * FROM im_friend_request WHERE id = ? LIMIT 1',
    [$requestId],
)[0];
$appendFriendEvent->invoke(
    new ThinkOrmWebImControlStore(),
    $requestRow,
    'friend_request.created',
    901,
    'user_d',
    901,
    'user_a',
    null,
    $now,
);
$assert(
    (int) $pdo->query(
        'SELECT COUNT(*) FROM im_realtime_control_outbox
          WHERE aggregate_type = "friend_request" AND aggregate_id = ' . $requestId,
    )->fetchColumn() === 2,
    'Exact duplicate friend outbox insertion created another row.',
);
$pdo->exec(
    'UPDATE im_realtime_control_outbox SET payload_json = "{}"
      WHERE aggregate_type = "friend_request" AND aggregate_id = ' . $requestId . '
        AND event_type = "friend_request.created"',
);
try {
    $expectRuntime(
        static fn () => $appendFriendEvent->invoke(
            new ThinkOrmWebImControlStore(),
            $requestRow,
            'friend_request.created',
            901,
            'user_d',
            901,
            'user_a',
            null,
            $now,
        ),
        'Duplicate key must not hide immutable friend outbox payload drift.',
    );
} finally {
    $restorePayload = $pdo->prepare(
        'UPDATE im_realtime_control_outbox SET payload_json = ?
          WHERE aggregate_type = "friend_request" AND aggregate_id = ?
            AND event_type = "friend_request.created"',
    );
    $restorePayload->execute([$createdOutbox['payload_json'], $requestId]);
}
$erin = ['organization' => 901, 'user_id' => 'user_e'];
$rejectedRequest = $service->sendFriendRequest($alice, 901, 'user_e', 'reject me');
$rejectedRequestId = (int) $pdo->query(
    'SELECT id FROM im_friend_request
      WHERE organization = 901 AND from_user_id = "user_a" AND to_user_id = "user_e"
      ORDER BY id DESC LIMIT 1',
)->fetchColumn();
$rejected = $service->handleFriendRequest($erin, $rejectedRequestId, 'reject');
$rejectedAgain = $service->handleFriendRequest($erin, $rejectedRequestId, 'reject');
$rejectedRows = $pdo->query(
    'SELECT event_type, organization, target_user_id, payload_json
       FROM im_realtime_control_outbox
      WHERE aggregate_type = "friend_request" AND aggregate_id = ' . $rejectedRequestId . '
      ORDER BY id ASC',
)->fetchAll(PDO::FETCH_ASSOC);
$rejectedPayload = json_decode((string) ($rejectedRows[1]['payload_json'] ?? ''), true);
$assert(
    $rejectedRequest['status'] === 'pending'
    && $rejected['status'] === 'rejected'
    && $rejectedAgain['status'] === 'rejected'
    && count($rejectedRows) === 2
    && $rejectedRows[1]['event_type'] === 'friend_request.rejected'
    && (int) $rejectedRows[1]['organization'] === 901
    && $rejectedRows[1]['target_user_id'] === 'user_a'
    && ($rejectedPayload['data']['event'] ?? null) === 'rejected'
    && ($rejectedPayload['data']['status'] ?? null) === 3
    && ($rejectedPayload['data']['actor_user_id'] ?? null) === 'user_e',
    'Rejected friend request outbox direction or idempotency is invalid.',
);
$ivan = ['organization' => 901, 'user_id' => 'user_i'];
$judy = ['organization' => 901, 'user_id' => 'user_j'];
$reversePending = $service->sendFriendRequest($ivan, 901, 'user_j', 'reverse pending');
$reverseRequestId = (int) $pdo->query(
    'SELECT id FROM im_friend_request
      WHERE organization = 901
        AND BINARY from_user_id = BINARY "user_i"
        AND BINARY to_user_id = BINARY "user_j"
      ORDER BY id DESC LIMIT 1',
)->fetchColumn();
$caseReverseFacts = [
    (int) $pdo->query('SELECT status FROM im_friend_request WHERE id = ' . $reverseRequestId)->fetchColumn(),
    (int) $pdo->query('SELECT COUNT(*) FROM im_friend_relation')->fetchColumn(),
    (int) $pdo->query('SELECT COUNT(*) FROM im_realtime_control_outbox')->fetchColumn(),
];
$caseJudy = ['organization' => 901, 'user_id' => 'User_J'];
$expectApiCode(422, static fn () => $service->sendFriendRequest($caseJudy, 901, 'user_i', 'case auto accept'));
$assert(
    $caseReverseFacts === [
        (int) $pdo->query('SELECT status FROM im_friend_request WHERE id = ' . $reverseRequestId)->fetchColumn(),
        (int) $pdo->query('SELECT COUNT(*) FROM im_friend_relation')->fetchColumn(),
        (int) $pdo->query('SELECT COUNT(*) FROM im_realtime_control_outbox')->fetchColumn(),
    ],
    'Case-colliding reverse requester auto-accepted or wrote friend facts.',
);
$reverseAccepted = $service->sendFriendRequest($judy, 901, 'user_i', 'auto accept');
$reverseAcceptedAgain = $service->sendFriendRequest($judy, 901, 'user_i', 'already friends');
$reverseRows = $pdo->query(
    'SELECT event_type, organization, target_user_id, payload_json
       FROM im_realtime_control_outbox
      WHERE aggregate_type = "friend_request" AND aggregate_id = ' . $reverseRequestId . '
      ORDER BY id ASC',
)->fetchAll(PDO::FETCH_ASSOC);
$reversePayload = json_decode((string) ($reverseRows[1]['payload_json'] ?? ''), true);
$reverseRelationCount = (int) $pdo->query(
    'SELECT COUNT(*) FROM im_friend_relation
      WHERE organization = 901
        AND ((user_id = "user_i" AND friend_user_id = "user_j")
          OR (user_id = "user_j" AND friend_user_id = "user_i"))
        AND status = 1 AND delete_time IS NULL',
)->fetchColumn();
$assert(
    $reversePending['status'] === 'pending'
    && $reverseAccepted['status'] === 'accepted'
    && $reverseAcceptedAgain['status'] === 'accepted'
    && count($reverseRows) === 2
    && $reverseRows[1]['event_type'] === 'friend_request.accepted'
    && $reverseRows[1]['target_user_id'] === 'user_i'
    && ($reversePayload['data']['actor_user_id'] ?? null) === 'user_j'
    && ($reversePayload['data']['target_user_id'] ?? null) === 'user_i'
    && $reverseRelationCount === 2,
    'Reverse pending auto-accept did not atomically persist one terminal outbox event.',
);
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
$group = $service->createGroup($alice, 'Project Group', ['user_b', 'user_c']);
$conversationId = (string) $group['conversation_id'];
$assert(
    array_keys($group) === [
        'organization', 'conversation_id', 'conversation_sort_id', 'conversation_type', 'title',
        'description', 'avatar_members', 'peer_user', 'last_message_id', 'last_message_seq',
        'last_message_index_id', 'last_message_summary', 'last_message_time', 'sort_time',
        'unread_count', 'is_pinned', 'is_muted', 'message_group_id', 'message_group_name',
        'access_version', 'access_state', 'periods',
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
$groupAccessFacts = static function (string $userId) use ($conversationId): array {
    return [
        'member' => Db::query(
            'SELECT status, access_version, access_state FROM im_conversation_member
              WHERE organization = 901 AND BINARY conversation_id = BINARY ?
                AND member_organization = 901 AND BINARY user_id = BINARY ? LIMIT 1',
            [$conversationId, $userId],
        )[0] ?? null,
        'periods' => Db::query(
            'SELECT period_no, visible_from_message_seq, visible_until_message_seq, status
               FROM im_conversation_membership_period
              WHERE organization = 901 AND BINARY conversation_id = BINARY ?
                AND member_organization = 901 AND BINARY user_id = BINARY ?
           ORDER BY period_no ASC',
            [$conversationId, $userId],
        ),
        'snapshot' => (string) Db::table('im_user_group_access_state')
            ->where('organization', 901)->where('user_id', $userId)->value('access_snapshot_id'),
        'audit' => (int) Db::query(
            'SELECT COUNT(*) AS aggregate FROM im_group_member_access_audit
              WHERE organization = 901 AND BINARY conversation_id = BINARY ?
                AND member_organization = 901 AND BINARY user_id = BINARY ?',
            [$conversationId, $userId],
        )[0]['aggregate'],
        'outbox' => (int) Db::query(
            'SELECT COUNT(*) AS aggregate FROM im_message_outbox
              WHERE organization = 901 AND BINARY conversation_id = BINARY ?
                AND event_type = "group.member_access_changed"
                AND payload_json LIKE ?',
            [$conversationId, '%"target_user_id":"' . $userId . '"%'],
        )[0]['aggregate'],
    ];
};

$pdo->prepare(
    'UPDATE im_conversation_member SET status = 99
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND member_organization = 901 AND BINARY user_id = BINARY "user_c"',
)->execute([$conversationId]);
$expectRuntime(
    static fn () => $service->conversations($carol),
    'Invalid group membership status was not rejected.',
);
$invalidStatusFacts = $groupAccessFacts('user_c');
$expectRuntime(
    static fn () => $service->removeGroupMember($alice, $conversationId, 'user_c', '1'),
    'Writer accepted an invalid group membership status.',
);
$assert(
    $groupAccessFacts('user_c') === $invalidStatusFacts,
    'Invalid membership status changed writer facts before rejection.',
);
$pdo->prepare(
    'UPDATE im_conversation_member SET status = 1
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND member_organization = 901 AND BINARY user_id = BINARY "user_c"',
)->execute([$conversationId]);
$pdo->prepare(
    'UPDATE im_conversation_membership_period SET visible_from_message_seq = 0
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND member_organization = 901 AND BINARY user_id = BINARY "user_c" AND period_no = 1',
)->execute([$conversationId]);
$expectRuntime(
    static fn () => $service->conversations($carol),
    'Zero group membership period start was not rejected.',
);
$zeroPeriodFacts = $groupAccessFacts('user_c');
$expectRuntime(
    static fn () => $service->removeGroupMember($alice, $conversationId, 'user_c', '1'),
    'Writer accepted a zero membership period start.',
);
$assert(
    $groupAccessFacts('user_c') === $zeroPeriodFacts,
    'Zero membership period changed writer facts before rejection.',
);
$pdo->prepare(
    'UPDATE im_conversation_membership_period SET visible_from_message_seq = 1,
        visible_until_message_seq = 0
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND member_organization = 901 AND BINARY user_id = BINARY "user_c" AND period_no = 1',
)->execute([$conversationId]);
$expectRuntime(
    static fn () => $service->conversations($carol),
    'Reverse group membership period interval was not rejected.',
);
$reversePeriodFacts = $groupAccessFacts('user_c');
$expectRuntime(
    static fn () => $service->removeGroupMember($alice, $conversationId, 'user_c', '1'),
    'Writer accepted a reverse membership period interval.',
);
$assert(
    $groupAccessFacts('user_c') === $reversePeriodFacts,
    'Reverse membership period changed writer facts before rejection.',
);
$pdo->prepare(
    'UPDATE im_conversation_membership_period SET visible_until_message_seq = NULL
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND member_organization = 901 AND BINARY user_id = BINARY "user_c" AND period_no = 1',
)->execute([$conversationId]);
$pdo->prepare(
    'INSERT INTO im_conversation_membership_period
        (organization, conversation_id, user_id, member_organization, period_no,
         visible_from_message_seq, visible_until_message_seq, join_at, leave_at,
         status, create_time, update_time)
     VALUES (901, ?, "user_c", 901, 2, 1, 1, ?, ?, 1, ?, ?)',
)->execute([$conversationId, $now, $now, $now, $now]);
$expectRuntime(
    static fn () => $service->conversations($carol),
    'Overlapping group membership periods were not rejected.',
);
$overlapPeriodFacts = $groupAccessFacts('user_c');
$expectRuntime(
    static fn () => $service->removeGroupMember($alice, $conversationId, 'user_c', '1'),
    'Writer accepted overlapping membership periods.',
);
$assert(
    $groupAccessFacts('user_c') === $overlapPeriodFacts,
    'Overlapping membership periods changed writer facts before rejection.',
);
$pdo->prepare(
    'DELETE FROM im_conversation_membership_period
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND member_organization = 901 AND BINARY user_id = BINARY "user_c" AND period_no = 2',
)->execute([$conversationId]);
$pdo->prepare(
    'INSERT INTO im_conversation_membership_period
        (organization, conversation_id, user_id, member_organization, period_no,
         visible_from_message_seq, visible_until_message_seq, join_at, leave_at,
         status, create_time, update_time)
     VALUES (901, ?, "user_c", 901, 2, 2, 2, ?, ?, 99, ?, ?)',
)->execute([$conversationId, $now, $now, $now, $now]);
$expectRuntime(
    static fn () => $service->conversations($carol),
    'An extra invalid-status membership period was ignored.',
);
$invalidPeriodStatusFacts = $groupAccessFacts('user_c');
$expectRuntime(
    static fn () => $service->removeGroupMember($alice, $conversationId, 'user_c', '1'),
    'Writer ignored an extra invalid-status membership period.',
);
$assert(
    $groupAccessFacts('user_c') === $invalidPeriodStatusFacts,
    'Invalid-status period changed writer facts before rejection.',
);
$pdo->prepare(
    'DELETE FROM im_conversation_membership_period
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND member_organization = 901 AND BINARY user_id = BINARY "user_c" AND period_no = 2',
)->execute([$conversationId]);
$pdo->prepare(
    'UPDATE im_conversation_member SET access_version = 0
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND member_organization = 901 AND BINARY user_id = BINARY "user_c"',
)->execute([$conversationId]);
$expectRuntime(
    static fn () => $service->conversations($carol),
    'An invalid group access version was accepted.',
);
$invalidAccessVersionFacts = $groupAccessFacts('user_c');
$expectRuntime(
    static fn () => $service->removeGroupMember($alice, $conversationId, 'user_c', '1'),
    'Writer accepted an invalid group access version.',
);
$assert(
    $groupAccessFacts('user_c') === $invalidAccessVersionFacts,
    'Invalid access version changed writer facts before rejection.',
);
$pdo->prepare(
    'UPDATE im_conversation_member SET access_version = 1
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND member_organization = 901 AND BINARY user_id = BINARY "user_c"',
)->execute([$conversationId]);

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
$expectApiCode(422, static fn () => $service->messages($alice, ' ', 0, '', 0, 0, 50));
$expectApiCode(422, static fn () => $service->groupMembers($alice, $conversationId . '|'));
$expectApiCode(403, static fn () => $service->addGroupMembers(
    $bob,
    $conversationId,
    ['user_d'],
    ['user_d' => '0'],
));
$expectApiCode(422, static fn () => $service->addGroupMembers(
    $alice,
    $conversationId,
    ['User_D'],
    ['User_D' => '0'],
));
$caseCollisionCount = (int) Db::query(
    'SELECT COUNT(*) AS aggregate FROM im_conversation_member
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND member_organization = 901 AND BINARY user_id = BINARY "User_D"',
    [$conversationId],
)[0]['aggregate'];
$assert($caseCollisionCount === 0, 'Case-colliding user identity created a membership row.');
$pdo->exec(
    'DELETE FROM im_user_group_access_state
      WHERE organization = 901 AND BINARY user_id = BINARY "user_d"',
);
$service->addGroupMembers($alice, $conversationId, ['user_d'], ['user_d' => '0']);
$danJoinFacts = [
    'snapshot' => (string) Db::table('im_user_group_access_state')
        ->where('organization', 901)->where('user_id', 'user_d')->value('access_snapshot_id'),
    'audit' => (int) Db::query(
        'SELECT COUNT(*) AS aggregate FROM im_group_member_access_audit
          WHERE organization = 901 AND BINARY conversation_id = BINARY ?
            AND member_organization = 901 AND BINARY user_id = BINARY "user_d"',
        [$conversationId],
    )[0]['aggregate'],
    'outbox' => (int) Db::query(
        'SELECT COUNT(*) AS aggregate FROM im_message_outbox
          WHERE organization = 901 AND BINARY conversation_id = BINARY ?
            AND event_type = "group.member_access_changed"
            AND payload_json LIKE ?',
        [$conversationId, '%"target_user_id":"user_d"%'],
    )[0]['aggregate'],
];
$service->addGroupMembers($alice, $conversationId, ['user_d'], ['user_d' => '0']);
$service->addGroupMembers($alice, $conversationId, ['user_d'], ['user_d' => '1']);
$pdo->exec(
    'UPDATE im_user SET status = 2
      WHERE organization = 901 AND BINARY user_id = BINARY "user_d"',
);
$service->addGroupMembers($alice, $conversationId, ['user_d'], ['user_d' => '0']);
$pdo->exec(
    'UPDATE im_user SET status = 1
      WHERE organization = 901 AND BINARY user_id = BINARY "user_d"',
);
$pdo->exec(
    'UPDATE im_friend_relation SET status = 2
      WHERE organization = 901 AND friend_organization = 901
        AND ((BINARY user_id = BINARY "user_a" AND BINARY friend_user_id = BINARY "user_d")
          OR (BINARY user_id = BINARY "user_d" AND BINARY friend_user_id = BINARY "user_a"))',
);
$service->addGroupMembers($alice, $conversationId, ['user_d'], ['user_d' => '0']);
$pdo->exec(
    'UPDATE im_friend_relation SET status = 1
      WHERE organization = 901 AND friend_organization = 901
        AND ((BINARY user_id = BINARY "user_a" AND BINARY friend_user_id = BINARY "user_d")
          OR (BINARY user_id = BINARY "user_d" AND BINARY friend_user_id = BINARY "user_a"))',
);
$expectApiCode(409, static fn () => $service->addGroupMembers(
    $alice,
    $conversationId,
    ['user_d', '123'],
    ['user_d' => '0', '123' => '0'],
));
$mixedBatchNumericCount = (int) Db::query(
    'SELECT COUNT(*) AS aggregate FROM im_conversation_member
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND member_organization = 901 AND BINARY user_id = BINARY "123"',
    [$conversationId],
)[0]['aggregate'];
$assert(
    $mixedBatchNumericCount === 0,
    'A mixed committed-retry/new-member batch partially changed membership facts.',
);
$assert(
    $danJoinFacts['snapshot'] === (string) Db::table('im_user_group_access_state')
        ->where('organization', 901)->where('user_id', 'user_d')->value('access_snapshot_id')
    && $danJoinFacts['audit'] === (int) Db::query(
        'SELECT COUNT(*) AS aggregate FROM im_group_member_access_audit
          WHERE organization = 901 AND BINARY conversation_id = BINARY ?
            AND member_organization = 901 AND BINARY user_id = BINARY "user_d"',
        [$conversationId],
    )[0]['aggregate']
    && $danJoinFacts['outbox'] === (int) Db::query(
        'SELECT COUNT(*) AS aggregate FROM im_message_outbox
          WHERE organization = 901 AND BINARY conversation_id = BINARY ?
            AND event_type = "group.member_access_changed"
            AND payload_json LIKE ?',
        [$conversationId, '%"target_user_id":"user_d"%'],
    )[0]['aggregate'],
    'Join retries changed snapshot, audit or outbox state.',
);
$danSnapshot = Db::query(
    'SELECT access_snapshot_id, create_time, update_time FROM im_user_group_access_state
      WHERE organization = 901 AND BINARY user_id = BINARY "user_d"',
)[0];
$pdo->exec(
    'DELETE FROM im_user_group_access_state
      WHERE organization = 901 AND BINARY user_id = BINARY "user_d"',
);
$missingSnapshotJoinFailedClosed = false;
try {
    $service->addGroupMembers($alice, $conversationId, ['user_d'], ['user_d' => '1']);
} catch (RuntimeException) {
    $missingSnapshotJoinFailedClosed = true;
}
$missingSnapshotFailedClosed = false;
try {
    $service->leaveGroup($dan, $conversationId, '1');
} catch (RuntimeException) {
    $missingSnapshotFailedClosed = true;
}
$danBeforeSnapshotRestore = Db::query(
    'SELECT status, access_version, access_state FROM im_conversation_member
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND member_organization = 901 AND BINARY user_id = BINARY "user_d"',
    [$conversationId],
)[0];
$assert(
    $missingSnapshotJoinFailedClosed
    && $missingSnapshotFailedClosed
    && (int) $danBeforeSnapshotRestore['status'] === 1
    && (string) $danBeforeSnapshotRestore['access_version'] === '1'
    && $danBeforeSnapshotRestore['access_state'] === 'active',
    'A non-join transition recreated a missing access snapshot or mutated membership before failing.',
);
$pdo->prepare(
    'INSERT INTO im_user_group_access_state
        (organization, user_id, access_snapshot_id, create_time, update_time)
     VALUES (901, "user_d", ?, ?, ?)',
)->execute([
    $danSnapshot['access_snapshot_id'],
    $danSnapshot['create_time'],
    $danSnapshot['update_time'],
]);
$danLeave = $service->leaveGroup($dan, $conversationId, '1');
$danLeaveRetry = $service->leaveGroup($dan, $conversationId, '1');
$assert(
    $danLeave === $danLeaveRetry
    && $danLeave['conversation_id'] === $conversationId
    && $danLeave['left'] === true
    && $danLeave['access_version'] === '2'
    && $danLeave['access_snapshot_id'] === (string) ((int) $danSnapshot['access_snapshot_id'] + 1)
    && $danLeave['access_state'] === 'revoked',
    'Leave retry did not return the committed access version and snapshot.',
);
$emptyPeriod = Db::query(
    'SELECT visible_from_message_seq, visible_until_message_seq, status
       FROM im_conversation_membership_period
      WHERE organization = 901 AND conversation_id = ? AND member_organization = 901
        AND user_id = "user_d" AND period_no = 1',
    [$conversationId],
)[0];
$emptyMember = Db::query(
    'SELECT status, access_version, access_state FROM im_conversation_member
      WHERE organization = 901 AND conversation_id = ? AND member_organization = 901
        AND user_id = "user_d"',
    [$conversationId],
)[0];
$assert(
    (int) $emptyPeriod['visible_from_message_seq'] === 1
    && $emptyPeriod['visible_until_message_seq'] === null
    && (int) $emptyPeriod['status'] === 2
    && (int) $emptyMember['status'] === 2
    && (string) $emptyMember['access_version'] === '2'
    && $emptyMember['access_state'] === 'revoked',
    'Leaving an empty since_join period created an invalid closed interval.',
);
$regularMemberIds = array_column(array_column(
    $service->groupMembers($carol, $conversationId),
    'user',
), 'user_id');
$managerMemberIds = array_column(array_column(
    $service->groupMembers($alice, $conversationId),
    'user',
), 'user_id');
$assert(
    !in_array('user_d', $regularMemberIds, true)
    && in_array('user_d', $managerMemberIds, true),
    'A regular group member could inspect historical or revoked members.',
);
$expectApiCode(403, static fn () => $service->messages($dan, $conversationId, 0, '', 0, 0, 50));
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
$pdo->prepare(
    'UPDATE im_group_profile SET history_visibility = "all", update_time = ?
      WHERE organization = 901 AND conversation_id = ?',
)->execute([$now, $conversationId]);
$service->addGroupMembers($alice, $conversationId, ['user_d'], ['user_d' => '2']);
$allReentry = Db::query(
    'SELECT period_no, visible_from_message_seq FROM im_conversation_membership_period
      WHERE organization = 901 AND conversation_id = ? AND member_organization = 901
        AND user_id = "user_d" ORDER BY period_no DESC LIMIT 1',
    [$conversationId],
)[0];
$assert(
    (int) $allReentry['period_no'] === 2 && (int) $allReentry['visible_from_message_seq'] === 2,
    'An all-history re-entry overlapped an earlier membership period.',
);
$service->suspendGroupMember($alice, $conversationId, 'user_d', '3');
$service->suspendGroupMember($alice, $conversationId, 'user_d', '3');
$suspendedDan = Db::query(
    'SELECT access_version, access_state FROM im_conversation_member
      WHERE organization = 901 AND conversation_id = ? AND member_organization = 901
        AND user_id = "user_d"',
    [$conversationId],
)[0];
$assert(
    (string) $suspendedDan['access_version'] === '4' && $suspendedDan['access_state'] === 'revoked',
    'Suspend did not revoke all group access or was not idempotent.',
);
$suspendedDanFacts = $groupAccessFacts('user_d');
$pdo->prepare(
    'UPDATE im_group_member_access_audit SET access_state = "history_only"
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND member_organization = 901 AND BINARY user_id = BINARY "user_d"
        AND access_version = 4',
)->execute([$conversationId]);
$expectRuntime(
    static fn () => $service->addGroupMembers(
        $alice,
        $conversationId,
        ['user_d'],
        ['user_d' => '4'],
    ),
    'Ordinary join trusted a suspended member audit that disagreed with locked facts.',
);
$pdo->prepare(
    'UPDATE im_group_member_access_audit SET access_state = "revoked"
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND member_organization = 901 AND BINARY user_id = BINARY "user_d"
        AND access_version = 4',
)->execute([$conversationId]);
$expectApiCode(409, static fn () => $service->addGroupMembers(
    $alice,
    $conversationId,
    ['user_d'],
    ['user_d' => '4'],
));
$assert(
    $groupAccessFacts('user_d') === $suspendedDanFacts,
    'Ordinary join bypassed the dedicated suspended-member restore transition.',
);
$service->restoreGroupMember($alice, $conversationId, 'user_d', '4');
$service->restoreGroupMember($alice, $conversationId, 'user_d', '4');
$restoredDanPeriod = Db::query(
    'SELECT period_no, visible_from_message_seq FROM im_conversation_membership_period
      WHERE organization = 901 AND conversation_id = ? AND member_organization = 901
        AND user_id = "user_d" AND status = 1 ORDER BY period_no DESC LIMIT 1',
    [$conversationId],
)[0];
$assert(
    (int) $restoredDanPeriod['period_no'] === 3
    && (int) $restoredDanPeriod['visible_from_message_seq'] === 2,
    'Restore did not create a new non-overlapping active period.',
);
$danSnapshotBeforeOverflow = (string) Db::query(
    'SELECT access_snapshot_id FROM im_user_group_access_state
      WHERE organization = 901 AND BINARY user_id = BINARY "user_d"',
)[0]['access_snapshot_id'];
$pdo->exec(
    'UPDATE im_user_group_access_state SET access_snapshot_id = 18446744073709551615
      WHERE organization = 901 AND BINARY user_id = BINARY "user_d"',
);
$expectApiCode(409, static fn () => $service->removeGroupMember(
    $alice,
    $conversationId,
    'user_d',
    '5',
));
$danAfterSnapshotOverflow = Db::query(
    'SELECT status, access_version, access_state FROM im_conversation_member
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND member_organization = 901 AND BINARY user_id = BINARY "user_d"',
    [$conversationId],
)[0];
$danOpenAfterSnapshotOverflow = (int) Db::query(
    'SELECT COUNT(*) AS aggregate FROM im_conversation_membership_period
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND member_organization = 901 AND BINARY user_id = BINARY "user_d"
        AND status = 1 AND visible_until_message_seq IS NULL',
    [$conversationId],
)[0]['aggregate'];
$danOverflowAuditCount = (int) Db::query(
    'SELECT COUNT(*) AS aggregate FROM im_group_member_access_audit
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND member_organization = 901 AND BINARY user_id = BINARY "user_d"
        AND access_version = 6',
    [$conversationId],
)[0]['aggregate'];
$danOverflowOutboxCount = (int) Db::query(
    'SELECT COUNT(*) AS aggregate FROM im_message_outbox
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND event_type = "group.member_access_changed"
        AND payload_json LIKE ?',
    [$conversationId, '%"target_user_id":"user_d"%"access_version":"6"%'],
)[0]['aggregate'];
$assert(
    (int) $danAfterSnapshotOverflow['status'] === 1
    && (string) $danAfterSnapshotOverflow['access_version'] === '5'
    && $danAfterSnapshotOverflow['access_state'] === 'active'
    && $danOpenAfterSnapshotOverflow === 1
    && $danOverflowAuditCount === 0
    && $danOverflowOutboxCount === 0,
    'Snapshot overflow did not roll back member and period mutations atomically.',
);
$pdo->prepare(
    'UPDATE im_user_group_access_state SET access_snapshot_id = ?
      WHERE organization = 901 AND BINARY user_id = BINARY "user_d"',
)->execute([$danSnapshotBeforeOverflow]);
$service->removeGroupMember($alice, $conversationId, 'user_d', '5');
$pdo->prepare(
    'UPDATE im_group_profile SET history_visibility = "since_join", update_time = ?
      WHERE organization = 901 AND conversation_id = ?',
)->execute([$now, $conversationId]);

$bobSnapshotBeforeRollback = (string) Db::table('im_user_group_access_state')
    ->where('organization', 901)
    ->where('user_id', 'user_b')
    ->value('access_snapshot_id');
$pdo->exec(
    'CREATE TRIGGER web_test_fail_group_access_audit BEFORE INSERT ON im_group_member_access_audit
     FOR EACH ROW SIGNAL SQLSTATE "45000" SET MESSAGE_TEXT = "injected audit failure"',
);
$rolledBack = false;
try {
    $service->leaveGroup($bob, $conversationId, '1');
} catch (Throwable) {
    $rolledBack = true;
} finally {
    $pdo->exec('DROP TRIGGER IF EXISTS web_test_fail_group_access_audit');
}
$bobAfterRollback = Db::query(
    'SELECT status, access_version, access_state FROM im_conversation_member
      WHERE organization = 901 AND conversation_id = ? AND member_organization = 901
        AND user_id = "user_b"',
    [$conversationId],
)[0];
$bobOpenPeriods = (int) Db::query(
    'SELECT COUNT(*) AS aggregate FROM im_conversation_membership_period
      WHERE organization = 901 AND conversation_id = ? AND member_organization = 901
        AND user_id = "user_b" AND status = 1 AND visible_until_message_seq IS NULL',
    [$conversationId],
)[0]['aggregate'];
$bobSnapshotAfterRollback = (string) Db::table('im_user_group_access_state')
    ->where('organization', 901)
    ->where('user_id', 'user_b')
    ->value('access_snapshot_id');
$assert(
    $rolledBack
    && (int) $bobAfterRollback['status'] === 1
    && (string) $bobAfterRollback['access_version'] === '1'
    && $bobAfterRollback['access_state'] === 'active'
    && $bobOpenPeriods === 1
    && $bobSnapshotAfterRollback === $bobSnapshotBeforeRollback,
    'Audit failure did not roll back member, period, version and user snapshot atomically.',
);

$bobSnapshotBeforeOutboxRollback = (string) Db::table('im_user_group_access_state')
    ->where('organization', 901)
    ->where('user_id', 'user_b')
    ->value('access_snapshot_id');
$pdo->exec(
    'CREATE TRIGGER web_test_fail_group_access_outbox BEFORE INSERT ON im_message_outbox
     FOR EACH ROW SIGNAL SQLSTATE "45000" SET MESSAGE_TEXT = "injected outbox failure"',
);
$outboxRolledBack = false;
try {
    $service->leaveGroup($bob, $conversationId, '1');
} catch (Throwable) {
    $outboxRolledBack = true;
} finally {
    $pdo->exec('DROP TRIGGER IF EXISTS web_test_fail_group_access_outbox');
}
$bobAfterOutboxRollback = Db::query(
    'SELECT status, access_version, access_state FROM im_conversation_member
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND member_organization = 901 AND BINARY user_id = BINARY "user_b"',
    [$conversationId],
)[0];
$bobOpenPeriodsAfterOutboxRollback = (int) Db::query(
    'SELECT COUNT(*) AS aggregate FROM im_conversation_membership_period
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND member_organization = 901 AND BINARY user_id = BINARY "user_b"
        AND status = 1 AND visible_until_message_seq IS NULL',
    [$conversationId],
)[0]['aggregate'];
$bobSnapshotAfterOutboxRollback = (string) Db::table('im_user_group_access_state')
    ->where('organization', 901)
    ->where('user_id', 'user_b')
    ->value('access_snapshot_id');
$bobAuditAfterOutboxRollback = (int) Db::query(
    'SELECT COUNT(*) AS aggregate FROM im_group_member_access_audit
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND member_organization = 901 AND BINARY user_id = BINARY "user_b"
        AND access_version = 2',
    [$conversationId],
)[0]['aggregate'];
$bobOutboxAfterOutboxRollback = (int) Db::query(
    'SELECT COUNT(*) AS aggregate FROM im_message_outbox
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND event_type = "group.member_access_changed"
        AND payload_json LIKE ?',
    [$conversationId, '%"target_user_id":"user_b"%"access_version":"2"%'],
)[0]['aggregate'];
$assert(
    $outboxRolledBack
    && (int) $bobAfterOutboxRollback['status'] === 1
    && (string) $bobAfterOutboxRollback['access_version'] === '1'
    && $bobAfterOutboxRollback['access_state'] === 'active'
    && $bobOpenPeriodsAfterOutboxRollback === 1
    && $bobSnapshotAfterOutboxRollback === $bobSnapshotBeforeOutboxRollback
    && $bobAuditAfterOutboxRollback === 0
    && $bobOutboxAfterOutboxRollback === 0,
    'Outbox failure did not roll back member, period, version, snapshot and audit atomically.',
);

$service->removeGroupMember($bob, $conversationId, 'user_c', '1');
$service->removeGroupMember($bob, $conversationId, 'user_c', '1');
$expectApiCode(409, static fn () => $service->removeGroupMember($alice, $conversationId, 'user_c', '1'));
$closedPeriod = $pdo->prepare(
    'SELECT visible_until_message_seq FROM im_conversation_membership_period
      WHERE organization = 901 AND conversation_id = ?
        AND member_organization = 901 AND user_id = "user_c" AND period_no = 1',
);
$closedPeriod->execute([$conversationId]);
$assert((int) $closedPeriod->fetchColumn() === 1, 'Removing a member did not close the visibility period.');
$removedMemberStatus = (int) Db::query(
    'SELECT status FROM im_conversation_member
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND member_organization = 901 AND BINARY user_id = BINARY "user_c"',
    [$conversationId],
)[0]['status'];
$assert($removedMemberStatus === 3, 'Removing a member did not persist membership status 3.');
$carolAccessAudit = Db::query(
    'SELECT event_id, access_snapshot_id, access_version, access_state, periods_json,
            reason, actor_organization, actor_user_id
       FROM im_group_member_access_audit
      WHERE organization = 901 AND conversation_id = ? AND member_organization = 901
        AND user_id = "user_c" AND access_version = 2 LIMIT 1',
    [$conversationId],
)[0] ?? null;
$carolAccessOutbox = Db::query(
    'SELECT event_id, routing_key, message_id, change_seq, payload_json
       FROM im_message_outbox
      WHERE organization = 901 AND conversation_id = ?
        AND event_type = "group.member_access_changed"
        AND payload_json LIKE ? LIMIT 1',
    [$conversationId, '%"target_user_id":"user_c"%"access_version":"2"%'],
)[0] ?? null;
$carolAccessPayload = json_decode((string) ($carolAccessOutbox['payload_json'] ?? ''), true);
$expectedCarolAccessEvent = hash('sha256', implode('|', [
    901,
    'group.member_access_changed',
    $conversationId,
    901,
    'user_c',
    (string) ($carolAccessAudit['access_snapshot_id'] ?? ''),
    '2',
]));
$expectedCarolAggregate = sha1(implode('|', [
    901,
    'user_c',
    $conversationId,
    '2',
    (string) ($carolAccessAudit['access_snapshot_id'] ?? ''),
]));
$assert(
    $carolAccessAudit !== null
    && $carolAccessOutbox !== null
    && $carolAccessAudit['event_id'] === $expectedCarolAccessEvent
    && $carolAccessOutbox['event_id'] === $expectedCarolAccessEvent
    && $carolAccessOutbox['routing_key'] === 'group.member_access_changed'
    && $carolAccessOutbox['message_id'] === $expectedCarolAggregate
    && (int) $carolAccessOutbox['change_seq'] === 0
    && (string) $carolAccessAudit['access_version'] === '2'
    && $carolAccessAudit['access_state'] === 'history_only'
    && $carolAccessAudit['reason'] === 'remove'
    && (int) $carolAccessAudit['actor_organization'] === 901
    && $carolAccessAudit['actor_user_id'] === 'user_b'
    && json_decode((string) $carolAccessAudit['periods_json'], true) === [[
        'period_no' => '1', 'from_seq' => '1', 'to_seq' => '1',
    ]]
    && ($carolAccessPayload['recipient_count'] ?? 0) === 1
    && ($carolAccessPayload['recipient_identities'] ?? []) === [[
        'organization' => 901, 'user_id' => 'user_c',
    ]]
    && ($carolAccessPayload['actor_user_id'] ?? '') === 'user_b',
    'Group access audit/outbox did not persist the exact immutable transition contract.',
);
$service->updateGroupProfile(
    $alice,
    $conversationId,
    'Post-leave private title',
    $groupAvatarFileId,
    'Post-leave private description',
    false,
);
$pdo->prepare(
    'UPDATE im_conversation_member SET conversation_remark = "Post-leave private remark"
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND member_organization = 901 AND BINARY user_id = BINARY "user_c"',
)->execute([$conversationId]);
$aliceNicknameBeforeHistoryRead = (string) Db::query(
    'SELECT nickname FROM im_user
      WHERE organization = 901 AND BINARY user_id = BINARY "user_a"',
)[0]['nickname'];
$pdo->exec(
    'UPDATE im_user SET nickname = "Post-leave private sender"
      WHERE organization = 901 AND BINARY user_id = BINARY "user_a"',
);
$removedCarolConversation = array_values(array_filter(
    $service->conversations($carol),
    static fn (array $conversation): bool => $conversation['conversation_id'] === $conversationId,
))[0];
$assert(
    $removedCarolConversation['access_version'] === '2'
    && $removedCarolConversation['access_state'] === 'history_only'
    && $removedCarolConversation['title'] === '群聊'
    && $removedCarolConversation['description'] === ''
    && $removedCarolConversation['avatar_members'] === []
    && $removedCarolConversation['avatar_file_id'] === ''
    && $removedCarolConversation['avatar_url'] === ''
    && $removedCarolConversation['avatar_expires_at'] === 0
    && $removedCarolConversation['periods'] === [[
        'period_no' => '1',
        'from_seq' => '1',
        'to_seq' => '1',
    ]],
    'History-only conversation projection is not bounded or leaked live member avatars.',
);
$removedPage = $service->messages($carol, $conversationId, 0, '', 0, 0, 50);
$removedSearch = $service->searchMessages($carol, $conversationId, 'visible before leave', 0, 50);
$assert(
    array_column($removedPage['messages'], 'message_seq') === [1]
    && ($removedPage['messages'][0]['sender_organization'] ?? 0) === 901
    && ($removedPage['messages'][0]['sender_id'] ?? '') === 'user_a'
    && array_key_exists('sender_user', $removedPage['messages'][0])
    && $removedPage['messages'][0]['sender_user'] === null
    && count($removedSearch) === 1
    && array_key_exists('sender_user', $removedSearch[0])
    && $removedSearch[0]['sender_user'] === null,
    'History-only message reads leaked current sender profile or lost valid history.',
);
$pdo->prepare(
    'UPDATE im_user SET nickname = ?
      WHERE organization = 901 AND BINARY user_id = BINARY "user_a"',
)->execute([$aliceNicknameBeforeHistoryRead]);
$expectApiCode(403, static fn () => $service->markRead($carol, $conversationId, false));
$expectApiCode(403, static fn () => $service->groupMembers($carol, $conversationId));
$insertMessage(2, 'hidden while absent');
$pdo->prepare(
    'UPDATE im_message_index SET message_seq = 0
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND BINARY message_id = BINARY "web-test-message-1"',
)->execute([$conversationId]);
$pdo->prepare(
    'UPDATE im_message_index SET message_seq = 1
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND BINARY message_id = BINARY "web-test-message-2"',
)->execute([$conversationId]);
$expectRuntime(
    static fn () => $service->messages($carol, $conversationId, 0, '', 0, 0, 50),
    'Forged message index sequence exposed an out-of-period shard body.',
);
$expectRuntime(
    static fn () => $service->conversations($carol),
    'Conversation preview trusted a forged message index sequence.',
);
$assert(
    $service->searchMessages($carol, $conversationId, 'hidden while absent', 0, 50) === [],
    'Message search trusted a forged index-to-shard sequence binding.',
);
$pdo->prepare(
    'UPDATE im_message_index SET message_seq = 2
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND BINARY message_id = BINARY "web-test-message-2"',
)->execute([$conversationId]);
$pdo->prepare(
    'UPDATE im_message_index SET message_seq = 1
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND BINARY message_id = BINARY "web-test-message-1"',
)->execute([$conversationId]);
$pdo->prepare(
    'UPDATE im_message_index SET client_msg_id = "forged-index-client"
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND BINARY message_id = BINARY "web-test-message-1"',
)->execute([$conversationId]);
$expectRuntime(
    static fn () => $service->messages($carol, $conversationId, 0, '', 0, 0, 50),
    'Message page trusted a forged client_msg_id binding.',
);
$expectRuntime(
    static fn () => $service->conversations($carol),
    'Conversation preview trusted a forged client_msg_id binding.',
);
$assert(
    $service->searchMessages($carol, $conversationId, 'visible before leave', 0, 50) === [],
    'Message search trusted a forged client_msg_id binding.',
);
$pdo->prepare(
    'UPDATE im_message_index SET client_msg_id = "web-test-client-1"
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND BINARY message_id = BINARY "web-test-message-1"',
)->execute([$conversationId]);

$pdo->prepare(
    'UPDATE im_message_index SET storage_node = "foreign-node"
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND BINARY message_id = BINARY "web-test-message-1"',
)->execute([$conversationId]);
$expectRuntime(
    static fn () => $service->messages($carol, $conversationId, 0, '', 0, 0, 50),
    'Message page accepted an unsupported storage node.',
);
$expectRuntime(
    static fn () => $service->conversations($carol),
    'Conversation preview accepted an unsupported storage node.',
);
$expectRuntime(
    static fn () => $service->searchMessages($carol, $conversationId, 'visible before leave', 0, 50),
    'Message search accepted an unsupported storage node.',
);
$pdo->prepare(
    'UPDATE im_message_index SET storage_node = "mysql-primary"
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND BINARY message_id = BINARY "web-test-message-1"',
)->execute([$conversationId]);

$pdo->prepare(
    'UPDATE ' . $messageTable . ' SET conversation_type = 1
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND BINARY message_id = BINARY "web-test-message-1"',
)->execute([$conversationId]);
$expectRuntime(
    static fn () => $service->messages($carol, $conversationId, 0, '', 0, 0, 50),
    'Message page trusted a forged conversation_type.',
);
$expectRuntime(
    static fn () => $service->conversations($carol),
    'Conversation preview trusted a forged conversation_type.',
);
$assert(
    $service->searchMessages($carol, $conversationId, 'visible before leave', 0, 50) === [],
    'Message search trusted a forged conversation_type.',
);
$pdo->prepare(
    'UPDATE ' . $messageTable . ' SET conversation_type = 2
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND BINARY message_id = BINARY "web-test-message-1"',
)->execute([$conversationId]);

$pdo->prepare(
    'UPDATE ' . $messageTable . ' SET message_type = 255
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND BINARY message_id = BINARY "web-test-message-1"',
)->execute([$conversationId]);
$expectRuntime(
    static fn () => $service->messages($carol, $conversationId, 0, '', 0, 0, 50),
    'Message page accepted a message type that is not enabled by the shared protocol.',
);
$expectRuntime(
    static fn () => $service->conversations($carol),
    'Conversation preview accepted a message type that is not enabled by the shared protocol.',
);
$assert(
    $service->searchMessages($carol, $conversationId, 'visible before leave', 0, 50) === [],
    'Message search accepted a message type that is not enabled by the shared protocol.',
);
$pdo->prepare(
    'UPDATE ' . $messageTable . ' SET message_type = 1
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND BINARY message_id = BINARY "web-test-message-1"',
)->execute([$conversationId]);

$alternateShard = 'im_message_0001_' . date('Ym');
$pdo->exec('CREATE TABLE ' . $alternateShard . ' LIKE ' . $messageTable);
$pdo->exec(
    'INSERT INTO ' . $alternateShard . '
     SELECT * FROM ' . $messageTable . '
      WHERE organization = 901 AND BINARY conversation_id = BINARY "' . $conversationId . '"
        AND BINARY message_id = BINARY "web-test-message-1"',
);
$pdo->prepare(
    'UPDATE im_message_index SET shard_table = ?
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND BINARY message_id = BINARY "web-test-message-1"',
)->execute([$alternateShard, $conversationId]);
$expectRuntime(
    static fn () => $service->messages($carol, $conversationId, 0, '', 0, 0, 50),
    'Message page trusted a forged shard route.',
);
$expectRuntime(
    static fn () => $service->conversations($carol),
    'Conversation preview trusted a forged shard route.',
);
$expectRuntime(
    static fn () => $service->searchMessages(
        $carol,
        $conversationId,
        'visible before leave',
        0,
        50,
    ),
    'Message search trusted a forged shard route.',
);
$pdo->prepare(
    'UPDATE im_message_index SET shard_table = ?
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND BINARY message_id = BINARY "web-test-message-1"',
)->execute([$messageTable, $conversationId]);

$wrongMonthShard = 'im_message_0000_202606';
$pdo->exec('CREATE TABLE ' . $wrongMonthShard . ' LIKE ' . $messageTable);
$pdo->exec(
    'INSERT INTO ' . $wrongMonthShard . '
     SELECT * FROM ' . $messageTable . '
      WHERE organization = 901 AND BINARY conversation_id = BINARY "' . $conversationId . '"
        AND BINARY message_id = BINARY "web-test-message-1"',
);
$pdo->prepare(
    'UPDATE im_message_index SET shard_table = ?
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND BINARY message_id = BINARY "web-test-message-1"',
)->execute([$wrongMonthShard, $conversationId]);
$expectRuntime(
    static fn () => $service->messages($carol, $conversationId, 0, '', 0, 0, 50),
    'Message page trusted a wrong-month shard containing a cloned body.',
);
$expectRuntime(
    static fn () => $service->conversations($carol),
    'Conversation preview trusted a wrong-month shard containing a cloned body.',
);
$expectRuntime(
    static fn () => $service->searchMessages(
        $carol,
        $conversationId,
        'visible before leave',
        0,
        50,
    ),
    'Message search trusted a wrong-month shard containing a cloned body.',
);
$pdo->prepare(
    'UPDATE im_message_index SET shard_table = ?
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND BINARY message_id = BINARY "web-test-message-1"',
)->execute([$messageTable, $conversationId]);

$pdo->prepare(
    'UPDATE im_message_index SET create_time = "2026-06-30 23:59:59"
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND BINARY message_id = BINARY "web-test-message-1"',
)->execute([$conversationId]);
$expectRuntime(
    static fn () => $service->messages($carol, $conversationId, 0, '', 0, 0, 50),
    'Message page trusted an index time inconsistent with its physical shard.',
);
$expectRuntime(
    static fn () => $service->conversations($carol),
    'Conversation preview trusted an index time inconsistent with its physical shard.',
);
$expectRuntime(
    static fn () => $service->searchMessages(
        $carol,
        $conversationId,
        'visible before leave',
        0,
        50,
    ),
    'Message search trusted an index time inconsistent with its physical shard.',
);
$pdo->prepare(
    'UPDATE im_message_index SET create_time = ?
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND BINARY message_id = BINARY "web-test-message-1"',
)->execute([$now, $conversationId]);

$pdo->prepare(
    'UPDATE ' . $messageTable . ' SET create_time = "2026-07-10 12:00:01"
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND BINARY message_id = BINARY "web-test-message-1"',
)->execute([$conversationId]);
$expectRuntime(
    static fn () => $service->messages($carol, $conversationId, 0, '', 0, 0, 50),
    'Message page trusted a shard body time that disagreed with the index.',
);
$expectRuntime(
    static fn () => $service->conversations($carol),
    'Conversation preview trusted a shard body time that disagreed with the index.',
);
$assert(
    $service->searchMessages($carol, $conversationId, 'visible before leave', 0, 50) === [],
    'Message search trusted a shard body time that disagreed with the index.',
);
$pdo->prepare(
    'UPDATE ' . $messageTable . ' SET create_time = ?
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND BINARY message_id = BINARY "web-test-message-1"',
)->execute([$now, $conversationId]);

$pdo->prepare(
    'UPDATE im_message_index SET sender_id = "forged-sender"
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND BINARY message_id = BINARY "web-test-message-1"',
)->execute([$conversationId]);
$expectRuntime(
    static fn () => $service->messages($carol, $conversationId, 0, '', 0, 0, 50),
    'Message page trusted a forged sender identity.',
);
$expectRuntime(
    static fn () => $service->conversations($carol),
    'Conversation preview trusted a forged sender identity.',
);
$assert(
    $service->searchMessages($carol, $conversationId, 'visible before leave', 0, 50) === [],
    'Message search trusted a forged sender identity.',
);
$pdo->prepare(
    'UPDATE im_message_index SET sender_id = "user_a"
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND BINARY message_id = BINARY "web-test-message-1"',
)->execute([$conversationId]);
$pdo->prepare(
    'UPDATE im_message_index SET sender_organization = 902
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND BINARY message_id = BINARY "web-test-message-1"',
)->execute([$conversationId]);
$expectRuntime(
    static fn () => $service->messages($carol, $conversationId, 0, '', 0, 0, 50),
    'Message page trusted a forged sender organization.',
);
$expectRuntime(
    static fn () => $service->conversations($carol),
    'Conversation preview trusted a forged sender organization.',
);
$assert(
    $service->searchMessages($carol, $conversationId, 'visible before leave', 0, 50) === [],
    'Message search trusted a forged sender organization.',
);
$pdo->prepare(
    'UPDATE im_message_index SET sender_organization = 901
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND BINARY message_id = BINARY "web-test-message-1"',
)->execute([$conversationId]);
$pdo->prepare(
    'UPDATE im_group_profile SET history_visibility = "all", update_time = ?
      WHERE organization = 901 AND conversation_id = ?',
)->execute([$now, $conversationId]);
$service->addGroupMembers($alice, $conversationId, ['user_c'], ['user_c' => '2']);
$pdo->prepare(
    'UPDATE im_group_profile SET history_visibility = "since_join", update_time = ?
      WHERE organization = 901 AND conversation_id = ?',
)->execute([$now, $conversationId]);
$expectApiCode(409, static fn () => $service->removeGroupMember(
    $bob,
    $conversationId,
    'user_c',
    '1',
));
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
    'Rejoining an all-history group overlapped an earlier effective period.',
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
        (901, ?, "web-test-message-3", "user_c", 901, 3, ?, ?, ?, ?),
        (901, ?, "web-test-message-3", "User_B", 901, 3, ?, ?, ?, ?)',
)->execute([
    $conversationId, $now, $now,
    $conversationId, $now, $now, $now,
    $conversationId, $now, $now,
    $conversationId, $now, $now, $now,
    $conversationId, $now, $now, $now, $now,
    $conversationId, $now, $now, $now, $now,
]);
$aliceMessagePage = $service->messages($alice, $conversationId, 0, '', 0, 0, 50);
$aliceMessage3 = array_values(array_filter(
    $aliceMessagePage['messages'],
    static fn (array $message): bool => $message['message_id'] === 'web-test-message-3',
))[0];
$assert(
    $aliceMessage3['delivery_status'] === 'delivered',
    'Outgoing delivery state ignored byte-exact receipt identities or the minimum high-water mark.',
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
    && $noticeCount === 1,
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
$sourceOrganizationPhysicalUsed = (int) $pdo->query(
    'SELECT COALESCE(SUM(path_size),0)
       FROM (
         SELECT storage_path,MAX(size_byte) AS path_size
           FROM im_upload_asset
          WHERE organization=901
       GROUP BY storage_path
       ) physical_paths',
)->fetchColumn();
$pdo->prepare(
    "UPDATE sm_tenant_quota
        SET used_value=?,version=version+1,update_time=NOW()
      WHERE organization=901 AND quota_key='storage_bytes'",
)->execute([$sourceOrganizationPhysicalUsed]);
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

$pdo->exec('INSERT INTO sm_system_config_group (id, code) VALUES (2, "social_config")');
$pdo->exec(
    'INSERT INTO sm_system_config (group_id, `key`, `value`) VALUES
        (2, "cross_org_social_enabled", "1"),
        (2, "cross_org_access_snapshot_id", "1")',
);
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
$targetPhysicalUsed = (int) $pdo->query(
    'SELECT COALESCE(SUM(path_size),0)
       FROM (
         SELECT storage_path,MAX(size_byte) AS path_size
           FROM im_upload_asset
          WHERE organization=902
       GROUP BY storage_path
       ) physical_paths',
)->fetchColumn();
$pdo->prepare(
    "UPDATE sm_tenant_quota
        SET used_value=?,version=version+1,update_time=NOW()
      WHERE organization=902 AND quota_key='storage_bytes'",
)->execute([$targetPhysicalUsed]);
$reverseSourceFileId = hash('sha1', 'web-cross-reverse-source-file');
$reverseSourceStoragePath = 'private/organizations/902/im/202607/'
    . substr(hash('sha256', 'web-cross-reverse-source-path'), 0, 32) . '.webp';
$reverseSourceUrl = 'https://cdn.example.test/' . $reverseSourceStoragePath;
$pdo->prepare(
    'INSERT INTO im_upload_asset
        (organization, file_id, user_id, kind, name, url, storage_path, size_byte,
         mime_type, extension, status, create_time, update_time)
     VALUES (902, ?, "outsider", "image", "reverse.webp", ?, ?, 3210,
             "image/webp", "webp", 1, ?, ?)',
)->execute([
    $reverseSourceFileId,
    $reverseSourceUrl,
    $reverseSourceStoragePath,
    $now,
    $now,
]);
$reverseAssetMessageId = 'web-cross-reverse-asset-message';
$reverseAssetContent = [
    'file_id' => $reverseSourceFileId,
    'url' => $reverseSourceUrl,
    'name' => 'reverse.webp',
    'size' => 3210,
    'mime_type' => 'image/webp',
    'extension' => 'webp',
];
foreach ([901, 902] as $homeOrganization) {
    $pdo->prepare(
        'INSERT INTO `' . $messageTable . '`
            (organization, conversation_id, conversation_type, message_id, message_seq,
             client_msg_id, sender_id, sender_organization, message_type, content, status,
             create_time, update_time)
         VALUES (?, ?, 1, ?, 2, "web-cross-reverse-asset-client", "outsider", 902,
                 2, ?, 1, ?, ?)',
    )->execute([
        $homeOrganization,
        $crossAssetConversationId,
        $reverseAssetMessageId,
        json_encode($reverseAssetContent, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        $now,
        $now,
    ]);
    $pdo->prepare(
        'INSERT INTO im_message_index
            (organization, global_seq, message_id, conversation_id, message_seq, sender_id,
             sender_organization, client_msg_id, storage_node, shard_table, create_time)
         VALUES (?, ?, ?, ?, 2, "outsider", 902, "web-cross-reverse-asset-client",
                 "mysql-primary", ?, ?)',
    )->execute([
        $homeOrganization,
        $homeOrganization === 901 ? 102 : 2,
        $reverseAssetMessageId,
        $crossAssetConversationId,
        $messageTable,
        $now,
    ]);
}
$targetPhysicalUsed = (int) $pdo->query(
    'SELECT COALESCE(SUM(path_size),0)
       FROM (
         SELECT storage_path,MAX(size_byte) AS path_size
           FROM im_upload_asset
          WHERE organization=902
       GROUP BY storage_path
       ) physical_paths',
)->fetchColumn();
$pdo->prepare(
    "UPDATE sm_tenant_quota
        SET used_value=?,version=version+1,update_time=NOW()
      WHERE organization=902 AND quota_key='storage_bytes'",
)->execute([$targetPhysicalUsed]);

if (function_exists('proc_open')) {
    $deriveRacePrefix = sys_get_temp_dir() . '/b8im-derive-race-' . bin2hex(random_bytes(8));
    $deriveRaceStart = $deriveRacePrefix . '-start';
    $deriveRaceJobs = [
        [902, 'outsider', $crossAssetMessageId, $sourceFileId],
        [901, 'user_a', $reverseAssetMessageId, $reverseSourceFileId],
    ];
    $deriveRaceWorkers = [];
    foreach ($deriveRaceJobs as $index => [$targetOrganization, $targetUserId, $sourceMessageId, $sourceAssetId]) {
        $readyPath = $deriveRacePrefix . "-{$index}-ready";
        $resultPath = $deriveRacePrefix . "-{$index}-result.json";
        $process = proc_open(
            [
                PHP_BINARY,
                dirname(__DIR__) . '/tests/support/web_im_asset_derive_worker.php',
                $database,
                (string) $targetOrganization,
                $targetUserId,
                $crossAssetConversationId,
                $sourceMessageId,
                $sourceAssetId,
                $readyPath,
                $deriveRaceStart,
                $resultPath,
            ],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start reverse derive worker.');
        }
        $deriveRaceWorkers[] = [$process, $pipes, $readyPath, $resultPath];
    }
    $allDeriveWorkersReady = false;
    $deriveRaceDeadline = microtime(true) + 10;
    while (microtime(true) < $deriveRaceDeadline) {
        $allDeriveWorkersReady = true;
        foreach ($deriveRaceWorkers as [, , $readyPath]) {
            if (!is_file($readyPath)) {
                $allDeriveWorkersReady = false;
                break;
            }
        }
        if ($allDeriveWorkersReady) {
            break;
        }
        usleep(1000);
    }
    touch($deriveRaceStart);
    $deriveRaceResults = [];
    foreach ($deriveRaceWorkers as [$process, $pipes, $readyPath, $resultPath]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $payload = is_file($resultPath)
            ? json_decode((string) file_get_contents($resultPath), true, 512, JSON_THROW_ON_ERROR)
            : ['error' => 'missing result'];
        $deriveRaceResults[] = [
            'exit' => $exit,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'payload' => $payload,
        ];
        @unlink($readyPath);
        @unlink($resultPath);
    }
    @unlink($deriveRaceStart);
    $reverseDeriveRows = (int) $pdo->query(
        'SELECT COUNT(*) FROM im_upload_asset
          WHERE (organization=902 AND user_id="outsider" AND storage_path='
        . $pdo->quote($sourceStoragePath) . ')
             OR (organization=901 AND user_id="user_a" AND storage_path='
        . $pdo->quote($reverseSourceStoragePath) . ')',
    )->fetchColumn();
    $deriveRacePassed = $allDeriveWorkersReady && $reverseDeriveRows >= 2;
    foreach ($deriveRaceResults as $deriveRaceResult) {
        $deriveRacePassed = $deriveRacePassed
            && $deriveRaceResult['exit'] === 0
            && ($deriveRaceResult['payload']['status'] ?? '') === 'derived'
            && preg_match(
                '/^[a-f0-9]{40}$/',
                (string) ($deriveRaceResult['payload']['asset']['file_id'] ?? ''),
            ) === 1;
    }
    $assert(
        $deriveRacePassed,
        'Reverse cross-organization derives deadlocked or lost an asset: '
        . json_encode($deriveRaceResults, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    );
} else {
    echo "SKIP proc_open reverse derive lock-order regression\n";
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
$hiddenPhysicalUsed = (int) $pdo->query(
    'SELECT COALESCE(SUM(path_size),0)
       FROM (
         SELECT storage_path,MAX(size_byte) AS path_size
           FROM im_upload_asset
          WHERE organization=901
       GROUP BY storage_path
       ) physical_paths',
)->fetchColumn();
$pdo->prepare(
    "UPDATE sm_tenant_quota
        SET used_value=?,version=version+1,update_time=NOW()
      WHERE organization=901 AND quota_key='storage_bytes'",
)->execute([$hiddenPhysicalUsed]);
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
$crossOrgUrl = $assetUrlService->resolve(
    $outsider,
    (string) $crossDerived['file_id'],
);
$assert(
    $crossOrgUrl['expires_at'] === $urlNow + 300
    && str_contains($crossOrgUrl['url'], 'X-Amz-Signature=')
    && str_contains($crossOrgUrl['url'], 'private/organizations/901/'),
    'Cross-org derived attachment did not sign its canonical source organization path.',
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
$pdo->exec(
    'INSERT INTO ' . $alternateShard . '
     SELECT * FROM ' . $messageTable . '
      WHERE organization = 901 AND BINARY conversation_id = BINARY "' . $conversationId . '"
        AND BINARY message_id = BINARY "' . $assetMessageId . '"',
);
$pdo->prepare(
    'UPDATE im_message_index SET shard_table = ?
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND BINARY message_id = BINARY ?',
)->execute([$alternateShard, $conversationId, $assetMessageId]);
$expectRuntime(
    static fn () => $assetUrlService->resolve(
        $bob,
        $sourceFileId,
        $conversationId,
        $assetMessageId,
    ),
    'Attachment authorization trusted a wrong-bucket shard containing a cloned body.',
);
$pdo->prepare(
    'UPDATE im_message_index SET shard_table = ?
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND BINARY message_id = BINARY ?',
)->execute([$messageTable, $conversationId, $assetMessageId]);
$pdo->prepare(
    'UPDATE ' . $messageTable . ' SET create_time = "2026-07-10 12:00:01"
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND BINARY message_id = BINARY ?',
)->execute([$conversationId, $assetMessageId]);
$expectRuntime(
    static fn () => $assetUrlService->resolve(
        $bob,
        $sourceFileId,
        $conversationId,
        $assetMessageId,
    ),
    'Attachment authorization trusted a shard body time that disagreed with the index.',
);
$pdo->prepare(
    'UPDATE ' . $messageTable . ' SET create_time = ?
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND BINARY message_id = BINARY ?',
)->execute([$now, $conversationId, $assetMessageId]);
$pdo->prepare(
    'UPDATE im_conversation
        SET next_message_seq = 101, last_message_seq = 100, last_message_id = ?, update_time = ?
      WHERE organization = 901 AND conversation_id = ?',
)->execute([$assetMessageId, $now, $conversationId]);
$service->removeGroupMember($bob, $conversationId, 'user_c', '3');
$historyOnlyUrl = $assetUrlService->resolve(
    $carol,
    $sourceFileId,
    $conversationId,
    $assetMessageId,
);
$assert(
    $historyOnlyUrl['expires_at'] === $urlNow + 300
    && str_contains($historyOnlyUrl['url'], 'X-Amz-Signature='),
    'History-only member could not resolve an attachment inside a valid closed period.',
);
$pdo->prepare(
    'UPDATE `' . $messageTable . '` SET message_seq = 101
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND BINARY message_id = BINARY ?',
)->execute([$conversationId, $assetMessageId]);
$expectRuntime(
    static fn () => $assetUrlService->resolve(
        $carol,
        $sourceFileId,
        $conversationId,
        $assetMessageId,
    ),
    'Attachment authorization trusted a forged index-to-shard sequence binding.',
);
$pdo->prepare(
    'UPDATE `' . $messageTable . '` SET message_seq = 100
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND BINARY message_id = BINARY ?',
)->execute([$conversationId, $assetMessageId]);
$expectApiCode(403, static fn () => $forwardService->derive(
    $carol,
    $conversationId,
    $assetMessageId,
    $sourceFileId,
    'video',
));
$expectApiCode(403, static fn () => $service->markRead($carol, $conversationId, false));
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

$service->removeGroupMember($alice, $conversationId, 'user_b', '1');
$expectApiCode(409, static fn () => $service->revokeGroupMemberHistory(
    $alice,
    $conversationId,
    'user_b',
    '18446744073709551615',
    ['1'],
));
$service->revokeGroupMemberHistory($alice, $conversationId, 'user_b', '2', ['1']);
$service->revokeGroupMemberHistory($alice, $conversationId, 'user_b', '2', ['1']);
$expectApiCode(409, static fn () => $service->revokeGroupMemberHistory(
    $alice,
    $conversationId,
    'user_b',
    '2',
    ['2'],
));
$bobRevoked = Db::query(
    'SELECT access_version, access_state FROM im_conversation_member
      WHERE organization = 901 AND conversation_id = ? AND member_organization = 901
        AND user_id = "user_b"',
    [$conversationId],
)[0];
$bobHistoryEvents = (int) Db::query(
    'SELECT COUNT(*) AS aggregate FROM im_group_member_access_audit
      WHERE organization = 901 AND conversation_id = ? AND member_organization = 901
        AND user_id = "user_b" AND reason = "history_revoke"',
    [$conversationId],
)[0]['aggregate'];
$assert(
    (string) $bobRevoked['access_version'] === '3'
    && $bobRevoked['access_state'] === 'revoked'
    && $bobHistoryEvents === 1,
    'History revoke did not revoke the selected closed period idempotently.',
);
$expectApiCode(403, static fn () => $service->messages($bob, $conversationId, 0, '', 0, 0, 50));

$insertFriend->execute(['user_a', '123', $now, $now, $now]);
$insertFriend->execute(['123', 'user_a', $now, $now, $now]);
$numericGroup = $service->createGroup($alice, 'Numeric Identity Group', ['user_b', 'user_c']);
$numericConversationId = (string) $numericGroup['conversation_id'];
$numericMembers = $service->addGroupMembers(
    $alice,
    $numericConversationId,
    ['123'],
    ['123' => '0'],
);
$numericMember = array_values(array_filter(
    $numericMembers,
    static fn (array $member): bool => ($member['user']['user_id'] ?? null) === '123',
))[0] ?? null;
$assert(
    is_array($numericMember)
    && ($numericMember['access_version'] ?? null) === '1'
    && ($numericMember['access_state'] ?? null) === 'active',
    'A canonical numeric user ID could not complete a group join transition.',
);
$numericUser = ['organization' => 901, 'user_id' => '123'];
$pdo->prepare(
    'UPDATE im_conversation_membership_period SET visible_from_message_seq = 2
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND member_organization = 901 AND BINARY user_id = BINARY "123" AND period_no = 1',
)->execute([$numericConversationId]);
$expectRuntime(
    static fn () => $service->conversations($numericUser),
    'Read guard accepted an open period beyond last_message_seq + 1.',
);
$expectRuntime(
    static fn () => $service->removeGroupMember(
        $alice,
        $numericConversationId,
        '123',
        '1',
    ),
    'Writer accepted an open period beyond last_message_seq + 1.',
);
$pdo->prepare(
    'UPDATE im_conversation_membership_period SET visible_from_message_seq = 1
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND member_organization = 901 AND BINARY user_id = BINARY "123" AND period_no = 1',
)->execute([$numericConversationId]);

$pdo->prepare(
    'UPDATE im_conversation SET next_message_seq = 51, last_message_seq = 50
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?',
)->execute([$numericConversationId]);
$pdo->prepare(
    'UPDATE im_conversation_member SET status = 3, access_state = "history_only"
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND member_organization = 901 AND BINARY user_id = BINARY "123"',
)->execute([$numericConversationId]);
$pdo->prepare(
    'UPDATE im_conversation_membership_period
        SET visible_from_message_seq = 1, visible_until_message_seq = 100
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND member_organization = 901 AND BINARY user_id = BINARY "123" AND period_no = 1',
)->execute([$numericConversationId]);
$expectRuntime(
    static fn () => $service->messages(
        $numericUser,
        $numericConversationId,
        0,
        '',
        0,
        0,
        50,
    ),
    'A closed period above last_message_seq authorized future messages.',
);
$expectRuntime(
    static fn () => $service->addGroupMembers(
        $alice,
        $numericConversationId,
        ['123'],
        ['123' => '1'],
    ),
    'Writer accepted a closed period above the locked message watermark.',
);

$pdo->prepare(
    'UPDATE im_conversation SET next_message_seq = 201, last_message_seq = 200
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?',
)->execute([$numericConversationId]);
$pdo->prepare(
    'UPDATE im_conversation_membership_period
        SET visible_from_message_seq = 100, visible_until_message_seq = 150
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND member_organization = 901 AND BINARY user_id = BINARY "123" AND period_no = 1',
)->execute([$numericConversationId]);
$pdo->prepare(
    'INSERT INTO im_conversation_membership_period
        (organization, conversation_id, user_id, member_organization, period_no,
         visible_from_message_seq, visible_until_message_seq, join_at, leave_at,
         status, create_time, update_time)
     VALUES (901, ?, "123", 901, 2, 1, 50, ?, ?, 1, ?, ?)',
)->execute([$numericConversationId, $now, $now, $now, $now]);
$expectRuntime(
    static fn () => $service->conversations($numericUser),
    'Read guard accepted non-overlapping periods in reverse period_no time order.',
);
$expectRuntime(
    static fn () => $service->addGroupMembers(
        $alice,
        $numericConversationId,
        ['123'],
        ['123' => '1'],
    ),
    'Writer accepted non-overlapping periods in reverse period_no time order.',
);
$pdo->prepare(
    'DELETE FROM im_conversation_membership_period
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND member_organization = 901 AND BINARY user_id = BINARY "123" AND period_no = 2',
)->execute([$numericConversationId]);
$pdo->prepare(
    'UPDATE im_conversation_membership_period
        SET visible_from_message_seq = 1, visible_until_message_seq = NULL
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND member_organization = 901 AND BINARY user_id = BINARY "123" AND period_no = 1',
)->execute([$numericConversationId]);
$pdo->prepare(
    'UPDATE im_conversation_member SET status = 1, access_state = "active"
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?
        AND member_organization = 901 AND BINARY user_id = BINARY "123"',
)->execute([$numericConversationId]);
$pdo->prepare(
    'UPDATE im_conversation SET next_message_seq = 1, last_message_seq = 0
      WHERE organization = 901 AND BINARY conversation_id = BINARY ?',
)->execute([$numericConversationId]);

if (function_exists('proc_open')) {
    $insertFriend->execute(['user_a', 'user_e', $now, $now, $now]);
    $insertFriend->execute(['user_e', 'user_a', $now, $now, $now]);
    $concurrentGroup = $service->createGroup(
        $alice,
        'Concurrent Join Group',
        ['user_b', 'user_c'],
    );
    $concurrentConversationId = (string) $concurrentGroup['conversation_id'];
    $pdo->exec(
        'DELETE FROM im_user_group_access_state
          WHERE organization = 901 AND BINARY user_id = BINARY "user_e"',
    );
    $concurrentPrefix = sys_get_temp_dir() . '/b8im-group-join-' . bin2hex(random_bytes(8));
    $concurrentBarrier = $concurrentPrefix . '-start';
    $concurrentWorkerCode = <<<'PHP'
$root = $argv[1];
$database = $argv[2];
$conversationId = $argv[3];
$barrier = $argv[4];
$resultPath = $argv[5];
$now = $argv[6];
$memberIds = json_decode($argv[7], true, 512, JSON_THROW_ON_ERROR);
if (!is_array($memberIds) || !array_is_list($memberIds) || $memberIds === []) {
    throw new RuntimeException('Concurrent join worker member list is invalid.');
}
$expectedVersions = array_fill_keys($memberIds, '0');
foreach (['DB_NAME' => $database, 'IM_MESSAGE_SHARD_BUCKETS' => '1'] as $key => $value) {
    putenv($key . '=' . $value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}
require $root . '/vendor/autoload.php';
require $root . '/support/bootstrap.php';
$config = config('think-orm');
$connectionName = (string) ($config['default'] ?? 'mysql');
$config['connections'][$connectionName]['database'] = $database;
support\think\Db::setConfig($config);
$deadline = microtime(true) + 10;
while (!is_file($barrier)) {
    if (microtime(true) >= $deadline) {
        file_put_contents($resultPath, json_encode(['error' => 'barrier timeout'], JSON_THROW_ON_ERROR));
        exit(1);
    }
    usleep(1000);
}
try {
    (new plugin\saimulti\service\web\GroupMemberAccessService())->join(
        901,
        'user_a',
        $conversationId,
        $memberIds,
        $expectedVersions,
        $now,
    );
    file_put_contents($resultPath, json_encode(['status' => 'ok'], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    file_put_contents($resultPath, json_encode([
        'error' => get_class($exception) . ': ' . $exception->getMessage(),
        'driver_code' => $exception instanceof PDOException
            ? (int) ($exception->errorInfo[1] ?? 0)
            : 0,
    ], JSON_THROW_ON_ERROR));
    exit(1);
}
PHP;
    $concurrentWorkers = [];
    for ($workerIndex = 0; $workerIndex < 2; ++$workerIndex) {
        $resultPath = $concurrentPrefix . '-result-' . $workerIndex . '.json';
        $pipes = [];
        $process = proc_open(
            [
                PHP_BINARY,
                '-r',
                $concurrentWorkerCode,
                dirname(__DIR__),
                $database,
                $concurrentConversationId,
                $concurrentBarrier,
                $resultPath,
                $now,
                json_encode(['user_e'], JSON_THROW_ON_ERROR),
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start a concurrent group join worker.');
        }
        $concurrentWorkers[] = [
            'process' => $process,
            'pipes' => $pipes,
            'result_path' => $resultPath,
        ];
    }
    file_put_contents($concurrentBarrier, 'start');
    $concurrentResults = [];
    foreach ($concurrentWorkers as $worker) {
        $stdout = stream_get_contents($worker['pipes'][1]);
        $stderr = stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        $exitCode = proc_close($worker['process']);
        $result = is_file($worker['result_path'])
            ? json_decode((string) file_get_contents($worker['result_path']), true)
            : null;
        $concurrentResults[] = [
            'exit_code' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'result' => $result,
        ];
        @unlink($worker['result_path']);
    }
    @unlink($concurrentBarrier);

    $memberFacts = Db::query(
        'SELECT COUNT(*) AS member_count, MIN(access_version) AS access_version
           FROM im_conversation_member
          WHERE organization = 901 AND BINARY conversation_id = BINARY ?
            AND member_organization = 901 AND BINARY user_id = BINARY "user_e"',
        [$concurrentConversationId],
    )[0];
    $openPeriods = (int) Db::query(
        'SELECT COUNT(*) AS aggregate FROM im_conversation_membership_period
          WHERE organization = 901 AND BINARY conversation_id = BINARY ?
            AND member_organization = 901 AND BINARY user_id = BINARY "user_e"
            AND status = 1 AND visible_until_message_seq IS NULL',
        [$concurrentConversationId],
    )[0]['aggregate'];
    $snapshot = (string) Db::query(
        'SELECT access_snapshot_id FROM im_user_group_access_state
          WHERE organization = 901 AND BINARY user_id = BINARY "user_e"',
    )[0]['access_snapshot_id'];
    $auditCount = (int) Db::query(
        'SELECT COUNT(*) AS aggregate FROM im_group_member_access_audit
          WHERE organization = 901 AND BINARY conversation_id = BINARY ?
            AND member_organization = 901 AND BINARY user_id = BINARY "user_e"',
        [$concurrentConversationId],
    )[0]['aggregate'];
    $outboxCount = (int) Db::query(
        'SELECT COUNT(*) AS aggregate FROM im_message_outbox
          WHERE organization = 901 AND BINARY conversation_id = BINARY ?
            AND event_type = "group.member_access_changed" AND payload_json LIKE ?',
        [$concurrentConversationId, '%"target_user_id":"user_e"%'],
    )[0]['aggregate'];
    $assert(
        array_reduce(
            $concurrentResults,
            static fn (bool $ok, array $result): bool => $ok
                && $result['exit_code'] === 0
                && ($result['result']['status'] ?? null) === 'ok',
            true,
        )
        && (int) $memberFacts['member_count'] === 1
        && (string) $memberFacts['access_version'] === '1'
        && $openPeriods === 1
        && $snapshot === '2'
        && $auditCount === 1
        && $outboxCount === 1,
        'Concurrent first joins deadlocked, duplicated an open period, or replayed version/audit/outbox facts: '
            . json_encode($concurrentResults, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    );

    $runConcurrentJoinJobs = static function (array $jobs, string $prefix) use (
        $concurrentWorkerCode,
        $database,
        $now,
    ): array {
        $barrier = $prefix . '-start';
        $workers = [];
        foreach ($jobs as $workerIndex => $job) {
            $resultPath = $prefix . '-result-' . $workerIndex . '.json';
            $pipes = [];
            $process = proc_open(
                [
                    PHP_BINARY,
                    '-r',
                    $concurrentWorkerCode,
                    dirname(__DIR__),
                    $database,
                    $job['conversation_id'],
                    $barrier,
                    $resultPath,
                    $now,
                    json_encode($job['member_ids'], JSON_THROW_ON_ERROR),
                ],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            if (!is_resource($process)) {
                throw new RuntimeException('Unable to start a cross-group join worker.');
            }
            $workers[] = [
                'process' => $process,
                'pipes' => $pipes,
                'result_path' => $resultPath,
            ];
        }
        file_put_contents($barrier, 'start');
        $results = [];
        foreach ($workers as $worker) {
            $stdout = stream_get_contents($worker['pipes'][1]);
            $stderr = stream_get_contents($worker['pipes'][2]);
            fclose($worker['pipes'][1]);
            fclose($worker['pipes'][2]);
            $exitCode = proc_close($worker['process']);
            $result = is_file($worker['result_path'])
                ? json_decode((string) file_get_contents($worker['result_path']), true)
                : null;
            $results[] = [
                'exit_code' => $exitCode,
                'stdout' => $stdout,
                'stderr' => $stderr,
                'result' => $result,
            ];
            @unlink($worker['result_path']);
        }
        @unlink($barrier);
        return $results;
    };
    $allWorkersSucceeded = static fn (array $results): bool => array_reduce(
        $results,
        static fn (bool $ok, array $result): bool => $ok
            && $result['exit_code'] === 0
            && ($result['result']['status'] ?? null) === 'ok',
        true,
    );

    $insertFriend->execute(['user_a', 'user_f', $now, $now, $now]);
    $insertFriend->execute(['user_f', 'user_a', $now, $now, $now]);
    $epochGroupLeft = (string) $service->createGroup(
        $alice,
        'Epoch Left Group',
        ['user_b', 'user_c'],
    )['conversation_id'];
    $epochGroupRight = (string) $service->createGroup(
        $alice,
        'Epoch Right Group',
        ['user_b', 'user_c'],
    )['conversation_id'];
    $pdo->exec(
        'DELETE FROM im_user_group_access_state
          WHERE organization = 901 AND BINARY user_id = BINARY "user_f"',
    );
    $epochResults = $runConcurrentJoinJobs([
        ['conversation_id' => $epochGroupLeft, 'member_ids' => ['user_f']],
        ['conversation_id' => $epochGroupRight, 'member_ids' => ['user_f']],
    ], sys_get_temp_dir() . '/b8im-group-epoch-' . bin2hex(random_bytes(8)));
    $epochSnapshot = (string) Db::query(
        'SELECT access_snapshot_id FROM im_user_group_access_state
          WHERE organization = 901 AND BINARY user_id = BINARY "user_f"',
    )[0]['access_snapshot_id'];
    $epochMemberCount = (int) Db::query(
        'SELECT COUNT(*) AS aggregate FROM im_conversation_member
          WHERE organization = 901 AND conversation_id IN (?, ?)
            AND member_organization = 901 AND BINARY user_id = BINARY "user_f"',
        [$epochGroupLeft, $epochGroupRight],
    )[0]['aggregate'];
    $epochPeriodCount = (int) Db::query(
        'SELECT COUNT(*) AS aggregate FROM im_conversation_membership_period
          WHERE organization = 901 AND conversation_id IN (?, ?)
            AND member_organization = 901 AND BINARY user_id = BINARY "user_f"
            AND status = 1 AND visible_until_message_seq IS NULL',
        [$epochGroupLeft, $epochGroupRight],
    )[0]['aggregate'];
    $epochAuditCount = (int) Db::query(
        'SELECT COUNT(*) AS aggregate FROM im_group_member_access_audit
          WHERE organization = 901 AND conversation_id IN (?, ?)
            AND member_organization = 901 AND BINARY user_id = BINARY "user_f"',
        [$epochGroupLeft, $epochGroupRight],
    )[0]['aggregate'];
    $assert(
        $allWorkersSucceeded($epochResults)
        && $epochSnapshot === '3'
        && $epochMemberCount === 2
        && $epochPeriodCount === 2
        && $epochAuditCount === 2,
        'Concurrent joins in different groups lost the shared user epoch or membership facts: '
            . json_encode($epochResults, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    );

    foreach (['user_g', 'user_h'] as $targetUserId) {
        $insertFriend->execute(['user_a', $targetUserId, $now, $now, $now]);
        $insertFriend->execute([$targetUserId, 'user_a', $now, $now, $now]);
    }
    $reverseGroupLeft = (string) $service->createGroup(
        $alice,
        'Reverse Lock Left Group',
        ['user_b', 'user_c'],
    )['conversation_id'];
    $reverseGroupRight = (string) $service->createGroup(
        $alice,
        'Reverse Lock Right Group',
        ['user_b', 'user_c'],
    )['conversation_id'];
    $pdo->exec(
        'DELETE FROM im_user_group_access_state
          WHERE organization = 901 AND BINARY user_id IN (BINARY "user_g", BINARY "user_h")',
    );
    $reverseResults = $runConcurrentJoinJobs([
        ['conversation_id' => $reverseGroupLeft, 'member_ids' => ['user_g', 'user_h']],
        ['conversation_id' => $reverseGroupRight, 'member_ids' => ['user_h', 'user_g']],
    ], sys_get_temp_dir() . '/b8im-group-reverse-' . bin2hex(random_bytes(8)));
    $reverseSnapshots = Db::query(
        'SELECT user_id, access_snapshot_id FROM im_user_group_access_state
          WHERE organization = 901 AND BINARY user_id IN (BINARY "user_g", BINARY "user_h")
       ORDER BY user_id COLLATE utf8mb4_bin ASC',
    );
    $reverseMemberCount = (int) Db::query(
        'SELECT COUNT(*) AS aggregate FROM im_conversation_member
          WHERE organization = 901 AND conversation_id IN (?, ?)
            AND member_organization = 901
            AND BINARY user_id IN (BINARY "user_g", BINARY "user_h")',
        [$reverseGroupLeft, $reverseGroupRight],
    )[0]['aggregate'];
    $reverseOpenCount = (int) Db::query(
        'SELECT COUNT(*) AS aggregate FROM im_conversation_membership_period
          WHERE organization = 901 AND conversation_id IN (?, ?)
            AND member_organization = 901
            AND BINARY user_id IN (BINARY "user_g", BINARY "user_h")
            AND status = 1 AND visible_until_message_seq IS NULL',
        [$reverseGroupLeft, $reverseGroupRight],
    )[0]['aggregate'];
    $assert(
        $allWorkersSucceeded($reverseResults)
        && array_column($reverseSnapshots, 'user_id') === ['user_g', 'user_h']
        && array_map(
            static fn (array $row): string => (string) $row['access_snapshot_id'],
            $reverseSnapshots,
        ) === ['3', '3']
        && $reverseMemberCount === 4
        && $reverseOpenCount === 4,
        'Reverse caller target order deadlocked or lost canonical user snapshot increments: '
            . json_encode($reverseResults, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    );
}

$columns = $pdo->query(
    'SELECT COLUMN_NAME FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "im_conversation_member"',
)->fetchAll(PDO::FETCH_COLUMN);
$assert(
    in_array('member_role', $columns, true)
    && in_array('mute_status', $columns, true)
    && in_array('join_at', $columns, true)
    && in_array('conversation_remark', $columns, true)
    && in_array('access_state', $columns, true)
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
