<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\model\system;

use plugin\saimulti\basic\TenantModel;
/**
 * 附件模型
 * Class SystemAttachment
 * @package app\model
 */
class SystemAttachment extends TenantModel
{
    /**
     * 数据表主键
     * @var string
     */
    protected $pk = 'id';

    protected $table = 'sm_system_attachment';

    public function searchOriginNameAttr($query, $value)
    {
        $query->where('origin_name', 'like', '%'.$value.'%');
    }

    public function searchMimeTypeAttr($query, $value)
    {
        $query->where('mime_type', 'like', $value.'/%');
    }

    public function organ()
    {
        return $this->belongsTo(SystemOrganization::class, 'organization', 'id');
    }
}