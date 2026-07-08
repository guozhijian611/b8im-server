<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\model\system;

use plugin\saimulti\basic\BaseModel;

/**
 * 系统配置模型
 */
class SystemConfig extends BaseModel
{
    // 完整数据库表名称
    protected $table = 'sm_system_config';
    // 主键
    protected $pk = 'id';

    public function getConfigSelectDataAttr($value)
    {
        return json_decode($value ?? '', true);
    }

    public function setConfigSelectDataAttr($value)
    {
        return json_encode($value);
    }

}