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

namespace app\services\product\inventory;


use app\dao\product\inventory\StoreProductStockOrderDao;
use app\services\BaseServices;
use app\services\user\UserServices;
use app\services\product\product\StoreProductServices;
use app\services\product\sku\StoreProductAttrValueServices;
use app\services\store\SystemStoreStaffServices;
use app\services\supplier\SystemSupplierServices;
use app\services\system\admin\SystemAdminServices;
use mohe\services\FormBuilder as Form;
use think\exception\ValidateException;
use think\facade\Route as Url;
use function app;

/**
 * 出入库单
 * Class StoreProductStockOrderServices
 * @package app\services\product\Inventory
 * @mixin StoreProductStockOrderDao
 */
class StoreProductStockOrderServices extends BaseServices
{

	/**
	 * 库存类型
	 * @var string[]
	 */
	public $stockType = [
		1 => '入库',
		2 => '出库',
	];

	/**
	 * 入库单类型
	 * @var string[]
	 */
	public $inOrderType = [
		1 => '采购入库',
		2 => '其他入库',
		3 => '退货入库',
		4 => '残次品转良品',
		5 => '盘盈入库',
	];

	/**
	 * 出库单类型
	 * @var string[]
	 */
	public $outOrderType = [
		1 => '销售出库',
		2 => '过期退货',
		3 => '试用出库',
		4 => '报废出库',
		5 => '良品转残次品',
		6 => '其他出库',
		7 => '盘亏出库'
	];

    /**
     * StoreProductStockInOrderServices constructor.
     * @param StoreProductStockOrderDao $dao
     */
    public function __construct(StoreProductStockOrderDao $dao)
    {
        $this->dao = $dao;
    }

