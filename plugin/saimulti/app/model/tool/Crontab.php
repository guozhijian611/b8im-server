<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\model\tool;

use plugin\saimulti\basic\BaseModel;

/**
 * 定时任务模型
 * Class SystemCrontab
 * @package app\model
 */
class Crontab extends BaseModel
{
    /**
     * 数据表主键
     * @var string
     */
    protected $pk = 'id';

    protected $table = 'sm_tool_crontab';

}