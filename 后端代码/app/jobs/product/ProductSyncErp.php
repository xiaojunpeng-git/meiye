<?php

namespace app\jobs\product;

use app\services\product\branch\StoreBranchProductAttrValueServices;
use app\services\product\branch\StoreBranchProductServices;
use app\services\product\product\StoreProductServices;
use app\services\product\sku\StoreProductAttrServices;
use app\services\product\sku\StoreProductAttrValueServices;
use app\services\store\SystemStoreServices;
use mohe\basic\BaseJobs;
use mohe\exceptions\AdminException;
use mohe\services\erp\Erp as erpServices;
use mohe\traits\QueueTrait;
use think\facade\Log;

class ProductSyncErp extends BaseJobs
{
    use QueueTrait;

	/**
     * @return mixed
     */
    public static function queueName()
    {
        return 'MOHE_PRO_ERP';
    }

    /**
     * 同步商品到erp
     * @param $id
     * @return mixed
     */
    public function upProductToErp($id)
    {
        try {
            /** @var StoreProductServices $productServices */
            $productServices = app()->make(StoreProductServices::class);
            // 获取商品信息
            $productInfo = $productServices->getInfo($id)['productInfo'];
            $data = [];
			$attrs = $productInfo['attrs'] ?? [];
			if (!$attrs && ($productInfo['attr'] ?? [])) {
				$attrs = [$productInfo['attr']];
		    }
            foreach ($attrs as $item) {
                if ($item['pic'] && strstr($item['pic'], 'http') === false) {
                    $siteUrl = sys_config('site_url');
                    $item['pic'] = $siteUrl . $item['pic'];
                }

                $data[] = [
                    'i_id' => $productInfo['code'],
                    'sku_id' => $item['code'],
                    'name' => $productInfo['store_name'],
                    'properties_value' => str_replace(',', ' ', $item['values']),
                    's_price' => $item['price'],
                    'pic' => $item['pic'],
                    'c_price' => $item['cost'],
                    'market_price' => $item['ot_price'],
                ];
            }

            (new erpServices())->serviceDriver('product')->updateProduct($data);
        } catch (\Exception $e) {
            Log::error('商品上传失败, 原因: ' . $e->getMessage());
        }
        return true;
    }

    /**
     * 上传店铺商品
     * @param $id
     * @param $shop
     * @return bool
     */
    public function upBranchProductToErp($id, $shop)
    {
        try {
            /** @var StoreProductServices $productServices */
            $productServices = app()->make(StoreProductServices::class);
            // 获取商品信息
            $productInfo = $productServices->getInfo($id)['productInfo'];
            $data = [];
			$attrs = $productInfo['attrs'] ?? [];
			if (!$attrs && ($productInfo['attr'] ?? [])) {
				$attrs = [$productInfo['attr']];
		    }
            foreach ($attrs as $item) {
                $data[] = [
                    'i_id' => $productInfo['code'],
                    'sku_id' => $item['code'],
                    'shop_i_id' => $shop['erp_shop_id'] . $productInfo['code'],
                    'shop_sku_id' => $shop['erp_shop_id'] . $item['code'],
                    'name' => $productInfo['store_name'],
                    'properties_value' => str_replace(',', ' ', $item['values']),
                    'shop_id' => $shop['erp_shop_id'],
                ];
            }

            (new erpServices())->serviceDriver('product')->updateShopProduct($data);
        } catch (\Exception $e) {
            Log::error('店铺商品上传失败, 原因: ' . $e->getMessage());
        }
        return true;
    }

