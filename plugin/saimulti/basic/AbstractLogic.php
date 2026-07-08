<?php
// +----------------------------------------------------------------------
// | saiadmin [ saiadmin快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\basic;

use plugin\saimulti\exception\ApiException;
use support\think\Db;

/**
 * 抽象逻辑层基类
 */
abstract class AbstractLogic
{
    /**
     * 模型注入
     * @var object
     */
    protected $model;

    /**
     * 管理员信息
     * @var array
     */
    protected array $adminInfo;

    /**
     * 租户信息
     * @var array
     */
    protected array $tenantInfo;

    /**
     * 排序字段
     * @var string
     */
    protected string $orderField = '';

    /**
     * 排序方式
     * @var string
     */
    protected string $orderType = 'ASC';


    /**
     * 设置排序字段
     * @param string $field
     * @return static
     */
    public function setOrderField(string $field): static
    {
        $this->orderField = $field;
        return $this;
    }

    /**
     * 设置排序方式
     * @param string $type
     * @return static
     */
    public function setOrderType(string $type): static
    {
        $this->orderType = $type;
        return $this;
    }

    /**
     * 获取模型实例
     * @return object
     */
    public function getModel(): object
    {
        return $this->model;
    }

    /**
     * 数据库事务操作
     * @param callable $closure
     * @param bool $isTran
     * @return mixed
     */
    public function transaction(callable $closure, bool $isTran = true): mixed
    {
        return $isTran ? Db::transaction($closure) : $closure();
    }

    /**
     * 添加数据
     * @param $data
     * @return mixed
     */
    public function add($data): mixed
    {
        $model = $this->model->create($data);
        return $model->getKey();
    }

    /**
     * 修改数据
     * @param $id
     * @param $data
     * @return mixed
     */
    public function edit($id, $data): mixed
    {
        $model = $this->model->findOrEmpty($id);
        if ($model->isEmpty()) {
            throw new ApiException('数据不存在');
        }
        return $model->save($data);
    }

    /**
     * 读取数据
     * @param $id
     * @return mixed
     */
    public function read($id): mixed
    {
        $model = $this->model->findOrEmpty($id);
        if ($model->isEmpty()) {
            throw new ApiException('数据不存在');
        }
        return $model;
    }

    /**
     * 删除数据
     * @param mixed $ids
     * @return bool
     */
    public function destroy($ids): bool
    {
        return $this->model->destroy($ids);
    }

    /**
     * 搜索器搜索
     * @param array $searchWhere
     * @return mixed
     */
    public function search(array $searchWhere = [])
    {
        $withSearch = array_keys($searchWhere);
        $data = [];
        foreach ($searchWhere as $key => $value) {
            if ($value !== '' && $value !== null && $value !== []) {
                $data[$key] = $value;
            }
        }
        $withSearch = array_keys($data);
        return $this->model->withSearch($withSearch, $data);
    }

    /**
     * 分页查询数据
     * @return mixed
     */
    public function getList($query)
    {
        $saiType = request()->input('saiType', 'list');
        $page = request()->input('page', 1);
        $limit = request()->input('limit', 10);
        $orderBy = request()->input('orderBy', '');
        $orderType = request()->input('orderType', $this->orderType);
        if(empty($orderBy)) {
            $orderBy = $this->orderField !== '' ? $this->orderField : $this->model->getPk();
        }
        $query->order($orderBy, $orderType);
        if ($saiType === 'all') {
            return $query->select()->toArray();
        }
        return $query->paginate($limit, false, ['page' => $page])->toArray();
    }

    /**
     * 获取全部数据
     * @param $query
     * @return mixed
     */
    public function getAll($query)
    {
        $orderBy = request()->input('orderBy', '');
        $orderType = request()->input('orderType', $this->orderType);
        if(empty($orderBy)) {
            $orderBy = $this->orderField !== '' ? $this->orderField : $this->model->getPk();
        }
        $query->order($orderBy, $orderType);
        return $query->select()->toArray();
    }

    /**
     * 获取上传的导入文件
     * @param $file
     * @return string
     */
    public function getImport($file): string
    {
        $full_dir = runtime_path() . '/resource/';
        if (!is_dir($full_dir)) {
            mkdir($full_dir, 0777, true);
        }
        $ext = $file->getUploadExtension() ?: null;
        $full_path = $full_dir . md5(time()) . '.' . $ext;
        $file->move($full_path);
        return $full_path;
    }

    /**
     * 方法调用代理到模型
     * @param string $name
     * @param array $arguments
     * @return mixed
     */
    public function __call(string $name, array $arguments): mixed
    {
        return call_user_func_array([$this->model, $name], $arguments);
    }
}
