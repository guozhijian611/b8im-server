<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\controller;

use plugin\saimulti\app\logic\admin\AdminLogic;
use plugin\saimulti\app\logic\system\SystemOrganizationLogic;
use plugin\saimulti\app\logic\tenant\UserLogic;
use plugin\saimulti\basic\OpenController;
use plugin\saimulti\utils\Captcha;
use support\Request;
use support\Response;

/**
 * 登录控制器
 */
class LoginController extends OpenController
{
    /**
     * 获取验证码
     */
    public function captcha(Request $request) : Response
    {
        $captcha = new Captcha();
        $result = $captcha->imageCaptcha();
        if ($result['result'] !== 1) {
            return $this->fail($result['message']);
        }
        return $this->success($result);
    }

    /**
     * 管理中台登录
     * @param Request $request
     * @return Response
     */
    public function adminLogin(Request $request): Response
    {
        $username = $request->post('username');
        $password = $request->post('password');
        $type = $request->post('type', 'pc');

        $code = $request->post('code', '');
        $uuid = $request->post('uuid', '');
        $captcha = new Captcha();
        if (!$captcha->checkCaptcha($uuid, $code)) {
            return $this->fail('验证码错误');
        }
        $logic = new AdminLogic();
        $data = $logic->login($username, $password, $type);
        return $this->success($data);
    }

    /**
     * 租户登录
     * @param Request $request
     * @return Response
     */
    public function tenantLogin(Request $request): Response
    {
        $username = $request->post('username');
        $password = $request->post('password');
        $type = $request->post('type', 'pc');

        $code = $request->post('code', '');
        $uuid = $request->post('uuid', '');
        $captcha = new Captcha();
        if (!$captcha->checkCaptcha($uuid, $code)) {
            return $this->fail('验证码错误');
        }
        $logic = new UserLogic();
        $data = $logic->login($username, $password, $type);
        return $this->success($data);
    }

    /**
     * 应用信息
     * @param Request $request
     * @return Response
     */
    public function appInfo(Request $request): Response
    {
        $id = $request->input('appid', '');
        $mode = $request->input('mode', '');
        if (empty($id)) {
            $id = $request->header('App-Id', '');
        }
        $logic = new SystemOrganizationLogic();
        $data = $logic->appInfo($id, $mode);
        return $this->success($data);
    }

}