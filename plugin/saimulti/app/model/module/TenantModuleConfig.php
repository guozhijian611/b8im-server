<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\model\module;

use plugin\saimulti\basic\TenantModel;

class TenantModuleConfig extends TenantModel
{
    protected $table = 'sm_tenant_module_config';

    protected $pk = 'id';
}
