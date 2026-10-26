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
namespace app\controller\supplier\product\inventory;

use app\controller\supplier\AuthController;
use app\services\product\inventory\StoreProductStockOrderServices;
use app\services\product\sku\StoreProductAttrValueServices;
use think\facade\App;

/**
 * 出库单
 * Class StoreProductStockOutOrder
 * @package app\controller\admin\v1\product\inventory
 */
class StoreProductStockOutOrder extends AuthController
{

    public function __construct(App $app, StoreProductStockOrderServices $service)
    {
        parent::__construct($app);
        $this->services = $service;
    }

	/**
	 * 获取出库单详情
	 * @param $id
	 * @return mixed
	 */
	public function info($id)
	{
		if (!$id) return $this->fail('缺少参数');
		return $this->success($this->services->detail((int)$id));
	}

	/**
	 * 出库单列表
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function index()
	{
		$where = $this->request->getMore([
			['order_type', ''],//出库类型
			['keyword', ''],//关键字搜索
			['stock_time', ''],//出库日期
			['add_time', '', '', 'time'],//创建时间
		]);
		//出库
		$where['stock_type'] = 2;
		return $this->success($this->services->getStockOrderList($where, 2, (int)$this->supplierId));
	}

	/**
	 * 保存出库单
	 * @param StoreProductAttrValueServices $attrValueServices
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function save(StoreProductAttrValueServices $attrValueServices)
	{
		$data = $this->request->postMore([
			['order_type', ''],//出库类型
			['refund_order_id', ''],//退款出库关联退款表ID
			['stock_time', ''],//出库日期
			['remark', ''],//出库备注
			['out_product_detail', []],//出库商品详情
		]);
		if (!$data['order_type']) {
			return $this->fail('请选择出库类型');
		}
		if ($data['order_type'] == 1 && !$data['order_id']) {
			return $this->fail('销售出库请选择订单');
		}
		if (!$data['out_product_detail']) {
			return $this->fail('请选择出库商品');
		}
		foreach ($data['out_product_detail'] as $productSku) {
			if (!isset($productSku['product_id']) || !isset($productSku['unique'])){
				return $this->fail('选择的出库商品格式错误，请重新选择');
			}
			$attrValue = $attrValueServices->getOne(['product_id' => $productSku['product_id'], 'unique' => $productSku['unique']]);
			if (!$attrValue) {
				return $this->fail('选择的商品信息不存在，请重新选择');
			}
			//良品出库
			if ($productSku['stock'] && $productSku['stock'] > $attrValue['stock']) {
				return $this->fail('商品ID：'. $productSku['product_id'] . '良品出库数量不能大于商品库存');
			}
			if ($productSku['defective_stock'] && $productSku['defective_stock'] > $attrValue['defective_stock']) {
				return $this->fail('商品ID：'. $productSku['product_id'] . '残次品出库数量不能大于商品残次品库存');
			}
		}
		$this->services->saveData(2, $data, 2, (int)$this->supplierId, (int)$this->supplierId);
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
		return app('json')->success($this->services->getRemarkForm((int)$id, 2));
	}

	/**
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
