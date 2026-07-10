<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\web\S3WebImUploadStorage;
use plugin\saimulti\service\web\WebImPrivateS3Config;
use plugin\saimulti\service\web\WebImUploadAssetStoreInterface;
use plugin\saimulti\service\web\WebImUploadService;
use plugin\saimulti\service\web\WebImUploadStorageInterface;
use support\Request;
use Webman\Http\UploadFile;

final class RecordingS3UploadClient
{
    /** @var list<array<string, mixed>> */
    public array $putCalls = [];

    /** @var list<array<string, mixed>> */
    public array $deleteCalls = [];

    public function putObject(array $input): void
    {
        $this->putCalls[] = $input;
    }

    public function deleteObject(array $input): void
    {
        $this->deleteCalls[] = $input;
    }
}

final class UploadStorage implements WebImUploadStorageInterface
{
    public int $readyCalls = 0;

    public bool $unavailable = false;

    /** @var list<array<string, mixed>> */
    public array $uploads = [];

    /** @var list<array{int, string}> */
    public array $deletes = [];

    public function assertReady(): void
    {
        $this->readyCalls++;
        if ($this->unavailable) {
            throw new ApiException('Web IM 附件必须使用私有 S3 存储。', 503);
        }
    }

    public function upload(
        int $organization,
        SplFileInfo $file,
        string $extension,
        string $mimeType,
    ): array {
        $this->uploads[] = get_defined_vars();

        return [
            'storage_path' => sprintf(
                'private/organizations/%d/im/202607/%s.%s',
                $organization,
                str_repeat('c', 48),
                $extension,
            ),
            'size_byte' => $file->getSize(),
        ];
    }

    public function delete(int $organization, string $storagePath): void
    {
        $this->deletes[] = [$organization, $storagePath];
    }
}

final class UploadAssetStore implements WebImUploadAssetStoreInterface
{
    /** @var list<array<string, mixed>> */
    public array $created = [];

    public bool $failCreate = false;

    public function create(array $asset): void
    {
        if ($this->failCreate) {
            throw new RuntimeException('simulated insert failure');
        }
        $this->created[] = $asset;
    }

    public function findActiveImage(int $organization, string $fileId, ?string $ownerUserId = null): ?array
    {
        return null;
    }
}

final class UploadRequest extends Request
{
    public int $fileCalls = 0;

    public function __construct(private UploadFile $upload)
    {
        parent::__construct("POST /saimulti/web/im/upload HTTP/1.1\r\nHost: localhost\r\n\r\n");
    }

    public function file(?string $name = null): array|null|UploadFile
    {
        $this->fileCalls++;

        return $name === null ? ['file' => $this->upload] : $this->upload;
    }
}

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

$privateConfig = [
    'upload_mode' => '5',
    's3_acl' => 'private',
    's3_key' => 'TESTACCESSKEY',
    's3_secret' => 'test-secret-never-returned',
    's3_bucket' => 'private-bucket',
    's3_dirname' => 'private',
    's3_region' => 'auto',
    's3_version' => 'latest',
    's3_use_path_style_endpoint' => '1',
    's3_endpoint' => 'https://s3.example.test',
];
$validated = (new WebImPrivateS3Config(static fn (): array => $privateConfig))->requirePrivate();
$assert(
    $validated['bucket'] === 'private-bucket'
    && $validated['dirname'] === 'private'
    && ($validated['options']['endpoint'] ?? '') === 'https://s3.example.test',
    'Private S3 configuration was not normalized.',
);
$expectApiCode(503, static fn () => (new WebImPrivateS3Config(
    static fn (): array => [...$privateConfig, 'upload_mode' => '1'],
))->requirePrivate());
$expectApiCode(503, static fn () => (new WebImPrivateS3Config(
    static fn (): array => [...$privateConfig, 's3_acl' => 'public-read'],
))->requirePrivate());
$expectApiCode(503, static fn () => (new WebImPrivateS3Config(
    static fn (): array => [...$privateConfig, 's3_endpoint' => 'http://s3.example.test'],
))->requirePrivate());

