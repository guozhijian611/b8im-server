<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\model\tenant;

use plugin\saimulti\basic\TenantModel;

/**
 * 租户配置模型
 */
class TenantConfig extends TenantModel
{
    // 完整数据库表名称
    protected $table  = 'sm_tenant_config';

    // 主键
    protected $pk = 'id';

    public function getValueAttr($value)
    {
        return json_decode($value, true);
    }

    public function setValueAttr($value)
    {
        return json_encode($value);
    }

}