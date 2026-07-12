<?php

declare(strict_types=1);

namespace plugin\saimulti\service;

use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\routing\RoutingConfigService;
use support\think\Db;
use Webman\Http\Request;

final class WebOrganizationResolver
{
    /** @return array<string, mixed> */
    public function fromRequest(Request $request): array
    {
        return $this->resolve(TenantContext::parseOrganization($request->header('App-Id')));
    }

    /** @return array<string, mixed> */
    public function resolve(int $organization): array
    {
        $row = Db::table('sm_system_organization')
            ->where('id', $organization)
            ->where('status', 1)
            ->whereNull('delete_time')
            ->find();
        if (!$row) {
            throw new ApiException('当前应用不可用。', 41003);
        }

        $deploymentId = OrganizationDiscovery::assertDeploymentId((string) ($row['deployment_id'] ?? ''));
        $localDeploymentId = OrganizationDiscovery::assertDeploymentId(
            (string) env('DEPLOYMENT_ID', 'b8im-local'),
        );
        if (!hash_equals($localDeploymentId, $deploymentId)) {
            throw new ApiException('请求目标与当前部署不一致。', 42101);
        }

        return $row;
    }

    public function registeredWebOrigin(array $organization): string
    {
        $routing = (new RoutingConfigService())->read((int) ($organization['id'] ?? 0), 'web');
        $routes = (array) ($routing['server_info']['routes'] ?? []);
        $primaryRouteId = (string) ($routing['server_info']['policy']['primary_route_id'] ?? '');
        $primary = current(array_filter(
            $routes,
            static fn (array $route): bool => ($route['route_id'] ?? null) === $primaryRouteId,
        ));
        if (!is_array($primary)) {
            throw new ApiException('Web 主线路配置无效。', 50301);
        }
        $url = OrganizationDiscovery::assertPublicUrl(
            (string) ($primary['endpoints']['web_server_url'] ?? ''), ['https', 'http'],
            (bool) config('app.debug', false),
        );

        return self::originFromUrl($url);
    }

    public function assertRegisteredOrigin(string $origin): string
    {
        $origin = strtolower(trim($origin));
        if ($origin === '') {
            throw new ApiException('Web 预检请求缺少 Origin。', 403);
        }
        $deploymentId = OrganizationDiscovery::assertDeploymentId(
            (string) env('DEPLOYMENT_ID', 'b8im-local'),
        );
        $rows = Db::table('sm_system_organization')
            ->where('deployment_id', $deploymentId)
            ->where('status', 1)
            ->whereNull('delete_time')
            ->field(['id'])
            ->select()
            ->toArray();
        foreach ($rows as $row) {
            try {
                $routing = (new RoutingConfigService())->read((int) $row['id'], 'web');
                $routes = (array) ($routing['server_info']['routes'] ?? []);
            } catch (ApiException) {
                continue;
            }
            foreach ($routes as $route) {
                try {
                    $registered = self::originFromUrl(OrganizationDiscovery::assertPublicUrl(
                        (string) ($route['endpoints']['web_server_url'] ?? ''), ['https', 'http'],
                        (bool) config('app.debug', false),
                    ));
                } catch (ApiException) {
                    continue;
                }
                if (hash_equals($registered, $origin)) {
                    return $registered;
                }
            }
        }

        throw new ApiException('当前 Origin 未在目标部署登记。', 403);
    }

    public static function originFromUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new ApiException('Web 服务地址配置无效。', 50301);
        }
        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        $origin = $scheme . '://' . $host;
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            $port = null;
        }
        if ($port !== null) {
            $origin .= ':' . $port;
        }

        return $origin;
    }
}
