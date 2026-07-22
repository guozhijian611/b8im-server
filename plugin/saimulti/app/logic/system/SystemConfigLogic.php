<?php
// +----------------------------------------------------------------------
// | saiadmin [ saiadmin快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\logic\system;

use plugin\saimulti\app\cache\ConfigCache;
use plugin\saimulti\basic\BaseLogic;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\app\model\system\SystemConfig;
use plugin\saimulti\app\model\system\SystemConfigGroup;
use plugin\saimulti\service\web\CrossOrganizationSocialConfigService;
use plugin\saimulti\service\web\CrossOrganizationSocialPolicy;

/**
 * 参数配置逻辑层
 */
class SystemConfigLogic extends BaseLogic
{
    private const CROSS_ORGANIZATION_MANAGED_KEYS = [
        CrossOrganizationSocialPolicy::CONFIG_KEY,
        CrossOrganizationSocialPolicy::SNAPSHOT_CONFIG_KEY,
    ];

    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->model = new SystemConfig();
        parent::__construct();
    }

    /**
     * 添加数据
     * @param mixed $data
     * @return mixed
     */
    public function add($data): mixed
    {
        $data = (array) $data;
        $group = SystemConfigGroup::find((int) ($data['group_id'] ?? 0));
        if (!$group) {
            throw new ApiException('配置组未找到');
        }
        if (in_array(trim((string) ($data['key'] ?? '')), self::CROSS_ORGANIZATION_MANAGED_KEYS, true)) {
            throw new ApiException('跨租户社交系统配置只能由专用逻辑管理。', 403);
        }
        $result = $this->model->create($data);
        ConfigCache::clearConfig($group->code);
        return $result;
    }

    /**
     * 编辑数据
     * @param mixed $id
     * @param mixed $data
     * @return bool
     */
    public function edit($id, $data): bool
    {
        $data = (array) $data;
        $existing = $this->model->findOrEmpty((int) $id);
        if ($existing->isEmpty()) {
            throw new ApiException('数据不存在');
        }
        $existingGroupId = (int) $existing->group_id;
        $groupId = array_key_exists('group_id', $data)
            ? (int) $data['group_id']
            : $existingGroupId;
        if ($groupId <= 0 || $groupId !== $existingGroupId) {
            throw new ApiException('配置项禁止跨配置组移动。', 422);
        }
        $group = SystemConfigGroup::find($groupId);
        if (!$group) {
            throw new ApiException('配置组未找到');
        }
        $existingKey = trim((string) $existing->key);
        $resultKey = trim((string) ($data['key'] ?? $existingKey));
        $socialGroup = (string) $group->code === CrossOrganizationSocialPolicy::CONFIG_GROUP;
        if ($socialGroup) {
            $data['group_id'] = $groupId;
            (new CrossOrganizationSocialConfigService())->edit((int) $id, $data);
            ConfigCache::clearConfig((string) $group->code);

            return true;
        }
        if (in_array($existingKey, self::CROSS_ORGANIZATION_MANAGED_KEYS, true)
            || in_array($resultKey, self::CROSS_ORGANIZATION_MANAGED_KEYS, true)) {
            throw new ApiException('跨租户社交系统配置只能由专用逻辑管理。', 403);
        }
        $result = parent::edit($id, $data);
        ConfigCache::clearConfig($group->code);
        return $result;
    }

    /**
     * @param mixed $ids
     */
    public function destroy($ids): bool
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', (array) $ids),
            static fn (int $id): bool => $id > 0,
        )));
        if ($ids === []) {
            throw new ApiException('配置项编号无效。', 422);
        }
        $rows = $this->model->whereIn('id', $ids)->select()->toArray();
        if (count($rows) !== count($ids)) {
            throw new ApiException('部分配置项不存在。', 404);
        }
        foreach ($rows as $row) {
            if (in_array(trim((string) ($row['key'] ?? '')), self::CROSS_ORGANIZATION_MANAGED_KEYS, true)) {
                throw new ApiException('跨租户社交系统配置禁止删除。', 403);
            }
        }

        return parent::destroy($ids);
    }

    /**
     * 批量更新
     * @param mixed $group_id
     * @param mixed $config
     * @return bool
     */
    public function batchUpdate($group_id, $config): bool
    {
        $group = SystemConfigGroup::find($group_id);
        if (!$group) {
            throw new ApiException('配置组未找到');
        }
        if ((string) $group->code === CrossOrganizationSocialPolicy::CONFIG_GROUP) {
            (new CrossOrganizationSocialConfigService())->batchUpdate((int) $group_id, (array) $config);
            ConfigCache::clearConfig($group->code);

            return true;
        }
        $mutations = [];
        $ids = [];
        foreach ((array) $config as $value) {
            if (!is_array($value)) {
                throw new ApiException('配置项格式无效。', 422);
            }
            $id = (int) ($value['id'] ?? 0);
            if ($id <= 0 || isset($ids[$id])) {
                throw new ApiException('配置项编号重复或无效。', 422);
            }
            $ids[$id] = true;
            $mutations[] = $value;
        }
        if ($mutations === []) {
            throw new ApiException('配置项不能为空。', 422);
        }
        $rows = $this->model->whereIn('id', array_keys($ids))->select()->toArray();
        if (count($rows) !== count($ids)) {
            throw new ApiException('部分配置项不存在。', 404);
        }
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }
        $saveData = [];
        foreach ($mutations as $value) {
            $id = (int) $value['id'];
            $existing = $byId[$id];
            $existingKey = trim((string) ($existing['key'] ?? ''));
            $resultKey = trim((string) ($value['key'] ?? $existingKey));
            if (in_array($existingKey, self::CROSS_ORGANIZATION_MANAGED_KEYS, true)
                || in_array($resultKey, self::CROSS_ORGANIZATION_MANAGED_KEYS, true)) {
                throw new ApiException('跨租户社交系统配置只能由专用逻辑管理。', 403);
            }
            if ((int) ($existing['group_id'] ?? 0) !== (int) $group_id) {
                throw new ApiException('配置项不存在或不属于当前配置组。', 422);
            }
            $saveData[] = [
                'id' => $id,
                'group_id' => $group_id,
                'name' => $value['name'],
                'key' => $resultKey,
                'value' => $value['value'],
            ];
        }
        $this->model->saveAll($saveData);
        ConfigCache::clearConfig($group->code);
        return true;
    }

    /**
     * 获取配置数据
     * @param mixed $code
     * @return array
     */
    public function getData($code): array
    {
        $group = SystemConfigGroup::where('code', $code)->findOrEmpty();
        if (empty($group)) {
            return [];
        }
        $config = SystemConfig::where('group_id', $group['id'])->select()->toArray();
        return $config;
    }

    /**
     * 获取配置组
     */
    public function getGroup($config): array
    {
        return ConfigCache::getConfig($config);
    }

}