    /**
     * 添加商品同步到门店中
     * @param $id
     * @param $shop
     */
    public function productToBranch($id, $shop)
    {
        /** @var StoreProductServices $productServices */
        $productServices = app()->make(StoreProductServices::class);
        // 获取商品信息
        $productInfo = $productServices->getInfo($id)['productInfo'];
        /** @var StoreProductAttrValueServices $storeProductAttrValueServices */
        $storeProductAttrValueServices = app()->make(StoreProductAttrValueServices::class);
        $skuArray = $storeProductAttrValueServices->getSkuArray(['product_id' => $id, 'type' => 0], 'unique', 'code');
        $data = [
            'product_id' => $id,
            'image' => $productInfo['image'],
            'store_name' => $productInfo['store_name'],
            'store_info' => $productInfo['store_info'],
            'keyword' => $productInfo['keyword'],
            'bar_code' => $productInfo['bar_code'],
            'cate_id' => $productInfo['cate_id'],
            'store_id' => $shop['id'],
            'sales' => 0,
            'stock' => 0,
            'sort' => 0,
            'label_id' => $productInfo['label_id'],
            'is_show' => 0,
            'add_time' => time(),
            'is_del' => 0,
            'code' => $productInfo['code'],
        ];
        $attrs = [];
        foreach ($productInfo['attrs'] as $item) {
            if (empty($item['code']) || !array_key_exists($item['code'], $skuArray)) {
                continue;
            }
            $attrs[] = [
                'product_id' => $id,
                'store_id' => $shop['id'],
                'unique' => $skuArray[$item['code']],
                'sales' => 0,
                'stock' => 0,
                'type' => 0,
                'bar_code' => $item['bar_code'],
                'code' => $item['code'],
            ];
        }

        /** @var StoreBranchProductAttrValueServices $branchProductAttrServices */
        $branchProductAttrServices = app()->make(StoreBranchProductAttrValueServices::class);

        $branchProductAttrServices->transaction(function () use ($id, $data, $attrs, $shop, $branchProductAttrServices) {

            /** @var StoreBranchProductServices $branchProductServices */
            $branchProductServices = app()->make(StoreBranchProductServices::class);

            // 判断门店是否有商品
            $branchProduct = $branchProductServices->getOne(['product_id' => $id, 'store_id' => $shop['id']]);
            if (empty($branchProduct)) {
                $branchProductServices->save($data);
                $branchProductAttrServices->saveAll($attrs);
            } else {
                $branchProductAttr = $branchProductAttrServices->getColumn(['product_id' => $id, 'store_id' => $shop['id']], '*', 'code');
                if (!empty($branchProductAttr)) {
                    foreach ($attrs as $key => $attr) {
                        if (isset($branchProductAttr[$attr['code']])) {
                            unset($attrs[$key]);
                        }
                    }
                    if (!empty($attrs)) {
                        $branchProductAttrServices->saveAll($attrs);
                    }
                }
            }
        });

        return true;
    }

    /**
     * 同步商品
     * @param $spuArr
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function productFromErp($spuArr)
    {
        try {
            $result = (new erpServices())->serviceDriver('product')->syncProduct([$spuArr]);;
            $productList = $result['datas'];
            $productInfo = [];
            /** @var StoreProductServices $productServices */
            $productServices = app()->make(StoreProductServices::class);
            /** @var StoreProductAttrServices $productAttrServices */
            $productAttrServices = app()->make(StoreProductAttrServices::class);

