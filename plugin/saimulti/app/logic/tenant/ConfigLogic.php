<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\logic\tenant;

use plugin\saimulti\app\model\system\SystemConfig;
use plugin\saimulti\app\model\system\SystemConfigGroup;
use plugin\saimulti\app\model\tenant\TenantConfig;
use plugin\saimulti\basic\BaseLogic;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\utils\Helper;
use support\think\Cache;

/**
 * 配置逻辑层
 */
class ConfigLogic extends BaseLogic
{
    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->model = new TenantConfig();
    }

    /**
     * 获取配置组列表
     * @return array
     */
    public function groupConfig(): array
    {
        $data = SystemConfigGroup::withoutGlobalScope()->where('type', 2)->select()->toArray();
        foreach ($data as &$item) {
            $item['data'] = $this->groupData($item['id']);
        }
        return $data;
    }

    /**
     * 获取配置项列表
     * @param $group_id
     * @return array
     */
    public function groupData($group_id): array
    {
        $data = SystemConfig::withoutGlobalScope()->where('group_id', $group_id)->select()->toArray();
        $model = $this->model->where('group_id', $group_id)->findOrEmpty();
        if (!$model->isEmpty()) {
            foreach ($data as &$item) {
                $item['value'] = $model->value[$item['key']] ?? '';
            }
        }
        return $data;
    }

    /**
     * 保存配置信息
     * @param $data
     * @return bool
     */
    public function saveGroup($data): bool
    {
        $group_id = $data['id'];
        $group = SystemConfigGroup::withoutGlobalScope()->where('id', $group_id)->findOrEmpty();
        if ($group->isEmpty()) {
            throw new ApiException('配置组不存在');
        }
        $value = [];
        foreach ($data['data'] as $item) {
            $value[$item['key']] = $item['value'];
        }
        $organization = request()->header('App-Id');
        $prefix = $organization . '_tenant_cfg_';
        Cache::delete($prefix . $group->code);

        $model = $this->model->where('group_id', $group_id)->findOrEmpty();
        if (!$model->isEmpty()) {
            $model->value = $value;
            return $model->save();
        } else {
            return $this->model->save([
                'group_id' => $group_id,
                'value' => $value
            ]);
        }
    }

    /**
     * 获取配置组
     */
    public function getGroup($config)
    {
        $organization = request()->header('App-Id');
        if (empty($organization)) {
            throw new ApiException('租户配置获取失败');
        }
        $prefix = $organization.'_tenant_cfg_';
        $data = Cache::get($prefix . $config);
        if (!is_null($data)) {
            return $data;
        }
        $group = SystemConfigGroup::where('code', $config)->findOrEmpty();
        if ($group->isEmpty()) {
            throw new ApiException('配置组不存在');
        }
        $info = $this->model->where('group_id', $group->id)->findOrEmpty();
        if ($info->isEmpty()) {
            throw new ApiException('租户配置不存在');
        }
        Cache::set($prefix . $config, $info->value);
        return $info;
    }

}