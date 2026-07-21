<?php

declare(strict_types=1);

namespace plugin\saimulti\app\controller\admin;

use plugin\saimulti\basic\AdminController;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\Permission;
use plugin\saimulti\service\quota\StorageQuotaService;
use plugin\saimulti\utils\CanonicalInteger;
use support\Request;
use support\Response;

final class StorageQuotaController extends AdminController
{
    #[Permission('机构存储配额列表', 'saimulti:admin:storage_quota:index')]
    public function index(Request $request): Response
    {
        return $this->success((new StorageQuotaService())->index($request->get()));
    }

    #[Permission('机构存储配额读取', 'saimulti:admin:storage_quota:index')]
    public function read(Request $request): Response
    {
        return $this->success(
            (new StorageQuotaService())->read($this->organization($request)),
        );
    }

    #[Permission('机构存储配额更新', 'saimulti:admin:storage_quota:update')]
    public function update(Request $request): Response
    {
        $input = is_array($request->post()) ? $request->post() : [];
        $keys = array_keys($input);
        sort($keys);
        if ($keys !== ['organization', 'quota_value', 'version']) {
            throw new ApiException(
                '请求体必须且只能包含 organization、quota_value、version。',
                422,
            );
        }

        return $this->success((new StorageQuotaService())->update(
            $this->organization($request),
            $input['quota_value'],
            $input['version'],
            $this->adminId,
        ));
    }

    private function organization(Request $request): int
    {
        return CanonicalInteger::positive(
            $request->input('organization') ?? $request->get('organization'),
            '机构编号',
        );
    }
}
