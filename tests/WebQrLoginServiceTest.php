<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\web\WebAccessIssuerInterface;
use plugin\saimulti\service\web\WebQrLoginService;
use plugin\saimulti\service\web\WebQrLoginStoreInterface;

final class InMemoryWebQrLoginStore implements WebQrLoginStoreInterface
{
    /** @var array<int,array<string,mixed>> */
    public array $organizations = [
        7 => ['id' => 7, 'deployment_id' => 'qr-test', 'organization_name' => 'QR Test', 'status' => 1, 'delete_time' => null],
        8 => ['id' => 8, 'deployment_id' => 'qr-test', 'organization_name' => 'Other', 'status' => 1, 'delete_time' => null],
    ];

    /** @var array<int,array<string,mixed>> */
    public array $users = [
        11 => ['id' => 11, 'organization' => 7, 'user_id' => 'app-user-1', 'account' => 'alice', 'nickname' => 'Alice', 'status' => 1, 'is_system' => 2, 'delete_time' => null],
        12 => ['id' => 12, 'organization' => 7, 'user_id' => 'app-user-2', 'account' => 'bob', 'nickname' => 'Bob', 'status' => 1, 'is_system' => 2, 'delete_time' => null],
    ];

    /** @var array<string,array<string,mixed>> */
    public array $rows = [];

    public function transaction(\Closure $callback): mixed
    {
        return $callback();
    }

    public function lockActiveOrganization(int $organization, string $deploymentId): array
    {
        $row = $this->organizations[$organization] ?? null;
        if (!$row || $row['deployment_id'] !== $deploymentId || $row['status'] !== 1 || $row['delete_time'] !== null) {
            throw new ApiException('organization rejected', 403);
        }
        return $row;
    }

    public function lockActiveUser(int $organization, int $id, string $userId): array
    {
        $row = $this->users[$id] ?? null;
        if (!$row || $row['organization'] !== $organization || $row['user_id'] !== $userId || $row['status'] !== 1) {
            throw new ApiException('user rejected', 401);
        }
        return $row;
    }

    public function lockImPolicy(int $organization): ?array
    {
        return ['organization' => $organization, 'allowed_client_families_json' => '["web","app"]', 'status' => 'ENABLED'];
    }

    public function insert(array $row): void
    {
        $row['id'] = count($this->rows) + 1;
        $this->rows[(string) $row['qr_id']] = $row;
    }

    public function find(int $organization, string $deploymentId, string $qrId, bool $lock = false): ?array
    {
        $row = $this->rows[$qrId] ?? null;
        if (!$row || $row['organization'] !== $organization || $row['deployment_id'] !== $deploymentId) {
            return null;
        }
        return $row;
    }

    public function transition(int $id, string $fromStatus, array $changes): bool
    {
        foreach ($this->rows as $qrId => $row) {
            if ($row['id'] === $id && $row['status'] === $fromStatus) {
                $this->rows[$qrId] = array_merge($row, $changes);
                return true;
            }
        }
        return false;
    }
}

final class RecordingWebAccessIssuer implements WebAccessIssuerInterface
{
    public int $issued = 0;
    /** @var list<array<string,mixed>> */
    public array $events = [];

    public function issueAccessForUser(
        array $organization,
        array $user,
        string $deviceId,
        string $clientFamily,
        string $os,
        string $clientIp,
        string $auditScope,
        ?int $now = null,
    ): array {
        ++$this->issued;
        return [
            'organization' => (int) $organization['id'],
            'deployment_id' => (string) $organization['deployment_id'],
            'token' => ['access_token' => 'web-access-' . $this->issued, 'token_type' => 'Bearer', 'expires_in' => 7200, 'refresh_token' => ''],
            'user' => ['id' => (string) $user['id'], 'user_id' => (string) $user['user_id']],
        ];
    }

