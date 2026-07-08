<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\logic\tenant;

use plugin\saimulti\app\model\tenant\Notice;
use plugin\saimulti\basic\BaseLogic;

/**
 * 系统公告逻辑层
 */
class NoticeLogic extends BaseLogic
{
    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->model = new Notice();
    }

}
