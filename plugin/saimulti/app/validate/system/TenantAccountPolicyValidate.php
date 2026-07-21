<?php
// +----------------------------------------------------------------------
// | b8im [ 即时通讯系统 ]
// +----------------------------------------------------------------------

declare(strict_types=1);

namespace plugin\saimulti\app\validate\system;

use think\Validate;

final class TenantAccountPolicyValidate extends Validate
{
    protected $rule = [
        'register_enabled' => 'require|boolean',
        'version' => 'require|integer|egt:1',
    ];

    protected $message = [
        'register_enabled.require' => '是否开放注册必须填写',
        'register_enabled.boolean' => '是否开放注册必须是布尔值',
        'version.require' => '策略版本必须填写',
        'version.integer' => '策略版本必须是整数',
        'version.egt' => '策略版本必须大于等于 1',
    ];

    protected $scene = [
        'update' => ['register_enabled', 'version'],
    ];
}
