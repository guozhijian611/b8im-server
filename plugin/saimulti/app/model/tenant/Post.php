<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\model\tenant;

use plugin\saimulti\basic\TenantModel;

/**
 * 岗位模型
 * Class SystemRole
 * @package app\model
 */
class Post extends TenantModel
{
    /**
     * 数据表主键
     * @var string
     */
    protected $pk = 'id';

    protected $table = 'sm_tenant_post';

}