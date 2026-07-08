<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\basic;

use think\Model;

/**
 * 中台正常模型基类
 * @package plugin\saimulti\basic
 */
class BaseNormalModel extends Model
{
    // 添加时间
    protected $createTime = 'create_time';
    // 更新时间
    protected $updateTime = 'update_time';
    // 隐藏字段
    protected $hidden = ['delete_time'];
    // 只读字段
    protected $readonly = ['created_by', 'create_time'];

    /**
     * 时间范围搜索
     */
    public function searchCreateTimeAttr($query, $value)
    {
        $query->whereTime('create_time', 'between', $value);
    }

}