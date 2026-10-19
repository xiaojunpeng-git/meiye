<?php
declare(strict_types=1);

namespace app\services\cashier\v3\reservation;

use app\services\cashier\v3\card\CashierV3HistoricalEntitlementScopePolicy;
use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\organization\OrganizationScopeService;
use app\services\product\product\StoreProductReservationServices;
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

    /**
     * Resolve one owned right for a historical booking. Both picker and submit
     * use this boundary: hidden carriers require import scope, ordinary orders
     * retain the configured cross-store rule; neither can supply foreign rights.
     */
    public function reservationDetail(int $uid, int $detailId, int $storeId, int $at = 0): array
    {
        if ($uid <= 0 || $detailId <= 0 || $storeId <= 0) {
            throw new ValidateException('请先选择预约门店。');
        }
        $detail = Db::name('store_order_cart_info')->where('id', $detailId)
            ->where('cart_type', 2)->where('product_type', 6)
            ->where('is_writeoff', 0)->where('write_surplus_times', '>', 0)->find();
        $order = $detail ? Db::name('store_order')->where('id', (int)$detail['oid'])
            ->where('uid', $uid)->where('paid', 1)->where('is_del', 0)
            ->where('is_user_del', 0)->where('refund_status', 0)->where('terminal_action', 0)
            ->where('card_upgrade_use_oid', 0)->whereIn('is_system_del', [0, 1])->find() : null;
        if (!$detail || !$order) throw new ValidateException('项目不存在或权益已失效。');
        $at = $at > 0 ? $at : time();
        $this->assertValidityWindow($detail, $at);
        if ((int)$order['is_system_del'] === 1) {
            $this->assertSelectedStore($uid, (int)$order['id'], $detailId, $storeId);
            $holder = Db::name('user_card_holder')->where('oid', (int)$order['id'])
                ->where('uid', $uid)->where('is_del', 0)->find();
            $this->assertValidityWindow($holder ?: [], $at);
        } elseif ((int)$order['store_id'] !== $storeId && (int)sys_config('cross_store_verification', 1) !== 1) {
            throw new ValidateException('该已购项目不能在所选门店预约。');
        }
        $product = Db::name('store_product')->where('id', (int)$detail['product_id'])
            ->field('id,pid,project_service_duration,addon_service_duration')->find();
        if (!$product) throw new ValidateException('预约项目不存在。');
        $durations = app()->make(StoreProductReservationServices::class)->fillServiceDuration($product);
        $detail['_project_duration'] = (int)$durations['project_service_duration'];
        $detail['_duration'] = (int)$durations['addon_service_duration'];
        $detail['_order'] = $order;
        return $detail;
    }

    /** A right must still be valid on the booked service date, not just today. */
    private function assertValidityWindow(array $right, int $at): void
    {
        if (((int)($right['write_start'] ?? 0) > 0 && (int)$right['write_start'] > $at)
            || ((int)($right['write_end'] ?? 0) > 0 && (int)$right['write_end'] < $at)) {
            throw new ValidateException('项目不在权益有效期内。');
        }
    }

    /** Pending appointments occupy visits; reads never deduct or repair rights. */
    public function availableQuantity(int $uid, array $detail): int
    {
        $reserved = Db::name('cashier_v3_reservation_line')->alias('l')
            ->join('cashier_v3_reservation r', 'r.id=l.reservation_id')
            ->where('l.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('l.entitlement_source_detail_id', (int)$detail['id'])
            ->where('r.member_id', $uid)
            ->where('r.lifecycle_generation', CashierV3ReservationLifecycleServices::GENERATION)
            ->whereIn('r.status', ['PENDING_CONFIRMATION', 'UNSTARTED', 'IN_SERVICE'])
            ->sum('l.quantity');
        return max(0, (int)floor((int)$detail['write_surplus_times'] - (float)$reserved));
    }

    /**
     * Historical picker uses read-only owned-right queries, not the legacy
     * write-off list's consistency repair. Main context is checked server-side.
     */
    public function addonOptions(int $uid, int $orderId, int $mainDetailId, int $storeId): array
    {
        $this->assertSelectedStore($uid, $orderId, $mainDetailId, $storeId);
        $candidates = Db::name('store_order_cart_info')->alias('c')
            ->join('store_order o', 'o.id=c.oid')->where('o.uid', $uid)
            ->where('o.paid', 1)->where('o.is_del', 0)->where('o.is_user_del', 0)
            ->where('o.refund_status', 0)->where('o.terminal_action', 0)
            ->whereIn('o.is_system_del', [0, 1])->where('c.cart_type', 2)
            ->where('c.product_type', 6)->where('c.is_writeoff', 0)
            ->where('c.write_surplus_times', '>', 0)->where('c.id', '<>', $mainDetailId)
            ->order('c.id desc')->column('c.id');
        $groups = [];
        foreach ($candidates as $candidate) {
            try {
                $detail = $this->reservationDetail($uid, (int)$candidate, $storeId);
            } catch (ValidateException $unavailable) {
                continue;
            }
            $available = $this->availableQuantity($uid, $detail);
            if ($available <= 0) continue;
            $order = $detail['_order'];
            $oid = (int)$order['id'];
            if (!isset($groups[$oid])) {
                $groups[$oid] = ['order' => ['id' => $oid, 'type' => (int)$order['type'],
                    'product_type' => (int)$order['product_type']], 'cart_info' => []];
            }
            $snapshot = json_decode((string)$detail['cart_info'], true) ?: [];
            $snapshot['productInfo']['addon_service_duration'] = $detail['_duration'];
            // Return only the picker contract, not the carrier's financial or
            // migration internals. IDs are revalidated again on submission.
            $groups[$oid]['cart_info'][] = [
                'id' => (int)$detail['id'], 'oid' => $oid,
                'product_id' => (int)$detail['product_id'], 'product_type' => 6,
                'sku_unique' => (string)$detail['sku_unique'],
                'is_gift' => (int)$detail['is_gift'], 'is_writeoff' => 0,
                'write_surplus_times' => $available, 'cart_info' => $snapshot,
                'addon_service_duration' => (int)$detail['_duration'],
            ];
        }
        return ['list' => array_values($groups)];
    }
}
