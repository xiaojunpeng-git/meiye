<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\services\product\inventory\query\InventoryBatchStockQueryContract;
use app\services\system\SystemMenusServices;
use think\facade\Db;

/** Rebuilds store inventory capabilities from the active staff role menus. */
final class InventoryStoreAccessPolicy
{
    /** @return string[] */
    public function features(int $storeId, int $operatorId): array
    {
        $staff = Db::name('system_store_staff')->where('id', $operatorId)
            ->where('store_id', $storeId)->where('status', 1)->where('is_del', 0)
            ->field('id,roles,level')->find();
        if (!$staff) {
            throw new \RuntimeException('inventory_store_access_denied');
        }
        $roles = [];
        foreach (explode(',', (string)($staff['roles'] ?? '')) as $role) {
            $roleId = (int)$role;
            if ($roleId > 0) $roles[$roleId] = $roleId;
        }
        try {
            [, $uniqueAuth] = app()->make(SystemMenusServices::class)->getMenusList(
                array_values($roles),
                (int)($staff['level'] ?? 1),
                2
            );
        } catch (\Throwable $exception) {
            $uniqueAuth = [];
        }
        $features = [InventoryBatchStockQueryContract::PERMISSION_VIEW, InventoryBatchStockQueryContract::PERMISSION_EXPORT];
        // The store session is issued server-side. A level-0 store principal
        // is the platform super-administrator's delegated store session and
        // retains the original full-management visibility without accepting
        // any client-side privilege hint.
        if ((int)($staff['level'] ?? 1) === 0
            || in_array(InventoryBatchStockQueryContract::PERMISSION_COST, (array)$uniqueAuth, true)) {
            $features[] = InventoryBatchStockQueryContract::PERMISSION_COST;
        }
        sort($features, SORT_STRING);
        return $features;
    }
}
