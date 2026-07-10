<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\basic;

use think\Model;
use think\model\concern\SoftDelete;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\TenantContext;

/**
 * 租户软删除模型基类
 * @package plugin\saimulti\basic
 */
class TenantModel extends Model
{
    use SoftDelete;
    // 删除时间
    protected $deleteTime = 'delete_time';
    // 添加时间
    protected $createTime = 'create_time';
    // 更新时间
    protected $updateTime = 'update_time';
    // 隐藏字段
    protected $hidden = ['delete_time'];
    // 只读字段
    protected $readonly = ['created_by', 'create_time'];

    protected $globalScope = ['organization'];

    public function scopeOrganization($query)
    {
        $organization = self::resolveOrganization();
        if ($organization !== null) {
            $query->where('organization', $organization);
        }
    }

    /**
     * 时间范围搜索
     */
    public function searchCreateTimeAttr($query, $value)
    {
        $query->whereTime('create_time', 'between', $value);
    }

    public static function onBeforeDelete($model)
    {
        self::assertModelOrganization($model);
    }

    public static function onBeforeRestore($model)
    {
        self::assertModelOrganization($model);
    }

    public static function onBeforeInsert($model)
    {
        $organization = self::resolveOrganization();
        if ($organization !== null) {
            $model->setAttr('organization', $organization);
        }
        $info = getTenantInfo();
        $info && $model->setAttr('created_by', $info['id']);
    }

    public static function onBeforeWrite($model)
    {
        $organization = self::resolveOrganization();
        if ($organization !== null) {
            $model->setAttr('organization', $organization);
        }
        $info = getTenantInfo();
        $info && $model->setAttr('updated_by', $info['id']);
    }

    private static function resolveOrganization(): ?int
    {
        if (!request()) {
            return null;
        }

        $organization = TenantContext::organization(false);
        if ($organization === null && !TenantContext::isAdminRequest()) {
            throw new ApiException('租户上下文缺失', TenantContext::REQUIRED);
        }

        return $organization;
    }

    private static function assertModelOrganization($model): void
    {
        $organization = self::resolveOrganization();
        if ($organization !== null && (int) $model->getAttr('organization') !== $organization) {
            throw new ApiException('非法跨租户操作', TenantContext::MISMATCH);
        }
    }
}
