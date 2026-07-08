<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\validate\data;

use think\Validate;

/**
 * 文章轮播验证器
 */
class ArticleBannerValidate extends Validate
{
    /**
     * 定义验证规则
     */
    protected $rule =   [
        'title' => 'require',
    ];

    /**
     * 定义错误信息
     */
    protected $message  =   [
        'title' => '标题必须填写',
    ];

    /**
     * 定义场景
     */
    protected $scene = [
        'save' => [
            'title',
        ],
        'update' => [
            'title',
        ],
    ];

}
