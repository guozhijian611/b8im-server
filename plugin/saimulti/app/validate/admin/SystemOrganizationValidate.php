<?php

declare(strict_types=1);

namespace plugin\saimulti\app\validate\admin;

use think\Validate;

final class SystemOrganizationValidate extends Validate
{
    protected $rule = [
        'title' => 'require|max:255',
        'organization_name' => 'require|max:255',
        'enterprise_code' => 'require|max:64',
        'deployment_id' => 'require|max:64',
        'api_server_url' => 'require|max:512',
        'im_server_url' => 'require|max:512',
        'upload_server_url' => 'require|max:512',
        'web_server_url' => 'require|max:512',
    ];

    protected $message = [
        'title.require' => '站点名称必须填写',
        'organization_name.require' => '机构名称必须填写',
        'enterprise_code.require' => '企业码必须填写',
        'deployment_id.require' => '部署标识必须填写',
        'api_server_url.require' => 'API 服务地址必须填写',
        'im_server_url.require' => 'IM 服务地址必须填写',
        'upload_server_url.require' => '上传服务地址必须填写',
        'web_server_url.require' => 'Web 服务地址必须填写',
    ];

    protected $scene = [
        'save' => [
            'title',
            'organization_name',
            'enterprise_code',
            'deployment_id',
            'api_server_url',
            'im_server_url',
            'upload_server_url',
            'web_server_url',
        ],
        'update' => [
            'title',
            'organization_name',
            'enterprise_code',
            'deployment_id',
            'api_server_url',
            'im_server_url',
            'upload_server_url',
            'web_server_url',
        ],
    ];
}
