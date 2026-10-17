<?php

declare(strict_types=1);

namespace app\services\report;

use app\services\cashier\v3\CashierV3ScopeResolver;
use think\facade\Db;

/**
 * Exclude migrated and old-card-entry debt settlements only from report person/event cohorts.
 * The original receipts still contribute to cash, actual performance and refunds.
 */
final class StoreReportImportedRepaymentCountScope
{
    public static function exclude(array $rows, array $stores, string $allocationKey = 'id'): array
    {
        if ($rows === [] || $stores === []) return $rows;
        $allocationIds = [];
        $paymentIds = [];
        foreach ($rows as $row) {
            if (!empty($row['unallocated_repayment'])) {
                $paymentId = self::directPaymentId($row);
                if ($paymentId !== '') $paymentIds[$paymentId] = true;
                continue;
            }
            $allocationId = (string)($row[$allocationKey] ?? '');
            if (preg_match('/^[1-9][0-9]*$/D', $allocationId)) {
                $allocationIds[$allocationId] = true;
            } elseif (($paymentId = self::directPaymentId($row)) !== '') {
                $paymentIds[$paymentId] = true;
            }
        }
        $byAllocation = [];
        foreach (array_chunk(array_keys($allocationIds), 500) as $ids) {
            foreach (Db::name('cashier_v3_payment_sale_allocation_fact')->alias('allocation')
                ->leftJoin('cashier_v3_payment_sale_allocation_fact original',
                    'original.tenant_id=allocation.tenant_id AND original.allocation_fact_id=allocation.reversal_of')
                ->where('allocation.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
                ->whereIn('allocation.store_id', $stores)->whereIn('allocation.id', $ids)
                ->fieldRaw("allocation.id,COALESCE(NULLIF(original.payment_fact_id,''),allocation.payment_fact_id) payment_fact_id")
                ->select()->toArray() as $allocation) {
                $paymentId = trim((string)($allocation['payment_fact_id'] ?? ''));
                $byAllocation[(string)$allocation['id']] = $paymentId;
                if ($paymentId !== '') $paymentIds[$paymentId] = true;
            }
        }
        $repayments = [];
        foreach (array_chunk(array_keys($paymentIds), 500) as $ids) {
            foreach (Db::name('cashier_v3_payment_fact')
                ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
                ->whereIn('store_id', $stores)->whereIn('fact_id', $ids)
                ->whereIn('source_document_type', ['debt_repayment', 'recharge_debt_repayment'])
                ->field('fact_id,source_document_type,natural_key,order_id,store_id')
                ->select()->toArray() as $payment) {
                $repayments[(string)$payment['fact_id']] = $payment;
            }
        }
        $excludedPaymentIds = [];
        $salesDebtIds = []; $rechargeDebtIds = [];
        foreach ($repayments as $paymentId => $payment) {
            $type = (string)$payment['source_document_type'];
            $naturalKey = (string)($payment['natural_key'] ?? '');
            if ($type === 'debt_repayment' && str_starts_with($naturalKey, 'historical_debt_repayment:payment:')) {
                $excludedPaymentIds[$paymentId] = true;
            } elseif ($type === 'debt_repayment') {
                $salesDebtIds[(string)$payment['order_id']] = true;
            } else {
                $rechargeDebtIds[(string)$payment['order_id']] = true;
            }
        }
        $excludedSales = self::excludedSalesRepaymentIds(array_keys($salesDebtIds), $stores);
        $importedRecharges = [];
        foreach (array_chunk(array_keys($rechargeDebtIds), 500) as $ids) {
            foreach (Db::name('cashier_v3_recharge_debt_repayment')->alias('repayment')
                ->join('user_recharge original_recharge', 'original_recharge.id=repayment.recharge_id')
                ->where('repayment.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
                ->whereIn('repayment.store_id', $stores)->whereIn('repayment.repayment_id', $ids)
                ->where(function ($query): void {
                    $query->where('original_recharge.channel_type', 'migration')
                        ->whereOr('original_recharge.recharge_type', 'migration');
                })->column('repayment.repayment_id') as $id) $importedRecharges[(string)$id] = true;
        }
        foreach ($repayments as $paymentId => $payment) {
            $orderId = (string)$payment['order_id'];
            if (($payment['source_document_type'] === 'debt_repayment' && isset($excludedSales[$orderId]))
                || ($payment['source_document_type'] === 'recharge_debt_repayment' && isset($importedRecharges[$orderId]))) {
                $excludedPaymentIds[$paymentId] = true;
            }
        }
        return array_values(array_filter($rows, static function (array $row) use ($allocationKey, $byAllocation, $excludedPaymentIds): bool {
            $allocationId = (string)($row[$allocationKey] ?? '');
            $paymentId = empty($row['unallocated_repayment']) && isset($byAllocation[$allocationId])
                ? $byAllocation[$allocationId] : self::directPaymentId($row);
            return !isset($excludedPaymentIds[$paymentId]);
        }));
    }

    /** Resolve excluded sales repayments from their original order, never from a repayment label or member name. */
    public static function excludedSalesRepaymentIds(array $repaymentIds, array $stores): array
    {
        if ($repaymentIds === [] || $stores === []) return [];
        $excluded = [];
        foreach (array_chunk(array_values(array_unique($repaymentIds)), 500) as $ids) {
            foreach (Db::name('cashier_v3_debt_repayment')->alias('repayment')
                ->join('store_debt debt', 'debt.id=repayment.debt_id')
                ->join('store_order original_sale', 'original_sale.id=debt.order_id')
                ->where('repayment.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
                ->whereIn('repayment.store_id', $stores)->whereIn('repayment.repayment_id', $ids)
                ->where('original_sale.channel_type', 'migration')
                ->column('repayment.repayment_id') as $id) $excluded[(string)$id] = true;

            // A V3 old-card sale is not a migration-channel store order. Its committed
            // payment draft is the authority; debt.order_id points to the V3 sale record.
            foreach (Db::name('cashier_v3_debt_repayment')->alias('repayment')
                ->join('store_debt debt', 'debt.id=repayment.debt_id')
                ->join('cashier_v3_sales_order original_sale',
                    'original_sale.id=debt.order_id AND original_sale.tenant_id=repayment.tenant_id'
                    . ' AND original_sale.store_id=repayment.store_id AND original_sale.order_id=repayment.sales_order_id')
                ->join('cashier_v3_checkout_payment_draft old_card',
                    'old_card.request_id=original_sale.checkout_request_id'
                    . ' AND old_card.tenant_id=original_sale.tenant_id AND old_card.store_id=original_sale.store_id'
                    . ' AND old_card.member_id=original_sale.member_id')
                ->where('repayment.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
                ->whereIn('repayment.store_id', $stores)->whereIn('repayment.repayment_id', $ids)
                ->where('old_card.payment_method', 'old_card_entry')->where('old_card.draft_status', 'committed')
                ->column('repayment.repayment_id') as $id) $excluded[(string)$id] = true;
        }
        return $excluded;
    }

    /** A standalone repayment has a payment fact but no sale allocation. */
    private static function directPaymentId(array $row): string
    {
        if (!empty($row['unallocated_repayment'])) return trim((string)($row['payment_fact_id'] ?? ''));
        $id = (string)($row['id'] ?? '');
        return (str_starts_with($id, 'sales-debt-repayment:') || str_starts_with($id, 'recharge-payment:'))
            ? trim((string)($row['fact_id'] ?? '')) : '';
    }
}