	/**
	 * 入库单详情
	 * @param int $id
	 * @return array
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function detail(int $id)
	{
		$info = $this->dao->get($id, ['id', 'stock_type']);
		if (!$id) {
			throw new ValidateException('出入库单不存在');
		}
		$info = $this->dao->get($id, ['*'], $info['stock_type'] == 1 ? ['refundOrderId'] : ['storeOrderId']);
		$info = $info->toArray();
		$admin = [];
		if ($info['admin_id']) {
			if ($info['stock_type'] == 2 && $info['order_type'] == 1) {//销售出库 为用户行为
//				/** @var UserServices $userServices */
//				$userServices = app()->make(UserServices::class);
//				$userInfo = $userServices->getUserCacheInfo($info['admin_id']);
//				$info['admin_name'] = $userInfo['nickname'] ?? '';
				$info['admin_name'] = '系统自动创建';
			} else {
				switch ($info['type']) {
					case 0://admin
						/** @var SystemAdminServices $systemAdminServices */
						$systemAdminServices = app()->make(SystemAdminServices::class);
						$admin = $systemAdminServices->getOne(['id' => $info['admin_id']], 'id,real_name as admin_name');
						break;
					case 1://门店
						/** @var SystemStoreStaffServices $staffServices */
						$staffServices = app()->make(SystemStoreStaffServices::class);
						$admin = $staffServices->getOne(['id' => $info['admin_id']], 'id,staff_name as admin_name');
						break;
					case 2://供应商
						/** @var SystemSupplierServices $supplierServices */
						$supplierServices = app()->make(SystemSupplierServices::class);
						$admin = $supplierServices->getOne(['id' => $info['admin_id']], 'id,supplier_name as admin_name');
						break;
				}
				$info['admin_name'] = $admin['admin_name'] ?? '系统自动创建';
			}
		}
		$info['stock_time'] = $info['stock_time'] ? date('Y-m-d', $info['stock_time']) : '';
		$info['add_time'] = $info['add_time'] ? date('Y-m-d H:i:s', $info['add_time']) : '';

		return $info;
	}

	/**
	 * 获取入库单列表
	 * @param array $where
	 * @param int $type
	 * @param int $relation_id
	 * @return array
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function getStockOrderList(array $where, int $type = 0, int $relation_id = 0, array $with = [])
	{
		$where['type'] = $type;
		$where['relation_id'] = $relation_id;
		$count = $this->dao->count($where);
		[$page, $limit] = $this->getPageValue();
		if (isset($where['stock_type'])) {//有单独筛选
			$with = array_merge($with, $where['stock_type'] == 1 ? ['refundOrderId'] : ['storeOrderId']);
		}
		if (isset($where['unique']) && $where['unique']) {//筛选某个商品的
			$with = array_merge($with,['detail' => function ($query) {
				$query->field("id,order_id,sum(stock) as stock, sum(defective_stock) as defective_stock")->group('order_id, unique');
			}]);
		}
		$list = $this->dao->getList($where, '*', $page, $limit, $with);
		if ($list) {
			$adminIds = array_column($list, 'admin_id');
			$adminIds = array_unique(array_diff($adminIds, [0]));
			$admin = [];
			if ($adminIds) {
				switch ($type) {
					case 0://admin
						/** @var SystemAdminServices $systemAdminServices */
						$systemAdminServices = app()->make(SystemAdminServices::class);
						$admin = $systemAdminServices->getColumn(['id' => $adminIds], 'id,real_name as admin_name', 'id');
						break;
					case 1://门店
						/** @var SystemStoreStaffServices $staffServices */
						$staffServices = app()->make(SystemStoreStaffServices::class);
						$admin = $staffServices->getColumn(['id' => $adminIds], 'id,staff_name as admin_name', 'id');
						break;
					case 2://供应商
						/** @var SystemSupplierServices $supplierServices */
						$supplierServices = app()->make(SystemSupplierServices::class);
						$admin = $supplierServices->getColumn(['id' => $adminIds], 'id,supplier_name as admin_name', 'id');
						break;
				}
			}
			/** @var UserServices $userServices */
			$userServices = app()->make(UserServices::class);
			foreach ($list as &$item) {
				if ($item['stock_type'] == 2 && $item['order_type'] == 1) {//销售出库 为用户行为
					//$userInfo = $userServices->getUserCacheInfo($item['admin_id']);
					//$item['admin_name'] = $userInfo['nickname'] ?? '';
					$item['admin_name'] = '系统自动创建';
				} else {
					$item['admin_name'] = $admin[$item['admin_id']]['admin_name'] ?? '系统自动创建';
				}
				$item['stock_time'] = $item['stock_time'] ? date('Y-m-d', $item['stock_time']) : '';
				$item['add_time'] = $item['add_time'] ? date('Y-m-d H:i:s', $item['add_time']) : '';
				if (isset($item['detail'])) {
					$item['stock'] = $item['detail'][0]['stock'] ?? 0;
					$item['defective_stock'] = $item['detail'][0]['defective_stock'] ?? 0;
					unset($item['detail']);
				}
			}
		}
		return compact('count', 'list');
	}


	/**
	 * 保存出入库记录
	 * @param int $stockType 1入库 2出库
	 * @param array $data
	 * @param int $type
	 * @param int $relation_id
	 * @param int $adminId
	 * @return bool
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function saveData(int $stockType = 1, array $data = [], int $type = 0, int $relation_id = 0, int $adminId = 0, bool $isStock = true)
	{
		$time = time();
		//入库单号
		$data['stock_type'] = $stockType;
		$data['order_id'] = $this->getUniqueId($data['stock_type'] == 1 ? 'IN' : 'OUT');
		$data['type'] = $type;
		$data['relation_id'] = $relation_id;
		$data['admin_id'] = $adminId;
		$data['add_time'] = $time;
		$data['stock_time'] = strtotime($data['stock_time']);
		$productDetail = $data['in_product_detail'] ?? $data['out_product_detail'] ?? [];
		unset($data['in_product_detail'], $data['out_product_detail']);
		$this->transaction(function () use ($stockType, $data, $productDetail, $type, $relation_id, $adminId, $isStock, $time) {
			$dataAll = $productAttrData = [];
			$res = $this->dao->save($data);
			if (!$res) {
				throw new ValidateException('入库单保存失败');
			}
			$inId = $res->id;
			/** @var StoreProductServices $productServices */
			$productServices = app()->make(StoreProductServices::class);
			/** @var StoreProductAttrValueServices $productAttrValueServices */
			$productAttrValueServices = app()->make(StoreProductAttrValueServices::class);
			foreach ($productDetail as $productSku) {
				$productInfo = $productServices->getCacheProductInfo((int)$productSku['product_id']);
				$attrInfo = $productAttrValueServices->getOne(['product_id' => $productSku['product_id'], 'unique' => $productSku['unique'], 'type' => 0]);
				if (!$productInfo || !$attrInfo) continue;
				$stock = $productSku['stock'] ?? 0;
				$defective_stock = $productSku['defective_stock'] ?? 0;
				if ($stockType == 1) {//残次品转良品入库
					if ($data['order_type'] == 4) {
						$defective_stock = -abs($stock);
					}
				} else {//良品转残次品出库
					if ($data['order_type'] == 5) {
						$defective_stock = abs($stock);
					} else {
						$defective_stock = -abs($defective_stock);
					}
					$stock = -abs($stock);
				}
				$dataAll[] = [
					'type' => $type,
					'relation_id' => $relation_id,
					'stock_type' => $data['stock_type'],//库存类型
					'order_type' => $data['order_type'],//出入单类型
					'order_id' => $inId,//出入库单ID
					'product_id' => $productSku['product_id'],
					'product_name' => $productInfo['store_name'] ?? '',
					'image' => $attrInfo['image'] ?? '',
					'sku' => $attrInfo['suk'] ?? '',
					'unique' => $productSku['unique'],
					'code' => $attrInfo['code'] ?? '',
					'bar_code' => $attrInfo['bar_code'] ?? '',
					'stock' => $stock,
					'defective_stock' => $defective_stock,
					'balance_stock' => $attrInfo['stock'] ?? 0,
					'admin_id' => $adminId,
					'add_time' => $time
				];
				if ($isStock) {//出入库单 同步修改商品库存
					$productAttrData[$productSku['product_id']][] = [
						'product_id' => $productSku['product_id'],
						'unique' => $productSku['unique'],
						'pm' => 1,
						'stock' => $stock,
						'defective_stock' => $defective_stock,
					];
				}
			}
			if ($dataAll) {
				/** @var StoreProductStockDetailServices $stockDetailServices */
				$stockDetailServices = app()->make(StoreProductStockDetailServices::class);
				$stockDetailServices->saveAll($dataAll);
			}
			//出入库单 同步商品库存
			if ($isStock && $productAttrData) {
				/** @var StoreProductAttrValueServices $attrServices */
				$attrServices = app()->make(StoreProductAttrValueServices::class);
				foreach ($productAttrData as $productId => $attrValue) {
					$attrServices->saveProductAttrsStock((int)$productId, $attrValue, $type, $relation_id, $adminId, false);
				}
			}
		});
		return true;
	}

	/**
	 * 获取备注表单
	 * @param int $id
	 * @return mixed
	 */
	public function getRemarkForm(int $id, int $stock_type = 1)
	{
		$order = $this->dao->get($id);
		if (!$order) {
			throw new ValidateException('数据不存在!');
		}
		if ($stock_type == 1) {//入库
			$form[] = Form::input('remark', '备注：', $order['remark'])->required('请输入备注');
			return create_form('入库单备注', $form, Url::buildUrl('/product/inventory/in/order/remark/'. $id), 'POST');
		} else {
			$form[] = Form::input('remark', '备注：', $order['remark'])->required('请输入备注');
			return create_form('出库单备注', $form, Url::buildUrl('/product/inventory/out/order/remark/'. $id), 'POST');
		}
	}


}
