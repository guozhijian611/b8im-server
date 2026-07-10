<?php
// +----------------------------------------------------------------------
// | b8im [ 即时通讯系统 ]
// +----------------------------------------------------------------------

declare(strict_types=1);

namespace plugin\saimulti\service\adminIm;

use Closure;
use plugin\saimulti\app\logic\system\SystemConfigLogic;
use Throwable;

final class SaiAdminStorageInspector implements AdminImStorageInspectorInterface
{
    /** @var array<string, array{label: string, required: array<string, string>}> */
    private const MODES = [
        '1' => [
            'label' => '本地存储',
            'required' => ['local_root' => '存储目录', 'local_domain' => '访问域名', 'local_uri' => '访问路径'],
        ],
        '2' => [
            'label' => '阿里云 OSS',
            'required' => [
                'oss_accessKeyId' => 'Access Key',
                'oss_accessKeySecret' => 'Access Secret',
                'oss_bucket' => 'Bucket',
                'oss_domain' => '访问域名',
                'oss_endpoint' => 'Endpoint',
            ],
        ],
        '3' => [
            'label' => '七牛云',
            'required' => [
                'qiniu_accessKey' => 'Access Key',
                'qiniu_secretKey' => 'Secret Key',
                'qiniu_bucket' => 'Bucket',
                'qiniu_domain' => '访问域名',
            ],
        ],
        '4' => [
            'label' => '腾讯云 COS',
            'required' => [
                'cos_secretId' => 'Secret Id',
                'cos_secretKey' => 'Secret Key',
                'cos_bucket' => 'Bucket',
                'cos_domain' => '访问域名',
                'cos_region' => 'Region',
            ],
        ],
        '5' => [
            'label' => 'S3 存储',
            'required' => [
                's3_key' => 'Access Key',
                's3_secret' => 'Secret',
                's3_bucket' => 'Bucket',
                's3_domain' => '访问域名',
                's3_region' => 'Region',
                's3_version' => 'API 版本',
                's3_endpoint' => 'Endpoint',
            ],
        ],
    ];

    /** @param (Closure(): array<int, array<string, mixed>>)|null $configProvider */
    public function __construct(private readonly ?Closure $configProvider = null)
    {
    }

    public function inspect(): array
    {
        try {
            $rows = $this->configProvider !== null
                ? ($this->configProvider)()
                : (new SystemConfigLogic())->getGroup('upload_config');
            $values = [];
            foreach ($rows as $row) {
                $key = isset($row['key']) ? (string) $row['key'] : '';
                if ($key !== '') {
                    $values[$key] = trim((string) ($row['value'] ?? ''));
                }
            }

            $mode = $values['upload_mode'] ?? '';
            if (!isset(self::MODES[$mode])) {
                return [
                    'status' => 'incomplete',
                    'mode' => 'unknown',
                    'label' => '未配置',
                    'configured' => false,
                    'missing' => ['上传模式'],
                ];
            }

            $definition = self::MODES[$mode];
            $missing = [];
            foreach ($definition['required'] as $key => $label) {
                if (($values[$key] ?? '') === '') {
                    $missing[] = $label;
                }
            }

            return [
                'status' => $missing === [] ? 'ready' : 'incomplete',
                'mode' => $mode,
                'label' => $definition['label'],
                'configured' => $missing === [],
                'missing' => $missing,
            ];
        } catch (Throwable) {
            return [
                'status' => 'unavailable',
                'mode' => 'unknown',
                'label' => '无法读取',
                'configured' => false,
                'missing' => [],
            ];
        }
    }
}
