<?php
// +----------------------------------------------------------------------
// | saiadmin [ saiadmin快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\logic\tool;

use plugin\saimulti\app\model\tool\Mail;
use plugin\saimulti\basic\BaseLogic;
use plugin\saimulti\utils\Helper;

/**
 * 邮件模型逻辑层
 */
class MailLogic extends BaseLogic
{
    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->model = new Mail();
    }

}
