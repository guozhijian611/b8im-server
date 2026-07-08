<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\controller;

use support\Request;
use support\Response;
use plugin\saimulti\service\Permission;
use plugin\saimulti\app\cache\DictCache;
use plugin\saimulti\basic\AdminController;
use plugin\saimulti\app\cache\AdminUserCache;
use plugin\saimulti\app\cache\AdminAuthCache;
use plugin\saimulti\app\logic\tool\AreaCodeLogic;
use plugin\saimulti\app\logic\admin\SystemCategoryLogic;
use plugin\saimulti\app\logic\system\SystemAttachmentLogic;

/**
 * 系统控制器
 */
class AdminCommonController extends AdminController
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
    #[Permission('上传图片', 'saimulti:system:uploadImage')]
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
    #[Permission('上传文件', 'saimulti:system:uploadFile')]
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
     * 切片上传
     */
    #[Permission('切片上传', 'saimulti:system:chunkUpload')]
    public function chunkUpload(Request $request): Response
    {
        $logic = new SystemAttachmentLogic();
        $data = $request->post();
        $result = $logic->chunkUpload($data);
        return $this->success($result);
    }

    /**
     * 获取资源列表
     * @param Request $request
     * @return Response
     */
    #[Permission('附件列表读取', 'saimulti:system:resource')]
    public function getResourceCategory(Request $request): Response
    {
        $logic = new SystemCategoryLogic();
        $data = $logic->tree([]);
        return $this->success($data);
    }

    /**
     * 获取资源列表
     * @param Request $request
     * @return Response
     */
    #[Permission('附件列表读取', 'saimulti:system:resource')]
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
     * @return Response
     */
    public function clearAllCache(): Response
    {
        AdminUserCache::clearUserInfo($this->adminId);
        AdminAuthCache::clearUserAuth($this->adminId);
        return $this->success([], '清除缓存成功!');
    }

}