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
    ];

    protected $message = [
        'title.require' => '站点名称必须填写',
        'organization_name.require' => '机构名称必须填写',
        'enterprise_code.require' => '企业码必须填写',
        'deployment_id.require' => '部署标识必须填写',
    ];

    protected $scene = [
        'save' => [
            'title',
            'organization_name',
            'enterprise_code',
            'deployment_id',
        ],
        'update' => [
            'title',
            'organization_name',
            'enterprise_code',
            'deployment_id',
        ],
    ];
}
