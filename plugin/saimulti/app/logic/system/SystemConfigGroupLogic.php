<?php

// +----------------------------------------------------------------------
// | saiadmin [ saiadmin快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\logic\system;

use plugin\saimulti\app\model\system\SystemConfigGroup;
use plugin\saimulti\basic\BaseLogic;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\app\model\system\SystemConfig;
use plugin\saimulti\app\cache\ConfigCache;
use support\think\Db;

/**
 * 参数配置分组逻辑层
 */
class SystemConfigGroupLogic extends BaseLogic
{
    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->model = new SystemConfigGroup();
        parent::__construct();
    }

    /**
     * 删除配置信息
     */
    public function destroy($ids): bool
    {
        $id = $ids[0];
        $model = $this->model->where('id', $id)->findOrEmpty();
        if ($model->isEmpty()) {
            throw new ApiException('配置数据未找到');
        }
        if (in_array(intval($id), [1, 2, 3])) {
            throw new ApiException('系统默认分组，无法删除');
        }
        Db::startTrans();
        try {
            // 删除配置组
            $model->delete();
            // 删除配置组数据
            $typeIds = SystemConfig::where('group_id', $id)->column('id');
            SystemConfig::destroy($typeIds);
            ConfigCache::clearConfig($model->code);
            Db::commit();
            return true;
        } catch (\Exception $e) {
            Db::rollback();
            throw new ApiException('删除数据异常，请检查');
        }
    }
}