            /** @var SystemStoreServices $systemStoreServices */
            $systemStoreServices = app()->make(SystemStoreServices::class);
            $systemStoreList = $systemStoreServices->getErpStore([['erp_shop_id', '>', 0]]);
            foreach ($productList as $item) {
                $productInfo = [
                    'image' => (string)$item['pic'],
                    'slider_image' => json_encode([(string)$item['pic']]),
                    'store_name' => $item['name'],
                    'store_info' => $item['name'],
                    'cate_id' => 0,
                    'price' => floatval($item['s_price']),
                    'ot_price' => floatval($item['market_price']),
                    'delivery_type' => '1,2,3',
                    'freight' => 1,
                    'is_show' => 0,
                    'add_time' => time(),
                    'cost' => floatval($item['c_price']),
                    'ficti' => 0,
                    'spec_type' => 1,
                    'code' => $item['i_id'],
                ];
                $detail = $details = $value = [];
                foreach ($item['skus'] as $items) {
                    $detail[] = $items['properties_value'];
                    $details[] = [
                        'name' => $items['properties_value'],
                        'select' => false
                    ];
                    $value[] = [
                        'bar_code' => '',
                        'brokerage' => 0,
                        'brokerage_two' => 0,
                        'code' => $items['sku_id'],
                        'cost' => floatval($items['cost_price']),
                        'detail' => ['规格' => $items['properties_value']],
                        'ot_price' => floatval($items['market_price']),
                        'pic' => (string)$items['pic'],
                        'price' => floatval($items['sale_price']),
                        'select' => true,
                        'value1' => $items['properties_value'],
                        'values' => $items['properties_value'],
                        'vip_price' => 0,
                        'volume' => 0,
                        'weight' => 0,
                        'stock' => 0,
                    ];
                }
                $pid = $productServices->value(['code' => $item['i_id']], 'id');
                if (!$pid) {
                    $pid = $productServices->ErpProductSave($productInfo);
                }
                //检测库存警戒和检测是否售罄
                ProductStockTips::dispatch([$pid, 0]);

                $attr = [[
                    'value' => '规格',
                    'detail' => $detail,
                    'details' => $details,
                ]];

                $skuList = $productAttrServices->validateProductAttr($attr, $value, $pid, 0, 0, 0);
                $productAttrServices->saveProductAttr($skuList, $pid);
                // 同步商品至erp门店
                if (!empty($systemStoreList)) {
                    foreach ($systemStoreList as $store) {
                        ProductSyncErp::dispatchDo('productToBranch', [$pid, $store]);
                    }
                }
            }

			//清除数据缓存
			$productServices->cacheTag()->clear();
			$productAttrServices->cacheTag()->clear();

        } catch (\Exception $e) {
            Log::error('商品同步失败, 原因: ' . $e->getMessage());
        }
        return true;
    }

    /**
     * 同步商品库存
     * @param array $ids
     * @return bool
     */
    public function stockFromErp(array $ids)
    {
        // 【库存铁律】ERP 拉取库存直写已停用
        Log::error('stockFromErp 已停用：ERP 不可直接覆盖库存');
        return true;
        // 原 saveAll 覆盖 SKU stock 逻辑已注释
    }

    /**
     * 同步门店商品库存
     * @param int $productId
     * @param array $data
     * @param int $storeId
     */
    public function syncBranchProductStock(int $productId, array $data, int $storeId)
    {
        // 【库存铁律】ERP 覆盖门店库存已停用
        return true;
        // 原直接 update stock 逻辑已注释
    }

    /**
     * 同步商品至新增门店
     * @param int $shopId
     */
    public function syncProductToBranch(int $shopId)
    {
        /** @var StoreProductServices $productServices */
        $productServices = app()->make(StoreProductServices::class);
        $list = $productServices->getColumn(['is_del' => 0, 'is_show' => 1], 'id', 'id');

        if (!empty($list)) {
            foreach ($list as $item) {
                ProductSyncErp::dispatchDo('productToBranch', [$item, ['id' => $shopId]]);
            }
        }
        return true;
    }

    /**
     * 更新商品库存
     * @param array $list
     * @return bool
     */
    public function updatePlatformStock(array $list): bool
    {
        // 【库存铁律】ERP 推送库存直写已停用
        Log::warning('updatePlatformStock 已停用：ERP 不可直接覆盖库存');
        return true;
    }

    /**
     * 更新门店规格库存（已停用）
     * @param array $data
     * @param int $storeId
     * @return bool
     */
    public function updateStoreAttrStockByCode(array $data, int $storeId): bool
    {
        // 【库存铁律】已停用
        return true;
    }

    /**
     * 更新门店商品库存（已停用）
     * @param array $productIds
     * @param int $storeId
     * @return bool
     */
    public function updateStoreProductStock(array $productIds, int $storeId): bool
    {
        // 【库存铁律】已停用
        return true;
    }

    /**
     * 更新平台商品库存
     * @param array $data
     * @return bool
     */
    public function updateStoreProductValueStock(array $data): bool
    {
        // 【库存铁律】ERP 覆盖平台库存已停用
        return true;
        // 原 saveAll 覆盖 stock 逻辑已注释
    }
}
