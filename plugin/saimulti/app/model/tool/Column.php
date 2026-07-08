<?php

// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\model\tool;

use plugin\saimulti\basic\BaseModel;

/**
 * 低代码字段模型
 */
class Column extends BaseModel
{
    /**
     * 数据表主键
     * @var string
     */
    protected $pk = 'id';

    protected $table = 'saimulti_column';

    public function getOptionsAttr($value)
    {
        return json_decode($value ?? '', true);
    }

    public function getQueryOptionsAttr($value)
    {
        return json_decode($value ?? '', true);
    }

}