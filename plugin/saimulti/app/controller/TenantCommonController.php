<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\controller;

use plugin\saimulti\app\cache\DictCache;
use plugin\saimulti\app\cache\TenantAuthCache;
use plugin\saimulti\app\cache\TenantUserCache;
use plugin\saimulti\app\logic\admin\SystemCategoryLogic;
use plugin\saimulti\app\logic\system\SystemAttachmentLogic;
use plugin\saimulti\app\logic\tool\AreaCodeLogic;
use plugin\saimulti\basic\TenantController;
use plugin\saimulti\service\Permission;
use support\Request;
use support\Response;

/**
 * 系统控制器
 */
class TenantCommonController extends TenantController
{
    /**
     * 全部字典数据
     */
    public function dictAll(): Response
    {
        $dict = DictCache::getDictAll();
        return $this->success($dict);
    }

    /**
     * 上传图片
     */
    #[Permission('上传图片', 'saimulti:tenant:uploadImage')]
    public function uploadImage(Request $request): Response
    {
        $logic = new SystemAttachmentLogic();
        $type = $request->input('mode', 'system');
        if ($type == 'local') {
            return $this->success($logic->uploadBase('image', true));
        }
        return $this->success($logic->uploadBase('image'));
    }

    /**
     * 上传文件
     */
    #[Permission('上传文件', 'saimulti:tenant:uploadFile')]
    public function uploadFile(Request $request): Response
    {
        $logic = new SystemAttachmentLogic();
        $type = $request->input('mode', 'system');
        if ($type == 'local') {
            return $this->success($logic->uploadBase('file', true));
        }
        return $this->success($logic->uploadBase('file'));
    }

    /**
     * 分片上传
     */
    #[Permission('分片上传', 'saimulti:tenant:chunkUpload')]
    public function chunkUpload(Request $request): Response
    {
        $logic = new SystemAttachmentLogic();
        $data = $request->post();
        $result = $logic->chunkUpload($data);
        return $this->success($result);
    }

    /**
     * 获取资源分类
     */
    #[Permission('附件列表读取', 'saimulti:tenant:resource')]
    public function getResourceCategory(Request $request): Response
    {
        $logic = new SystemCategoryLogic();
        $data = $logic->tree([]);
        return $this->success($data);
    }

    /**
     * 获取资源列表
     */
    #[Permission('附件列表读取', 'saimulti:tenant:resource')]
    public function getResourceList(Request $request): Response
    {
        $logic = new SystemAttachmentLogic();
        $where = $request->more([
            ['origin_name', ''],
            ['category_id', ''],
        ]);
        $query = $logic->search($where);
        $query->whereIn('mime_type', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
        $data = $logic->getList($query);
        return $this->success($data);
    }

    /**
     * 获取地区级联数据
     */
    public function areaCode(Request $request): Response
    {
        $logic = new AreaCodeLogic();
        $code = $request->input('code', '');
        $pcode = $request->input('pcode', '');
        $level = (int) $request->input('level', 5);
        $data = $code !== '' ? $logic->cascaderPath($code, $level) : $logic->cascaderNodes($pcode, $level);
        return $this->success($data);
    }

    /**
     * 清除缓存
     */
    public function clearAllCache(): Response
    {
        TenantUserCache::clearUserInfo($this->tenantId);
        TenantAuthCache::clearUserAuth($this->tenantId);
        return $this->success([], '清除缓存成功!');
    }
}
