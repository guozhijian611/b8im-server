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
use plugin\saimulti\service\web\CrossOrganizationSocialPolicy;
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

    public function add($data): mixed
    {
        if (trim((string) (((array) $data)['code'] ?? '')) === CrossOrganizationSocialPolicy::CONFIG_GROUP) {
            throw new ApiException('跨租户社交系统配置组只能由系统迁移管理。', 403);
        }

        return parent::add($data);
    }

    public function edit($id, $data): mixed
    {
        $model = $this->model->findOrEmpty((int) $id);
        if ($model->isEmpty()) {
            throw new ApiException('配置数据未找到');
        }
        $existingCode = trim((string) $model->code);
        $resultCode = trim((string) (((array) $data)['code'] ?? $existingCode));
        if (($existingCode === CrossOrganizationSocialPolicy::CONFIG_GROUP
                || $resultCode === CrossOrganizationSocialPolicy::CONFIG_GROUP)
            && $existingCode !== $resultCode) {
            throw new ApiException('跨租户社交系统配置组标识禁止修改。', 403);
        }

        return parent::edit($id, $data);
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
        if ((string) $model->code === CrossOrganizationSocialPolicy::CONFIG_GROUP) {
            throw new ApiException('跨租户社交系统配置组禁止删除。', 403);
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
