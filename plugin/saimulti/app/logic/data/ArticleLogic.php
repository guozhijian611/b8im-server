<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\logic\data;

use plugin\saimulti\app\model\data\Article;
use plugin\saimulti\basic\BaseLogic;
use plugin\saimulti\utils\Helper;

/**
 * 文章管理逻辑层
 */
class ArticleLogic extends BaseLogic
{
    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->model = new Article();
    }

}
