<?php

declare(strict_types=1);

namespace app\services\cashier\v3\card;

use think\facade\Db;

/**
 * Reads the immutable actual sale allocation for issued card components.
 * Card component carts retain configured service value for compatibility;
 * this fact is the authority when the card sale was manually repriced.
 */
final class CashierV3CardSaleActualAmountServices
{
    /**
     * @return array<int,int> legacy cart detail id => actual sale cents
     */
    public function forOrders(array $orderIds, array $carts): array
    {
        if ($orderIds === [] || $carts === []) {
            return [];
        }
        try {
            $receipts = $this->rows(Db::name('cashier_v3_card_purchase_receipt')
                ->field('legacy_order_id,receipt_id,benefit_detail_ids_json')
                ->whereIn('legacy_order_id', $orderIds)
                ->where('status', 'completed')
                ->select());
        } catch (\Throwable $exception) {
            if ($this->missingOptionalAmountFactTable($exception)) {
                return [];
            }
            throw $exception;
        }
        if ($receipts === []) {
            return [];
        }
        $receiptIds = array_values(array_unique(array_filter(array_map(
            static fn(array $row): string => trim((string)($row['receipt_id'] ?? '')),
            $receipts
        ))));
        if ($receiptIds === []) {
            return [];
        }
        try {
            $allocations = $this->rows(Db::name('cashier_v3_card_sale_item_allocation_fact')
                ->field('card_receipt_id,component_product_id,sale_amount_cents,status')
                ->whereIn('card_receipt_id', $receiptIds)
                ->where('status', 'effective')
                ->select());
        } catch (\Throwable $exception) {
            if ($this->missingOptionalAmountFactTable($exception)) {
                return [];
            }
            throw $exception;
        }
        if ($allocations === []) {
            return [];
        }
        $cartsById = [];
        foreach ($carts as $cart) {
            $cartsById[(int)($cart['id'] ?? 0)] = $cart;
        }
        $allocationsByReceipt = [];
        foreach ($allocations as $allocation) {
            $receiptId = trim((string)($allocation['card_receipt_id'] ?? ''));
            if ($receiptId !== '') {
                $allocationsByReceipt[$receiptId][] = $allocation;
            }
        }
        $result = [];
        foreach ($receipts as $receipt) {
            $receiptId = trim((string)($receipt['receipt_id'] ?? ''));
            $orderId = (int)($receipt['legacy_order_id'] ?? 0);
            $detailIds = json_decode((string)($receipt['benefit_detail_ids_json'] ?? ''), true);
            if ($receiptId === '' || $orderId <= 0 || !is_array($detailIds)) {
                continue;
            }
            $detailsByProduct = [];
            foreach ($detailIds as $detailId) {
                $detailId = (int)$detailId;
                $cart = $cartsById[$detailId] ?? null;
                if (!$cart || (int)($cart['oid'] ?? 0) !== $orderId) {
                    continue;
                }
                $productId = (int)($cart['product_id'] ?? 0);
                if ($productId > 0) {
                    $detailsByProduct[$productId][] = $detailId;
                }
            }
            foreach ($allocationsByReceipt[$receiptId] ?? [] as $allocation) {
                $productId = (int)($allocation['component_product_id'] ?? 0);
                $amountCents = (int)($allocation['sale_amount_cents'] ?? -1);
                $detailIdsForProduct = $detailsByProduct[$productId] ?? [];
                if ($productId <= 0 || $amountCents < 0 || $detailIdsForProduct === []) {
                    continue;
                }
                sort($detailIdsForProduct, SORT_NUMERIC);
                $count = count($detailIdsForProduct);
                $base = intdiv($amountCents, $count);
                $remainder = $amountCents % $count;
                foreach ($detailIdsForProduct as $index => $detailId) {
                    $result[$detailId] = (int)($result[$detailId] ?? 0)
                        + $base + ($index < $remainder ? 1 : 0);
                }
            }
        }
        return $result;
    }

    private function rows($rows): array
    {
        if (is_array($rows)) {
            return array_values(array_map(static fn($row): array => (array)$row, $rows));
        }
        if (is_object($rows) && method_exists($rows, 'toArray')) {
            return array_values(array_map(static fn($row): array => (array)$row, $rows->toArray()));
        }
        return [];
    }

    private function missingOptionalAmountFactTable(\Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());
        return (strpos($message, 'cashier_v3_card_purchase_receipt') !== false
                || strpos($message, 'cashier_v3_card_sale_item_allocation_fact') !== false)
            && (strpos($message, "doesn't exist") !== false || strpos($message, 'not found') !== false);
    }
}
