<?php
declare(strict_types=1);

namespace app\services\cashier\v3\reservation;

use app\services\cashier\v3\card\CashierV3HistoricalEntitlementScopePolicy;
use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\organization\OrganizationScopeService;
use think\exception\ValidateException;
use think\facade\Db;

/**
 * Historical rights have no sales store. Offer only operating stores in the
 * imported right's owner organization, then recheck that scope at submission.
 */
final class ImportedEntitlementReservationStoreServices
{
    /** Return the authoritative holder ID, or zero for an unrelated order. */
    public function holderId(int $uid, int $orderId, int $detailId): int
    {
        if ($uid <= 0 || $orderId <= 0 || $detailId <= 0) return 0;
        $order = Db::name('store_order')->where('id', $orderId)->where('uid', $uid)
            ->where('paid', 1)->where('is_del', 0)->where('is_user_del', 0)
            ->where('refund_status', 0)->where('terminal_action', 0)
            ->where('is_system_del', 1)->where('store_id', 0)->find();
        if (!$order) return 0;
        $detail = Db::name('store_order_cart_info')->where('id', $detailId)
            ->where('oid', $orderId)->where('cart_type', 2)->where('product_type', 6)
            ->where('is_writeoff', 0)->where('write_surplus_times', '>', 0)->find();
        if (!$detail) return 0;
        $holder = Db::name('user_card_holder')->where('oid', $orderId)
            ->where('uid', $uid)->where('is_del', 0)->where('write_surplus_times', '>', 0)
            ->field('id')->find();
        if (!$holder) return 0;
        $holderId = (int)$holder['id'];
        if (!CashierV3HistoricalEntitlementScopePolicy::isImportedHolder(
            $holderId, CashierV3ScopeResolver::TENANT_SCOPE_ID
        )) return 0;
        return (int)Db::name('migration_entitlement_scope')
            ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('card_holder_id', $holderId)->where('target_uid', $uid)
            ->where('status', 'active')->count() === 1 ? $holderId : 0;
    }

    /** Options are derived from the import scope, never from the member's home store. */
    public function availableStores(int $uid, int $orderId, int $detailId): array
    {
        $holderId = $this->holderId($uid, $orderId, $detailId);
        if ($holderId <= 0) return [];
        $ownerOrgId = (int)Db::name('migration_entitlement_scope')
            ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('card_holder_id', $holderId)->where('target_uid', $uid)
            ->where('status', 'active')->value('owner_org_id');
        if ($ownerOrgId <= 0) return [];
        // A disabled ancestor revokes every descendant. Mirror the write-side
        // historical policy instead of listing a store that booking rejects.
        $orgIds = app()->make(OrganizationScopeService::class)->getOrgIds($ownerOrgId, true);
        $orgRows = Db::name('organization')->whereIn('id', $orgIds)
            ->field('id,pid,status,is_del')->select()->toArray();
        $orgById = [];
        foreach ($orgRows as $row) $orgById[(int)$row['id']] = $row;
        $allowedOrgIds = [];
        foreach ($orgById as $orgId => $row) {
            $current = $orgId;
            $seen = [];
            for ($depth = 0; $depth < 64 && $current > 0; $depth++) {
                if (isset($seen[$current]) || !isset($orgById[$current])
                    || (int)$orgById[$current]['status'] !== 1
                    || (int)$orgById[$current]['is_del'] !== 0) break;
                if ($current === $ownerOrgId) {
                    $allowedOrgIds[] = $orgId;
                    break;
                }
                $seen[$current] = true;
                $current = (int)$orgById[$current]['pid'];
            }
        }
        if (!$allowedOrgIds) return [];
        $storeIds = Db::name('organization_store')->whereIn('org_id', $allowedOrgIds)->column('store_id');
        if (!$storeIds) return [];
        return Db::name('system_store')->whereIn('id', $storeIds)
            ->where('is_show', 1)->where('is_del', 0)->where('business_status', 1)
            ->field('id,name')->order('id asc')->select()->toArray();
    }

    /** Booking must use the current operating-store policy even if the picker is stale. */
    public function assertSelectedStore(int $uid, int $orderId, int $detailId, int $storeId): void
    {
        $holderId = $this->holderId($uid, $orderId, $detailId);
        if ($holderId <= 0 || $storeId <= 0 || !in_array($holderId,
            CashierV3HistoricalEntitlementScopePolicy::allowedHolderIds(
                [$holderId], CashierV3ScopeResolver::TENANT_SCOPE_ID, $storeId
            ), true)) {
            throw new ValidateException('该历史权益不能在所选门店预约，请重新选择渼约营业门店。');
        }
    }
}
