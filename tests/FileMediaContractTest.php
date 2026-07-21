<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use plugin\saimulti\app\controller\admin\FileMediaController as AdminFileMediaController;
use plugin\saimulti\app\controller\admin\StorageQuotaController as AdminStorageQuotaController;
use plugin\saimulti\app\controller\tenant\FileMediaController as TenantFileMediaController;
use plugin\saimulti\app\controller\web\FileMediaController as WebFileMediaController;
use plugin\saimulti\app\controller\web\ImController;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\module\FileMediaService;
use plugin\saimulti\service\module\FileMediaPolicyService;
use plugin\saimulti\service\quota\CanonicalPhysicalStoragePath;
use plugin\saimulti\service\quota\StorageQuotaAuthority;
use plugin\saimulti\service\web\ThinkOrmWebImAssetForwardStore;
use support\Request;

final class FileMediaContractRequest extends Request
{
    /** @param array<string,mixed> $post @param array<string,mixed> $get */
    public function __construct(
        private readonly array $postData = [],
        private readonly array $getData = [],
    ) {
        parent::__construct("POST / HTTP/1.1\r\nHost: localhost\r\n\r\n");
    }

    public function post(?string $name = null, mixed $default = null): mixed
    {
        return $name === null ? $this->postData : ($this->postData[$name] ?? $default);
    }

    public function get(?string $name = null, mixed $default = null): mixed
    {
        return $name === null ? $this->getData : ($this->getData[$name] ?? $default);
    }

    public function input(string $name, mixed $default = null): mixed
    {
        return $this->postData[$name] ?? $this->getData[$name] ?? $default;
    }
}

