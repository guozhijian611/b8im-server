<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\logic\data;

use plugin\saimulti\app\model\data\ArticleCategory;
use plugin\saimulti\basic\BaseLogic;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\utils\Helper;

/**
 * 文章分类逻辑层
 */
class ArticleCategoryLogic extends BaseLogic
{
    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->model = new ArticleCategory();
        parent::__construct();
    }

    /**
     * 修改数据
     * @param $id
     * @param $data
     * @return mixed
     */
    public function edit($id, $data): mixed
    {
        if (!isset($data['parent_id'])) {
            $data['parent_id'] = 0;
        }
        if ($data['parent_id'] == $data['id']) {
            throw new ApiException('不能设置父级为自身');
        }
        return parent::edit($id, $data);
    }

    /**
     * 删除数据
     * @param $ids
     */
    public function destroy($ids): bool
    {
        $num = $this->model->whereIn('parent_id', $ids)->count();
        if ($num > 0) {
            throw new ApiException('该分类下存在子分类，请先删除子分类');
        } else {
            return parent::destroy($ids);
        }
    }

    /**
     * 树形数据
     */
    public function tree($where)
    {
        $query = $this->search($where);
        $request = request();
        if ($request && $request->input('tree', 'false') === 'true') {
            $query->field('id, id as value, category_name as label, parent_id');
        }
        $data = $this->getAll($query);
        return Helper::makeTree($data);
    }

}
