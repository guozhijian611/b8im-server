<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\web\WebImAccessSessionGuard;
use plugin\saimulti\service\web\WebImAccessSessionStoreInterface;
use plugin\saimulti\service\web\WebImPolicyGuard;
use plugin\saimulti\service\web\WebImPolicyStoreInterface;

final class GuardTestAccessStore implements WebImAccessSessionStoreInterface
{
    /** @var array<string, mixed>|null */
    public ?array $row = null;

    public bool $fail = false;

    public function findByJti(int $organization, string $jti): ?array
    {
        if ($this->fail) {
            throw new RuntimeException('database unavailable');
        }

        return $this->row;
    }
}

final class GuardTestPolicyStore implements WebImPolicyStoreInterface
{
    /** @var array<string, mixed>|null */
    public ?array $row = null;

    public bool $fail = false;

    public function findPolicy(int $organization): ?array
    {
        if ($this->fail) {
            throw new RuntimeException('database unavailable');
        }

        return $this->row;
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
        $assert($exception->getCode() === $code, 'ApiException code mismatch');
        return;
    }
    throw new RuntimeException('Expected ApiException was not thrown');
};

$now = 1_800_000_000;
$jti = '0123456789abcdef0123456789abcdef';
$claims = [
    'organization' => 7,
    'jti' => $jti,
    'id' => 9,
    'user_id' => 'user_9',
    'device_id' => 'web-device-9',
    'exp' => $now + 300,
];
$accessStore = new GuardTestAccessStore();
$accessStore->row = [
    'organization' => 7,
    'jti' => $jti,
    'im_user_id' => 9,
    'user_id' => 'user_9',
    'device_id' => 'web-device-9',
    'status' => 1,
    'expire_at' => date('Y-m-d H:i:s', $now + 300),
    'revoked_at' => null,
];
$accessGuard = new WebImAccessSessionGuard($accessStore, static fn (): int => $now);
$accessGuard->assertActive($claims, 7);
$assert(true, 'active access session was rejected');

$accessStore->row['status'] = 2;
$accessStore->row['revoked_at'] = date('Y-m-d H:i:s', $now);
$expectCode(401, static fn () => $accessGuard->assertActive($claims, 7));
$accessStore->row['status'] = 1;
$accessStore->row['revoked_at'] = null;
$accessStore->row['device_id'] = 'other-device';
$expectCode(401, static fn () => $accessGuard->assertActive($claims, 7));
$accessStore->row['device_id'] = 'web-device-9';
$accessStore->fail = true;
$expectCode(401, static fn () => $accessGuard->assertActive($claims, 7));
$accessStore->fail = false;

$policyStore = new GuardTestPolicyStore();
$policyStore->row = [
    'organization' => 7,
    'status' => 'ENABLED',
    'allowed_client_families_json' => '["web","app"]',
];
$policyGuard = new WebImPolicyGuard($policyStore);
$policyGuard->assertAllowed(7, 'web');
$assert(true, 'enabled Web IM policy was rejected');
$policyGuard->assertAllowed(7, 'app');
$assert(true, 'enabled App IM policy was rejected');
$policyStore->row['status'] = 'DISABLED';
$expectCode(403, static fn () => $policyGuard->assertAllowed(7, 'web'));
$policyStore->row['status'] = 'ENABLED';
$policyStore->row['allowed_client_families_json'] = '["app"]';
$expectCode(403, static fn () => $policyGuard->assertAllowed(7, 'web'));
$policyGuard->assertAllowed(7, 'app');
$policyStore->row['allowed_client_families_json'] = '{"0":"web"}';
$expectCode(403, static fn () => $policyGuard->assertAllowed(7, 'web'));
$policyStore->fail = true;
$expectCode(403, static fn () => $policyGuard->assertAllowed(7, 'app'));

$assert(WebImPolicyGuard::familyForPath('/saimulti/web/im/messages') === 'web', 'Web IM route escaped policy guard');
$assert(WebImPolicyGuard::familyForPath('saimulti/app/im') === 'app', 'App IM root escaped policy guard');
$assert(WebImPolicyGuard::familyForPath('/saimulti/client/config') === null, 'client config was accidentally policy gated');
$assert(WebImPolicyGuard::familyForPath('/saimulti/web/announcement/index') === null, 'announcement route was accidentally policy gated');

fwrite(STDOUT, sprintf("Web IM session and policy guards: %d assertions passed.\n", $assertions));
