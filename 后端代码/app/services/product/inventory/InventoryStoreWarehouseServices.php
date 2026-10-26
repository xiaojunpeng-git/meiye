<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\services\cashier\v3\CashierV3ScopeResolver;
use think\facade\Db;

/**
 * This release operates one default warehouse per store. The multi-warehouse
 * table and historical rows remain intact, but are not selectable in V3.
 */
final class InventoryStoreWarehouseServices
{
    public function list(int $storeId, int $operatorId): array
    {
        $staff = Db::name('system_store_staff')->where('id', $operatorId)->where('store_id', $storeId)->where('status', 1)->where('is_del', 0)->find();
        $sessionInfo = (array)(request()->storeStaffInfo ?? []);
        $organizationSession = (!empty($sessionInfo['_cashier_v3_delegated']) || !empty($sessionInfo['_cashier_v3_organization']))
            && (int)($sessionInfo['store_id'] ?? 0) === $storeId;
        if (!$staff && !$organizationSession) throw new \RuntimeException('inventory_store_warehouse_scope_denied');
        return Db::name('inventory_location')->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('store_id', $storeId)->where('location_status', 'ACTIVE')->where('is_default', 1)
            ->field('id,location_code,location_name,location_type,is_default')->order('is_default desc,id asc')->select()->toArray();
    }
}
