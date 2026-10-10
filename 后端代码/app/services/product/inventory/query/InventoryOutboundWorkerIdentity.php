<?php
declare(strict_types=1);

namespace app\services\product\inventory\query;

use app\services\cashier\v3\CashierV3StoreLoginServices;
use app\services\cashier\v3\permission\CashierV3FeatureResolver;
use app\services\organization\EmployeeDataScopeServices;
use app\services\query\UnifiedQueryException;
use think\facade\Db;

/** Revalidate an outbound task's typed identity without persisting or replaying login tokens. */
final class InventoryOutboundWorkerIdentity
{
    public function profile(array $task): array
    {
        $scope = json_decode((string)($task['frozen_scope'] ?? ''), true);
        $kind = $scope['scope_dimensions']['inventory_actor_type'] ?? [];
        $id = (int)($task['operator_id'] ?? 0);
        $storeId = (int)($task['origin_store_id'] ?? 0);
        if ($id <= 0 || $storeId <= 0 || !is_array($kind) || count($kind) !== 1) $this->deny();
        if ($kind === ['staff']) {
            $profile = Db::name('system_store_staff')->where('id', $id)->where('store_id', $storeId)
                ->where('status', 1)->where('is_del', 0)->find();
            if (!$profile) $this->deny();
            if ((int)($profile['employee_id'] ?? 0) > 0 && !Db::name('employee')->where('id', (int)$profile['employee_id'])
                ->where('status', 1)->where('is_del', 0)->find()) $this->deny();
        } elseif ($kind === ['organization'] || $kind === ['delegated']) {
            $session = Db::name('cashier_v3_store_session')->where('id', $id)->where('store_id', $storeId)
                ->where('status', 1)->where('is_del', 0)->find();
            if (!$session || (int)$session['expire_time'] < time()) $this->deny();
            $employee = Db::name('employee')->where('id', (int)$session['employee_id'])
                ->where('status', 1)->where('is_del', 0)->field('id,auth_version')->find();
            if (!$employee || (int)$employee['auth_version'] !== (int)$session['auth_version']) $this->deny();
            $allowed = app()->make(EmployeeDataScopeServices::class)->resolveEffectiveStoreIds((int)$employee['id'], 0, ['admin_type' => 3]);
            if ($allowed !== null && !in_array($storeId, array_map('intval', $allowed), true)) $this->deny();
            $delegated = $kind === ['delegated'];
            $profile = ['id' => $id, 'store_id' => $storeId, 'employee_id' => (int)$employee['id'],
                'read_only' => $delegated, 'session_mode' => $delegated ? 'store_read_only' : 'store_organization',
                $delegated ? '_cashier_v3_delegated' : '_cashier_v3_organization' => 1];
            if (!$delegated && $session['source'] === CashierV3StoreLoginServices::STAFF_SCOPE_SESSION_SOURCE) {
                $profile['_cashier_v3_staff_scope'] = 1;
            }
        } else {
            $this->deny();
        }
        // The page must still be authorized now; frozen grants never override revocation or store closure.
        $store = Db::name('system_store')->alias('s')->leftJoin('organization_store os', 'os.store_id=s.id')
            ->leftJoin('organization o', 'o.id=os.org_id')->where('s.id', $storeId)
            ->where('s.is_del', 0)->where('s.is_show', 1)->field('o.is_del AS org_deleted')->find();
        if (!$store || !empty($store['org_deleted'])) $this->deny();
        $resolver = app()->make(CashierV3FeatureResolver::class);
        $features = !empty($profile['_cashier_v3_delegated']) ? $resolver->resolveVisibleFeatures($profile) : $resolver->resolveGrantedFeatures($profile);
        if (!in_array('cashier.v3.inventory.outbound', $features, true)) $this->deny();
        return $profile;
    }

    private function deny(): void
    {
        throw new UnifiedQueryException('UNIFIED_QUERY_EXPORT_PERMISSION_REVOKED', '出库导出账号或门店权限已失效，请重新登录查询。', []);
    }
}
