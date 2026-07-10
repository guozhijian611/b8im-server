<?php
// +----------------------------------------------------------------------
// | saiadmin [ saiadmin快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\middleware;

use ReflectionClass;
use ReflectionMethod;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;
use plugin\saimulti\service\Permission;
use plugin\saimulti\service\ModuleRequired;
use plugin\saimulti\service\TenantContext;
use plugin\saimulti\service\module\ModuleServiceFactory;
use plugin\saimulti\app\cache\TenantAuthCache;
use plugin\saimulti\exception\SystemException;

/**
 * 权限检查中间件
 */
class CheckTenantAuth implements MiddlewareInterface
{
    public function process(Request $request, callable $handler) : Response
    {
        $controller = $request->controller;
        $action = $request->action;

        // 通过反射获取控制器哪些方法不需要登录
        $controllerClass = new ReflectionClass($controller);
        $noNeedLogin = $controllerClass->getDefaultProperties()['noNeedLogin'] ?? [];
        $moduleRequired = $this->getModuleRequired($controller, $action, $controllerClass);

        // 不登录访问，无需权限验证
        if (in_array($action, $noNeedLogin)) {
            if ($moduleRequired !== null) {
                $this->assertModuleRequired($moduleRequired, TenantContext::requestOrganization());
            }
            return $handler($request);
        }

        // 登录信息
        $token = $request->header('check_saimulti_tenant');
        if (!is_array($token)) {
            throw new SystemException('权限不足，无法访问或操作');
        }

        // organization 只从登录凭证取得，不使用 body/query 中的租户参数。
        if ($moduleRequired !== null) {
            $this->assertModuleRequired(
                $moduleRequired,
                TenantContext::parseOrganization($token['organization'] ?? null),
            );
        }

        // 系统默认超级管理员，无需权限验证
        if ((int) $token['user_type'] === 100) {
            return $handler($request);
        }

        // 获取接口权限属性 (使用缓存类)
        $permissions = $this->getPermissions($controller, $action);

        if (!empty($permissions) && !empty($permissions['slug'])) {
            // 用户权限缓存
            $auth = TenantAuthCache::getUserAuth($token['id']);

            if (!$this->checkPermissions($permissions, $auth)) {
                throw new SystemException('权限不足，无法访问或操作');
            }
        }
        return $handler($request);
    }

    /**
     * 获取接口权限属性
     * @return array ['title' => '权限名称', 'slug' => '权限标识']
    */
    public function getPermissions($controller, $action): array
    {
        $data = [];
        if (method_exists($controller, $action)) {
            $refMethod = new ReflectionMethod($controller, $action);
            $attributes = $refMethod->getAttributes(Permission::class);
            if (!empty($attributes)) {
                $attr = $attributes[0]->newInstance();
                $data = [
                    'title' => $attr->getTitle(),
                    'slug'  => $attr->getSlug(),
                ];
            }
        }
        return $data;
    }

    /**
     * 检查权限
     */
    private function checkPermissions(array $attr, array $userPermissions): bool
    {
        // 直接对比 slug
        return in_array($attr['slug'], $userPermissions);
    }

    private function getModuleRequired($controller, $action, ReflectionClass $controllerClass): ?ModuleRequired
    {
        if (method_exists($controller, $action)) {
            $attributes = (new ReflectionMethod($controller, $action))->getAttributes(ModuleRequired::class);
            if ($attributes !== []) {
                return $attributes[0]->newInstance();
            }
        }

        $attributes = $controllerClass->getAttributes(ModuleRequired::class);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    private function assertModuleRequired(ModuleRequired $required, int $organization): void
    {
        ModuleServiceFactory::access()->assertAvailable(
            $organization,
            $required->moduleKey(),
            $required->platform(),
            $required->capability(),
        );
    }

}
