<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

use B8im\ModuleSdk\Manifest\Manifest;
use Closure;
use plugin\saimulti\exception\ApiException;
use support\think\Db;

final class ClientConfigProjectionService
{
    /** @var Closure(): list<Manifest> */
    private readonly Closure $installedManifestProvider;

    /** @var Closure(int): int */
    private readonly Closure $versionProvider;

    /** @var Closure(int, string): ?array */
    private readonly Closure $tenantConfigProvider;

    public function __construct(
        private readonly ModuleAccessService $access,
        ?callable $installedManifestProvider = null,
        ?callable $versionProvider = null,
        ?callable $tenantConfigProvider = null,
    ) {
        $this->installedManifestProvider = $installedManifestProvider === null
            ? fn (): array => $this->installedManifests()
            : Closure::fromCallable($installedManifestProvider);
        $this->versionProvider = $versionProvider === null
            ? fn (int $organization): int => $this->organizationConfigVersion($organization)
            : Closure::fromCallable($versionProvider);
        $this->tenantConfigProvider = $tenantConfigProvider === null
            ? fn (int $organization, string $moduleKey): ?array => $this->storedTenantConfig($organization, $moduleKey)
            : Closure::fromCallable($tenantConfigProvider);
    }

    /**
     * 只接受认证层已解析的 organization。本服务不读请求头、query 或 body。
     *
     * @return array{version: int, organization: int, deployment_id: string, features: array<string, bool>, modules: list<array{module_key: string, version: string, available: true, capabilities: list<string>, permissions: list<string>, config: array<string, mixed>}>, tabbar: list<array{module_key: string, title: string}>}
     */
    public function project(int $organization, string $deploymentId, string $clientFamily): array
    {
        if ($organization <= 0) {
            throw new ApiException('认证 organization 无效。', 401);
        }
        $deploymentId = trim($deploymentId);
        if ($deploymentId === '') {
            throw new ApiException('认证 deployment_id 缺失。', 401);
        }

        $platforms = match ($clientFamily) {
            'web' => ['web'],
            'app' => ['android', 'ios', 'harmonyos'],
            'desktop' => ['desktop'],
            default => throw new ApiException('client_family 只允许 web/app/desktop。', 422),
        };

        $features = [];
        $modules = [];
        $tabbar = [];
        foreach (($this->installedManifestProvider)() as $manifest) {
            $implemented = $this->implementedPlatforms($organization, $manifest, $platforms);
            if ($implemented === []) {
                continue;
            }

            $capabilities = [];
            foreach ($implemented as $platform) {
                foreach ($manifest->capabilities()[$platform] ?? [] as $capability) {
                    if ($this->access->isAvailable($organization, $manifest->moduleKey(), $platform, $capability)) {
                        $capabilities[] = $capability;
                    }
                }
            }
            $capabilities = array_values(array_unique($capabilities));
            if ($capabilities === []) {
                continue;
            }
            $features[$manifest->moduleKey()] = true;

            $permissions = [];
            foreach ($manifest->routes() as $route) {
                if (!in_array($route['platform'], $implemented, true) || empty($route['permission'])) {
                    continue;
                }
                $capability = $route['capability'] ?? null;
                if ($capability !== null
                    && !$this->access->isAvailable($organization, $manifest->moduleKey(), $route['platform'], $capability)) {
                    continue;
                }
                $permissions[] = $route['permission'];
            }

            $modules[] = [
                'module_key' => $manifest->moduleKey(),
                'version' => $manifest->version(),
                'available' => true,
                'capabilities' => $capabilities,
                'permissions' => array_values(array_unique($permissions)),
                'config' => $this->publicTenantConfig($organization, $manifest),
            ];

            foreach ($manifest->menus() as $menu) {
                if ($menu['platform'] !== 'web'
                    || !in_array('web', $implemented, true)
                    || $menu['type'] !== 'menu') {
                    continue;
                }
                if (!empty($menu['permission']) && !in_array($menu['permission'], $permissions, true)) {
                    continue;
                }
                $tabbar[] = [
                    'module_key' => $manifest->moduleKey(),
                    'title' => $menu['name'],
                    '_sort' => (int) $menu['sort'],
                ];
            }
        }

        ksort($features);
        usort($modules, static fn (array $left, array $right): int => $left['module_key'] <=> $right['module_key']);
        usort($tabbar, static fn (array $left, array $right): int => $left['_sort'] <=> $right['_sort']);
        $tabbar = array_map(static function (array $item): array {
            unset($item['_sort']);
            return $item;
        }, $tabbar);
        $version = (int) ($this->versionProvider)($organization);
        if ($version <= 0) {
            throw new ApiException('客户端配置 version 必须为正整数。', 500);
        }

        return [
            'version' => $version,
            'organization' => $organization,
            'deployment_id' => $deploymentId,
            'features' => $features,
            'modules' => $modules,
            'tabbar' => $tabbar,
        ];
    }

