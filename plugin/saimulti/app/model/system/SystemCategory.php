<?php
// +----------------------------------------------------------------------
// | saiadmin [ saiadmin快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\model\system;

use plugin\saimulti\basic\BaseModel;

/**
 * 附件分类模型
 */
class SystemCategory extends BaseModel
{
    /**
     * 数据表主键
     * @var string
     */
    protected $pk = 'id';

    protected $table = 'sm_system_category';

    /**
     * 分类名称搜索
     */
    public function searchCategoryNameAttr($query, $value)
    {
        $query->where('category_name', 'like', '%' . $value . '%');
    }

}