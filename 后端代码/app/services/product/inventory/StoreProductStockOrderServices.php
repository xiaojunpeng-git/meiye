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
use app\services\product\product\StoreProductSkuWriteLock;
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
	/**
	 * 入库单类型
	 * 注意：order_type=8 双语义——入库=调拨入库，出库=院装领用；展示/筛选必须带 stock_type
	 * @var string[]
	 */
	public $inOrderType = [
		1 => '采购入库',
		2 => '其他入库',
		3 => '退货入库',
		4 => '残次品转良品',
		5 => '盘盈入库',
		6 => '初始入库',
		7 => '院装退回',
		8 => '调拨入库',
	];

	/**
	 * 出库单类型
	 * 注意：order_type=8 双语义——出库=院装领用，入库=调拨入库；展示/筛选必须带 stock_type
	 * @var string[]
	 */
	public $outOrderType = [
		1 => '销售出库',
		2 => '过期退货',
		3 => '试用出库',
		4 => '报废出库',
		5 => '良品转残次品',
		6 => '其他出库',
		7 => '盘亏出库',
		8 => '院装领用',
		9 => '调拨出库',
	];

	/**
	 * 按出入库方向解析 order_type 文案（硬化双语义 8）
	 */
	public function resolveOrderTypeName(int $stockType, int $orderType): string
	{
		if ($stockType === 1) {
			return (string)($this->inOrderType[$orderType] ?? '');
		}
		if ($stockType === 2) {
			return (string)($this->outOrderType[$orderType] ?? '');
		}
		return '';
	}

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
	public function getStockOrderList(array $where, int $type = 0, $relation_id = 0, array $with = [], bool $allStores = false)
	{
		$where['type'] = $type;
		if ($allStores) {
			unset($where['relation_id']);
			// 全部门店：仅有效门店单据，relation_id>0
			$where['relation_id'] = \think\facade\Db::name('system_store')
				->where('is_del', 0)->where('is_show', 1)->column('id');
			if (!$where['relation_id']) {
				return ['count' => 0, 'list' => []];
			}
		} else {
			$where['relation_id'] = $relation_id;
		}
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
			$storeNames = [];
			if ((int)$type === 1 || $allStores) {
				$storeIds = array_values(array_unique(array_filter(array_map('intval', array_column($list, 'relation_id')))));
				if ($storeIds) {
					$storeNames = \think\facade\Db::name('system_store')->whereIn('id', $storeIds)->column('name', 'id');
				}
			}
			foreach ($list as &$item) {
				if ($item['stock_type'] == 2 && $item['order_type'] == 1) {//销售出库 为用户行为
					$item['admin_name'] = '系统自动创建';
				} else {
					$item['admin_name'] = $admin[$item['admin_id']]['admin_name'] ?? '系统自动创建';
				}
				$item['stock_time'] = $item['stock_time'] ? date('Y-m-d', $item['stock_time']) : '';
				$item['add_time'] = $item['add_time'] ? date('Y-m-d H:i:s', $item['add_time']) : '';
				$rid = (int)($item['relation_id'] ?? 0);
				$item['store_name_label'] = ((int)$type === 0 && $rid === 0)
					? '总部仓'
					: ($storeNames[$rid] ?? ($rid > 0 ? ('门店#' . $rid) : ''));
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
	public function saveData(int $stockType = 1, array $data = [], int $type = 0, int $relation_id = 0, int $adminId = 0, bool $isStock = true, bool $isTran = true)
	{
		$time = time();
		//入库单号
		$data['stock_type'] = $stockType;
		$data['order_id'] = $this->getUniqueId($data['stock_type'] == 1 ? 'IN' : 'OUT');
		$data['type'] = $type;
		$data['relation_id'] = $relation_id;
		$data['admin_id'] = $adminId;
		$data['add_time'] = $time;
		$data['stock_time'] = is_numeric($data['stock_time']) ? (int)$data['stock_time'] : strtotime($data['stock_time']);
		// 非 Excel 导入保持 import_key 为空，便于唯一索引允许多条 NULL
		if (!isset($data['import_key']) || $data['import_key'] === '') {
			$data['import_key'] = null;
		}
		$orderType = (int)($data['order_type'] ?? 0);
		if ($stockType == 1) {
			if (!isset($this->inOrderType[$orderType])) {
				throw new ValidateException('入库类型不正确');
			}
		} else {
			if (!isset($this->outOrderType[$orderType])) {
				throw new ValidateException('出库类型不正确');
			}
		}
		$productDetail = $data['in_product_detail'] ?? $data['out_product_detail'] ?? [];
		unset($data['in_product_detail'], $data['out_product_detail']);
		if (!$productDetail) {
			throw new ValidateException('请选择出入库商品');
		}
		$inId = 0;
		$this->transaction(function () use ($stockType, $data, $productDetail, $type, $relation_id, $adminId, $isStock, $time, $orderType, &$inId) {
			$dataAll = $productAttrData = [];
			try {
				$res = $this->dao->save($data);
			} catch (\Throwable $e) {
				$msg = $e->getMessage();
				if (stripos($msg, 'Duplicate') !== false || stripos($msg, '1062') !== false) {
					throw new ValidateException('相同导入文件已生成过库存单，请勿重复提交');
				}
				throw $e;
			}
			if (!$res) {
				throw new ValidateException('入库单保存失败');
			}
			$inId = (int)$res->id;
			// 销售出库 / 退货入库 才可豁免「参与库存管理」；禁止仅按 order_type=1/3 误判采购入库等
			$isSaleOrRefund = ($stockType === 2 && $orderType === 1 && !empty($data['store_order_id']))
				|| ($stockType === 1 && $orderType === 3 && !empty($data['refund_order_id']));

			// 同一张单先锁完全部商品，再按 SKU id 升序锁规格。
			$lockTargets = [];
			foreach ($productDetail as $productSku) {
				$pid = (int)($productSku['product_id'] ?? 0);
				$uq = (string)($productSku['unique'] ?? '');
				if ($pid > 0 && $uq !== '') {
					$lockTargets[] = [
						'product_id' => $pid,
						'unique' => $uq,
						'require_sku' => true,
					];
				}
			}
			/** @var StoreProductSkuWriteLock $catalogWriteLock */
			$catalogWriteLock = app()->make(StoreProductSkuWriteLock::class);
			$lockedCatalog = $catalogWriteLock->lock($lockTargets);
			$productMap = $lockedCatalog['products'];
			$attrMap = [];
			foreach ($lockedCatalog['skus'] as $attrRow) {
				$attrMap[(int)$attrRow['product_id'] . ':' . (string)$attrRow['unique']] = $attrRow;
			}

			$seenUnique = [];
			foreach ($productDetail as $idx => $productSku) {
				$productId = (int)($productSku['product_id'] ?? 0);
				$unique = (string)($productSku['unique'] ?? '');
				$excelRow = (int)($productSku['excel_row'] ?? 0);
				$rowLabel = $excelRow > 0 ? ('第' . $excelRow . '行') : ('第' . ($idx + 1) . '行商品');
				if (!$productId || $unique === '') {
					throw new ValidateException($rowLabel . '格式错误：缺少商品ID或SKU');
				}
				if (isset($seenUnique[$unique])) {
					throw new ValidateException($rowLabel . '：SKU 重复，同一单据不可重复同一规格');
				}
				$seenUnique[$unique] = true;
				$productInfo = $productMap[$productId] ?? null;
				$attrInfo = $attrMap[$productId . ':' . $unique] ?? null;
				if (!$productInfo || !$attrInfo) {
					throw new ValidateException(sprintf(
						'%s规格不存在：商品ID %s / SKU %s',
						$rowLabel,
						(string)$productId,
						$unique
					));
				}
				// 平台/门店归属写死：商品 type、relation_id 必须与当前单据一致
				if ((int)($productInfo['type'] ?? -1) !== (int)$type
					|| (int)($productInfo['relation_id'] ?? -1) !== (int)$relation_id) {
					throw new ValidateException($rowLabel . '：商品不属于当前平台/门店，禁止跨归属改库存');
				}
				if (!$isSaleOrRefund && (int)($productInfo['is_inventory'] ?? 0) !== 1) {
					throw new ValidateException($rowLabel . '：商品未开启「参与库存管理」');
				}
				$stock = $productSku['stock'] ?? 0;
				$defective_stock = $productSku['defective_stock'] ?? 0;
				if (!is_numeric($stock) || !is_numeric($defective_stock)) {
					throw new ValidateException($rowLabel . '：数量格式错误');
				}
				/** @var StockQtyValidateServices $qtyValidate */
				$qtyValidate = app()->make(StockQtyValidateServices::class);
				$scale = $qtyValidate->resolveScale(
					(int)($productInfo['product_type'] ?? 0),
					(int)($productInfo['is_inventory'] ?? 0),
					(int)($productInfo['salon_stock_enabled'] ?? 0)
				);
				$goodsLabel = (string)($productInfo['store_name'] ?? '') . '/' . (string)($attrInfo['suk'] ?? $unique);
				$excelRowNum = $excelRow > 0 ? $excelRow : null;
				try {
					$stock = $qtyValidate->assertQty($stock, $scale, $goodsLabel, $excelRowNum);
					$defective_stock = $qtyValidate->assertQty($defective_stock, $scale, $goodsLabel . '(残次)', $excelRowNum);
				} catch (\mohe\exceptions\AdminException $e) {
					throw new ValidateException($e->getMessage());
				}
				// 入库：普通/初始等禁止负数；转换类按业务方向单独处理
				if ($stockType == 1) {
					if ($orderType == 4) {
						if ($stock <= 0) {
							throw new ValidateException($rowLabel . '：转换数量必须大于0');
						}
					} else {
						if ($stock < 0 || $defective_stock < 0) {
							throw new ValidateException($rowLabel . '：入库数量不能为负数');
						}
						if ($stock == 0.0 && $defective_stock == 0.0) {
							throw new ValidateException($rowLabel . '：良品与残次品数量不能同为0');
						}
					}
				} else {
					if ($orderType == 5) {
						if ($stock <= 0) {
							throw new ValidateException($rowLabel . '：转换数量必须大于0');
						}
					} else {
						if ($stock < 0 || $defective_stock < 0) {
							throw new ValidateException($rowLabel . '：出库数量不能为负数');
						}
						if ($stock == 0.0 && $defective_stock == 0.0) {
							throw new ValidateException($rowLabel . '：良品与残次品数量不能同为0');
						}
					}
				}
				if ($stockType == 1) {//残次品转良品入库
					if ($orderType == 4) {
						$defective_stock = -abs($stock);
					}
				} else {//良品转残次品出库
					if ($orderType == 5) {
						$defective_stock = abs($stock);
					} else {
						$defective_stock = -abs($defective_stock);
					}
					$stock = -abs($stock);
				}
				// isStock=true：最终库存=锁后+本次变化；isStock=false（销售/退款台账）：库存已由 changeSkuStock 改完，取锁后当前值，禁止再叠加/再校验
				if ($isStock) {
					$balanceStock = bcadd((string)($attrInfo['stock'] ?? 0), (string)$stock, 4);
					$balanceDefective = bcadd((string)($attrInfo['defective_stock'] ?? 0), (string)$defective_stock, 4);
					$allowNegative = (int)($productInfo['allow_negative_stock'] ?? 1) === 1;
					if (!$allowNegative && bccomp($balanceStock, '0', 4) < 0) {
						throw new ValidateException($rowLabel . '：库存不足');
					}
					if (bccomp($balanceDefective, '0', 4) < 0) {
						throw new ValidateException($rowLabel . '：残次品库存不足');
					}
				} else {
					$balanceStock = bcadd((string)($attrInfo['stock'] ?? 0), '0', 4);
				}
				$dataAll[] = [
					'type' => $type,
					'relation_id' => $relation_id,
					'stock_type' => $data['stock_type'],//库存类型
					'order_type' => $data['order_type'],//出入单类型
					'order_id' => $inId,//出入库单ID
					'product_id' => $productId,
					'product_name' => $productInfo['store_name'] ?? '',
					'image' => $attrInfo['image'] ?? '',
					'sku' => $attrInfo['suk'] ?? '',
					'unique' => $unique,
					'code' => $attrInfo['code'] ?? '',
					'bar_code' => $attrInfo['bar_code'] ?? '',
					'stock' => $stock,
					'defective_stock' => $defective_stock,
					'balance_stock' => $balanceStock,
					'admin_id' => $adminId,
					'add_time' => $time
				];
				if ($isStock) {//出入库单 同步修改商品库存
					$productAttrData[$productId][] = [
						'product_id' => $productId,
						'unique' => $unique,
						'pm' => 1,
						'stock' => $stock,
						'defective_stock' => $defective_stock,
					];
				}
			}
			if (!$dataAll) {
				throw new ValidateException('有效出入库明细为空，整单已取消');
			}
			/** @var StoreProductStockDetailServices $stockDetailServices */
			$stockDetailServices = app()->make(StoreProductStockDetailServices::class);
			$stockDetailServices->saveAll($dataAll);
			// 出入库单同步商品库存（上方已按商品 42 -> SKU 43 批量加锁）
			if ($isStock && $productAttrData) {
				/** @var StoreProductAttrValueServices $attrServices */
				$attrServices = app()->make(StoreProductAttrValueServices::class);
				$sortedProductIds = array_map('intval', array_keys($productAttrData));
				sort($sortedProductIds, SORT_NUMERIC);
				foreach ($sortedProductIds as $productId) {
					$attrServices->saveProductAttrsStock((int)$productId, $productAttrData[$productId], $type, $relation_id, $adminId, false);
				}
			}
		}, $isTran);
		return $inId > 0 ? $inId : true;
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
