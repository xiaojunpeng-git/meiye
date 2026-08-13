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
        // V3 数据权限选店会话没有 system_store_staff 任职行；权限快照
        // 已在 cashier_v3_store_session 签发时由服务端计算并随 token
        // 回读。这里仅允许当前已认证的 delegated profile 使用该快照，
        // 不接受前端传入的权限提示。
        $sessionInfo = (array)(request()->storeStaffInfo ?? []);
        if (!empty($sessionInfo['_cashier_v3_delegated'])) {
            if ((int)($sessionInfo['store_id'] ?? 0) !== $storeId) {
                throw new \RuntimeException('inventory_store_access_denied');
            }
            // V3 岗位功能已通过 delegated 登录快照裁剪；库存统一查询
            // 的旧权限名只作为内部查询能力标识，不从浏览器 payload 读取。
            return ['inventory.batch.view', 'inventory.batch.export'];
        }
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
