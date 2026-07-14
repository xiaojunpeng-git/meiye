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
namespace app\controller\store\product\inventory;

use app\controller\store\AuthController;
use app\services\order\StoreOrderRefundServices;
use app\services\product\inventory\StoreProductStockOrderServices;
use app\services\product\sku\StoreProductAttrValueServices;
use think\facade\App;

/**
 * 入库单
 * Class StoreProductStockInOrder
 * @package app\controller\admin\v1\product\inventory
 */
class StoreProductStockInOrder extends AuthController
{

    public function __construct(App $app, StoreProductStockOrderServices $service)
    {
        parent::__construct($app);
        $this->services = $service;
    }

	/**
	 * 获取入库单详情
	 * @param $id
	 * @return mixed
	 */
	public function info($id)
	{
		if (!$id) return $this->fail('缺少参数');
		return $this->success($this->services->detail((int)$id));
	}

	/**
	 * 入库单列表
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function index()
    {
        $where = $this->request->getMore([
            ['order_type', ''],//入库类型
			['keyword', ''],//关键字搜索
			['stock_time', ''],//入库日期
			['add_time', '', '', 'time'],//创建时间
        ]);
		//入库单
		$where['stock_type'] = 1;
        return $this->success($this->services->getStockOrderList($where, 1, (int)$this->storeId));
    }

	/**
	 * 获取退款单信息
	 * @param StoreOrderRefundServices $refundServices
	 * @return mixed
	 */
	public function getRefundOrderInfo(StoreOrderRefundServices $refundServices)
	{
		[$order_id] = $this->request->postMore([
			['order_id', ''],//退款入库关联退款表ID
		], true);
		if (!$order_id) return app('json')->fail('缺少参数');
		$order = $refundServices->get(['id|order_id' => $order_id], ['*']);
		if (!$order) return $this->fail('单号错误，请重新选择');
		if ($order['refund_type'] != 6) {//退款单需要是已完成状态
			return app('json')->fail('退款单需要是已完成状态');
		}
		if ($this->services->count(['stock_type' => 1, 'order_type' => 3, 'refund_order_id' => $order['id']])) {
			return app('json')->fail('该售后单已入库，请重新选择');
		}
		$order = $order->toArray();
		$result = [];
		$result['orderInfo'] = ['id' => $order['id'], 'order_id' => $order['order_id']];
		$cartInfo = $order['cart_info'];
		$productInfo = [];
		if ($cartInfo) {
			foreach ($cartInfo as $cart) {
				$pInfo = $cart['productInfo'] ?? [];
				if (!$pInfo) continue;
				$productInfo[] = [
					'product_id' => $pInfo['id'],
					'store_name' => $pInfo['store_name'],
					'stock' => $cart['cart_num'] ?? 1,
					'sku' => $pInfo['attrInfo']['suk'] ?? '',
					'unique' => $pInfo['attrInfo']['unique'] ?? ''
				];
			}
		}
		$result['productInfo'] = $productInfo;
		return $this->success($result);
	}

	/**
	 * 保存入库单
	 * @param StoreProductAttrValueServices $attrValueServices
	 * @param StoreOrderRefundServices $refundServices
	 * @return \think\Response
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function save(StoreProductAttrValueServices $attrValueServices, StoreOrderRefundServices $refundServices)
    {
        $data = $this->request->postMore([
            ['order_type', ''],//入库类型
			['refund_order_id', ''],//退款入库关联退款表ID
            ['stock_time', ],//入库日期
			['remark', ''],//入库备注
			['in_product_detail', []],//入库商品详情
        ]);
		if (!$data['order_type']) {
			return $this->fail('请选择入库类型');
		}
		//退货入库
		if ($data['order_type'] == 3) {
			if (!$data['refund_order_id']) {
				return $this->fail('退货入库请选择退款单');
			}
			$refundOrder = $refundServices->get($data['refund_order_id']);
			if (!$refundOrder) {
				return $this->fail('退款单不存在');
			}
			if ($refundOrder['refund_type'] != 6) {//退款单需要是已完成状态
				return app('json')->fail('退款单需要是已完成状态');
			}
			if ($this->services->count(['stock_type' => 1, 'order_type' => 3, 'refund_order_id' => $refundOrder['id']])) {
				return app('json')->fail('该售后单已入库，请重新输入');
			}
		}
		if (!$data['in_product_detail']) {
			return $this->fail('请选择入库商品');
		}
		foreach ($data['in_product_detail'] as &$productSku) {
			if (!isset($productSku['product_id']) || !isset($productSku['unique'])){
				return $this->fail('选择的入库商品格式错误，请重新选择');
			}
			$attrValue = $attrValueServices->getOne(['product_id' => $productSku['product_id'], 'unique' => $productSku['unique']]);
			if (!$attrValue) {
				return $this->fail('选择的商品信息不存在，请重新选择');
			}
			//入库数量
			if (isset($productSku['in']) && $productSku['in'] && isset($productSku['stock']) && !$productSku['stock']) $productSku['stock'] = $productSku['in'];
			if ($data['order_type'] == 4) {//残次品转良品 验证残次品库存
				if ($productSku['stock'] && $productSku['stock'] > $attrValue['defective_stock']) {
					return $this->fail('商品ID：'. $productSku['product_id'] . '入库数量不能大于商品残次品库存');
				}
			}
		}
        $this->services->saveData(1, $data, 1, (int)$this->storeId, (int)$this->storeStaffId);
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

	/**
	 * 下载入库 Excel 模板（scene=initial_in|in）
	 */
	public function downloadTemplate(\app\services\product\inventory\StoreProductStockImportServices $importServices)
	{
		[$scene, $keyword] = $this->request->getMore([
			['scene', 'in'],
			['keyword', ''],
		], true);
		if (!in_array($scene, ['initial_in', 'in'], true)) {
			return $this->fail('模板场景不正确');
		}
		$result = $importServices->downloadTemplate($scene, 1, (int)$this->storeId, ['keyword' => $keyword]);
		return $this->success($result);
	}

	/**
	 * 导入入库 Excel
	 */
	public function import(\app\services\product\inventory\StoreProductStockImportServices $importServices)
	{
		[$scene, $file, $realName] = $this->request->postMore([
			['scene', 'in'],
			['file', ''],
			['real_name', ''],
		], true);
		if (!in_array($scene, ['initial_in', 'in'], true)) {
			return $this->fail('导入场景不正确');
		}
		if (!$file) {
			return $this->fail('请上传文件');
		}
		$path = public_path() . (($file[0] ?? '') === '/' ? substr($file, 1) : ltrim($file, '/'));
		$result = $importServices->importFile(
			$scene,
			$path,
			1,
			(int)$this->storeId,
			(int)$this->storeStaffId,
			(string)($this->storeStaffInfo['staff_name'] ?? ''),
			(string)$realName
		);
		return $this->success('导入成功', $result);
	}

}