    /**
     * @param list<string> $platforms
     * @return list<string>
     */
    private function implementedPlatforms(int $organization, Manifest $manifest, array $platforms): array
    {
        $implemented = [];
        foreach ($platforms as $platform) {
            if (!in_array($platform, $manifest->platforms(), true)) {
                continue;
            }
            $capabilities = $manifest->capabilities()[$platform] ?? [];
            if ($capabilities === []) {
                continue;
            }
            foreach ($capabilities as $capability) {
                if ($this->access->isAvailable($organization, $manifest->moduleKey(), $platform, $capability)) {
                    $implemented[] = $platform;
                    break;
                }
            }
        }

        return $implemented;
    }

    /** @return list<Manifest> */
    private function installedManifests(): array
    {
        $rows = Db::table('sm_module')
            ->where('status', \B8im\ModuleSdk\State\SystemModuleStatus::ENABLED->value)
            ->whereNull('delete_time')
            ->order('module_key', 'asc')
            ->column('manifest_json');
        $manifests = [];
        foreach ($rows as $json) {
            $data = json_decode((string) $json, true);
            if (is_array($data)) {
                $manifests[] = new Manifest($data);
            }
        }

        return $manifests;
    }

    private function organizationConfigVersion(int $organization): int
    {
        $version = Db::table('sm_system_organization')
            ->where('id', $organization)
            ->where('status', 1)
            ->whereNull('delete_time')
            ->value('config_version');

        return (int) $version;
    }

    /** @return array<string, mixed> */
    private function publicTenantConfig(int $organization, Manifest $manifest): array
    {
        $definitions = array_values(array_filter(
            $manifest->config(),
            static fn (array $definition): bool => ($definition['scope'] ?? null) === 'tenant'
                && ($definition['sensitive'] ?? false) === false
                && ($definition['type'] ?? null) !== 'secret',
        ));
        if ($definitions === []) {
            return [];
        }

        $stored = ($this->tenantConfigProvider)($organization, $manifest->moduleKey()) ?? [];
        if (!is_array($stored) || array_is_list($stored)) {
            throw new ApiException('租户模块配置格式无效。', 500);
        }

        $config = [];
        foreach ($definitions as $definition) {
            $key = (string) $definition['key'];
            $config[$key] = array_key_exists($key, $stored)
                ? $stored[$key]
                : ($definition['default'] ?? null);
        }

        return $config;
    }

    /** @return array<string, mixed>|null */
    private function storedTenantConfig(int $organization, string $moduleKey): ?array
    {
        $row = Db::table('sm_tenant_module_config')
            ->where('organization', $organization)
            ->where('module_key', $moduleKey)
            ->whereNull('delete_time')
            ->find();
        if (!$row) {
            return null;
        }

        $stored = json_decode((string) $row['config_json'], true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($stored) || array_is_list($stored)) {
            throw new ApiException('租户模块配置格式无效。', 500);
        }

        return $stored;
    }
}
