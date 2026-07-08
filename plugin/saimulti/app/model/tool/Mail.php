<?php
// +----------------------------------------------------------------------
// | saiadmin [ saiadmin快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\model\tool;

use plugin\saimulti\basic\TenantModel;

/**
 * 邮件记录模型
 */
class Mail extends TenantModel
{
    /**
     * 数据表主键
     * @var string
     */
    protected $pk = 'id';

    protected $table = 'sm_tool_mail';

    public function searchFromAttr($query, $value)
    {
        $query->where('from', 'like', '%' . $value . '%');
    }

    public function searchEmailAttr($query, $value)
    {
        $query->where('email', 'like', '%' . $value . '%');
    }
}