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
        // 【库存铁律】旧门店规格编辑（删光重建并写库存）已停用
        throw new AdminException('已停用：不可在此编辑规格库存；资料请走商品编辑，库存请到「库存管理」操作');
        // 原逻辑：delete 全部 type=0 后按请求库存重建 —— 已注释
        // $this->transaction(function () use (...) { ... });
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
