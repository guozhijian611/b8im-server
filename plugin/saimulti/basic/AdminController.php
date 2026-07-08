<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\basic;

use plugin\saimulti\app\cache\AdminUserCache;

/**
 * 管理中台控制器基类
 * @package plugin\saimulti\basic
 */
class AdminController extends OpenController
{
    /**
     * 当前登陆管理员信息
     */
    protected $adminInfo;

    /**
     * 当前登陆管理员ID
     */
    protected int $adminId;

    /**
     * 当前登陆管理员账号
     */
    protected string $adminName;

    /**
     * 机构编号
     */
    protected $organization = 0;

    /**
     * 初始化
     * @return void
     */
    public function init():  void
    {
        // 登录模式赋值
        $isLogin = request()->header('check_saimulti_login', false);
        if ($isLogin) {
            $result = request()->header('check_saimulti_admin');
            $this->adminId = $result['id'];
            $this->adminName = $result['username'];
            $this->adminInfo = AdminUserCache::getUserInfo($result['id']);
        }
    }

    /**
     * 仅管理员操作
     * @return bool
     */
    protected function onlyAdmin():  bool
    {
        if ($this->adminId === 1) {
            return true;
        }
        return false;
    }

}