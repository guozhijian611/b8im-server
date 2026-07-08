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
        $organization = request()->header('App-Id');
        $organization && $query->where('organization', $organization);
    }

    /**
     * 时间范围搜索
     */
    public function searchCreateTimeAttr($query, $value)
    {
        $query->whereTime('create_time', 'between', $value);
    }

    public static function onBeforeDelete($model) {
        $organization = request()->header('App-Id');
        if (!empty($organization)) {
            if ($model->getAttr('organization') != $organization) {
                throw new ApiException('非法操作');
            }
        }

    }

    public static function onBeforeRestore($model) {
        $organization = request()->header('App-Id');
        if (!empty($organization)) {
            if ($model->getAttr('organization') != $organization) {
                throw new ApiException('非法操作');
            }
        }
    }

    public static function onBeforeInsert($model) {
        $organization = request()->header('App-Id');
        $organization && $model->setAttr('organization', $organization);
        $info = getTenantInfo();
        $info && $model->setAttr('created_by', $info['id']);
    }

    public static function onBeforeWrite($model) {
        $organization = request()->header('App-Id');
        $organization && $model->setAttr('organization', $organization);
        $info = getTenantInfo();
        $info && $model->setAttr('updated_by', $info['id']);
    }

}