$storage = new UploadStorage();
$assets = new UploadAssetStore();
$service = new WebImUploadService($storage, $assets);
$identity = ['organization' => 901, 'user_id' => 'user_a'];
$prepared = $service->prepare($identity, 'image', 'avatar.webp', 12, 'image/webp');
$assert(
    $prepared === [
        'mode' => 'proxy',
        'upload_path' => '/saimulti/web/im/upload',
        'method' => 'POST',
        'filename' => 'avatar.webp',
        'size' => 12,
        'mime_type' => 'image/webp',
        'extension' => 'webp',
    ]
    && !array_key_exists('upload_url', $prepared)
    && !array_key_exists('public_url', $prepared),
    'Prepare response leaked a raw URL or changed the proxy metadata contract.',
);

$temporary = tempnam(sys_get_temp_dir(), 'b8im-upload-test-');
if ($temporary === false || file_put_contents($temporary, 'private-data') === false) {
    throw new RuntimeException('Unable to create upload test fixture.');
}
try {
    $s3Client = new RecordingS3UploadClient();
    $s3Storage = new S3WebImUploadStorage(
        new WebImPrivateS3Config(static fn (): array => $privateConfig),
        static fn (array $options): RecordingS3UploadClient => $s3Client,
    );
    $storedObject = $s3Storage->upload(901, new SplFileInfo($temporary), 'webp', 'image/webp');
    $assert(
        count($s3Client->putCalls) === 1
        && $s3Client->putCalls[0]['ACL'] === 'private'
        && $s3Client->putCalls[0]['Bucket'] === 'private-bucket'
        && $s3Client->putCalls[0]['Key'] === $storedObject['storage_path']
        && str_starts_with($storedObject['storage_path'], 'private/organizations/901/im/'),
        'S3 uploader did not enforce the private canonical object contract.',
    );
    $s3Storage->delete(901, $storedObject['storage_path']);
    $assert(
        $s3Client->deleteCalls === [[
            'Bucket' => 'private-bucket',
            'Key' => $storedObject['storage_path'],
        ]],
        'S3 compensation did not delete the exact private object key.',
    );

    $request = new UploadRequest(new UploadFile(
        $temporary,
        'avatar.webp',
        'image/webp',
        UPLOAD_ERR_OK,
    ));
    $uploaded = $service->upload($identity, $request, 'image');
    $assert(
        array_keys($uploaded) === ['file_id', 'kind', 'name', 'size', 'mime_type', 'extension']
        && preg_match('/^[a-f0-9]{40}$/', (string) $uploaded['file_id']) === 1
        && !array_key_exists('url', $uploaded)
        && !array_key_exists('storage_path', $uploaded),
        'Upload response leaked a raw URL/path or changed the safe metadata contract.',
    );
    $assert(
        count($assets->created) === 1
        && $assets->created[0]['url'] === ''
        && str_starts_with(
            (string) $assets->created[0]['storage_path'],
            'private/organizations/901/im/',
        ),
        'Upload persistence did not keep only the canonical private object identity.',
    );

    $storage->unavailable = true;
    $blockedRequest = new UploadRequest(new UploadFile(
        $temporary,
        'avatar.webp',
        'image/webp',
        UPLOAD_ERR_OK,
    ));
    $expectApiCode(503, static fn () => $service->upload($identity, $blockedRequest, 'image'));
    $assert(
        $blockedRequest->fileCalls === 0 && count($storage->uploads) === 1,
        'Private S3 preflight did not fail before reading the uploaded file.',
    );

    $storage->unavailable = false;
    $assets->failCreate = true;
    $failedRequest = new UploadRequest(new UploadFile(
        $temporary,
        'avatar.webp',
        'image/webp',
        UPLOAD_ERR_OK,
    ));
    try {
        $service->upload($identity, $failedRequest, 'image');
        throw new RuntimeException('Expected simulated asset insert failure.');
    } catch (RuntimeException $exception) {
        $assert(
            $exception->getMessage() === 'simulated insert failure',
            'Upload did not preserve the database failure after object cleanup.',
        );
    }
    $assert(
        count($assets->created) === 1
        && count($storage->deletes) === 1
        && $storage->deletes[0][0] === 901,
        'Database failure did not compensate by deleting the uploaded private object.',
    );
} finally {
    @unlink($temporary);
}

echo sprintf("WebImUploadServiceTest: %d assertions passed\n", $assertions);
