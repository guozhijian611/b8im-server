<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
return [
    // http请求配置
    'jwt' => [
        // 中台 - token有效时间
        'admin_expire' => 3600 * 2,
        // 租户 - token有效时间
        'tenant_expire' => 3600 * 4,
    ],

    // excel模板下载路径
    'template' => base_path(). '/plugin/saimulti/public/template',

    // excel导出文件路径
    'export_path' => base_path() . '/plugin/saimulti/public/export/',

    // 验证码存储模式
    'captcha' => [
        // 验证码存储模式 session 或者 cache
        'mode' => 'cache',
        // 验证码过期时间 (秒)
        'expire' => 300,
    ],

    // 用户信息缓存
    'admin_cache' => [
        'prefix' => 'saimulti:admin_cache:info_',
        'expire' => 60 * 60 * 4,
        'dept' => 'saimulti:admin_cache:dept_',
        'role' => 'saimulti:admin_cache:role_',
        'post' => 'saimulti:admin_cache:post_',
    ],

    // 用户权限缓存
    'admin_button_cache' => [
        'prefix' => 'saimulti:admin_button_cache:user_',
        'expire' => 60 * 60 * 2,
        'all' => 'saimulti:admin_button_cache:all',
        'role' => 'saimulti:admin_button_cache:role_',
        'tag' => 'saimulti:admin_button_cache',
    ],

    // 租户信息缓存
    'tenant_cache' => [
        'prefix' => 'saimulti:tenant_cache:info_',
        'expire' => 60 * 60 * 4,
        'dept' => 'saimulti:tenant_cache:dept_',
        'role' => 'saimulti:tenant_cache:role_',
        'post' => 'saimulti:tenant_cache:post_',
    ],

    // 租户权限缓存
    'tenant_button_cache' => [
        'prefix' => 'saimulti:tenant_button_cache:user_',
        'expire' => 60 * 60 * 2,
        'all' => 'saimulti:tenant_button_cache:all',
        'role' => 'saimulti:tenant_button_cache:role_',
        'tag' => 'saimulti:tenant_button_cache',
    ],

    'dict_cache' => [
        'expire' => 60 * 60 * 24 * 365,
        'tag' => 'saimulti:dict_cache',
    ],

    // 配置数据缓存
    'config_cache' => [
        'expire' => 60 * 60 * 24 * 365,
        'prefix' => 'saimulti:config_cache:config_',
        'tag' => 'saimulti:config_cache'
    ],

    // 用户菜单缓存
    'menu_cache' => [
        'prefix' => 'saiadmin:menu_cache:user_',
        'expire' => 60 * 60 * 24 * 7,
        'tag' => 'saiadmin:menu_cache',
    ],

    'tenant_route_replace' => [
        '/saimulti/tenant/config/saveGroup' => '/saimulti/tenant/config/saveBasic'
    ],
];
