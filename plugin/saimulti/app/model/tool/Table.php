<?php

// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\model\tool;

use plugin\saimulti\basic\BaseModel;

/**
 * 低代码表模型
 */
class Table extends BaseModel
{
    /**
     * 数据表主键
     * @var string
     */
    protected $pk = 'id';

    protected $table = 'saimulti_table';

    public function getOptionsAttr($value)
    {
        return json_decode($value ?? '', true);
    }

}