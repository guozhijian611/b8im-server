<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\model\data;

use plugin\saimulti\basic\TenantModel;

/**
 * 文章分类模型
 * Class ArticleCategory
 */
class ArticleCategory extends TenantModel
{
    /**
     * 数据表主键
     * @var string
     */
    protected $pk = 'id';

    protected $table = 'sm_article_category';

    /**
     * 分类标题 搜索
     */
    public function searchCategoryNameAttr($query, $value)
    {
        $query->where('category_name', 'like', '%'.$value.'%');
    }

}