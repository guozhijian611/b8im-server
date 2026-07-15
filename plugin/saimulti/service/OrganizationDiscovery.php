<?php

declare(strict_types=1);

namespace plugin\saimulti\service;

use DateTimeImmutable;
use DateTimeZone;
use plugin\saimulti\app\model\system\SystemOrganization;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\routing\RoutingConfigService;
use plugin\saimulti\service\trace\Telemetry;
use support\Log;

/**
 * Resolves the public enterprise/domain bootstrap contract.
 *
 * Entry identifiers select a tenant only. The returned organization is the
 * target deployment's authoritative runtime organization identifier.
 */
final class OrganizationDiscovery
{
    public const MODE_ENTERPRISE_CODE = 'enterprise_code';
    public const MODE_DOMAIN = 'domain';
    public const INVALID_REQUEST = 42201;
    public const UNAVAILABLE = 40401;
    public const INVALID_CONFIGURATION = 50301;

    private bool $allowInsecureUrls;

    public function __construct(?bool $allowInsecureUrls = null)
    {
        $this->allowInsecureUrls = $allowInsecureUrls
            ?? (bool) config('app.debug', false);
    }

    /**
     * @return array{0: string, 1: string}
     */
    public static function requestIdentifier(
        mixed $mode,
        mixed $enterpriseCode,
        mixed $domain,
    ): array {
        $mode = is_string($mode) ? trim($mode) : '';
        $enterpriseCode = is_string($enterpriseCode) ? trim($enterpriseCode) : '';
        $domain = is_string($domain) ? trim($domain) : '';

        if ($mode === self::MODE_DOMAIN) {
            if ($domain === '' || $enterpriseCode !== '') {
                throw new ApiException('入口参数无效', self::INVALID_REQUEST);
            }

            return [self::MODE_DOMAIN, self::normalizeDomain($domain)];
        }

        if ($mode !== '' && $mode !== self::MODE_ENTERPRISE_CODE) {
            throw new ApiException('入口参数无效', self::INVALID_REQUEST);
        }

        if ($enterpriseCode === '' || $domain !== '') {
            throw new ApiException('入口参数无效', self::INVALID_REQUEST);
        }

        return [self::MODE_ENTERPRISE_CODE, self::normalizeEnterpriseCode($enterpriseCode)];
    }

