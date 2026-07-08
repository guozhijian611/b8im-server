<?php
// +----------------------------------------------------------------------
// | saiadmin [ saiadmin快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
use Webman\Route;
use Tinywan\Jwt\JwtToken;

if (!function_exists('getAdminInfo')) {
    /**
     * 获取中台管理员
     */
    function getAdminInfo(): bool|array
    {
        if (!request()) {
            return false;
        }
        try {
            $token = JwtToken::getExtend();
            if ($token['plat'] !== 'admin') {
                return false;
            }
        } catch (\Throwable $e) {
            return false;
        }
        return $token;
    }
}

if (!function_exists('getTenantInfo')) {
    /**
     * 获取租户管理员
     */
    function getTenantInfo(): bool|array
    {
        if (!request()) {
            return false;
        }
        try {
            $token = JwtToken::getExtend();
            if ($token['plat'] !== 'tenant') {
                return false;
            }
        } catch (\Throwable $e) {
            return false;
        }
        return $token;
    }
}

if (!function_exists('saiMultiRoute')) {
    /**
     * 快速注册路由[index|save|update|read|destroy|import|export]
     * @param string $name
     * @param string $controller
     * @return void
     */
    function saiMultiRoute(string $name, string $controller): void
    {
        $name = trim($name, '/');
        if (method_exists($controller, 'index')) Route::get("/$name/index", [$controller, 'index']);
        if (method_exists($controller, 'save')) Route::post("/$name/save", [$controller, 'save']);
        if (method_exists($controller, 'update')) Route::put("/$name/update", [$controller, 'update']);
        if (method_exists($controller, 'read')) Route::get("/$name/read", [$controller, 'read']);
        if (method_exists($controller, 'destroy')) Route::delete("/$name/destroy", [$controller, 'destroy']);
        if (method_exists($controller, 'import')) Route::post("/$name/import", [$controller, 'import']);
        if (method_exists($controller, 'export')) Route::post("/$name/export", [$controller, 'export']);
    }
}

if (!function_exists('formatBytes')) {
    /**
     * 根据字节计算大小
     * @param $bytes
     * @return string
     */
    function formatBytes($bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        for ($i = 0; $bytes > 1024; $i++) {
            $bytes /= 1024;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
