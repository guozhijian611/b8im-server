<?php

// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\model\tool;

use plugin\saimulti\app\model\system\SystemOrganization;
use plugin\saimulti\basic\TenantModel;

/**
 * 操作日志模型
 * Class SystemOperLog
 * @package app\model
 */
class OperLog extends TenantModel
{
    /**
     * 数据表主键
     * @var string
     */
    protected $pk = 'id';

    protected $table = 'sm_tool_oper_log';

    public function organ()
    {
        return $this->belongsTo(SystemOrganization::class, 'organization', 'id');
    }
}