final class FileMediaContractWebController extends WebFileMediaController
{
    public function init(): void
    {
        $this->organization = 1;
        $this->webIdentity = [
            'id' => 1,
            'organization' => 1,
            'user_id' => 'contract-user',
            'deployment_id' => 'contract',
        ];
        $this->deploymentId = 'contract';
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $assertions++;
};
$expectCode = static function (int $code, callable $callback) use ($assert): void {
    try {
        $callback();
    } catch (ApiException $exception) {
        $assert($exception->getCode() === $code, 'unexpected API error code');

        return;
    }
    throw new RuntimeException("expected ApiException {$code}");
};

$usageRatio = static function (int $quota, int $occupancy): float {
    $projection = (new StorageQuotaAuthority())->format([
        'row' => [
            'organization' => 1,
            'version' => 1,
            'update_time' => '2026-07-22 00:00:00',
        ],
        'quota_value' => $quota,
        'used_value' => $occupancy,
        'held_value' => 0,
        'occupancy_value' => $occupancy,
        'used_file_count' => 1,
        'held_file_count' => 0,
    ]);

    return $projection['usage_ratio'];
};
$assert(
    $usageRatio(8000000000000000000, 2666667999999999999) === 0.333333
    && $usageRatio(8000000000000000000, 3999995999999999999) === 0.499999
    && $usageRatio(8000000000000000000, 987652000000000000) === 0.123457,
    'usage ratio crossed an exact six-decimal half-up boundary',
);

$web = new FileMediaContractWebController();
$expectCode(422, static fn () => $web->checkUpload(
    new FileMediaContractRequest(['size' => 1]),
));
$expectCode(422, static fn () => $web->checkUpload(
    new FileMediaContractRequest(['size_bytes' => 1, 'size' => 1]),
));
$expectCode(422, static fn () => $web->checkUpload(
    new FileMediaContractRequest(['size_bytes' => '01']),
));
$expectCode(422, static fn () => $web->checkUpload(
    new FileMediaContractRequest(['size_bytes' => 0]),
));
$expectCode(422, static fn () => $web->checkUpload(
    new FileMediaContractRequest(['size_bytes' => 2147483649]),
));
$expectCode(422, static fn () => $web->checkUpload(
    new FileMediaContractRequest(['size_bytes' => '9223372036854775808']),
));

$adminStorage = (new ReflectionClass(AdminStorageQuotaController::class))
    ->newInstanceWithoutConstructor();
$adminPolicy = (new ReflectionClass(AdminFileMediaController::class))
    ->newInstanceWithoutConstructor();
$tenantPolicy = (new ReflectionClass(TenantFileMediaController::class))
    ->newInstanceWithoutConstructor();
$im = (new ReflectionClass(ImController::class))->newInstanceWithoutConstructor();
foreach (['01', '9223372036854775808'] as $invalidOrganization) {
    $request = new FileMediaContractRequest([], ['organization' => $invalidOrganization]);
    $expectCode(422, static fn () => $adminStorage->read($request));
    $expectCode(422, static fn () => $adminPolicy->policyRead($request));
}
$expectCode(422, static fn () => $adminStorage->update(
    new FileMediaContractRequest([
        'organization' => 1,
        'quota_value' => '1000',
        'version' => 1,
        'legacy_quota' => '1000',
    ]),
));

$completePolicy = [
    'max_file_bytes' => '2147483648',
    'preview_enabled' => 1,
    'large_file_enabled' => 1,
    'status' => 1,
];
$expectCode(422, static fn () => $adminPolicy->policyUpdate(
    new FileMediaContractRequest(['organization' => 1] + array_diff_key(
        $completePolicy,
        ['status' => true],
    )),
));
$expectCode(422, static fn () => $adminPolicy->policyUpdate(
    new FileMediaContractRequest(
        ['organization' => 1] + $completePolicy + ['max_storage_bytes' => '1'],
    ),
));
$expectCode(422, static fn () => $tenantPolicy->policyUpdate(
    new FileMediaContractRequest(array_diff_key($completePolicy, ['status' => true])),
));
$expectCode(422, static fn () => $tenantPolicy->policyUpdate(
    new FileMediaContractRequest($completePolicy + ['organization' => 1]),
));

$policyService = new FileMediaService();
$expectCode(422, static fn () => $policyService->policyUpdate(1, [], 1));
$expectCode(422, static fn () => $policyService->policyUpdate(
    1,
    ['max_file_bytes' => 2147483648] + array_diff_key(
        $completePolicy,
        ['max_file_bytes' => true],
    ),
    1,
));
$invalidFlagPolicy = $completePolicy;
$invalidFlagPolicy['preview_enabled'] = '1';
$expectCode(422, static fn () => $policyService->policyUpdate(1, $invalidFlagPolicy, 1));

$policyProjector = new FileMediaPolicyService();
foreach ([
    ['max_file_bytes' => 'x', 'preview_enabled' => 1, 'large_file_enabled' => 1, 'status' => 1],
    ['max_file_bytes' => '1', 'preview_enabled' => 1, 'large_file_enabled' => 1, 'status' => 'x'],
    ['max_file_bytes' => '2147483649', 'preview_enabled' => 1, 'large_file_enabled' => 1, 'status' => 1],
] as $corruptPolicy) {
    $expectCode(503, static fn () => $policyProjector->project($corruptPolicy));
}

$prepareBody = [
    'idempotency_key' => str_repeat('a', 32),
    'kind' => 'file',
    'filename' => 'a.txt',
    'size' => 1,
    'mime_type' => 'text/plain',
];
$expectCode(422, static fn () => $im->prepareUpload(
    new FileMediaContractRequest(array_diff_key($prepareBody, ['mime_type' => true])),
));
$expectCode(422, static fn () => $im->prepareUpload(
    new FileMediaContractRequest($prepareBody + ['legacy_size' => 1]),
));
foreach ([true, 1.0, '01', '9223372036854775808'] as $invalidPrepareSize) {
    $invalidPrepare = $prepareBody;
    $invalidPrepare['size'] = $invalidPrepareSize;
    $expectCode(422, static fn () => $im->prepareUpload(
        new FileMediaContractRequest($invalidPrepare),
    ));
}

$uploadIdOnly = new ReflectionMethod(ImController::class, 'uploadIdOnly');
foreach ([1, true, str_repeat('A', 64), '01'] as $invalidUploadId) {
    $expectCode(422, static fn () => $uploadIdOnly->invoke(
        $im,
        new FileMediaContractRequest(['upload_id' => $invalidUploadId]),
    ));
}
$assert(
    $uploadIdOnly->invoke(
        $im,
        new FileMediaContractRequest(['upload_id' => str_repeat('a', 64)]),
    ) === str_repeat('a', 64),
    'canonical upload_id body was not preserved exactly',
);

$checkedUsageAdd = new ReflectionMethod(
    ThinkOrmWebImAssetForwardStore::class,
    'checkedUsageAdd',
);
$forwardStore = new ThinkOrmWebImAssetForwardStore();
$expectCode(503, static fn () => $checkedUsageAdd->invoke(
    $forwardStore,
    PHP_INT_MAX,
    1,
));
$assert(
    $checkedUsageAdd->invoke($forwardStore, PHP_INT_MAX - 1, 1) === PHP_INT_MAX,
    'forward quota arithmetic rejected the exact PHP integer boundary',
);

$serverConfig = require dirname(__DIR__) . '/config/server.php';
$assert(
    ($serverConfig['max_package_size'] ?? null) === 2148532224,
    'Webman package ceiling is not the exact frozen proxy-upload value',
);
$previousPackageSize = getenv('WEBMAN_MAX_PACKAGE_SIZE_BYTES');
putenv('WEBMAN_MAX_PACKAGE_SIZE_BYTES=2148532225');
$invalidPackageSizeRejected = false;
try {
    require dirname(__DIR__) . '/config/server.php';
} catch (RuntimeException) {
    $invalidPackageSizeRejected = true;
} finally {
    $previousPackageSize === false
        ? putenv('WEBMAN_MAX_PACKAGE_SIZE_BYTES')
        : putenv('WEBMAN_MAX_PACKAGE_SIZE_BYTES=' . $previousPackageSize);
}
$assert($invalidPackageSizeRejected, 'invalid Webman package ceiling was silently coerced');

$physicalPath = new CanonicalPhysicalStoragePath();
$assert(
    $physicalPath->assert(
        'private/organizations/902/im/202607/' . str_repeat('a', 32) . '.pdf',
    ) !== '',
    'cross-organization canonical physical path was rejected',
);
$expectCode(503, static fn () => $physicalPath->assert(
    'private/organizations/902/im/../901/' . str_repeat('a', 32) . '.pdf',
));

$routes = file_get_contents(dirname(__DIR__) . '/plugin/saimulti/config/route.php');
$assert(
    is_string($routes)
    && str_contains($routes, "'/admin/storage-quota/index'")
    && str_contains($routes, "'/admin/storage-quota/read'")
    && str_contains($routes, "'/admin/storage-quota/update'")
    && str_contains($routes, "'/tenant/storage-quota/read'")
    && str_contains($routes, "'/admin/file-media/policyIndex'")
    && str_contains($routes, "'/tenant/file-media/policyRead'")
    && !str_contains($routes, "'/admin/file-media/quota")
    && !str_contains($routes, "'/tenant/file-media/quota"),
    'core storage or module policy route split drifted',
);

echo sprintf("FileMediaContractTest: %d assertions passed\n", $assertions);
