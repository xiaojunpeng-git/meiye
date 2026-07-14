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

use app\services\product\inventory\StoreProductStockDetailServices;
use app\services\product\product\StoreProductServices;
use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;
use think\facade\Log;

class ProductStockJob extends BaseJobs
{
    use QueueTrait;

	/**
	 * 保存销售出库单（兜底补单；主路径已在支付成功事务内同步写）
	 * @param $id
	 * @return bool
	 */
	public function saveSaleOutOrder($id)
	{
		if (!$id) return true;
		try {
			/** @var \app\services\product\inventory\ProductInventoryChangeServices $inventoryChange */
			$inventoryChange = app()->make(\app\services\product\inventory\ProductInventoryChangeServices::class);
			// 失败必须抛出，禁止吞异常伪装成功。
			$inventoryChange->createSaleOutOrdersForPaidOrder((int)$id, false);
		} catch (\Throwable $e) {
			Log::error('写入商品销售出库单发生错误,错误原因:' . $e->getMessage());
			throw $e;
		}
		return true;
	}

	/**
	 * 保存退货入库单（参数必须是退款单ID，禁止传销售订单ID）
	 * @param int $refundId
	 * @param bool $isGood true：入库良品，false：入库残次品
	 * @param bool $isStock 必须为 true（同步改库存）；false 会抛错，禁止空标幂等
	 * @return bool
	 */
	public function saveRefundInOrder($refundId, $isGood = true, $isStock = true)
	{
		if (!$refundId) {
			return true;
		}
		/** @var \app\services\product\inventory\ProductInventoryChangeServices $inventoryChange */
		$inventoryChange = app()->make(\app\services\product\inventory\ProductInventoryChangeServices::class);
		// 整段事务在 handleShippedRefundInbound 内；失败必须抛出
		$inventoryChange->handleShippedRefundInbound((int)$refundId, (bool)$isGood, (bool)$isStock);
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
