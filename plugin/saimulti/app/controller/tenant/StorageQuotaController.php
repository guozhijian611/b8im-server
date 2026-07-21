<?php

declare(strict_types=1);

namespace plugin\saimulti\app\controller\tenant;

use plugin\saimulti\basic\TenantController;
use plugin\saimulti\service\Permission;
use plugin\saimulti\service\quota\StorageQuotaService;
use support\Request;
use support\Response;

final class StorageQuotaController extends TenantController
{
    #[Permission('机构存储配额读取', 'saimulti:tenant:storage_quota:read')]
    public function read(Request $request): Response
    {
        return $this->success(
            (new StorageQuotaService())->read((int) $this->organization),
        );
    }
}
