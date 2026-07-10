<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\logic\system;

use plugin\saimulti\utils\Arr;
use plugin\saimulti\basic\BaseLogic;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\storage\ChunkUploadService;
use plugin\saimulti\service\storage\UploadService;
use plugin\saimulti\app\model\system\SystemCategory;
use plugin\saimulti\app\model\system\SystemAttachment;

/**
 * 附件逻辑层
 */
class SystemAttachmentLogic extends BaseLogic
{
    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->model = new SystemAttachment();
        parent::__construct();
    }

    /**
     * @param $category_id
     * @param $ids
     * @return mixed
     */
    public function move($category_id, $ids): mixed
    {
        $category = SystemCategory::where('id', $category_id)->findOrEmpty();
        if ($category->isEmpty()) {
            throw new ApiException('目标分类不存在');
        }
        return $this->model->whereIn('id', $ids)->update(['category_id' => $category_id]);
    }

    /**
     * 文件上传
     * @param string $upload
     * @param bool $local
     * @return array
     */
    public function uploadBase($upload = 'image', $local = false, ?string $dirname = null): array
    {
        $logic = new SystemConfigLogic();
        $uploadConfig = $logic->getGroup('upload_config');
        $type = Arr::getConfigValue($uploadConfig, 'upload_mode');
        if ($local === true) {
            $type = 1;
        }
        $result = UploadService::disk($type, $upload, true, $dirname)->uploadFile();
        $data = $result[0];
        $url = str_replace('\\', '/', $data['url']);
        $savePath = str_replace('\\', '/', $data['save_path']);
        $info['storage_mode'] = $type;
        $info['category_id'] = request()->input('category_id', 1);
        $info['origin_name'] = $data['origin_name'];
        $info['object_name'] = $data['save_name'];
        $info['hash'] = $data['unique_id'];
        $info['mime_type'] = $data['mime_type'];
        $info['storage_path'] = $savePath;
        $info['suffix'] = $data['extension'];
        $info['size_byte'] = $data['size'];
        $info['size_info'] = formatBytes($data['size']);
        $info['url'] = $url;
        $this->model->save($info);
        return $info;
    }

    /**
     * 切片上传
     * @param $data
     * @return array
     */
    public function chunkUpload($data): array
    {
        $chunkService = new ChunkUploadService();
        if ($data['index'] == 0) {
            $model = $this->model->where('hash', $data['hash'])->findOrEmpty();
            if (!$model->isEmpty()) {
                return $model->toArray();
            } else {
                return $chunkService->checkChunk($data);
            }
        } else {
            return $chunkService->uploadChunk($data);
        }
    }

}
