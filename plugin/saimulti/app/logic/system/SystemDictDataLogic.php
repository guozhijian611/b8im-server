<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\logic\system;

use plugin\saimulti\app\cache\DictCache;
use plugin\saimulti\basic\BaseLogic;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\app\model\system\SystemDictData;
use plugin\saimulti\app\model\system\SystemDictType;

/**
 * 字典类型逻辑层
 */
class SystemDictDataLogic extends BaseLogic
{
    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->model = new SystemDictData();
    }

    /**
     * 添加数据
     * @param $data
     * @return mixed
     */
    public function add($data): mixed
    {
        $type = SystemDictType::where('id', $data['type_id'])->findOrEmpty();
        if ($type->isEmpty()) {
            throw new ApiException('字典类型不存在');
        }
        $data['code'] = $type->code;
        $model = $this->model->create($data);
        DictCache::clear();
        return $model->getKey();
    }

}
