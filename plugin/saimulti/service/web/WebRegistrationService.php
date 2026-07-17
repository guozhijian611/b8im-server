<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

use Closure;
use plugin\saimulti\app\validate\web\WebRegisterValidate;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\imUser\ImUserManagementService;
use plugin\saimulti\utils\Captcha;

final class WebRegistrationService
{
    private Closure $captchaVerifier;

    public function __construct(
        private readonly ?TenantAccountPolicyService $policies = null,
        private readonly ?ImUserManagementService $users = null,
        private readonly ?WebAccessIssuerInterface $access = null,
        ?Closure $captchaVerifier = null,
    ) {
        $this->captchaVerifier = $captchaVerifier ?? static fn (string $uuid, string $code): bool => (new Captcha())->checkCaptcha($uuid, $code);
    }

    /** @param array<string,mixed> $organization @return array<string,mixed> */
    public function accountPolicy(array $organization): array
    {
        return ($this->policies ?? new TenantAccountPolicyService())->publicPolicy($this->organization($organization));
    }

    /** @param array<string,mixed> $organization @return array<string,mixed> */
    public function register(array $organization, array $input, string $clientIp): array
    {
        $validate = new WebRegisterValidate();
        if (!$validate->scene('register')->check($input)) {
            throw new ApiException((string) $validate->getError(), 422);
        }
        if (!(bool) ($this->captchaVerifier)((string) $input['uuid'], (string) $input['code'])) {
            throw new ApiException('验证码错误。', 422);
        }
        $organizationId = $this->organization($organization);
        $policies = $this->policies ?? new TenantAccountPolicyService();
        $access = $this->access ?? new WebImAuthService();

        return ($this->users ?? new ImUserManagementService())->register(
            $organizationId,
            [
                'account' => (string) $input['account'],
                'password' => (string) $input['password'],
                'nickname' => (string) $input['nickname'],
                'status' => 1,
            ],
            static function (int $lockedOrganization) use ($policies): void {
                $policies->lockOpenRegistration($lockedOrganization);
            },
            static function (array $user, string $provisionedAt) use ($organization, $input, $clientIp, $access): array {
                return $access->issueAccessForUser(
                    $organization,
                    $user,
                    (string) $input['device_id'],
                    'web',
                    'browser',
                    $clientIp,
                    'register',
                );
            },
        );
    }

    /** @param array<string,mixed> $organization */
    private function organization(array $organization): int
    {
        $id = (int) ($organization['id'] ?? 0);
        if ($id <= 0) {
            throw new ApiException('当前应用不可用。', 41003);
        }

        return $id;
    }
}
