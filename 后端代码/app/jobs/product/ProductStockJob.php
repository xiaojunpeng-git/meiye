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

namespace app\jobs\product;

use app\services\order\StoreOrderCartInfoServices;
use app\services\order\StoreOrderRefundServices;
use app\services\order\StoreOrderServices;
use app\services\product\inventory\StoreProductStockDetailServices;
use app\services\product\inventory\StoreProductStockOrderServices;
use app\services\product\product\StoreProductServices;
use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;
use think\facade\Log;

class ProductStockJob extends BaseJobs
{
    use QueueTrait;

	/**
	 * 保存销售出库单
	 * @param $id
	 * @return bool
	 */
	public function saveSaleOutOrder($id)
	{
		if (!$id) return true;
		try {
			/** @var StoreProductStockOrderServices $stockOrderServices */
			$stockOrderServices = app()->make(StoreProductStockOrderServices::class);
			/** @var StoreOrderServices $stockOrderServices */
			$storeOrderServices = app()->make(StoreOrderServices::class);
			/** @var StoreOrderCartInfoServices $cartInfoServices */
			$cartInfoServices = app()->make(StoreOrderCartInfoServices::class);
			$order = $storeOrderServices->get((int)$id);
			if (!$order) return true;
			$cartInfo = $cartInfoServices->getColumn(['oid' => $id], 'id,type,relation_id,product_id,sku_unique,cart_num');
			if (!$cartInfo) return true;
			$stockAdminData = $stockStoreData = $stockSupplierData = [];
			foreach ($cartInfo as $cart) {
				$attrInfo = [
					'product_id' => $cart['product_id'],
					'unique' => $cart['sku_unique'],
					'stock' => $cart['cart_num'],
				];
				switch ($cart['type']) {
					case 0://平台
						$stockAdminData[] = $attrInfo;
						break;
					case 1://门店
						$stockStoreData[$cart['relation_id']][] = $attrInfo;
						break;
					case 2://供应商
						$stockSupplierData[$cart['relation_id']][] = $attrInfo;
						break;
				}
			}
			//出库单
			if ($stockAdminData) {
				$stockOrderServices->saveData(2, [
					'store_order_id' => $id,
					'order_type' => 1,
					'stock_time' => date('Y-m-d', $order['add_time']),
					'remark' => '',
					'out_product_detail' => $stockAdminData
				], 0, 0, (int)$order['uid'], false);
			}
			if ($stockStoreData) {
				//单个门店
				foreach ($stockStoreData as $store_id => $data) {
					$stockOrderServices->saveData(2, [
						'store_order_id' => $id,
						'order_type' => 1,
						'stock_time' => date('Y-m-d', $order['add_time']),
						'remark' => '',
						'out_product_detail' => $data
					], 1, $store_id, (int)$order['uid'],false);
				}
				unset($data);
			}
			if ($stockSupplierData) {
				foreach ($stockSupplierData as $supplier_id => $data) {
					$stockOrderServices->saveData(2, [
						'store_order_id' => $id,
						'order_type' => 1,
						'stock_time' => date('Y-m-d', $order['add_time']),
						'remark' => '',
						'out_product_detail' => $data
					], 2, $supplier_id, (int)$order['uid'], false);
				}
			}

		} catch (\Throwable $e) {
			Log::error('写入商品销售出库单发生错误,错误原因:' . $e->getMessage());
		}
		return true;
	}

