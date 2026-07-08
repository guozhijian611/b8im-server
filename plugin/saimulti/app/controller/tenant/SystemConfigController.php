<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\controller\tenant;

use support\Request;
use support\Response;
use plugin\saimulti\service\Permission;
use plugin\saimulti\basic\TenantController;
use plugin\saimulti\app\logic\tenant\ConfigLogic;
use plugin\saimulti\app\logic\system\SystemOrganizationLogic;

/**
 * 配置控制器
 */
class SystemConfigController extends TenantController
{
    /**
     * 构造
     */
    public function __construct()
    {
        $this->logic = new SystemOrganizationLogic();
        parent::__construct();
    }

    /**
     * 基本配置
     * @return Response
     */
    #[Permission('基础配置', 'saimulti:tenant:config:index')]
    public function basicConfig(): Response
    {
        $data = $this->logic->tenant($this->organization);
        return $this->success($data);
    }

    /**
     * 保存基本配置
     * @param Request $request
     * @return Response
     */
    #[Permission('保存基本配置', 'saimulti:tenant:config:save')]
    public function saveBasic(Request $request): Response
    {
        $data = $request->post();
        unset($data['organization']);
        unset($data['domain']);
        $region = $data['region'];
        if (is_array($region)) {
            $data['province'] = $region[0] ?? '';
            $data['city'] = $region[1] ?? '';
            $data['area'] = $region[2] ?? '';
        }
        $result = $this->logic->update($data, ['id' => $this->organization]);
        if ($result) {
            return $this->success('保存成功');
        } else {
            return $this->fail('保存失败');
        }
    }

    /**
     * 分组配置
     * @return Response
     */
    #[Permission('分组配置', 'saimulti:tenant:config:index')]
    public function groupConfig(): Response
    {
        $logic = new ConfigLogic();
        $data = $logic->groupConfig();
        return $this->success($data);
    }

    /**
     * 保存配置
     * @param Request $request
     * @return Response
     */
    #[Permission('保存配置', 'saimulti:tenant:config:save')]
    public function saveGroup(Request $request): Response
    {
        $data = $request->post();
        $logic = new ConfigLogic();
        $result = $logic->saveGroup($data);
        if ($result) {
            return $this->success('保存成功');
        } else {
            return $this->fail('保存失败');
        }
    }
}
