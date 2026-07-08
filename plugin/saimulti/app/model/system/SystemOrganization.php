<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\model\system;

use plugin\saimulti\app\model\tenant\Group;
use plugin\saimulti\basic\BaseModel;

/**
 * 单位信息模型
 */
class SystemOrganization extends BaseModel
{
    // 完整数据库表名称
    protected $table = 'sm_system_organization';
    // 主键
    protected $pk = 'id';

    /**
     * 关联机构分组
     */
    public function groupInfo()
    {
        return $this->belongsTo(Group::class, 'group_id', 'id');
    }
}