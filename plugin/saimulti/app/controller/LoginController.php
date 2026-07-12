<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\controller;

use plugin\saimulti\app\logic\admin\AdminLogic;
use plugin\saimulti\app\logic\system\SystemOrganizationLogic;
use plugin\saimulti\app\logic\tenant\UserLogic;
use plugin\saimulti\basic\OpenController;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\AppInfoRateLimiter;
use plugin\saimulti\service\OrganizationDiscovery;
use plugin\saimulti\utils\Captcha;
use support\Log;
use support\Request;
use support\Response;

/**
 * 登录控制器
 */
class LoginController extends OpenController
{
    /**
     * 获取验证码
     */
    public function captcha(Request $request) : Response
    {
        $captcha = new Captcha();
        $result = $captcha->imageCaptcha();
        if ($result['result'] !== 1) {
            return $this->fail($result['message']);
        }
        return $this->success($result);
    }

    /**
     * 管理中台登录
     * @param Request $request
     * @return Response
     */
    public function adminLogin(Request $request): Response
    {
        $username = $request->post('username');
        $password = $request->post('password');
        $type = $request->post('type', 'pc');

        $code = $request->post('code', '');
        $uuid = $request->post('uuid', '');
        $captcha = new Captcha();
        if (!$captcha->checkCaptcha($uuid, $code)) {
            return $this->fail('验证码错误');
        }
        $logic = new AdminLogic();
        $data = $logic->login($username, $password, $type);
        return $this->success($data);
    }

    /**
     * 租户登录
     * @param Request $request
     * @return Response
     */
    public function tenantLogin(Request $request): Response
    {
        $username = $request->post('username');
        $password = $request->post('password');
        $type = $request->post('type', 'pc');

        $code = $request->post('code', '');
        $uuid = $request->post('uuid', '');
        $captcha = new Captcha();
        if (!$captcha->checkCaptcha($uuid, $code)) {
            return $this->fail('验证码错误');
        }
        $logic = new UserLogic();
        $data = $logic->login($username, $password, $type);
        return $this->success($data);
    }

    /**
     * 应用信息
     * @param Request $request
     * @return Response
     */
    public function appInfo(Request $request): Response
    {
        $headers = $this->appInfoCorsHeaders();

        try {
            $limit = max(1, (int) env('APP_INFO_RATE_LIMIT_PER_MINUTE', 60));
            $rateLimiter = new AppInfoRateLimiter($limit);
            $clientIp = $request->getRealIp();
            $rateLimiter->assertAllowed('ip:' . $clientIp);

            $deviceId = trim((string) $request->header('X-Device-Id', ''));
            if ($deviceId !== '') {
                $rateLimiter->assertAllowed('device:' . substr($deviceId, 0, 128));
            }

            [$mode, $identifier] = OrganizationDiscovery::requestIdentifier(
                $request->input('mode', ''),
                $request->input('enterprise_code', ''),
                $request->input('domain', ''),
            );

            $logic = new SystemOrganizationLogic();
            $data = $logic->appInfo(
                $identifier,
                $mode,
                (string) $request->input('client_family', ''),
            );
            $etag = '"' . hash(
                'sha256',
                json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ) . '"';
            $headers += [
                'Cache-Control' => 'public, max-age=60, stale-while-revalidate=300',
                'ETag' => $etag,
            ];

            Log::info('appInfo discovery succeeded', [
                'mode' => $mode,
                'identifier_hash' => hash('sha256', $identifier),
                'organization' => $data['organization'],
                'deployment_id' => $data['deployment_id'],
                'client_family' => $data['client_family'],
                'client_ip' => $clientIp,
            ]);

            if (trim((string) $request->header('If-None-Match', '')) === $etag) {
                return response('', 304, $headers);
            }

            return $this->success($data)->withHeaders($headers);
        } catch (ApiException $exception) {
            $isRateLimited = $exception->getCode() === AppInfoRateLimiter::RATE_LIMITED;
            Log::log($isRateLimited ? 'warning' : 'info', 'appInfo discovery rejected', [
                'code' => $exception->getCode(),
                'client_ip' => $request->getRealIp(),
            ]);

            $response = json([
                'code' => $exception->getCode(),
                'message' => $exception->getMessage(),
            ])->withHeaders($headers + ['Cache-Control' => 'no-store']);

            return $response->withStatus(match ($exception->getCode()) {
                AppInfoRateLimiter::RATE_LIMITED => 429,
                AppInfoRateLimiter::UNAVAILABLE => 503,
                OrganizationDiscovery::INVALID_REQUEST => 422,
                OrganizationDiscovery::UNAVAILABLE => 404,
                default => 400,
            });
        }
    }

    public function appInfoOptions(Request $request): Response
    {
        return response('', 204, $this->appInfoCorsHeaders());
    }

    /**
     * Public, credential-free discovery CORS. Authentication CORS is configured
     * separately against the deployment's registered web_server_url.
     *
     * @return array<string, string>
     */
    private function appInfoCorsHeaders(): array
    {
        return [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, X-Device-Id, If-None-Match',
            'Access-Control-Max-Age' => '600',
        ];
    }
}
