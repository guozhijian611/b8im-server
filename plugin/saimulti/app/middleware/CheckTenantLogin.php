<?php
// +----------------------------------------------------------------------
// | saiadmin [ saiadmin快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\middleware;

use ReflectionClass;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;
use Tinywan\Jwt\JwtToken;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\TenantContext;
use think\facade\Db;

/**
 * 租户登录检查中间件
 */
class CheckTenantLogin implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        // 通过反射获取控制器哪些方法不需要登录
        $controller = new ReflectionClass($request->controller);
        $noNeedLogin = $controller->getDefaultProperties()['noNeedLogin'] ?? [];

        // 访问的方法需要登录
        if (!in_array($request->action, $noNeedLogin)) {
            try {
                $token = JwtToken::getExtend();
            } catch (\Throwable $e) {
                throw new ApiException('您的登录凭证错误或者已过期，请重新登录', 401);
            }
            if (($token['plat'] ?? null) !== 'tenant' || ($token['aud'] ?? null) !== 'tenant-api') {
                throw new ApiException('登录凭证不适用于租户 API', 401);
            }
            if (!isset($token['id'], $token['organization'])) {
                throw new ApiException('登录凭证缺少租户上下文', 401);
            }

            $organization = TenantContext::parseOrganization($token['organization']);
            TenantContext::assertRequestMatches($organization);

            $tenant = Db::table('sm_tenant_user')
                ->where('id', (int) $token['id'])
                ->where('organization', $organization)
                ->whereNull('delete_time')
                ->find();
            if (!$tenant || (int) $tenant['status'] !== 1) {
                throw new ApiException('租户管理员已停用或不存在', 401);
            }

            $organizationInfo = Db::table('sm_system_organization')
                ->where('id', $organization)
                ->whereNull('delete_time')
                ->find();
            if (!$organizationInfo || (int) $organizationInfo['status'] !== 1) {
                throw new ApiException('当前租户已停用', 41003);
            }

            // 权限中间件只使用数据库当前值，不使用 token 中可能已过时的 user_type。
            $token['organization'] = $organization;
            $token['username'] = $tenant['username'];
            $token['user_type'] = $tenant['user_type'];
            $request->setHeader('check_saimulti_login', true);
            $request->setHeader('check_saimulti_tenant', $token);
        }
        return $handler($request);
    }
}
