<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\controller\admin;

use plugin\saimulti\basic\AdminController;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\module\ModuleServiceFactory;
use plugin\saimulti\service\Permission;
use support\Request;
use support\Response;

class ModuleController extends AdminController
{
    #[Permission('模块目录', 'saimulti:admin:module:catalog')]
    public function catalog(): Response
    {
        return $this->success(ModuleServiceFactory::manager()->catalog());
    }

    #[Permission('模块详情', 'saimulti:admin:module:read')]
    public function read(Request $request): Response
    {
        return $this->success(ModuleServiceFactory::manager()->detail($this->moduleKey($request)));
    }

    #[Permission('发现模块', 'saimulti:admin:module:discover')]
    public function discover(Request $request): Response
    {
        $moduleKey = trim((string) $request->input('module_key', ''));

        return $this->success(ModuleServiceFactory::manager()->discover(
            $moduleKey === '' ? null : $moduleKey,
            $this->actor($request),
        ));
    }

    #[Permission('安装模块', 'saimulti:admin:module:install')]
    public function install(Request $request): Response
    {
        return $this->success(ModuleServiceFactory::manager()->install($this->moduleKey($request), $this->actor($request)));
    }

    #[Permission('升级模块', 'saimulti:admin:module:upgrade')]
    public function upgrade(Request $request): Response
    {
        return $this->success(ModuleServiceFactory::manager()->upgrade($this->moduleKey($request), $this->actor($request)));
    }

    #[Permission('启用系统模块', 'saimulti:admin:module:enable')]
    public function enable(Request $request): Response
    {
        return $this->success(ModuleServiceFactory::manager()->enableSystem($this->moduleKey($request), $this->actor($request)));
    }

    #[Permission('禁用系统模块', 'saimulti:admin:module:disable')]
    public function disable(Request $request): Response
    {
        return $this->success(ModuleServiceFactory::manager()->disableSystem($this->moduleKey($request), $this->actor($request)));
    }

    #[Permission('卸载模块', 'saimulti:admin:module:uninstall')]
    public function uninstall(Request $request): Response
    {
        return $this->success(ModuleServiceFactory::manager()->uninstall(
            $this->moduleKey($request),
            $this->boolean($request->input('preserve_data', true)),
            $this->actor($request),
        ));
    }

    #[Permission('授权租户模块', 'saimulti:admin:module:license:grant')]
    public function grant(Request $request): Response
    {
        return $this->success(ModuleServiceFactory::manager()->grantLicense(
            (int) $request->input('organization', 0),
            $this->moduleKey($request),
            $this->nullableString($request->input('expire_at')),
            $this->nullableString($request->input('remark')),
            $this->actor($request),
        ));
    }

    #[Permission('撤销租户模块授权', 'saimulti:admin:module:license:revoke')]
    public function revoke(Request $request): Response
    {
        return $this->success(ModuleServiceFactory::manager()->revokeLicense(
            (int) $request->input('organization', 0),
            $this->moduleKey($request),
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
        return ['type' => 'admin', 'id' => $this->adminId, 'ip' => $request->getRealIp()];
    }

    private function boolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) {
            throw new ApiException('preserve_data 必须为布尔值。', 422);
        }

        return $parsed;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }
}
