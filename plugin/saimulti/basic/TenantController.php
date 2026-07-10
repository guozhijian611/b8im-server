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
use plugin\saimulti\service\TenantContext;

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
            if (empty($this->tenantInfo) || (int) $this->tenantInfo['status'] !== 1) {
                throw new ApiException('租户管理员已停用或不存在', 401);
            }
            $this->organization = TenantContext::parseOrganization($result['organization']);
            if ((int) $this->tenantInfo['organization'] !== $this->organization) {
                throw new ApiException('登录凭证与用户归属不一致', TenantContext::MISMATCH);
            }

            $organLogic = new SystemOrganizationLogic();
            $organization = $organLogic->where('id', $this->organization)->findOrEmpty();
            if ($organization->isEmpty()) {
                throw new ApiException('机构信息读取失败,请检查');
            }
            $this->organInfo = $organization->toArray();
            if ((int) $this->organInfo['status'] !== 1) {
                throw new ApiException('当前租户已停用', 41003);
            }
        }

        // 检查站点信息
        $this->checkSite($isLogin);
    }

    protected function checkSite($isLogin)
    {
        $organization = TenantContext::requestOrganization();
        if ($isLogin && $organization !== $this->organization) {
            throw new ApiException('App-Id 与登录租户不一致', TenantContext::MISMATCH);
        }
    }
}
