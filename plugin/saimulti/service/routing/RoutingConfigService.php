<?php

declare(strict_types=1);

namespace plugin\saimulti\service\routing;

use DateTimeImmutable;
use DateTimeZone;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\OrganizationDiscovery;
use plugin\saimulti\service\trace\Telemetry;
use support\Log;
use support\think\Db;

final class RoutingConfigService
{
    public const CLIENT_FAMILIES = ['web', 'app', 'desktop'];
    public const MODES = ['single', 'primary_backup'];

    public function __construct(private readonly ?RoutingSnapshotSigner $signer = null)
    {
    }

    /** @param array<string, mixed> $input */
    public function publish(array $input, array $actor = []): array
    {
        $normalized = $this->normalizePublishInput($input);
        $signer = $this->signer ?? new RoutingSnapshotSigner();
        $auditId = max(0, (int) ($actor['id'] ?? 0));
        $now = new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get()));

        return Db::transaction(function () use ($normalized, $signer, $auditId, $now): array {
            $organization = Db::table('sm_system_organization')
                ->where('id', $normalized['organization'])
                ->whereNull('delete_time')
                ->lock(true)
                ->find();
            if (!$organization || (int) ($organization['status'] ?? 0) !== 1) {
                throw new ApiException('机构不存在或已停用。', 404);
            }
            if ((string) $organization['deployment_id'] !== $normalized['deployment_id']) {
                throw new ApiException('线路 deployment_id 与机构不一致。', 422);
            }

            $this->upsertDeployment($normalized, $now);
            $frozenRoutes = [];
            foreach ($normalized['routes'] as $route) {
                $frozenRoutes[] = $this->freezeRoute($normalized['deployment_id'], $route, $auditId, $now);
            }
            usort($frozenRoutes, static fn (array $a, array $b): int => [$a['priority'], $a['route_id']] <=> [$b['priority'], $b['route_id']]);

            [$poolVersion, $poolItems] = $this->freezePool($normalized, $frozenRoutes, $auditId, $now);
            $result = [];
            foreach ($normalized['client_families'] as $clientFamily) {
                $result[$clientFamily] = $this->publishBinding(
                    $organization,
                    $normalized,
                    $clientFamily,
                    $poolVersion,
                    $poolItems,
                    $signer,
                    $auditId,
                    $now,
                );
            }

            return [
                'organization' => $normalized['organization'],
                'deployment_id' => $normalized['deployment_id'],
                'route_pool_id' => $normalized['route_pool_id'],
                'route_pool_version' => $poolVersion,
                'published' => $result,
                'routing_public_key' => $signer->publicKey(),
            ];
        });
    }

    public function read(int $organization, string $clientFamily): array
    {
        $clientFamily = $this->clientFamily($clientFamily);
        $deploymentId = Db::table('sm_system_organization')
            ->where('id', $organization)
            ->whereNull('delete_time')
            ->value('deployment_id');
        if (!is_string($deploymentId) || $deploymentId === '') {
            throw new ApiException('机构不存在。', 404);
        }
        $row = Db::table('sm_organization_route_publish')
            ->where('deployment_id', $deploymentId)
            ->where('organization', $organization)
            ->where('client_family', $clientFamily)
            ->order('routing_version', 'desc')
            ->find();
        if (!$row) {
            throw new ApiException('当前机构尚未发布该客户端线路。', 404);
        }
        $serverInfo = $this->decodeObject((string) $row['snapshot_json'], '线路发布快照');
        if ($this->snapshotExpired($serverInfo)) {
            return $this->renewExpiredSnapshot($deploymentId, $organization, $clientFamily);
        }

        return $this->publishedResult($row, $serverInfo);
    }

    /** @return array<string, mixed> */
    private function renewExpiredSnapshot(string $deploymentId, int $organization, string $clientFamily): array
    {
        return Db::transaction(function () use ($deploymentId, $organization, $clientFamily): array {
            $organizationRow = Db::table('sm_system_organization')
                ->where('id', $organization)
                ->where('deployment_id', $deploymentId)
                ->whereNull('delete_time')
                ->lock(true)
                ->find();
            if (!$organizationRow || (int) ($organizationRow['status'] ?? 0) !== 1) {
                throw new ApiException('机构不存在或已停用。', 404);
            }
            $policy = Db::table('sm_organization_route_policy')
                ->where('deployment_id', $deploymentId)
                ->where('organization', $organization)
                ->where('client_family', $clientFamily)
                ->lock(true)
                ->find();
            if (!$policy) {
                throw new ApiException('当前机构尚未发布该客户端线路。', 404);
            }
            $row = Db::table('sm_organization_route_publish')
                ->where('deployment_id', $deploymentId)
                ->where('organization', $organization)
                ->where('client_family', $clientFamily)
                ->order('routing_version', 'desc')
                ->lock(true)
                ->find();
            if (!$row) {
                throw new ApiException('当前机构尚未发布该客户端线路。', 404);
            }
            $serverInfo = $this->decodeObject((string) $row['snapshot_json'], '线路发布快照');
            if (!$this->snapshotExpired($serverInfo)) {
                return $this->publishedResult($row, $serverInfo);
            }

            $now = new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get()));
            $issued = $now->format(DATE_ATOM);
            $routingVersion = max(
                (int) ($row['routing_version'] ?? 0),
                (int) ($policy['current_routing_version'] ?? 0),
            ) + 1;
            $serverInfo['routing_version'] = $routingVersion;
            $serverInfo['server_time'] = $issued;
            $serverInfo['issued_at'] = $issued;
            $serverInfo['expires_at'] = $now
                ->modify('+' . max(300, (int) env('ROUTING_SNAPSHOT_TTL_SECONDS', 86400)) . ' seconds')
                ->format(DATE_ATOM);
            $serverInfo['stale_if_error_until'] = $now
                ->modify('+' . max(600, (int) env('ROUTING_STALE_TTL_SECONDS', 172800)) . ' seconds')
                ->format(DATE_ATOM);
            $signature = ($this->signer ?? new RoutingSnapshotSigner())->sign([
                'organization' => $organization,
                'deployment_id' => $deploymentId,
                'enterprise_code' => (string) $organizationRow['enterprise_code'],
                'client_family' => $clientFamily,
                'server_info' => $serverInfo,
            ]);
            $publishTime = $now->format('Y-m-d H:i:s');
            Db::table('sm_organization_route_policy')
                ->where('id', $policy['id'])
                ->update([
                    'current_routing_version' => $routingVersion,
                    'update_time' => $publishTime,
                ]);
            Db::table('sm_organization_route_publish')->insert([
                'deployment_id' => $deploymentId,
                'organization' => $organization,
                'client_family' => $clientFamily,
                'routing_version' => $routingVersion,
                'route_pool_id' => (string) $row['route_pool_id'],
                'pool_version' => (int) $row['pool_version'],
                'snapshot_json' => $this->json($serverInfo),
                'signature_kid' => $signature['kid'],
                'signature' => $signature['value'],
                'publish_time' => $publishTime,
                'audit_id' => 0,
            ]);
            Log::info('routing snapshot renewed', array_merge([
                'organization' => $organization,
                'deployment_id' => $deploymentId,
                'client_family' => $clientFamily,
                'routing_version' => $routingVersion,
            ], Telemetry::currentLogContext()));

            return $this->publishedResult([
                ...$row,
                'routing_version' => $routingVersion,
                'snapshot_json' => $this->json($serverInfo),
                'signature_kid' => $signature['kid'],
                'signature' => $signature['value'],
                'publish_time' => $publishTime,
            ], $serverInfo);
        });
    }

    /** @param array<string, mixed> $serverInfo */
    private function snapshotExpired(array $serverInfo): bool
    {
        $expiresAt = trim((string) ($serverInfo['expires_at'] ?? ''));
        if ($expiresAt === '') {
            throw new ApiException('线路发布快照缺少 expires_at。', 50302);
        }
        try {
            $expires = new DateTimeImmutable($expiresAt);
        } catch (\Exception) {
            throw new ApiException('线路发布快照 expires_at 无效。', 50302);
        }

        return $expires <= new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get()));
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $serverInfo @return array<string, mixed> */
    private function publishedResult(array $row, array $serverInfo): array
    {
        return [
            'organization' => (int) $row['organization'],
            'deployment_id' => (string) $row['deployment_id'],
            'client_family' => (string) $row['client_family'],
            'server_info' => $serverInfo,
            'routing_signature' => [
                'alg' => 'Ed25519',
                'kid' => (string) $row['signature_kid'],
                'canonicalization' => 'JCS-RFC8785',
                'value' => (string) $row['signature'],
            ],
            'publish_time' => (string) $row['publish_time'],
        ];
    }

    /** @return array<string, mixed> */
    private function normalizePublishInput(array $input): array
    {
        $organization = filter_var($input['organization'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($organization === false) {
            throw new ApiException('organization 必须是正整数。', 422);
        }
        $deploymentId = OrganizationDiscovery::assertDeploymentId((string) ($input['deployment_id'] ?? ''));
        $routePoolId = $this->slug((string) ($input['route_pool_id'] ?? ''), 'route_pool_id');
        $mode = (string) ($input['mode'] ?? '');
        if (!in_array($mode, self::MODES, true)) {
            throw new ApiException('mode 只允许 single 或 primary_backup。', 422);
        }
        $families = array_values(array_unique((array) ($input['client_families'] ?? [])));
        if ($families === []) {
            throw new ApiException('至少选择一个 client_family。', 422);
        }
        $families = array_map(fn (mixed $family): string => $this->clientFamily((string) $family), $families);

        $routes = array_values((array) ($input['routes'] ?? []));
        if ($routes === [] || count($routes) > 8) {
            throw new ApiException('线路数量必须在 1 到 8 之间。', 422);
        }
        if ($mode === 'single' && count($routes) !== 1) {
            throw new ApiException('single 模式必须且只能发布一条线路。', 422);
        }
        if ($mode === 'primary_backup' && count($routes) < 2) {
            throw new ApiException('primary_backup 模式至少需要两条线路。', 422);
        }

        $seen = [];
        $normalizedRoutes = [];
        foreach ($routes as $index => $route) {
            if (!is_array($route)) {
                throw new ApiException('线路配置格式无效。', 422);
            }
            $routeId = $this->slug((string) ($route['route_id'] ?? ''), 'route_id');
            if (isset($seen[$routeId])) {
                throw new ApiException('route_id 不能重复。', 422);
            }
            $seen[$routeId] = true;
            $priority = filter_var($route['priority'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
            $weight = filter_var($route['weight'] ?? 100, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
            if ($priority === false || $weight === false) {
                throw new ApiException('线路 priority 或 weight 无效。', 422);
            }
            $normalizedRoutes[] = [
                'route_id' => $routeId,
                'name' => $this->requiredText((string) ($route['name'] ?? ''), '线路名称', 128),
                'priority' => $priority,
                'weight' => $weight,
                'region' => $this->optionalText((string) ($route['region'] ?? ''), 'region', 64),
                'carrier' => $this->optionalText((string) ($route['carrier'] ?? ''), 'carrier', 64),
                'failure_domain' => $this->optionalText((string) ($route['failure_domain'] ?? ''), 'failure_domain', 128),
                'endpoints' => $this->normalizeEndpoints((array) ($route['endpoints'] ?? [])),
                'probes' => [],
                '_index' => $index,
            ];
        }
        usort($normalizedRoutes, static fn (array $a, array $b): int => [$a['priority'], $a['_index']] <=> [$b['priority'], $b['_index']]);
        foreach ($normalizedRoutes as &$route) {
            unset($route['_index']);
        }

        return [
            'organization' => $organization,
            'deployment_id' => $deploymentId,
            'deployment_name' => $this->optionalText((string) ($input['deployment_name'] ?? $deploymentId), 'deployment_name', 128) ?: $deploymentId,
            'route_pool_id' => $routePoolId,
            'route_pool_name' => $this->optionalText((string) ($input['route_pool_name'] ?? $routePoolId), 'route_pool_name', 128) ?: $routePoolId,
            'mode' => $mode,
            'client_families' => $families,
            'routes' => $normalizedRoutes,
        ];
    }

    /** @return array<string, string> */
    private function normalizeEndpoints(array $endpoints): array
    {
        $allowInsecure = (bool) config('app.debug', false);
        return [
            'api_server_url' => OrganizationDiscovery::assertPublicUrl((string) ($endpoints['api_server_url'] ?? ''), ['https', 'http'], $allowInsecure),
            'im_server_url' => OrganizationDiscovery::assertPublicUrl((string) ($endpoints['im_server_url'] ?? ''), ['wss', 'ws'], $allowInsecure),
            'upload_server_url' => OrganizationDiscovery::assertPublicUrl((string) ($endpoints['upload_server_url'] ?? ''), ['https', 'http'], $allowInsecure),
            'web_server_url' => OrganizationDiscovery::assertPublicUrl((string) ($endpoints['web_server_url'] ?? ''), ['https', 'http'], $allowInsecure),
        ];
    }

    private function upsertDeployment(array $config, DateTimeImmutable $now): void
    {
        $existing = Db::table('sm_server_deployment')->where('deployment_id', $config['deployment_id'])->find();
        $data = ['name' => $config['deployment_name'], 'status' => 1, 'update_time' => $now->format('Y-m-d H:i:s')];
        if ($existing) {
            Db::table('sm_server_deployment')->where('id', $existing['id'])->update($data);
            return;
        }
        Db::table('sm_server_deployment')->insert($data + [
            'deployment_id' => $config['deployment_id'],
            'create_time' => $now->format('Y-m-d H:i:s'),
        ]);
    }

    private function freezeRoute(string $deploymentId, array $route, int $auditId, DateTimeImmutable $now): array
    {
        $content = [
            'name' => $route['name'],
            'endpoints' => $route['endpoints'],
            'region' => $route['region'],
            'carrier' => $route['carrier'],
            'failure_domain' => $route['failure_domain'],
            'probes' => $route['probes'],
        ];
        $hash = hash('sha256', CanonicalJson::encode($content));
        $identity = Db::table('sm_server_route')->where('deployment_id', $deploymentId)->where('route_id', $route['route_id'])->lock(true)->find();
        $version = Db::table('sm_server_route_version')->where('deployment_id', $deploymentId)->where('route_id', $route['route_id'])->where('content_hash', $hash)->find();
        if (!$version) {
            $next = (int) (Db::table('sm_server_route_version')->where('deployment_id', $deploymentId)->where('route_id', $route['route_id'])->max('route_version') ?? 0) + 1;
            Db::table('sm_server_route_version')->insert([
                'deployment_id' => $deploymentId,
                'route_id' => $route['route_id'],
                'route_version' => $next,
                'name' => $route['name'],
                ...$route['endpoints'],
                'region' => $route['region'],
                'carrier' => $route['carrier'],
                'failure_domain' => $route['failure_domain'],
                'probe_json' => $this->json($route['probes']),
                'content_hash' => $hash,
                'publish_time' => $now->format('Y-m-d H:i:s'),
                'audit_id' => $auditId,
            ]);
            $version = ['route_version' => $next];
        }
        $routeVersion = (int) $version['route_version'];
        $identityData = ['name' => $route['name'], 'draft_version' => $routeVersion, 'admin_status' => 1, 'update_time' => $now->format('Y-m-d H:i:s')];
        if ($identity) {
            Db::table('sm_server_route')->where('id', $identity['id'])->update($identityData);
        } else {
            Db::table('sm_server_route')->insert($identityData + ['deployment_id' => $deploymentId, 'route_id' => $route['route_id'], 'create_time' => $now->format('Y-m-d H:i:s')]);
        }

        return $route + ['deployment_id' => $deploymentId, 'route_version' => $routeVersion];
    }

    /** @return array{0: int, 1: list<array<string, mixed>>} */
    private function freezePool(array $config, array $routes, int $auditId, DateTimeImmutable $now): array
    {
        $items = array_map(static fn (array $route): array => [
            'route_id' => $route['route_id'],
            'route_version' => $route['route_version'],
            'priority' => $route['priority'],
            'weight' => $route['weight'],
        ], $routes);
        $hash = hash('sha256', CanonicalJson::encode($items));
        $identity = Db::table('sm_server_route_pool')->where('deployment_id', $config['deployment_id'])->where('route_pool_id', $config['route_pool_id'])->lock(true)->find();
        $version = Db::table('sm_server_route_pool_version')->where('deployment_id', $config['deployment_id'])->where('route_pool_id', $config['route_pool_id'])->where('content_hash', $hash)->find();
        if (!$version) {
            $poolVersion = (int) (Db::table('sm_server_route_pool_version')->where('deployment_id', $config['deployment_id'])->where('route_pool_id', $config['route_pool_id'])->max('pool_version') ?? 0) + 1;
            Db::table('sm_server_route_pool_version')->insert([
                'deployment_id' => $config['deployment_id'], 'route_pool_id' => $config['route_pool_id'], 'pool_version' => $poolVersion,
                'content_hash' => $hash, 'publish_time' => $now->format('Y-m-d H:i:s'), 'rollback_from' => null, 'audit_id' => $auditId,
            ]);
            foreach ($items as $item) {
                Db::table('sm_server_route_pool_item')->insert($item + [
                    'deployment_id' => $config['deployment_id'], 'route_pool_id' => $config['route_pool_id'], 'pool_version' => $poolVersion,
                ]);
            }
        } else {
            $poolVersion = (int) $version['pool_version'];
        }
        $identityData = ['name' => $config['route_pool_name'], 'draft_version' => $poolVersion, 'status' => 1, 'update_time' => $now->format('Y-m-d H:i:s')];
        if ($identity) {
            Db::table('sm_server_route_pool')->where('id', $identity['id'])->update($identityData);
        } else {
            Db::table('sm_server_route_pool')->insert($identityData + ['deployment_id' => $config['deployment_id'], 'route_pool_id' => $config['route_pool_id'], 'create_time' => $now->format('Y-m-d H:i:s')]);
        }

        return [$poolVersion, $routes];
    }

    private function publishBinding(array $organization, array $config, string $clientFamily, int $poolVersion, array $routes, RoutingSnapshotSigner $signer, int $auditId, DateTimeImmutable $now): array
    {
        $current = Db::table('sm_organization_route_policy')->where('deployment_id', $config['deployment_id'])->where('organization', $config['organization'])->where('client_family', $clientFamily)->lock(true)->find();
        $routingVersion = (int) ($current['current_routing_version'] ?? 0) + 1;
        $primary = $routes[0]['route_id'];
        $backups = array_values(array_map(static fn (array $route): string => $route['route_id'], array_slice($routes, 1)));
        $policy = $this->policy($config['mode'], $primary, $backups);
        $issued = $now->format(DATE_ATOM);
        $serverInfo = [
            'schema_version' => 2,
            'route_pool_id' => $config['route_pool_id'],
            'route_pool_version' => $poolVersion,
            'routing_version' => $routingVersion,
            'server_time' => $issued,
            'issued_at' => $issued,
            'expires_at' => $now->modify('+' . max(300, (int) env('ROUTING_SNAPSHOT_TTL_SECONDS', 86400)) . ' seconds')->format(DATE_ATOM),
            'stale_if_error_until' => $now->modify('+' . max(600, (int) env('ROUTING_STALE_TTL_SECONDS', 172800)) . ' seconds')->format(DATE_ATOM),
            'policy' => $policy,
            'routes' => array_map(static fn (array $route): array => [
                'route_id' => $route['route_id'], 'route_version' => $route['route_version'], 'name' => $route['name'],
                'priority' => $route['priority'], 'weight' => $route['weight'], 'region' => $route['region'], 'carrier' => $route['carrier'],
                'deployment_id' => $route['deployment_id'], 'endpoints' => $route['endpoints'],
            ], $routes),
        ];
        $signature = $signer->sign([
            'organization' => (int) $organization['id'],
            'deployment_id' => $config['deployment_id'],
            'enterprise_code' => (string) $organization['enterprise_code'],
            'client_family' => $clientFamily,
            'server_info' => $serverInfo,
        ]);
        $policyRow = [
            'route_pool_id' => $config['route_pool_id'], 'pool_version' => $poolVersion, 'mode' => $config['mode'],
            'policy_json' => $this->json($policy), 'current_routing_version' => $routingVersion, 'update_time' => $now->format('Y-m-d H:i:s'),
        ];
        if ($current) {
            Db::table('sm_organization_route_policy')->where('id', $current['id'])->update($policyRow);
        } else {
            Db::table('sm_organization_route_policy')->insert($policyRow + [
                'deployment_id' => $config['deployment_id'], 'organization' => $config['organization'], 'client_family' => $clientFamily,
                'create_time' => $now->format('Y-m-d H:i:s'),
            ]);
        }
        Db::table('sm_organization_route_publish')->insert([
            'deployment_id' => $config['deployment_id'], 'organization' => $config['organization'], 'client_family' => $clientFamily,
            'routing_version' => $routingVersion, 'route_pool_id' => $config['route_pool_id'], 'pool_version' => $poolVersion,
            'snapshot_json' => $this->json($serverInfo), 'signature_kid' => $signature['kid'], 'signature' => $signature['value'],
            'publish_time' => $now->format('Y-m-d H:i:s'), 'audit_id' => $auditId,
        ]);

        return ['routing_version' => $routingVersion, 'signature_kid' => $signature['kid']];
    }

    private function policy(string $mode, string $primary, array $backups): array
    {
        return [
            'mode' => $mode, 'route_bundle_required' => true, 'failover_scope' => 'service',
            'primary_route_id' => $primary, 'backup_route_ids' => $backups,
            'sticky_ttl_seconds' => 86400, 'switch_cooldown_seconds' => 30, 'failback_min_stable_seconds' => 300,
            'failures_before_open' => 1, 'circuit_open_seconds' => 15, 'probe_timeout_ms' => 1000,
            'connect_timeout_ms' => 5000, 'max_parallel_probes' => 1, 'allow_user_choice' => false,
            'manual_selection_ttl_seconds' => 3600, 'hash_version' => 1,
        ];
    }

    private function clientFamily(string $value): string
    {
        if (!in_array($value, self::CLIENT_FAMILIES, true)) {
            throw new ApiException('client_family 只允许 web、app 或 desktop。', 422);
        }
        return $value;
    }

    private function slug(string $value, string $field): string
    {
        $value = strtolower(trim($value));
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $value)) {
            throw new ApiException($field . ' 格式无效。', 422);
        }
        return $value;
    }

    private function requiredText(string $value, string $field, int $max): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $max) {
            throw new ApiException($field . '格式无效。', 422);
        }
        return $value;
    }

    private function optionalText(string $value, string $field, int $max): string
    {
        $value = trim($value);
        if (mb_strlen($value) > $max) {
            throw new ApiException($field . '长度无效。', 422);
        }
        return $value;
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function decodeObject(string $value, string $field): array
    {
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new ApiException($field . '格式无效。', 50302);
        }
        return $decoded;
    }
}
