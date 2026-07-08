<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\basic;

use plugin\saimulti\app\cache\AdminUserCache;
use plugin\saimulti\app\cache\TenantUserCache;;

/**
 * 基础逻辑层基类
 * @package plugin\saimulti\basic
 */
class BaseLogic extends AbstractLogic
{
    /**
     * 构造方法
     */
    public function __construct()
    {
        // 初始化中台端用户信息
        $adminInfo = getAdminInfo();
        $adminInfo && $this->adminInfo = $this->adminInfo = AdminUserCache::getUserInfo($adminInfo['id']);

        // 初始化租户端用户信息
        $tenantInfo = getTenantInfo();
        $tenantInfo && $this->tenantInfo = $this->tenantInfo = TenantUserCache::getUserInfo($tenantInfo['id']);
    }

}
