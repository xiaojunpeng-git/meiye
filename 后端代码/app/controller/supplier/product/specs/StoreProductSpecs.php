<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2020 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------
namespace app\controller\supplier\product\specs;

use app\controller\supplier\AuthController;
use app\services\product\specs\StoreProductSpecsServices;
use app\services\product\specs\StoreProductSpecsTemplateServices;
use think\facade\App;
use app\Request;

/**
 * 商品参数
 * Class StoreProductSpecs
 * @package app\controller\supplier\product\specs
 */
class StoreProductSpecs extends AuthController
{
    /**
     * 参数模版services
     * @var StoreProductSpecsTemplateServices
     */
    protected $templateServices;

    /**
     * StoreProductSpecs constructor.
     * @param App $app
     * @param StoreProductSpecsServices $services
     * @param StoreProductSpecsTemplateServices $templateServices
     */
    public function __construct(App $app, StoreProductSpecsServices $services, StoreProductSpecsTemplateServices $templateServices)
    {
        parent::__construct($app);
        $this->services = $services;
        $this->templateServices = $templateServices;
    }


    /**
     * 显示资源列表
     *
     * @return \think\Response
     */
    public function index(Request $request)
    {
        $where = $request->postMore([
            ['name', '']
        ]);
        return $this->success($this->templateServices->getProductSpecsTemplateList($where, 2, (int)$this->supplierId));
    }

    /**
     * 显示资源列表
     *
     * @return \think\Response
     */
    public function allSpecs(Request $request)
    {
        return $this->success($this->templateServices->getAllSpecs(2, (int)$this->supplierId));
    }

    /**
     * 获取参数模版信息
     * @param $id
     * @return mixed
     */
    public function getInfo($id)
    {
        if (!$id) return $this->fail('缺少参数ID');
        $temp = $this->templateServices->get(['id' => $id, 'group' => 3], ['*'], ['specs']);
        if (!$temp) {
            return $this->fail('参数模版不存在');
        }
        return $this->success($temp->toArray());
    }


    /**
     * 添加、编辑商品参数模版
     * @param Request $request
     * @param $id
     * @return mixed
     */
    public function save(Request $request, $id)
    {
        $data = $request->postMore([
            ['name', ''],
            ['specs', []],
            ['sort', ''],
        ]);
        if (!$data['name']) {
            return $this->fail('请输入参数模版名称');
        }
        $specs = $data['specs'];
        unset($data['specs']);
		$type = 2;
		$supplier_id = (int)$this->supplierId;
        $temp = $this->templateServices->getOne(['name' => $data['name'], 'group' => 3, 'type' => $type, 'relation_id' => $supplier_id]);
        if ($id) {
            if ($temp && $id != $temp['id']) {
                return $this->fail('参数模版已经存在');
            }
            $this->templateServices->transaction(function () use ($id, $data, $specs, $type, $supplier_id) {
                $this->templateServices->update($id, $data);
                $this->services->updateData($id, $specs);
            });
            return $this->success('编辑成功');
        } else {
            if ($temp) {
                return $this->fail('参数模版已经存在');
            }
            $data['type'] = $type;
            $data['relation_id'] = $supplier_id;
            $data['group'] = 3;
            $data['add_time'] = time();
            $this->templateServices->transaction(function () use ($id, $data, $specs, $type, $supplier_id) {
                $res = $this->templateServices->save($data);
                $this->services->saveData((int)$res->id, $specs, $type, $supplier_id);
            });
            return $this->success('保存成功');
        }
    }

    /**
     * 删除指定资源
     *
     * @param int $id
     * @return \think\Response
     */
    public function delete($id)
    {
        if (!$id || !($this->templateServices->count(['id' => $id]))) {
            return $this->fail('删除的数据不存在');
        }
        $this->templateServices->delete($id);
        $this->services->delete(['temp_id' => $id]);
        return $this->success('删除成功');
    }
}
