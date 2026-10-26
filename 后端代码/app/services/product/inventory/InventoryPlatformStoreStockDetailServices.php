<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\product\inventory\query\InventoryBatchStockDataScope;
use app\services\query\UnifiedQueryException;
use think\facade\Db;

/** 平台门店仓库存详情只复用库存查询读权限，不继承业务单据或仓库写权限。 */
final class InventoryPlatformStoreStockDetailServices
{
    public function productDetail(array $adminInfo, int $locationId, int $productId): array
    {
        if ($locationId <= 0 || $productId <= 0) {
            throw new \InvalidArgumentException('inventory_product_detail_selection_invalid');
        }
        $policy = new InventoryPlatformAccessPolicy();
        $access = $policy->forStockRead($policy->resolve($adminInfo));
        // 浏览器传仓库 ID 仅用于收窄；必须重查当前启用的默认门店仓及账号可读范围。
        $location = Db::name('inventory_location')
            ->where('id', $locationId)
            ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('location_type', 'STORE')->where('location_status', 'ACTIVE')->where('is_default', 1)
            ->field('id,store_id,store_name_snapshot')->find();
        if (!$location || !in_array((int)$location['store_id'], (array)$access['store_ids'], true)) {
            throw new UnifiedQueryException('UNIFIED_QUERY_SCOPE_INVALID', '所选门店仓不在当前平台库存范围内。', []);
        }
        $scope = new InventoryBatchStockDataScope(
            CashierV3ScopeResolver::TENANT_SCOPE_ID,
            [(int)$location['id']],
            (array)$access['features']
        );
        $detail = (new InventoryStoreReadModelServices())->productDetailForScope($scope, $productId);
        $storeName = trim((string)$location['store_name_snapshot']);
        if ($storeName === '') {
            $storeName = trim((string)Db::name('system_store')->where('id', (int)$location['store_id'])->value('name'));
        }
        $displayName = $storeName !== '' ? $storeName : '门店' . (int)$location['store_id'];
        // 门店仓流水已被单仓事实范围锁定，显示名与库存列表一致，历史事实仍原样保留。
        foreach ($detail['movements'] as &$movement) $movement['location_name'] = $displayName;
        unset($movement);
        return $detail;
    }
}
