<?php
// +----------------------------------------------------------------------
// | saimulti [ saiadmin快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: your name
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\validate\admin;

use think\Validate;

/**
 * 机构分组表验证器
 */
class SystemGroupValidate extends Validate
{
    /**
     * 定义验证规则
     */
    protected $rule =   [
        'group_name' => 'require',
    ];

    /**
     * 定义错误信息
     */
    protected $message  =   [
        'group_name' => '分组名称必须填写',
    ];

    /**
     * 定义场景
     */
    protected $scene = [
        'save' => [
            'group_name',
        ],
        'update' => [
            'group_name',
        ],
    ];

}
