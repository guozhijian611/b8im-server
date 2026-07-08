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

        // 不登录访问，无需权限验证
        if (in_array($action, $noNeedLogin)) {
            return $handler($request);
        }

        // 登录信息
        $token = getTenantInfo();
        if ($token === false) {
            throw new SystemException('权限不足，无法访问或操作');
        }

        // 系统默认超级管理员，无需权限验证
        if ($token['user_type'] == 100) {
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

}
