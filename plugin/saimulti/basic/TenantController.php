<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\basic;

use plugin\saimulti\exception\ApiException;
use plugin\saimulti\app\cache\TenantUserCache;
use plugin\saimulti\app\logic\system\SystemOrganizationLogic;

/**
 * 租户控制器基类
 * @package plugin\saimulti\basic
 */
class TenantController extends OpenController
{
    /**
     * 当前登陆管理员信息
     */
    protected $tenantInfo;

    /**
     * 当前登陆管理员ID
     */
    protected int $tenantId;

    /**
     * 当前登陆管理员账号
     */
    protected string $tenantName;

    /**
     * 机构编号
     */
    protected $organization = 0;

    /**
     * 机构信息
     */
    protected $organInfo;

    /**
     * 初始化
     * @return void
     */
    public function init(): void
    {

        // 登录模式赋值
        $isLogin = request()->header('check_saimulti_login', false);
        if ($isLogin) {
            $result = request()->header('check_saimulti_tenant');
            $this->tenantId = $result['id'];
            $this->tenantName = $result['username'];
            $this->tenantInfo = TenantUserCache::getUserInfo($result['id']);
            $this->organization = $this->tenantInfo['organization'];

            $organLogic = new SystemOrganizationLogic();
            $organization = $organLogic->where('id', $this->organization)->findOrEmpty();
            if ($organization->isEmpty()) {
                throw new ApiException('机构信息读取失败,请检查');
            }
            $this->organInfo = $organization->toArray();
        }

        // 检查站点信息
        $this->checkSite($isLogin);
    }

    protected function checkSite($isLogin)
    {
        $organization = request()->header('App-Id');
        if (empty($organization)) {
            throw new ApiException('站点信息读取失败,请重新登录');
        }
        if ($isLogin && $organization != $this->tenantInfo['organization']) {
            throw new ApiException('站点信息校验失败,请重新登录');
        }
    }
}
