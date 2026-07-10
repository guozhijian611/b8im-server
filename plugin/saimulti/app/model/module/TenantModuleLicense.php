<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\model\module;

use plugin\saimulti\basic\TenantModel;

class TenantModuleLicense extends TenantModel
{
    protected $table = 'sm_tenant_module_license';

    protected $pk = 'id';
}
