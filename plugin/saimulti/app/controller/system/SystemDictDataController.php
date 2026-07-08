<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\controller\system;

use plugin\saimulti\app\logic\system\SystemDictDataLogic;
use plugin\saimulti\app\validate\system\SystemDictDataValidate;
use plugin\saimulti\basic\AdminController;
use plugin\saimulti\service\Permission;
use plugin\saimulti\app\cache\DictCache;
use support\Request;
use support\Response;

/**
 * 字典类型控制器
 */
class SystemDictDataController extends AdminController
{
    /**
     * 构造
     */
    public function __construct()
    {
        $this->logic = new SystemDictDataLogic();
        $this->validate = new SystemDictDataValidate;
        parent::__construct();
    }

   /**
     * 数据列表
     * @param Request $request
     * @return Response
     */
    #[Permission('数据字典列表', 'saimulti:dict:index')]
    public function index(Request $request) : Response
    {
        $where = $request->more([
            ['name', ''],
            ['code', ''],
            ['type_id', ''],
            ['status', ''],
        ]);
        $query = $this->logic->search($where);
        $data = $this->logic->getList($query);
        return $this->success($data);
    }

    /**
     * 保存数据
     * @param Request $request
     * @return Response
     */
    #[Permission('数据字典新增', 'saimulti:dict:save')]
    public function save(Request $request): Response
    {
        $data = $request->post();
        $this->validate('save', $data);
        $result = $this->logic->add($data);
        if ($result) {
            DictCache::clear();
            return $this->success('添加成功');
        } else {
            return $this->fail('添加失败');
        }
    }

    /**
     * 更新数据
     * @param Request $request
     * @return Response
     */
    #[Permission('数据字典更新', 'saimulti:dict:update')]
    public function update(Request $request): Response
    {
        $data = $request->post();
        $this->validate('update', $data);
        $result = $this->logic->edit($data['id'], $data);
        if ($result) {
            DictCache::clear();
            return $this->success('修改成功');
        } else {
            return $this->fail('修改失败');
        }
    }

    /**
     * 删除数据
     * @param Request $request
     * @return Response
     */
    #[Permission('数据字典删除', 'saimulti:dict:destroy')]
    public function destroy(Request $request) : Response
    {
        $ids = $request->post('ids', '');
        if (empty($ids)) {
            return $this->fail('请选择要删除的数据');
        }
        $result = $this->logic->destroy($ids);
        if ($result) {
            DictCache::clear();
            return $this->success('删除成功');
        } else {
            return $this->fail('删除失败');
        }
    }
}
