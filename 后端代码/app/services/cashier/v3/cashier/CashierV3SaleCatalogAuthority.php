<?php

namespace app\services\cashier\v3\cashier;

/**
 * 门店可售目录权威读取边界。
 *
 * 实现必须只返回服务端强制门店下的门店商品和 SKU。命令读取必须在当前
 * Gateway 事务内锁定商品、SKU 及卡项组成，不能复用列表投影快照。
 */
interface CashierV3SaleCatalogAuthority
{
    /** @return array<int,array> */
    public function listStoreItems(int $storeId): array;

    /**
     * @return array|null 主商品/SKU原始行；卡项同时包含 card_components
     */
    public function lockStoreItemBySkuId(int $storeId, int $skuId);

    /**
     * Server-side discovery and post-lock revalidation. This method never
     * acquires a row lock; card packages include their complete component set.
     *
     * @return array|null
     */
    public function readStoreItemBySkuId(int $storeId, int $skuId);

    /** Lock exactly one catalog resource row in the caller-owned transaction. */
    public function lockStoreResourceRow(int $storeId, string $kind, int $resourceId): bool;
}
