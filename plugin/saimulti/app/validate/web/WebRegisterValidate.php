<?php

declare(strict_types=1);

namespace plugin\saimulti\app\validate\web;

use think\Validate;

final class WebRegisterValidate extends Validate
{
    protected $rule = [
        'account' => 'require|length:2,64|alphaDash',
        'password' => 'require|length:8,72',
        'password_confirm' => 'require|confirm:password',
        'nickname' => 'require|length:1,64',
        'device_id' => 'require|length:1,100|regex:/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/',
        'uuid' => 'require|length:36',
        'code' => 'require|length:4',
    ];

    protected $message = [
        'account.require' => '登录账号必须填写',
        'account.length' => '登录账号长度必须为 2 至 64 个字符',
        'account.alphaDash' => '登录账号只能包含字母、数字、下划线和连字符',
        'password.require' => '登录密码必须填写',
        'password.length' => '登录密码长度必须为 8 至 72 个字符',
        'password_confirm.require' => '确认密码必须填写',
        'password_confirm.confirm' => '两次输入的密码不一致',
        'nickname.require' => '用户昵称必须填写',
        'nickname.length' => '用户昵称长度必须为 1 至 64 个字符',
        'device_id.require' => '浏览器设备标识必须填写',
        'device_id.length' => '浏览器设备标识最多 100 个字符',
        'device_id.regex' => '浏览器设备标识格式无效',
        'uuid.require' => '验证码标识必须填写',
        'uuid.length' => '验证码标识格式无效',
        'code.require' => '验证码必须填写',
        'code.length' => '验证码格式无效',
    ];

    protected $scene = ['register' => ['account', 'password', 'password_confirm', 'nickname', 'device_id', 'uuid', 'code']];
}
