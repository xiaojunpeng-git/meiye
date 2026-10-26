<?php

namespace app\services\cashier\v3\card;

use think\facade\Db;

/**
 * Read current imported card rights for a signed-in operating store.
 * Hidden migration orders are transport records, so only their active scope,
 * current holder balance and usable project rows may enter customer assets.
 */
final class CashierV3HistoricalEntitlementReadServices
{
    /**
     * @param int[] $memberIds Server-authorized member IDs, never client scope.
     * @return array<int,array<string,mixed>> Current holders keyed by holder ID.
     */
    public function activeHolders(array $memberIds, string $tenantId, int $operatorStoreId): array
    {
        $memberIds = array_values(array_unique(array_filter(array_map('intval', $memberIds))));
        if (!$memberIds || $tenantId === '' || $operatorStoreId <= 0) return [];

        // The technical order is intentionally hidden from ordinary sales.
        // Exact migration identity prevents unrelated system-deleted orders
        // from becoming card rights through the scope lookup.
        $holders = Db::name('user_card_holder')->alias('h')
            ->join('store_order o', 'o.id=h.oid')
            ->whereIn('h.uid', $memberIds)
            ->where('h.store_id', 0)->where('h.is_del', 0)
            ->where('h.write_surplus_times', '>', 0)
            ->where('o.store_id', 0)->where('o.paid', 1)
            ->where('o.is_del', 0)->where('o.is_user_del', 0)
            ->where('o.refund_status', 0)->where('o.terminal_action', 0)
            ->where('o.card_upgrade_use_oid', 0)
            ->where('o.is_system_del', 1)->where('o.mark', 'migration_entitlement')
            ->field('h.id,h.uid,h.oid,h.card_name,h.card_no,h.store_id,h.write_start,h.write_end,h.add_time,h.write_surplus_times')
            ->select()->toArray();
        if (!$holders) return [];

        $allowed = array_fill_keys(CashierV3HistoricalEntitlementScopePolicy::allowedHolderIds(
            array_column($holders, 'id'), $tenantId, $operatorStoreId
        ), true);
        if (!$allowed) return [];

        $now = time();
        $eligible = [];
        foreach ($holders as $holder) {
            $id = (int)$holder['id'];
            $start = max(0, (int)$holder['write_start']);
            $end = max(0, (int)$holder['write_end']);
            if (!isset($allowed[$id]) || ($start > 0 && $start > $now)
                || ($end > 0 && $end < $now)) continue;
            $eligible[$id] = $holder;
        }
        if (!$eligible) return [];

        $states = Db::name('cashier_v3_card_state')->where('tenant_id', $tenantId)
            ->whereIn('card_holder_id', array_keys($eligible))
            ->column('card_status', 'card_holder_id');
        foreach ($eligible as $id => $holder) {
            if ((string)($states[$id] ?? 'enabled') !== 'enabled') unset($eligible[$id]);
        }
        if (!$eligible) return [];

        // A holder with no currently usable project is not an active card.
        // The holder balance remains the authority for choice-count cards:
        // component project rows are alternatives in one shared pool.
        $orders = array_column($eligible, 'oid');
        $usableOrders = [];
        foreach (Db::name('store_order_cart_info')->whereIn('oid', $orders)
            ->where('cart_type', 2)->where('product_type', 6)
            ->where('is_writeoff', 0)->where('write_surplus_times', '>', 0)
            ->field('oid,write_times,write_surplus_times,write_start,write_end')
            ->select()->toArray() as $project) {
            $start = max(0, (int)$project['write_start']);
            $end = max(0, (int)$project['write_end']);
            $total = (int)$project['write_times'];
            $remaining = (int)$project['write_surplus_times'];
            if ($total <= 0 || $remaining > $total || ($start > 0 && $start > $now)
                || ($end > 0 && $end < $now)) continue;
            $usableOrders[(int)$project['oid']] = true;
        }
        foreach ($eligible as $id => $holder) {
            if (!isset($usableOrders[(int)$holder['oid']])) unset($eligible[$id]);
        }
        return $eligible;
    }
}
