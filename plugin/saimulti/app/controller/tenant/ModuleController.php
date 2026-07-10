<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\controller\tenant;

use plugin\saimulti\basic\TenantController;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\module\ModuleServiceFactory;
use plugin\saimulti\service\Permission;
use support\Request;
use support\Response;

class ModuleController extends TenantController
{
    #[Permission('可用模块', 'saimulti:tenant:module:index')]
    public function index(): Response
    {
        return $this->success(ModuleServiceFactory::manager()->availableForTenant((int) $this->organization));
    }

    #[Permission('启用租户模块', 'saimulti:tenant:module:enable')]
    public function enable(Request $request): Response
    {
        return $this->success(ModuleServiceFactory::manager()->enableTenant(
            (int) $this->organization,
            $this->moduleKey($request),
            $this->actor($request),
        ));
    }

    #[Permission('禁用租户模块', 'saimulti:tenant:module:disable')]
    public function disable(Request $request): Response
    {
        return $this->success(ModuleServiceFactory::manager()->disableTenant(
            (int) $this->organization,
            $this->moduleKey($request),
            $this->actor($request),
        ));
    }

    #[Permission('读取模块配置', 'saimulti:tenant:module:config:read')]
    public function config(Request $request): Response
    {
        return $this->success(ModuleServiceFactory::manager()->readTenantConfig(
            (int) $this->organization,
            $this->moduleKey($request),
        ));
    }

    #[Permission('更新模块配置', 'saimulti:tenant:module:config:update')]
    public function updateConfig(Request $request): Response
    {
        $config = $request->input('config');
        if (!is_array($config)) {
            throw new ApiException('config 必须为 JSON 对象。', 422);
        }

        return $this->success(ModuleServiceFactory::manager()->updateTenantConfig(
            (int) $this->organization,
            $this->moduleKey($request),
            $config,
            $this->actor($request),
        ));
    }

    private function moduleKey(Request $request): string
    {
        $moduleKey = trim((string) $request->input('module_key', ''));
        if ($moduleKey === '') {
            throw new ApiException('module_key 不能为空。', 422);
        }

        return $moduleKey;
    }

    /** @return array{type: string, id: int, ip: string} */
    private function actor(Request $request): array
    {
        return ['type' => 'tenant', 'id' => $this->tenantId, 'ip' => $request->getRealIp()];
    }
}
