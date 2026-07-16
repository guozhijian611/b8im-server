<?php
// +----------------------------------------------------------------------
// | b8im [ 即时通讯系统 ]
// +----------------------------------------------------------------------

declare(strict_types=1);

namespace plugin\saimulti\app\validate\system;

use think\Validate;

final class ImUserValidate extends Validate
{
    protected $rule = [
        'id' => 'require|integer',
        'organization' => 'require|integer',
        'account' => 'require|length:2,64|alphaDash',
        'password' => 'require|length:8,72',
        'password_confirm' => 'require|confirm:password',
        'nickname' => 'require|length:1,64',
        'im_short_no' => 'max:32|alphaNum',
        'avatar' => 'max:255',
        'mobile' => 'max:32',
        'email' => 'email|max:120',
        'signature' => 'max:255',
        'remark' => 'max:255',
        'gender' => 'in:0,1,2',
        'status' => 'require|in:1,2,3',
        'quota_value' => 'require|integer|egt:0',
    ];

    protected $message = [
        'id.require' => 'IM 用户编号必须填写',
        'id.integer' => 'IM 用户编号格式错误',
        'organization.require' => '所属机构必须选择',
        'organization.integer' => '所属机构格式错误',
        'account.require' => '登录账号必须填写',
        'account.length' => '登录账号长度必须为 2 至 64 个字符',
        'account.alphaDash' => '登录账号只能包含字母、数字、下划线和连字符',
        'password.require' => '登录密码必须填写',
        'password.length' => '登录密码长度必须为 8 至 72 个字符',
        'password_confirm.require' => '确认密码必须填写',
        'password_confirm.confirm' => '两次输入的密码不一致',
        'nickname.require' => '用户昵称必须填写',
        'nickname.length' => '用户昵称长度必须为 1 至 64 个字符',
        'im_short_no.max' => 'IM 短号最多 32 个字符',
        'im_short_no.alphaNum' => 'IM 短号只能包含字母和数字',
        'avatar.max' => '头像标识最多 255 个字符',
        'mobile.max' => '手机号最多 32 个字符',
        'email.email' => '邮箱格式错误',
        'email.max' => '邮箱最多 120 个字符',
        'signature.max' => '个性签名最多 255 个字符',
        'remark.max' => '备注最多 255 个字符',
        'gender.in' => '性别值无效',
        'status.require' => '用户状态必须选择',
        'status.in' => '用户状态值无效',
        'quota_value.require' => '席位数必须填写',
        'quota_value.integer' => '席位数必须是整数',
        'quota_value.egt' => '席位数不能小于 0',
    ];

    protected $scene = [
        'adminSave' => [
            'organization', 'account', 'password', 'password_confirm', 'nickname',
            'im_short_no', 'avatar', 'mobile', 'email', 'gender', 'signature', 'remark', 'status',
        ],
        'tenantSave' => [
            'account', 'password', 'password_confirm', 'nickname', 'im_short_no',
            'avatar', 'mobile', 'email', 'gender', 'signature', 'remark', 'status',
        ],
        'update' => [
            'id', 'account', 'nickname', 'im_short_no', 'avatar', 'mobile', 'email', 'gender',
            'signature', 'remark',
        ],
        'status' => ['id', 'status'],
        'reset' => ['id', 'password', 'password_confirm'],
        'quota' => ['organization', 'quota_value'],
    ];
}
