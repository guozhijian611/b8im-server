<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\model\tenant;

use plugin\saimulti\basic\TenantModel;

/**
 * 系统公告模型
 */
class Notice extends TenantModel
{
    // 完整数据库表名称
    protected $table  = 'sm_tenant_notice';
    // 主键
    protected $pk = 'id';

}