    public function recordLoginEvent(
        int $organization,
        string $userId,
        ?string $deviceId,
        ?string $loginIp,
        string $clientFamily,
        string $os,
        string $result,
        string $auditScope,
        ?string $failureCode = null,
        ?int $now = null,
    ): void {
        $this->events[] = compact('organization', 'userId', 'deviceId', 'clientFamily', 'os', 'result', 'auditScope');
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$assertions;
};
$expectCode = static function (int $code, callable $callback) use ($assert): void {
    try {
        $callback();
    } catch (ApiException $exception) {
        $assert($exception->getCode() === $code, 'ApiException code mismatch: ' . $exception->getCode());
        return;
    }
    throw new RuntimeException('Expected ApiException was not thrown.');
};

$store = new InMemoryWebQrLoginStore();
$issuer = new RecordingWebAccessIssuer();
$now = 1_800_000_000;
$tokens = [str_repeat('A', 43), str_repeat('B', 43), str_repeat('C', 43), str_repeat('D', 43)];
$service = new WebQrLoginService(
    $store,
    $issuer,
    static function () use (&$now): int { return $now; },
    static function () use (&$tokens): string { return array_shift($tokens) ?? str_repeat('Z', 43); },
);
$organization = ['id' => 7, 'deployment_id' => 'qr-test'];
$app = ['id' => 11, 'organization' => 7, 'deployment_id' => 'qr-test', 'user_id' => 'app-user-1', 'device_id' => 'app-device-1', 'client_family' => 'app', 'os' => 'android'];
$otherApp = ['id' => 12, 'organization' => 7, 'deployment_id' => 'qr-test', 'user_id' => 'app-user-2', 'device_id' => 'app-device-2', 'client_family' => 'app', 'os' => 'ios'];

$created = $service->create($organization, 'browser-device-1', 'https://web.example.test');
$row = $store->rows[$created['qr_id']];
$assert($created['browser_token'] === str_repeat('A', 43), 'browser token mismatch');
$assert(str_contains($created['qr_content'], 'scan_token=' . str_repeat('B', 43)), 'QR content omitted scan token');
$assert(!str_contains($created['qr_content'], $created['browser_token']), 'QR content leaked browser token');
$assert($row['browser_token_hash'] !== $row['scan_token_hash'], 'browser and scan token hashes were not separated');
$assert(!in_array($created['browser_token'], $row, true) && !in_array(str_repeat('B', 43), $row, true), 'plaintext token was persisted');

$expectCode(403, static fn () => $service->scan($organization, $app, $created['qr_id'], str_repeat('X', 43), '203.0.113.10'));
$scanned = $service->scan($organization, $app, $created['qr_id'], str_repeat('B', 43), '203.0.113.10');
$assert($scanned['qr_id'] === $created['qr_id'] && $scanned['status'] === 'scanned' && $scanned['expires_at'] > $now, 'scan response contract mismatch');
$expectCode(409, static fn () => $service->scan($organization, $otherApp, $created['qr_id'], str_repeat('B', 43), '203.0.113.11'));
$expectCode(403, static fn () => $service->confirm($organization, $otherApp, $created['qr_id'], '203.0.113.11'));
$confirmed = $service->confirm($organization, $app, $created['qr_id'], '203.0.113.10');
$assert($confirmed === ['qr_id' => $created['qr_id'], 'status' => 'confirmed'], 'confirm response contract mismatch');
$assert(count($issuer->events) === 1 && $issuer->events[0]['auditScope'] === 'qr_login', 'QR confirmation audit missing');

$expectCode(403, static fn () => $service->poll($organization, $created['qr_id'], $created['browser_token'], 'other-browser', '203.0.113.20'));
$consumed = $service->poll($organization, $created['qr_id'], $created['browser_token'], 'browser-device-1', '203.0.113.20');
$assert($consumed['status'] === 'confirmed' && isset($consumed['token'], $consumed['user']), 'first poll did not return confirmed session contract');
$assert($store->rows[$created['qr_id']]['status'] === 'consumed', 'first poll did not atomically consume QR state');
$repeat = $service->poll($organization, $created['qr_id'], $created['browser_token'], 'browser-device-1', '203.0.113.20');
$assert($repeat === ['status' => 'consumed'] && $issuer->issued === 1, 'repeat poll issued another access token');
$expectCode(404, static fn () => $service->poll(['id' => 8, 'deployment_id' => 'qr-test'], $created['qr_id'], $created['browser_token'], 'browser-device-1', '203.0.113.20'));

$expiring = $service->create($organization, 'browser-device-2', 'https://web.example.test');
$now += 121;
$expectCode(409, static fn () => $service->poll($organization, $expiring['qr_id'], $expiring['browser_token'], 'browser-device-2', '203.0.113.21'));
$assert($store->rows[$expiring['qr_id']]['status'] === 'expired', 'expired QR was not closed');

fwrite(STDOUT, sprintf("Web QR login service: %d assertions passed.\n", $assertions));
