<?php

declare(strict_types=1);

namespace plugin\saimulti\app\controller\admin;

use plugin\saimulti\basic\AdminController;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\ModuleRequired;
use plugin\saimulti\service\module\I18nService;
use plugin\saimulti\service\Permission;
use support\Request;
use support\Response;

#[ModuleRequired('i18n', 'server', 'i18n.admin.manage')]
final class I18nController extends AdminController
{
    #[Permission('平台语言列表', 'saimulti:admin:i18n:index')]
    public function index(Request $request): Response
    {
        return $this->success((new I18nService())->localeList(0, $request->get()));
    }

    #[Permission('平台语言详情', 'saimulti:admin:i18n:index')]
    public function read(Request $request): Response
    {
        return $this->success((new I18nService())->localeRead(0, $this->id($request)));
    }

    #[Permission('新增平台语言', 'saimulti:admin:i18n:save')]
    public function save(Request $request): Response
    {
        return $this->success((new I18nService())->localeCreate(0, $request->post(), $this->adminId));
    }

    #[Permission('更新平台语言', 'saimulti:admin:i18n:update')]
    public function update(Request $request): Response
    {
        return $this->success((new I18nService())->localeUpdate(0, $this->id($request), $request->post(), $this->adminId));
    }

    #[Permission('删除平台语言', 'saimulti:admin:i18n:destroy')]
    public function destroy(Request $request): Response
    {
        return $this->success([
            'deleted' => (new I18nService())->localeDelete(0, $this->ids($request), $this->adminId),
        ]);
    }

    #[Permission('平台词条列表', 'saimulti:admin:i18n:entry')]
    public function entryIndex(Request $request): Response
    {
        return $this->success((new I18nService())->entryList(0, $request->get()));
    }

    #[Permission('新增平台词条', 'saimulti:admin:i18n:entry')]
    public function entrySave(Request $request): Response
    {
        return $this->success((new I18nService())->entryCreate(0, $request->post(), $this->adminId));
    }

    #[Permission('更新平台词条', 'saimulti:admin:i18n:entry')]
    public function entryUpdate(Request $request): Response
    {
        return $this->success((new I18nService())->entryUpdate(0, $this->id($request), $request->post(), $this->adminId));
    }

    #[Permission('删除平台词条', 'saimulti:admin:i18n:entry')]
    public function entryDestroy(Request $request): Response
    {
        return $this->success([
            'deleted' => (new I18nService())->entryDelete(0, $this->ids($request), $this->adminId),
        ]);
    }

    private function id(Request $request): int
    {
        $id = $request->input('id');
        if (!is_int($id) && (!is_string($id) || !preg_match('/^\d+$/', $id))) {
            throw new ApiException('编号无效。', 422);
        }

        return (int) $id;
    }

    /** @return list<int> */
    private function ids(Request $request): array
    {
        $ids = $request->input('ids');
        if (!is_array($ids)) {
            throw new ApiException('编号列表无效。', 422);
        }

        return array_map(function (mixed $id): int {
            if (!is_int($id) && (!is_string($id) || !preg_match('/^\d+$/', $id))) {
                throw new ApiException('编号列表无效。', 422);
            }

            return (int) $id;
        }, $ids);
    }
}
