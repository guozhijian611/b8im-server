<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\model\module;

use plugin\saimulti\basic\BaseNormalModel;

class ModuleLifecycleAudit extends BaseNormalModel
{
    protected $table = 'sm_module_lifecycle_audit';

    protected $pk = 'id';

    protected $updateTime = false;
}
