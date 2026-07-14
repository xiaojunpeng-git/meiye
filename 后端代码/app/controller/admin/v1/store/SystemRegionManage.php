<?php
namespace app\controller\admin\v1\store;

use app\controller\admin\AuthController;
use app\services\store\SystemRegionManageServices;
use think\facade\App;

/**
 * 区域架构
 */
class SystemRegionManage extends AuthController
{
    public function __construct(App $app, SystemRegionManageServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    /**
     * 子级区域列表（树懒加载）
     */
    public function index()
    {
        [$pid] = $this->request->getMore([['pid', 0]], true);
        return $this->success($this->services->getChildrenList((int)$pid));
    }

    /**
     * 全部区域
     */
    public function all()
    {
        return $this->success($this->services->getAllList());
    }

    /**
     * 完整区域树
     */
    public function tree()
    {
        return $this->success($this->services->getTree());
    }

    /**
     * 各区域门店/管理人员数量
     */
    public function counts()
    {
        return $this->success($this->services->getRegionCountsMap());
    }

    /**
     * 级联选择
     */
    public function cascader_list()
    {
        return $this->success($this->services->cascaderList());
    }

    /**
     * 详情
     */
    public function info($id)
    {
        $id = (int)$id;
        if (!$id) {
            return $this->fail('数据不存在');
        }
        $info = $this->services->get($id);
        if (!$info || $info['is_del']) {
            return $this->fail('数据不存在');
        }
        return $this->success($info->toArray());
    }

    /**
     * 保存
     */
    public function save($id = 0)
    {
        $data = $this->request->postMore([
            [['pid', 'd'], 0],
            [['name', 's'], ''],
            [['sort', 'd'], 0],
        ]);
        try {
            $regionId = $this->services->saveRegion((int)$id, $data);
            return $this->success('保存成功', ['id' => $regionId]);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * 删除
     */
    public function delete($id)
    {
        $id = (int)$id;
        if (!$id) {
            return $this->fail('数据不存在');
        }
        try {
            $this->services->deleteRegion($id);
            return $this->success('删除成功');
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * 获取架构区域关联代理商ID（门店筛选）
     */
    public function agent_ids($id)
    {
        $id = (int)$id;
        if (!$id) {
            return $this->success([]);
        }
        return $this->success($this->services->getAgentIdsByManageRegion($id, true));
    }
}
