<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\logic\tenant;

use plugin\saimulti\app\model\tenant\Dept;
use plugin\saimulti\app\model\tenant\User;
use plugin\saimulti\basic\BaseLogic;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\utils\Arr;
use plugin\saimulti\utils\Helper;

/**
 * 部门逻辑层
 */
class DeptLogic extends BaseLogic
{
    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->model = new Dept();
        parent::__construct();
    }

    /**
     * 数据保存
     */
    public function add($data): mixed
    {
        $data = $this->handleData($data);
        return parent::add($data);
    }

    /**
     * 数据修改
     */
    public function edit($id, $data): mixed
    {
        $oldLevel = $data['level'] . $id . ',';
        $data = $this->handleData($data);
        if ($data['parent_id'] == $id) {
            throw new ApiException('上级部门和当前部门不能相同');
        }
        if (in_array($id, explode(',', $data['level']))) {
            throw new ApiException('不能将上级部门设置为当前部门的子部门');
        }
        $newLevel = $data['level'] . $id . ',';
        $deptIds = $this->model->where('level', 'like', $oldLevel . '%')->column('id');

        return $this->transaction(function () use ($deptIds, $oldLevel, $newLevel, $data, $id) {
            $this->model->whereIn('id', $deptIds)->exp('level', "REPLACE(level, '$oldLevel', '$newLevel')")->update([]);
            return $this->model->update($data, ['id' => $id]);
        });
    }

    /**
     * 数据删除
     */
    public function destroy($ids): bool
    {
        $num = $this->model->where('parent_id', 'in', $ids)->count();
        if ($num > 0) {
            throw new ApiException('该部门下存在子部门，请先删除子部门');
        } else {
            $count = User::where('dept_id', 'in', $ids)->count();
            if ($count > 0) {
                throw new ApiException('该部门下存在用户，请先删除或者转移用户');
            }
            return parent::destroy($ids);
        }
    }

    /**
     * 数据处理
     */
    protected function handleData($data)
    {
        if (empty($data['parent_id']) || $data['parent_id'] == 0) {
            $data['level'] = '0';
            $data['parent_id'] = 0;
        } else {
            $parentMenu = $this->model->findOrEmpty($data['parent_id']);
            $data['level'] = $parentMenu['level'] . ',' . $parentMenu['id'];
        }
        return $data;
    }

    /**
     * 数据树形化
     * @param array $where
     * @return array
     */
    public function tree(array $where = []): array
    {
        $query = $this->search($where);
        if (request()->input('tree', 'false') === 'true') {
            $query->field('id, id as value, name as label, parent_id');
        }
        $query->order('sort', 'desc');
        $data = $this->getAll($query);
        return Helper::makeTree($data);
    }

    /**
     * 可操作部门
     * @param array $where
     * @return array
     */
    public function accessDept(array $where = []): array
    {
        $query = $this->search($where);
        $query->auth($this->tenantInfo['deptList']);
        $query->field('id, id as value, name as label, parent_id');
        $query->order('sort', 'desc');
        $data = $this->getAll($query);
        return Helper::makeTree($data);
    }

}
