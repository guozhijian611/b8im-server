<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\validate\system;

use think\Validate;

/**
 * 租户管理员验证器
 */
class TenantUserValidate extends Validate
{
    protected $rule = [
        'id' => 'require|integer',
        'username' => 'require|length:2,20|alphaDash',
        'password' => 'require|length:8,72',
        'password_confirm' => 'require|confirm:password',
        'dept_id' => 'require|integer',
        'role_ids' => 'require|array',
        'status' => 'require|in:1,2',
        'email' => 'email',
        'phone' => 'max:20',
    ];

    protected $message = [
        'id.require' => '用户编号必须填写',
        'id.integer' => '用户编号格式错误',
        'username.require' => '用户名必须填写',
        'username.length' => '用户名长度必须为 2 至 20 个字符',
        'username.alphaDash' => '用户名只能包含字母、数字、下划线和连字符',
        'password.require' => '密码必须填写',
        'password.length' => '密码长度必须为 8 至 72 个字符',
        'password_confirm.require' => '确认密码必须填写',
        'password_confirm.confirm' => '两次输入的密码不一致',
        'dept_id.require' => '部门必须选择',
        'dept_id.integer' => '部门编号格式错误',
        'role_ids.require' => '角色必须选择',
        'role_ids.array' => '角色参数格式错误',
        'status.require' => '状态必须填写',
        'status.in' => '状态值无效',
        'email.email' => '邮箱格式错误',
        'phone.max' => '手机号长度不能超过 20 个字符',
    ];

    protected $scene = [
        'save' => [
            'username',
            'password',
            'password_confirm',
            'dept_id',
            'role_ids',
            'status',
            'email',
            'phone',
        ],
        'update' => [
            'id',
            'username',
            'dept_id',
            'role_ids',
            'status',
            'email',
            'phone',
        ],
    ];
}
