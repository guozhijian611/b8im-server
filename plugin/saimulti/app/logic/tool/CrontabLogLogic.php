<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\logic\tool;

use plugin\saimulti\app\model\tool\CrontabLog;
use plugin\saimulti\basic\BaseLogic;

/**
 * 定时任务日志逻辑层
 */
class CrontabLogLogic extends BaseLogic
{
    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->model = new CrontabLog();
    }

}
