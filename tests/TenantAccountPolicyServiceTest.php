<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\web\TenantAccountPolicyService;
use plugin\saimulti\service\web\TenantAccountPolicyStoreInterface;

final class FakeTenantAccountPolicyStore implements TenantAccountPolicyStoreInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];
    public int $updates = 0;

    public function transaction(callable $callback): mixed
    {
        return $callback();
    }

    public function find(int $organization, bool $lock = false): ?array
    {
        return $this->rows[$organization] ?? null;
    }

    public function createDefault(int $organization, array $defaults): void
    {
        $this->rows[$organization] = ['organization' => $organization] + $defaults;
    }

    public function updateRegisterEnabled(int $organization, int $expectedVersion, array $values): bool
    {
        if (!isset($this->rows[$organization]) || (int) $this->rows[$organization]['version'] !== $expectedVersion) {
            return false;
        }
        ++$this->updates;
        $this->rows[$organization] = array_replace($this->rows[$organization], $values);
        return true;
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
        $assert($exception->getCode() === $code, "Expected {$code}, got {$exception->getCode()}.");
        return;
    }
    throw new RuntimeException("Expected ApiException {$code}.");
};

$defaults = TenantAccountPolicyService::defaults('2026-07-21 13:00:00');
$assert($defaults['register_enabled'] === 0, 'Account policy defaults must fail closed.');

$store = new FakeTenantAccountPolicyStore();
$service = new TenantAccountPolicyService($store);
$service->createDefault(101);
$assert($store->rows[101]['register_enabled'] === 0, 'New organization policy was not created closed.');
$assert($service->publicPolicy(101)['register_enabled'] === false, 'Public policy exposed closed registration as open.');
try {
    $service->lockOpenRegistration(101);
    throw new RuntimeException('Closed registration was accepted.');
} catch (ApiException $exception) {
    $assert($exception->getCode() === 403, 'Closed registration returned the wrong error code.');
}

$store->rows[101]['register_enabled'] = 1;
$assert($service->lockOpenRegistration(101)['register_enabled'] === 1, 'Explicit open registration was rejected.');
$assert($service->publicPolicy(101)['register_enabled'] === true, 'Explicit open registration was hidden.');

$store->rows[101]['register_enabled'] = 0;
$read = $service->read(101);
$assert(array_keys($read) === ['organization', 'register_enabled', 'version', 'update_time'], 'Read shape drifted.');
$assert($read['organization'] === 101 && $read['register_enabled'] === false, 'Read scope/state mismatch.');
$same = $service->update(101, ['register_enabled' => false, 'version' => 1]);
$assert($same['version'] === 1 && $store->updates === 0, 'No-op advanced the version.');
$opened = $service->update(101, ['register_enabled' => true, 'version' => 1]);
$assert($opened['version'] === 2 && $opened['register_enabled'] === true, 'CAS update failed.');
$retry = $service->update(101, ['register_enabled' => true, 'version' => 1]);
$assert($retry['version'] === 2 && $store->updates === 1, 'Exact retry was not idempotent.');
$expectCode(422, static fn () => $service->update(101, [
    'register_enabled' => false, 'version' => 2, 'organization' => 999,
]));
$expectCode(422, static fn () => $service->update(101, ['register_enabled' => 1, 'version' => 2]));
$expectCode(422, static fn () => $service->update(101, ['register_enabled' => false, 'version' => '2']));
$expectCode(422, static fn () => $service->update(101, [
    'register_enabled' => false, 'version' => TenantAccountPolicyService::MAX_SAFE_VERSION,
]));
$expectCode(409, static fn () => $service->update(101, ['register_enabled' => false, 'version' => 1]));
$store->rows[101]['register_enabled'] = 0;
$store->rows[101]['invite_required'] = 1;
$expectCode(422, static fn () => $service->update(101, ['register_enabled' => true, 'version' => 2]));
$store->rows[101]['invite_required'] = 0;
$store->rows[101]['email_verify_enabled'] = 1;
$expectCode(422, static fn () => $service->update(101, ['register_enabled' => true, 'version' => 2]));
$store->rows[101]['email_verify_enabled'] = 0;
foreach (['mobile_verify_enabled', 'realname_required'] as $requirement) {
    $store->rows[101][$requirement] = 1;
    $expectCode(422, static fn () => $service->update(101, ['register_enabled' => true, 'version' => 2]));
    $store->rows[101][$requirement] = 0;
}
$store->rows[101]['status'] = 'DISABLED';
$expectCode(409, static fn () => $service->read(101));
$store->rows[101]['status'] = 'ENABLED';
$store->rows[101]['version'] = '9007199254740992';
$expectCode(409, static fn () => $service->read(101));
$expectCode(404, static fn () => $service->read(404));

fwrite(STDOUT, sprintf("Tenant account policy service: %d assertions passed.\n", $assertions));
