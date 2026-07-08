<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\model\data;

use plugin\saimulti\basic\TenantModel;

/**
 * 文章管理模型
 * Class Article
 */
class Article extends TenantModel
{
    /**
     * 数据表主键
     * @var string
     */
    protected $pk = 'id';

    protected $table = 'sm_article';

    /**
     * 文章标题 搜索
     */
    public function searchTitleAttr($query, $value)
    {
        $query->where('title', 'like', '%'.$value.'%');
    }

    public function category()
    {
        return $this->belongsTo(ArticleCategory::class, 'category_id', 'id');
    }

}