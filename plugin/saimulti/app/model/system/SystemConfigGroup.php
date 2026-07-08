<?php
// +----------------------------------------------------------------------
// | saiadmin [ saiadmin快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\model\system;

use plugin\saimulti\basic\BaseModel;

/**
 * 参数配置分组模型
 */
class SystemConfigGroup extends BaseModel
{
    /**
     * 数据表主键
     * @var string
     */
    protected $pk = 'id';

    protected $table = 'sm_system_config_group';

    public function configs()
    {
        return $this->hasMany(SystemConfig::class, 'group_id', 'id');
    }

}