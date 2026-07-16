<?php

declare(strict_types=1);

namespace plugin\saimulti\service;

use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\routing\RoutingConfigService;
use support\think\Db;
use Webman\Http\Request;
use plugin\saimulti\service\trace\Telemetry;

final class WebOrganizationResolver
{
    /** @return array<string, mixed> */
    public function fromRequest(Request $request): array
    {
        return Telemetry::inSpan(
            'b8im.tenant.resolve',
            'tenant.resolve',
            ['b8im.context.source' => 'app_id'],
            function () use ($request): array {
                $organization = TenantContext::parseOrganization($request->header('App-Id'));
                \OpenTelemetry\API\Trace\Span::getCurrent()->setAttribute('b8im.organization', $organization);

                return $this->resolve($organization);
            },
        );
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

    public function assertOrganizationOrigin(array $organization, string $origin): string
    {
        $normalizedOrigin = TrustedCorsPolicy::normalizeOrigin($origin);
        if ($normalizedOrigin === null) {
            throw new ApiException('当前 Origin 未在目标部署登记。', 403);
        }

        $routing = (new RoutingConfigService())->read((int) ($organization['id'] ?? 0), 'web');
        foreach ((array) ($routing['server_info']['routes'] ?? []) as $route) {
            try {
                $url = OrganizationDiscovery::assertPublicUrl(
                    (string) ($route['endpoints']['web_server_url'] ?? ''), ['https', 'http'],
                    (bool) config('app.debug', false),
                );
            } catch (ApiException) {
                continue;
            }
            if (self::allowedOriginForUrl($normalizedOrigin, $url) !== null) {
                return $normalizedOrigin;
            }
        }

        throw new ApiException('当前 Origin 未在目标部署登记。', 403);
    }

    public function assertRegisteredOrigin(string $origin): string
    {
        $origin = TrustedCorsPolicy::normalizeOrigin($origin);
        if ($origin === null) {
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
                return $this->assertOrganizationOrigin($row, $origin);
            } catch (ApiException) {
                continue;
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

    public static function allowedOriginForUrl(string $origin, string $url): ?string
    {
        $requestedOrigin = TrustedCorsPolicy::normalizeOrigin($origin);
        if ($requestedOrigin === null) {
            return null;
        }

        $registeredOrigin = self::originFromUrl($url);
        $requested = parse_url($requestedOrigin);
        $registered = parse_url($registeredOrigin);
        if (!is_array($requested) || !is_array($registered)) {
            return null;
        }

        $requestedHost = strtolower((string) ($requested['host'] ?? ''));
        $registeredHost = strtolower((string) ($registered['host'] ?? ''));
        if (str_starts_with($requestedHost, 'www.')) {
            $requestedHost = substr($requestedHost, 4);
        }
        if (str_starts_with($registeredHost, 'www.')) {
            $registeredHost = substr($registeredHost, 4);
        }

        if (
            hash_equals((string) ($registered['scheme'] ?? ''), (string) ($requested['scheme'] ?? ''))
            && hash_equals($registeredHost, $requestedHost)
            && ($registered['port'] ?? null) === ($requested['port'] ?? null)
        ) {
            return $requestedOrigin;
        }

        return null;
    }
}
