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
namespace app\controller\admin\v1\product\inventory;

use app\controller\admin\AuthController;
use app\services\order\StoreOrderRefundServices;
use app\services\product\inventory\StoreProductStockCountServices;
use app\services\product\inventory\StoreProductStockOrderServices;
use think\facade\App;

/**
 * 入库单
 * Class StoreProductStockCount
 * @package app\controller\admin\v1\product\inventory
 */
class StoreProductStockCount extends AuthController
{

    public function __construct(App $app, StoreProductStockCountServices $service)
    {
        parent::__construct($app);
        $this->services = $service;
    }

	/**
	 * 获取库存盘点详情
	 * @param $id
	 * @return mixed
	 */
	public function info($id)
	{
		if (!$id) return $this->fail('缺少参数');
		return $this->success($this->services->detail((int)$id));
	}

	/**
	 * 库存盘点列表
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function index()
    {
        $where = $this->request->getMore([
			['keyword', ''],//关键字搜索
			['status', ''],//0
			['add_time', '', '', 'time'],//创建时间
        ]);
        return $this->success($this->services->getStockCountList($where));
    }


	/**
	 * 保存库存盘点
	 * @param $id
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function save($id)
    {
        $data = $this->request->postMore([
			['status', 1],//状态:0 草稿,1完成
			['remark', ''],//入库备注
			['product_detail', []],//盘点商品详情
        ]);
		if (!$data['product_detail']) {
			return $this->fail('请选择入库商品');
		}
		foreach ($data['product_detail'] as $productSku) {
			if (!isset($productSku['product_id']) || !isset($productSku['unique'])){
				return $this->fail('选择的盘点商品格式错误，请重新选择');
			}
		}
        $this->services->saveData((int)$id, $data, 0, 0, (int)$this->adminId);
        return $this->success('保存成功!');
    }

	/**
	 * 写入备注表单
	 * @param $id
	 * @return \think\Response
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function remarkForm($id)
	{
		if (!$id)
			return app('json')->fail('缺少参数');
		return app('json')->success($this->services->getRemarkForm((int)$id));
	}

    /**z
     * 获取规格信息
     * @param $id
     * @return mixed
     */
    public function remark($id)
    {
		$data = $this->request->postMore([['remark', '']]);
		if (!$data['remark'])
			return app('json')->fail('请输入备注的内容');
		if (!$id)
			return app('json')->fail('缺少参数');
		if (!$inOrder = $this->services->get($id)) {
			return app('json')->fail('数据不存在!');
		}
		$inOrder->remark = $data['remark'];
		if ($inOrder->save()) {
			return app('json')->success('备注成功');
		} else
			return app('json')->fail('备注失败');
    }

}
