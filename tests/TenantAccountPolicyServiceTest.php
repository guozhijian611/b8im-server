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

    public function find(int $organization, bool $lock = false): ?array
    {
        return $this->rows[$organization] ?? null;
    }

    public function createDefault(int $organization, array $defaults): void
    {
        $this->rows[$organization] = ['organization' => $organization] + $defaults;
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$assertions;
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

fwrite(STDOUT, sprintf("Tenant account policy service: %d assertions passed.\n", $assertions));
