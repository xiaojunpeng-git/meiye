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

namespace app\services\product\branch;


use app\dao\product\sku\StoreProductAttrValueDao;
use app\services\BaseServices;
use app\services\product\inventory\StoreProductStockDetailServices;
use app\services\product\sku\StoreProductAttrValueServices;
use mohe\exceptions\AdminException;
use mohe\traits\ServicesTrait;

/**
 * Class StoreBranchProductAttrValueServices
 * @package app\services\product\branch
 * @mixin StoreProductAttrValueDao
 */
class StoreBranchProductAttrValueServices extends BaseServices
{

    use ServicesTrait;

    /**
     * StoreBranchProductAttrValueServices constructor.
     * @param StoreProductAttrValueDao $dao
     */
    public function __construct(StoreProductAttrValueDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * @param string $unique
     * @param int $storeId
     * @return int|mixed
     */
    public function uniqueByStock(string $unique, int $storeId)
    {
        if (!$unique) return 0;
        return $this->dao->uniqueByStock($unique, $storeId);
    }

    /**
     * 更新
     * @param int $id
     * @param array $data
     * @param int $store_id
     */
    public function updataAll(int $id, array $data, int $store_id)
    {
        /** @var StoreBranchProductServices $productServices */
        $productServices = app()->make(StoreBranchProductServices::class);
        $where = [];
        $where['product_id'] = $id;
        $where['store_id'] = $store_id;
        $where['type'] = 0;

        $this->transaction(function () use ($id, $store_id, $where, $data, $productServices) {
            $attrArr = [];
            $stock = 0;
            $this->dao->delete($where);
            foreach ($data['attrs'] as $key => $item) {
                $attrArr[$key]['product_id'] = $id;
                $attrArr[$key]['store_id'] = $store_id;
                $attrArr[$key]['unique'] = $item['unique'] ?? '';
                $attrArr[$key]['stock'] = intval($item['stock']) ?? 0;
                $attrArr[$key]['bar_code'] = $item['bar_code'] ?? 0;
                $attrArr[$key]['type'] = 0;
                $stock += (int)($item['stock'] ?? 0);
            }
            $res1 = $this->dao->saveAll($attrArr);
            $productServices->saveStoreProduct($id, $store_id, $stock, $data);
            $unique = array_column($data['attrs'], 'unique');
            /** @var StoreProductAttrValueServices $storeProductAttrValueServices */
            $storeProductAttrValueServices = app()->make(StoreProductAttrValueServices::class);
            $storeProductAttrValueServices->updateSumStock($unique);
            //记录入出库
            /** @var StoreProductStockDetailServices $storeProductStockDetailServices */
            $storeProductStockDetailServices = app()->make(StoreProductStockDetailServices::class);
			$storeProductStockDetailServices->handelProductStock($id, $attrArr, $store_id ? 1 : 0, $store_id);
            if (!$res1) {
                throw new AdminException('添加失败！');
            }
        });
    }

    /**
     * 获取某个门店商品下的库存
     * @param int $storeId
     * @param int $productId
     * @return array
     */
    public function getProductAttrUnique(int $storeId, int $productId)
    {
        return $this->dao->getColumn(['store_id' => $storeId, 'product_id' => $productId], 'stock', 'unique');
    }

    /**
     * 获取某个sku下的商品库存总和
     * @param string $unique
     * @return array
     */
    public function getProductAttrValueStockSum(array $unique)
    {
        return $this->dao->getColumn([['unique', 'in', $unique]], 'sum(stock) as sum_stock', 'unique');
    }

    /**
     * 获取门店商品规格信息
     * @param int $id
     * @param int $type
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getStoreProductAttr(int $id, int $type = 0, array $with = [])
    {
        return $this->dao->getProductAttrValue(['product_id' => $id, 'type' => $type], $with);
    }

}
