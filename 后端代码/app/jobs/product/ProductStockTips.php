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
use app\services\product\product\StoreCardRelatedServices;
use app\services\product\product\StoreCatalogWriteLease;
use app\services\product\product\StoreCatalogWriteLockGuard;
use app\services\product\product\StoreProductServices;
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
        $store_stock = sys_config('store_stock') ?? 0;//库存预警界限
        /** @var StoreCatalogWriteLockGuard $catalogGuard */
        $catalogGuard = app()->make(StoreCatalogWriteLockGuard::class);
        $shouldSend = $catalogGuard->withComponentCatalogMutation([$productId], function (StoreCatalogWriteLease $catalogLease) use ($productId, $store_stock) {
            /** @var StoreProductServices $make */
            $make = app()->make(StoreProductServices::class);
            $product = $make->get(['id' => $productId], ['stock', 'id', 'is_police', 'is_sold']);
            if (!$product) {
                return false;
            }
            /** @var StoreProductAttrValueServices $storeValueService */
            $storeValueService = app()->make(StoreProductAttrValueServices::class);
            /** @var StoreCardRelatedServices $relatedService */
            $relatedService = app()->make(StoreCardRelatedServices::class);
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
            if ($soldCount) {
                $relatedService->setStatusInGuard($catalogLease, [$productId], 0);
            }
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
