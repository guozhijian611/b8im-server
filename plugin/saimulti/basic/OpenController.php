<?php
// +----------------------------------------------------------------------
// | saiadmin [ saiadmin快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\basic;

use support\Request;
use support\Response;
use plugin\saimulti\exception\ApiException;

/**
 * 基类 控制器继承此类
 */
class OpenController
{
    /**
     * 逻辑层注入
     */
    protected $logic;

    /**
     * 验证器注入
     */
    protected $validate;

    /**
     * 构造方法
     * @access public
     */
    public function __construct()
    {
        // 控制器初始化
        $this->init();
    }

    /**
     * 成功返回json内容
     * @param array|string $data
     * @param string $msg
	 * @param int $option
     * @return Response
     */
    public function success($data = [], string $msg = 'success', $option = JSON_UNESCAPED_UNICODE): Response
    {
        if (is_string($data)) {
            $msg = $data;
        }
        return json(['code' => 200, 'message' => $msg, 'data' => $data], $option);
    }

    /**
     * 失败返回json内容
     * @param string $msg
     * @return Response
     */
    public function fail(string $msg = 'fail'): Response
    {
        return json(['code' => 400, 'message' => $msg, 'type' => 'failed']);
    }

    /**
     * 初始化
     */
    protected function init(): void
    {
        // TODO
    }

    /**
     * 验证器调用
     */
    protected function validate(string $scene, $data): bool
    {
        if ($this->validate) {
            if (!$this->validate->scene($scene)->check($data)) {
                throw new ApiException($this->validate->getError());
            }
        }
        return true;
    }

}