    public static function normalizeEnterpriseCode(string $enterpriseCode): string
    {
        $enterpriseCode = strtolower(trim($enterpriseCode));
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $enterpriseCode)) {
            throw new ApiException('企业码格式无效', self::INVALID_REQUEST);
        }

        return $enterpriseCode;
    }

    public static function normalizeDomain(string $domain): string
    {
        $domain = strtolower(rtrim(trim($domain), '.'));
        // www 与 @ 同为网站主入口：统一剥掉前导 www. 再匹配 organization.domain
        if (str_starts_with($domain, 'www.')) {
            $domain = substr($domain, 4);
        }
        if (
            $domain === ''
            || strlen($domain) > 64
            || str_contains($domain, '://')
            || preg_match('/[\s\/@?#]/', $domain)
        ) {
            throw new ApiException('域名格式无效', self::INVALID_REQUEST);
        }

        if (filter_var($domain, FILTER_VALIDATE_IP) !== false || $domain === 'localhost') {
            return $domain;
        }

        if (!preg_match('/^(?=.{1,64}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $domain)) {
            throw new ApiException('域名格式无效', self::INVALID_REQUEST);
        }

        return $domain;
    }

    public static function assertDeploymentId(string $deploymentId): string
    {
        $deploymentId = strtolower(trim($deploymentId));
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $deploymentId)) {
            throw new ApiException('部署标识格式无效', self::INVALID_REQUEST);
        }

        return $deploymentId;
    }

    public static function assertPublicUrl(
        string $url,
        array $allowedSchemes,
        bool $allowInsecure,
        bool $required = true,
    ): string {
        $url = trim($url);
        if ($url === '' && !$required) {
            return '';
        }
        if ($url === '') {
            throw new ApiException('服务地址配置不完整', self::INVALID_CONFIGURATION);
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new ApiException('服务地址配置无效', self::INVALID_CONFIGURATION);
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if (
            $host === ''
            || !in_array($scheme, $allowedSchemes, true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
        ) {
            throw new ApiException('服务地址配置无效', self::INVALID_CONFIGURATION);
        }

        if (!$allowInsecure && in_array($scheme, ['http', 'ws'], true)) {
            throw new ApiException('生产环境服务地址必须使用 TLS', self::INVALID_CONFIGURATION);
        }

        return $url;
    }

    /**
     * @return array<string, mixed>
     */
    public function resolve(string $identifier, string $mode, string $clientFamily): array
    {
        $field = $mode === self::MODE_DOMAIN ? 'domain' : 'enterprise_code';
        $rows = (new SystemOrganization())
            ->where($field, $identifier)
            ->limit(2)
            ->select()
            ->toArray();

        if (count($rows) !== 1 || (int) $rows[0]['status'] !== 1) {
            throw new ApiException('当前应用不可用', self::UNAVAILABLE);
        }

        try {
            return $this->toPublicContract($rows[0], $clientFamily);
        } catch (ApiException $exception) {
            Log::warning('appInfo organization configuration rejected', array_merge([
                'organization' => (int) $rows[0]['id'],
                'reason_code' => $exception->getCode(),
            ], Telemetry::currentLogContext()));

            throw new ApiException('当前应用不可用', self::UNAVAILABLE);
        }
    }

    /**
     * Validates the atomic organization/deployment/server mapping before save.
     *
     * @param array<string, mixed> $data
     */
    public function validatePublicConfiguration(array $data): void
    {
        self::normalizeEnterpriseCode((string) ($data['enterprise_code'] ?? ''));
        self::assertDeploymentId((string) ($data['deployment_id'] ?? ''));

        if (($data['domain'] ?? null) !== null && trim((string) $data['domain']) !== '') {
            self::normalizeDomain((string) $data['domain']);
        }

        foreach (['logo', 'favicon', 'public_security_record_url', 'android_download_url', 'ios_download_url'] as $field) {
            self::assertPublicUrl(
                (string) ($data[$field] ?? ''),
                ['https', 'http'],
                $this->allowInsecureUrls,
                false,
            );
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function toPublicContract(array $row, string $clientFamily): array
    {
        $this->validatePublicConfiguration($row);
        $routing = (new RoutingConfigService())->read((int) $row['id'], $clientFamily);
        if ($routing['deployment_id'] !== (string) $row['deployment_id']) {
            throw new ApiException('线路发布部署与机构不一致', self::INVALID_CONFIGURATION);
        }
        $configVersion = (int) ($row['config_version'] ?? 0);
        if ($configVersion < 1) {
            throw new ApiException('应用公开配置无效', self::INVALID_CONFIGURATION);
        }

        $updatedAt = trim((string) ($row['update_time'] ?? ''));
        if ($updatedAt === '') {
            throw new ApiException('应用公开配置无效', self::INVALID_CONFIGURATION);
        }
        $updatedAt = (new DateTimeImmutable($updatedAt, new DateTimeZone(date_default_timezone_get())))
            ->format(DATE_ATOM);

        return [
            'organization' => (int) $row['id'],
            'deployment_id' => (string) $row['deployment_id'],
            'enterprise_code' => (string) $row['enterprise_code'],
            'client_family' => $clientFamily,
            'config_version' => $configVersion,
            'updated_at' => $updatedAt,
            'site_name' => (string) ($row['title'] ?? ''),
            'logo' => (string) ($row['logo'] ?? ''),
            'favicon' => (string) ($row['favicon'] ?? ''),
            'icp' => (string) ($row['icp'] ?? ''),
            'public_security_record_no' => (string) ($row['public_security_record_no'] ?? ''),
            'public_security_record_url' => (string) ($row['public_security_record_url'] ?? ''),
            'copyright' => (string) ($row['copyright'] ?? ''),
            'download' => [
                'android' => (string) ($row['android_download_url'] ?? ''),
                'ios' => (string) ($row['ios_download_url'] ?? ''),
            ],
            'server_info' => $routing['server_info'],
            'routing_signature' => $routing['routing_signature'],
            'agreements' => [
                'user_agreement' => [
                    'title' => (string) ($row['user_agreement_title'] ?? '用户协议'),
                    'content' => (string) ($row['user_agreement_content'] ?? ''),
                ],
                'privacy_policy' => [
                    'title' => (string) ($row['privacy_policy_title'] ?? '隐私政策'),
                    'content' => (string) ($row['privacy_policy_content'] ?? ''),
                ],
            ],
        ];
    }

}
