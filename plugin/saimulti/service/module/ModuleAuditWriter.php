<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

use JsonException;
use support\think\Db;

final class ModuleAuditWriter
{
    /**
     * @param array{type?: string, id?: int|null, ip?: string|null} $actor
     * @param array<string, mixed> $context
     */
    public function write(
        string $moduleKey,
        string $operation,
        ?string $fromStatus,
        ?string $toStatus,
        ?string $fromVersion,
        ?string $targetVersion,
        bool $success,
        array $actor = [],
        ?int $organization = null,
        ?string $errorMessage = null,
        array $context = [],
    ): void {
        Db::table('sm_module_lifecycle_audit')->insert([
            'module_key' => $moduleKey,
            'organization' => $organization,
            'operation' => $operation,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'from_version' => $fromVersion,
            'target_version' => $targetVersion,
            'success' => $success ? 1 : 0,
            'error_message' => $errorMessage,
            'context_json' => $context === [] ? null : $this->encode($context),
            'operator_type' => (string) ($actor['type'] ?? 'system'),
            'operator_id' => isset($actor['id']) ? (int) $actor['id'] : null,
            'source_ip' => $actor['ip'] ?? null,
            'create_time' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @param array<string, mixed> $value */
    private function encode(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new \RuntimeException('模块审计上下文序列化失败。', previous: $exception);
        }
    }
}
