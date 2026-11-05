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


use app\jobs\system\SocketPushJob;
use app\services\product\product\StoreCatalogWriteLease;
use app\services\product\product\StoreCatalogWriteLockGuard;
use app\services\product\product\StoreProductServices;
use app\services\product\product\ProductStockEligibility;
use app\services\product\sku\StoreProductAttrValueServices;
use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;

/**
 * 商品库存警戒提示
 * Class ProductStockTips
 * @package app\jobs\product
 */
class ProductStockTips extends BaseJobs
{
    use QueueTrait;


    public function doJob($productId, $send = 1)
    {
        $productId = (int)$productId;
        if ($productId <= 0) {
            return true;
        }
        // 快速跳过无库存商品；进入写锁后再次检查，防止读取后配置发生变化。
        $candidate = app()->make(StoreProductServices::class)->get(['id' => $productId], ['product_type', 'is_inventory']);
        if (!$candidate || !ProductStockEligibility::appliesTo($candidate)) return true;
        $store_stock = sys_config('store_stock') ?? 0;//库存预警界限
        /** @var StoreCatalogWriteLockGuard $catalogGuard */
        $catalogGuard = app()->make(StoreCatalogWriteLockGuard::class);
        $shouldSend = $catalogGuard->withComponentCatalogMutation([$productId], function (StoreCatalogWriteLease $catalogLease) use ($productId, $store_stock) {
            /** @var StoreProductServices $make */
            $make = app()->make(StoreProductServices::class);
            $product = $make->get(['id' => $productId], ['stock', 'id', 'is_police', 'is_sold', 'product_type', 'is_inventory']);
            // 卡项、项目和不管理库存的产品不会因零库存触发警报。
            if (!$product || !ProductStockEligibility::appliesTo($product)) {
                return false;
            }
            /** @var StoreProductAttrValueServices $storeValueService */
            $storeValueService = app()->make(StoreProductAttrValueServices::class);
            $count = $storeValueService->getPolice([
                ['type', '=', 0],
                ['stock', '<=', $store_stock],
                ['product_id', '=', $productId]
            ]);
            $soldCount = $storeValueService->getPolice([
                ['type', '=', 0],
                ['product_type', '<>', 6],
                ['stock', '<=', 0],
                ['product_id', '=', $productId]
            ]);
            $product->is_sold = $soldCount ? 1 : 0;
            // 缺货只更新库存派生状态，不改变卡内组成；实际领用仍校验库存余额。
            $shouldSend = $store_stock >= $product['stock'] || $count;
            $product->is_police = $shouldSend ? 1 : 0;
            $product->save();
            return $shouldSend;
        });
        if ($send && $shouldSend) {
            SocketPushJob::dispatch(['', 'STORE_STOCK', ['id' => $productId], 'admin']);
        }
        return true;
    }

	/**
	 * 发送库存预警socket消息
	 * @param $productId
	 * @param $unique
	 * @param $type
	 * @return bool
	 */
	public function sendTips($productId, $unique, $type)
	{
		// 明细库存消息必须与商品级预警采用相同的库存管理口径。
		$product = app()->make(StoreProductServices::class)->get(['id' => (int)$productId], ['product_type', 'is_inventory']);
		if (!$product || !ProductStockEligibility::appliesTo($product)) return true;
		/** @var StoreProductAttrValueServices $make */
		$make = app()->make(StoreProductAttrValueServices::class);
		$stock = $make->value([
			'product_id' => $productId,
			'unique' => $unique,
			'type' => $type
		], 'stock');
		$store_stock = sys_config('store_stock') ?? 0;//库存预警界限
		if ($store_stock >= $stock) {
			SocketPushJob::dispatch(['', 'STORE_STOCK', ['id' => $productId], 'admin']);
		}
		return true;
	}

}
