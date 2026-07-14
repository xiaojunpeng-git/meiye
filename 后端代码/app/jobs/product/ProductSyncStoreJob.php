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


use app\services\product\branch\StoreBranchProductServices;
use app\services\product\product\StoreCardRelatedServices;
use app\services\product\product\StoreProductServices;
use app\services\product\category\StoreProductCategoryServices;
use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;
use think\facade\Log;

/**
 * 商品同步到门店
 * Class ProductSyncStoreJob
 * @package app\jobs\product
 */
class ProductSyncStoreJob extends BaseJobs
{
    use QueueTrait;

    /**
     * @return mixed
     */
    public static function queueName()
    {
        $default = config('queue.default');
        return config('queue.connections.' . $default . '.batch_queue');
    }

	/**
	 * 同步某个商品到某个门店
	 * @param int $product_id
	 * @param int $store_id
	 * @param int $card_product_id
	 * @param int $is_sync_stock
	 * @param int $is_sync_show
	 * @return bool
	 */
    public function syncProduct(int $product_id = 0, int $store_id = 0, int $card_product_id = 0, int $is_sync_stock = 1, int $is_sync_show = 1)
    {
        if (!$product_id || !$store_id) {
            return true;
        }
        try {
            /** @var StoreBranchProductServices $storeBranchProductServices */
            $storeBranchProductServices = app()->make(StoreBranchProductServices::class);
            $storeBranchProductServices->syncProduct($product_id, $store_id, $card_product_id, $is_sync_stock, $is_sync_show);
        } catch (\Throwable $e) {
            Log::error('同步商品[syncProduct]到门店发生错误,错误原因:' . $e->getMessage() . '-----' . $e->getFile() . '----' . $e->getLine());
        }
        return true;
    }

    /**
     * 卡项商品同步门店时处理卡项权益
     * @param int $store_id
     * @param $product_id
     * @param $related
     * @return bool
     */
    public function syncCardRelatedProducts(int $store_id = 0, int $product_id = 0, array $related = [], int $id = 0)
    {
        if (!$store_id || !$product_id || !$id || !$related) {
            return true;
        }
        try {
            /** @var StoreProductServices $productServices */
            $productServices = app()->make(StoreProductServices::class);
            /** @var StoreCardRelatedServices $cardRelatedServices */
            $cardRelatedServices = app()->make(StoreCardRelatedServices::class);
            $i = 0;
            $result = [];
            foreach ($related as $value) {
                if (!in_array($value['product_id'], $result)) {
                    $result[] = $value['product_id'];
                }
            }
            foreach ($result as $product_id) {
                $products = $productServices->getBranchProduct(['pid' => $product_id, 'relation_id' => $store_id, 'type' => 1]);
                if (!$products) {
                    ProductSyncStoreJob::dispatchSece($i, 'syncProduct', [$product_id, $store_id, $id]);
                } else {
                    $cardRelatedServices->updateCardProduct($product_id, $id, $products['id']);
                }
                $i += 5;
            }
        } catch (\Throwable $e) {
            Log::error('同步卡项权益商品[syncCardRelatedProducts]到门店发生错误,错误原因:' . $e->getMessage() . '-----' . $e->getFile() . '----' . $e->getLine());
        }
        return true;
    }

	/**
	 * 新增门店:同步平台商品(选择多个，或者所有)
	 * @param $store_id
	 * @param $applicable_type
	 * @param $product_id
	 * @return bool
	 */
    public function syncProducts($store_id, $applicable_type = 1, $product_id = [])
    {
        if (!$store_id) {
            return true;
        }
        try {
            $where = ['type' => [0, 2], 'product_type' => [0, 4, 5, 6], 'is_del' => 0, 'is_verify' => 1, 'pid' => 0];
            switch ($applicable_type) {
                case 1://所有商品
                    break;
                case 2://部分商品
                    if ($product_id) {//同步某些商品
                        $where['id'] = is_array($product_id) ? $product_id : explode(',', $product_id);
                    } else {
                        return true;
                    }
                    break;
                case 3://不同步商品
                    return true;
                    break;
                default:
                    return true;
                    break;
            }
            /** @var StoreProductServices $productServices */
            $productServices = app()->make(StoreProductServices::class);
            $products = $productServices->getSearchList($where, 0, 0, ['id'], '', []);
            if ($products) {
                $productIds = array_column($products, 'id');
                $i = 0;
                foreach ($productIds as $id) {
                    ProductSyncStoreJob::dispatchSece($i, 'syncProduct', [$id, $store_id]);
                    $i += 5;
                }
            }
        } catch (\Throwable $e) {
            Log::error('同步商品[syncProducts]到门店发生错误,错误原因:' . $e->getMessage() . '-----' . $e->getFile() . '----' . $e->getLine());
        }
        return true;
    }

	/**
	 * 同步一个商品到多个门店
	 * @param $product_id
	 * @param $applicable_type
	 * @param $store_ids
	 * @param $is_sync_stock
	 * @param $is_sync_show
	 * @return bool
	 */
    public function syncProductToStores($product_id, $applicable_type = 0, $store_ids = [], $is_sync_stock = 1, $is_sync_show = 1)
    {
        $product_id = (int)$product_id;
        if (!$product_id || ($applicable_type == 2 && !$store_ids)) {
            return true;
        }
        try {
            if ($store_ids) {//同步门店
                $store_ids = is_array($store_ids) ? $store_ids : explode(',', $store_ids);
            }
            /** @var StoreBranchProductServices $storeBranchProductServices */
            $storeBranchProductServices = app()->make(StoreBranchProductServices::class);
            $storeBranchProductServices->syncProductToStores((int)$product_id, (int)$applicable_type, (array)$store_ids, (int)$is_sync_stock, (int)$is_sync_show);
        } catch (\Throwable $e) {
            Log::error('同步商品[syncProductToStores]到门店发生错误,错误原因:' . $e->getMessage() . '-----' . $e->getFile() . '----' . $e->getLine());
        }
        return true;
    }

    /**
     * 给门店同步平台商品分类
     * @param int $store_id
     * @return bool
     */
    public function syncProductCate(int $store_id)
    {
        if (!$store_id) {
            return true;
        }
        try {
            /** @var StoreProductCategoryServices $categoryServices */
            $categoryServices = app()->make(StoreProductCategoryServices::class);
            $categoryServices->synchronousCate($store_id);
        } catch (\Throwable $e) {
            Log::error('同步商品分类到门店发生错误,错误原因:' . $e->getMessage());
        }
        return true;
    }

    /**
     * 商品设置默认门店商品分类
     * @param int $store_id
     * @return bool
     */
    public function syncProductAddCate(int $store_id)
    {
        if (!$store_id) {
            return true;
        }
        try {
            /** @var StoreProductServices $productServices */
            $productServices = app()->make(StoreProductServices::class);
            $productServices->storeProductAddCate($store_id);
        } catch (\Throwable $e) {
            Log::error('商品设置默认门店商品分类发生错误,错误原因:' . $e->getMessage());
        }
        return true;
    }

}