	/**
	 * 保存退货入库单
	 * @param $id
	 * @param $isGood //true：入库良品，false：入库残次品
	 * @param $isStock //true：入商品库存，false：不计入
	 * @return bool
	 */
	public function saveRefundInOrder($id, $isGood = true, $isStock = false)
	{
		if (!$id) return true;
		try {
			/** @var StoreOrderRefundServices $services */
			$refundOrderServices = app()->make(StoreOrderRefundServices::class);
			$refundOrder = $refundOrderServices->getOne(['store_order_id' => $id]);
			/** @var StoreProductStockOrderServices $stockOrderServices */
			$stockOrderServices = app()->make(StoreProductStockOrderServices::class);
			/** @var StoreOrderServices $stockOrderServices */
			$storeOrderServices = app()->make(StoreOrderServices::class);
			/** @var StoreOrderCartInfoServices $cartInfoServices */
			$cartInfoServices = app()->make(StoreOrderCartInfoServices::class);
			$order = $storeOrderServices->get((int)$id);
			if (!$order) return true;
			$cartInfo = $cartInfoServices->getColumn(['oid' => $id], 'id,type,relation_id,product_id,sku_unique,cart_num');
			if (!$cartInfo) return true;
			$stockAdminData = $stockStoreData = $stockSupplierData = [];
			foreach ($cartInfo as $cart) {
				$attrInfo = [
					'product_id' => $cart['product_id'],
					'unique' => $cart['sku_unique'],
					'stock' => 0,
					'defective_stock' => 0,
				];
				if ($isGood) {//入良品
					$attrInfo['stock'] = $cart['cart_num'];
				} else {//入残次品
					$attrInfo['defective_stock'] = $cart['cart_num'];
				}
				switch ($cart['type']) {
					case 0://平台
						$stockAdminData[] = $attrInfo;
						break;
					case 1://门店
						$stockStoreData[$cart['relation_id']][] = $attrInfo;
						break;
					case 2://供应商
						$stockSupplierData[$cart['relation_id']][] = $attrInfo;
						break;
				}
			}
			$refundOrderId = $refundOrder ? $refundOrder['id'] : 0;
			//退款是3 退货入库，取消订单为：2其他入库
			$orderType = $refundOrder ? 3 : 2;
			if ($refundOrder) {
				$adminId = app('request')->hasMacro('adminId') ? intval(app('request')->adminId()) : 0;
			} else {//取消订单 操作人是用户
				$adminId = (int)$order['uid'];
			}

			//入库单
			if ($stockAdminData) {
				$stockOrderServices->saveData(1, [
					'refund_order_id' => $refundOrderId,
					'order_type' => $orderType,
					'stock_time' => date('Y-m-d', $order['add_time']),
					'remark' => '',
					'out_product_detail' => $stockAdminData
				], 0, 0, $adminId, (bool)$isStock);
			}
			if ($stockStoreData) {
				//单个门店
				foreach ($stockStoreData as $store_id => $data) {
					$stockOrderServices->saveData(1, [
						'refund_order_id' => $refundOrderId,
						'order_type' => $orderType,
						'stock_time' => date('Y-m-d', $order['add_time']),
						'remark' => '',
						'out_product_detail' => $data
					], 1, (int)$store_id, $adminId, (bool)$isStock );
				}
				unset($data);
			}
			if ($stockSupplierData) {
				foreach ($stockSupplierData as $supplier_id => $data) {
					$stockOrderServices->saveData(1, [
						'refund_order_id' => $refundOrderId,
						'order_type' => $orderType,
						'stock_time' => date('Y-m-d', $order['add_time']),
						'remark' => '',
						'out_product_detail' => $data
					], 2, (int)$supplier_id, $supplier_id, (bool)$isStock);
				}
			}

		} catch (\Throwable $e) {
			Log::error('写入商品退货入库单发生错误,错误原因:' . $e->getMessage());
		}
		return true;
	}

	/**
	 * 保存商品出入库单
	 * @param int $id
	 * @param array $valueGroup
	 * @param $type
	 * @param $relation_id
	 * @param $adminId
	 * @return bool
	 */
	public function saveStockOrder(int $id, array $valueGroup, $type = 0, $relation_id = 0, $adminId = 0)
	{
		if (!$valueGroup) return true;
		try {
			/** @var StoreProductStockDetailServices $storeProductStockDetailServices */
			$storeProductStockDetailServices = app()->make(StoreProductStockDetailServices::class);
			//保存库存记录
			$storeProductStockDetailServices->handelProductStock($id, $valueGroup, (int)$type, (int)$relation_id, (int)$adminId);
		} catch (\Throwable $e) {
			Log::error('写入商品库存出入库单发生错误,错误原因:' . $e->getMessage());
		}
		return true;
	}

    /**
     * 拆分计算
     * @param array $data
     * @return bool
     */
    public function distribute(array $data): bool
    {
        try {
            foreach ($data as $key => $item) {
                ProductStockJob::dispatch('calcValueStock', [$key]);
            }
        } catch (\Exception $e) {
            Log::error(['msg' => '拆分计算失败,错误原因:' . $e->getMessage(), 'data' => $data]);
        }
        return true;
    }

    /**
     * 计算库存
     * @param int $id
     * @return bool
     */
    public function calcValueStock(int $id): bool
    {
        try {
            /** @var StoreProductServices $services */
            $services = app()->make(StoreProductServices::class);
            $services->calcStockByAttrValue($id);
        } catch (\Exception $e) {
            Log::error(['msg' => '计算商品库存失败,错误原因:' . $e->getMessage(), 'data' => $id]);
        }
        return true;
    }
}
