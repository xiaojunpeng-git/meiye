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


use app\dao\product\inventory\StoreProductStockCountDao;
use app\services\BaseServices;
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
 * 商品库存盘点
 * Class StoreProductStockCountServices
 * @package app\services\product\inventory
 * @mixin StoreProductStockCountDao
 */
class StoreProductStockCountServices extends BaseServices
{

	/**
	 * @var string[]
	 */
	public $statusType = [
		0 => '进行中',
		1 => '已完成'
	];

    /**
     * StoreProductStockCountServices constructor.
     * @param StoreProductStockCountDao $dao
     */
    public function __construct(StoreProductStockCountDao $dao)
    {
        $this->dao = $dao;
    }

	/**
	 * 获取库存盘点详情
	 * @param int $id
	 * @return array
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function detail(int $id)
	{
		$info = $this->dao->get($id, ['*']);
		if (!$id) {
			throw new ValidateException('盘点记录不存在');
		}
		$info = $info->toArray();
		//查询库存盘点商品
		/** @var StoreProductStockDetailServices $stockDetailServices */
		$stockDetailServices = app()->make(StoreProductStockDetailServices::class);
		$stockDetail = $stockDetailServices->getList(['stock_type' => 3, 'order_id' => $id]);
		$product = [];
		if ($stockDetail) {
			/** @var StoreProductServices $productServices */
			$productServices = app()->make(StoreProductServices::class);
			/** @var StoreProductAttrValueServices $productAttrValueServices */
			$productAttrValueServices = app()->make(StoreProductAttrValueServices::class);
			foreach ($stockDetail as &$detail) {
				$productInfo = $productServices->getCacheProductInfo((int)$detail['product_id']);
				$attrInfo = $productAttrValueServices->getOne(['product_id' => $detail['product_id'], 'unique' => $detail['unique'], 'type' => 0]);
				if (!$productInfo || !$attrInfo) continue;
				$detail['image'] = $productInfo['image'];
				$detail['change_stock'] = $detail['count_stock'] > -1 ? (int)bcsub((string)$detail['count_stock'], (string)$detail['stock'], 0) : 0;
				$detail['change_defective_stock'] = $detail['count_defective_stock'] > -1 ? (int)bcsub((string)$detail['count_defective_stock'], (string)$detail['defective_stock'], 0) : 0;
				$product[] = $detail;
			}
		}
		$info['product_detail'] = $product;
		return $info;
	}

	/**
	 * 获取库存明细列表
	 * @param array $where
	 * @return array
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function getStockCountList(array $where, int $type = 0, int $relation_id = 0)
	{
		$where['type'] = $type;
		$where['relation_id'] = $relation_id;
		$count = $this->dao->count($where);
		[$page, $limit] = $this->getPageValue();
		$list = $this->dao->getList($where, '*', $page, $limit);
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
			/** @var StoreProductStockDetailServices $stockDetailServices */
			$stockDetailServices = app()->make(StoreProductStockDetailServices::class);
			foreach ($list as &$item) {
				$item['admin_name'] = $admin[$item['admin_id']]['admin_name'] ?? '系统自动创建';
				$item['update_time'] = $item['update_time'] ? date('Y-m-d H:i:s', $item['update_time']) : '';
				$item['add_time'] = $item['add_time'] ? date('Y-m-d H:i:s', $item['add_time']) : '';
				$detailList = $stockDetailServices->getList(['stock_type' => 3, 'order_id' => $item['id']], 'id,stock_type,stock,count_stock,defective_stock,count_defective_stock');
				$over_count_stock = $loss_count_stock = $over_count_defective_stock = $loss_count_defective_stock = 0;
				if ($detailList) {
					foreach ($detailList as $detail) {
						//良品
						if ($detail['count_stock'] > -1) {
							if ($detail['stock'] > $detail['count_stock']) {//盘亏
								$loss_count_stock = bcadd((string)$loss_count_stock, bcsub((string)$detail['stock'], (string)$detail['count_stock'], 0));
							} else {//盘盈
								$over_count_stock = bcadd((string)$over_count_stock, bcsub((string)$detail['count_stock'], (string)$detail['stock'], 0));
							}
						}
						//残次品
						if ($detail['count_defective_stock'] > -1) {
							if ($detail['defective_stock'] > $detail['count_defective_stock']) {//盘亏
								$loss_count_defective_stock = bcadd((string)$loss_count_defective_stock, bcsub((string)$detail['defective_stock'], (string)$detail['count_defective_stock'], 0));
							} else {//盘盈
								$over_count_defective_stock = bcadd((string)$over_count_defective_stock, bcsub((string)$detail['count_defective_stock'], (string)$detail['defective_stock'], 0));
							}
						}
					}
				}
				$item['over_count_stock'] = $over_count_stock;
				$item['loss_count_stock'] = $loss_count_stock;
				$item['over_count_defective_stock'] = $over_count_defective_stock;
				$item['loss_count_defective_stock'] = $loss_count_defective_stock;
			}
		}
		return compact('count', 'list');
	}

	/**
	 * 保存库存盘点
	 * @param int $id
	 * @param array $data
	 * @param int $type
	 * @param int $relation_id
	 * @param int $adminId
	 * @return bool
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function saveData(int $id, array $data, int $type = 0, int $relation_id = 0, int $adminId = 0)
	{
		if ($id) {//修改
			$info = $this->dao->get($id);
			if (!$info) throw new ValidateException('盘点记录不存在');
			if ((int)$info['status'] === 1) {
				throw new ValidateException('盘点单已完成，禁止再次提交或修改');
			}
		}
		$time = time();
		//单号
		$data['order_id'] = $this->getUniqueId('IC');
		$data['type'] = $type;
		$data['relation_id'] = $relation_id;
		$data['admin_id'] = $adminId;
		$data['update_time'] = $time;
		$productDetail = $data['product_detail'] ?? [];
		unset($data['product_detail']);
		$this->transaction(function () use ($id, $data, $productDetail, $type, $relation_id, $adminId, $time) {
			if ($id) {//编辑
				$this->dao->update($id, $data);
			} else {
				$data['add_time'] = time();
				$res = $this->dao->save($data);
				if (!$res) {
					throw new ValidateException('盘点记录保存失败');
				}
				$id = $res->id;
			}
			// 汇总从 0 起累加，禁止用 -1 作初始值（会导致少算 1）
			$count_stock = $count_defective_stock = '0';
			$stock = $over_count_stock = $loss_count_stock = '0';
			$defective_stock = $over_count_defective_stock = $loss_count_defective_stock = '0';
			$dataAll = [];
			//整理盘点库存 盘盈||盘亏
			$inProductDetailData = $outProductDetailData = [];
			$inKey = $outKey = 0;
			/** @var StoreProductStockDetailServices $stockDetailServices */
			$stockDetailServices = app()->make(StoreProductStockDetailServices::class);
			/** @var StoreProductServices $productServices */
			$productServices = app()->make(StoreProductServices::class);
			/** @var StoreProductAttrValueServices $productAttrValueServices */
			$productAttrValueServices = app()->make(StoreProductAttrValueServices::class);
			foreach ($productDetail as $productSku) {
				$productInfo = $productServices->getCacheProductInfo((int)$productSku['product_id']);
				$attrInfo = $productAttrValueServices->getOne(['product_id' => $productSku['product_id'], 'unique' => $productSku['unique'], 'type' => 0]);
				if (!$productInfo || !$attrInfo) {
					if ((int)($data['status'] ?? 0) === 1) {
						throw new ValidateException('存在无效商品或规格，无法完成盘点');
					}
					continue;
				}
				$detail = [
					'type' => $type,
					'relation_id' => $relation_id,
					'stock_type' => 3,//库存盘点
					'order_id' => $id,//盘点单ID
					'product_id' => $productSku['product_id'],
					'product_name' => $productInfo['store_name'] ?? '',
					'sku' => $attrInfo['suk'] ?? '',
					'unique' => $productSku['unique'],
					'code' => $attrInfo['code'] ?? '',
					'bar_code' => $attrInfo['bar_code'] ?? '',
					'stock' => $productSku['stock'] ?? 0,
					'count_stock' => $productSku['count_stock'] ?? -1,//-1未盘点
					'defective_stock' => $productSku['defective_stock'] ?? 0,
					'count_defective_stock' => $productSku['count_defective_stock'] ?? -1,//未盘点
					'balance_stock' => $attrInfo['stock'] ?? 0,
					'admin_id' => $adminId,
					'add_time' => $time
				];
				$dataAll[] = $detail;
				$stock = bcadd((string)$stock, (string)$productSku['stock'], 4);
				$defective_stock = bcadd((string)$defective_stock, (string)$productSku['defective_stock'], 4);
				if ($detail['count_stock'] > -1) {//有盘点
					$count_stock = bcadd((string)$count_stock, (string)$productSku['count_stock'], 4);
				}
				if ($detail['count_defective_stock'] > -1) {//有盘点
					$count_defective_stock = bcadd((string)$count_defective_stock, (string)$productSku['count_defective_stock'], 4);
				}
				if ($data['status'] == 1) {//盘点完整 整理盘盈||盘亏
					$productDetailOne = ['product_id' => $productSku['product_id'], 'unique' => $productSku['unique']];
					if ($detail['count_stock'] > -1) {//良品盘点
						//盘点盈亏计算（保留小数，禁止 int 截断）
						$changeStock = bcsub((string)$detail['count_stock'], (string)$detail['stock'], 4);
						if (bccomp($changeStock, '0', 4) > 0) {//良品盘盈
							$over_count_stock = bcadd((string)$over_count_stock, (string)$changeStock, 4);
							$inProductDetailData[$inKey] = array_merge($productDetailOne, ['stock' => $changeStock]);
						} else if (bccomp($changeStock, '0', 4) < 0) {//良品盘亏（必须累加到 loss，禁止误用 over）
							$loss_count_stock = bcadd((string)$loss_count_stock, $changeStock, 4);
							$outProductDetailData[$outKey] = array_merge($productDetailOne, ['stock' => $changeStock]);
						}
					}
					if ($detail['count_defective_stock'] > -1) {//残次品盘点
						$changeDefectiveStock = bcsub((string)$detail['count_defective_stock'], (string)$detail['defective_stock'], 4);
						if (bccomp($changeDefectiveStock, '0', 4) > 0) {//残次品盘盈 合并入一条sku详情
							$over_count_defective_stock = bcadd((string)$over_count_defective_stock, (string)$changeDefectiveStock, 4);
							if (isset($inProductDetailData[$inKey])) {
								$inProductDetailData[$inKey]['defective_stock'] = $changeDefectiveStock;
							} else {
								$inProductDetailData[$inKey] = array_merge($productDetailOne, ['defective_stock' => $changeDefectiveStock]);
							}
						} elseif (bccomp($changeDefectiveStock, '0', 4) < 0) {//单独残次品出库单
							$loss_count_defective_stock = bcadd((string)$loss_count_defective_stock, (string)$changeDefectiveStock, 4);
							if (isset($outProductDetailData[$outKey])) {
								$outProductDetailData[$outKey]['defective_stock'] = $changeDefectiveStock;
							} else {
								$outProductDetailData[$outKey] = array_merge($productDetailOne, ['defective_stock' => $changeDefectiveStock]);
							}
						}
					}
					if (isset($inProductDetailData[$inKey])) $inKey++;
					if (isset($outProductDetailData[$outKey])) $outKey++;
					unset($productDetailOne);
				}
			}
			if ($dataAll) {
				//清空原来盘点数据重新写入
				$stockDetailServices->delete(['stock_type' => 3, 'order_id' => $id]);
				$stockDetailServices->saveAll($dataAll);
			}
			//修改本次盘点良品、残次品盘点数量（保留 DECIMAL，禁止 int 截断）
			$this->dao->update($id, [
				'stock' => $stock,
				'count_stock' => $count_stock,
				'over_count_stock' => $over_count_stock,
				'loss_count_stock' => $loss_count_stock,
				'defective_stock' => $defective_stock,
				'count_defective_stock' => $count_defective_stock,
				'over_count_defective_stock' => $over_count_defective_stock,
				'loss_count_defective_stock' => $loss_count_defective_stock
			]);
			/** @var StoreProductStockOrderServices $stockOrderServices */
			$stockOrderServices = app()->make(StoreProductStockOrderServices::class);
			if ($inProductDetailData) {//盘盈入库
				$stockOrderServices->saveData(1, [
					'count_id' => $id,
					'order_type' => 5,
					'stock_time' => date('Y-m-d'),
					'remark' => '',
					'in_product_detail' => $inProductDetailData
				], $type, $relation_id, $adminId);
			}
			if ($outProductDetailData) {//盘亏出库
				$stockOrderServices->saveData(2, [
					'count_id' => $id,
					'order_type' => 7,
					'stock_time' => date('Y-m-d'),
					'remark' => '',
					'out_product_detail' => $outProductDetailData
				], $type, $relation_id, $adminId);
			}
		});
		return true;
	}

	/**
	 * 获取备注表单
	 * @param int $id
	 * @return mixed
	 */
	public function getRemarkForm(int $id)
	{
		$count = $this->dao->get($id);
		if (!$count) {
			throw new ValidateException('数据不存在!');
		}
		$form[] = Form::input('remark', '备注', $count['remark'])->required('请输入备注');
		return create_form('库存盘点备注', $form, Url::buildUrl('/product/inventory/count/remark/'. $id), 'POST');
	}


}
