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
    public function forOrders(array $orderIds, array $carts, ?string $tenantId = null): array
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
            $allocationQuery = Db::name('cashier_v3_card_sale_item_allocation_fact')
                ->field('card_receipt_id,component_product_id,sale_amount_cents,status')
                ->whereIn('card_receipt_id', $receiptIds)
                ->where('status', 'effective');
            // The immutable fact is keyed by tenant before receipt. The
            // caller-verified tenant keeps this bounded read on that index;
            // legacy callers without a tenant retain their previous behavior.
            if ($tenantId !== null) $allocationQuery->where('tenant_id', $tenantId);
            $allocations = $this->rows($allocationQuery->select());
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

    /**
     * Convert immutable sale weights into whole-yuan entitlement pools.
     *
     * Card checkout settles a whole-yuan total, while the sale allocation fact
     * may contain cents because it is also used for reporting. Writeoff cannot
     * consume those reporting cents directly. Each order is therefore rounded
     * with Hamilton allocation: floor every component to yuan, then give the
     * remaining yuan to the largest cent remainders in stable cart order. The
     * result keeps every component nonnegative and reconciles exactly to the
     * card's settled total.
     *
     * @return array<int,int> legacy cart detail id => whole-yuan writeoff cents
     */
    public function wholeYuanForOrders(array $orders, array $carts): array
    {
        $orderIds = array_values(array_unique(array_filter(array_map(
            static fn(array $order): int => (int)($order['id'] ?? 0),
            $orders
        ))));
        if ($orderIds === [] || $carts === []) return [];
        $tenants = array_values(array_unique(array_filter(array_map(
            static fn(array $order): string => trim((string)($order['tenant_id'] ?? '')),
            $orders
        ), static fn(string $value): bool => $value !== '')));
        // Only a complete single-tenant order set can narrow the allocation
        // query. Mixed or partially attributed sets keep the old read path.
        $tenantId = count($tenants) === 1 && count($orders) === count(array_filter($orders,
            static fn(array $order): bool => trim((string)($order['tenant_id'] ?? '')) !== ''))
            ? $tenants[0] : null;
        $raw = $this->forOrders($orderIds, $carts, $tenantId);
        $ordersById = [];
        foreach ($orders as $order) $ordersById[(int)($order['id'] ?? 0)] = $order;
        $cartsByOrder = [];
        foreach ($carts as $cart) {
            $orderId = (int)($cart['oid'] ?? 0);
            $cartId = (int)($cart['id'] ?? 0);
            if ($orderId > 0 && $cartId > 0) $cartsByOrder[$orderId][] = $cartId;
        }
        // Cart id is the immutable sale-line order used to break equal
        // remainders. Do not depend on whichever ordering a caller selected.
        foreach ($cartsByOrder as &$cartIds) sort($cartIds, SORT_NUMERIC);
        unset($cartIds);
        $result = [];
        foreach ($orderIds as $orderId) {
            $order = $ordersById[$orderId] ?? [];
            $paid = trim((string)($order['pay_price'] ?? ''));
            if (preg_match('/^(?:0|[1-9][0-9]*)(?:\.0{1,2})?$/D', $paid) !== 1) continue;
            $paidCents = (int)bcmul($paid, '100', 0);
            $ids = $cartsByOrder[$orderId] ?? [];
            $amounts = [];
            foreach ($ids as $id) {
                if (!array_key_exists($id, $raw)) {
                    $amounts = [];
                    break;
                }
                $amounts[$id] = (int)$raw[$id];
            }
            if ($ids === [] || $amounts === []) continue;
            try {
                $result += self::normalizeWholeYuanAllocations($amounts, $ids, $paidCents);
            } catch (\InvalidArgumentException $exception) {
                // Missing or unreconciled sale facts must fail closed at the
                // caller; never fall back to a different writeoff amount.
            }
        }
        return $result;
    }

    /**
     * Pure whole-yuan normalization used by contracts and read models.
     * Stable order resolves equal remainders without depending on DB row order.
     *
     * @param array<int,int> $rawCentsByCartId
     * @param int[] $orderedCartIds
     * @return array<int,int>
     */
    public static function normalizeWholeYuanAllocations(
        array $rawCentsByCartId,
        array $orderedCartIds,
        int $paidCents
    ): array {
        if ($paidCents < 0 || $paidCents % 100 !== 0 || $orderedCartIds === []) {
            throw new \InvalidArgumentException('card writeoff total must be whole yuan');
        }
        $rows = [];
        $rawTotal = 0;
        foreach (array_values($orderedCartIds) as $sequence => $cartId) {
            $cartId = (int)$cartId;
            if ($cartId <= 0 || !array_key_exists($cartId, $rawCentsByCartId)) {
                throw new \InvalidArgumentException('card writeoff allocation is incomplete');
            }
            $amount = (int)$rawCentsByCartId[$cartId];
            if ($amount < 0 || $amount > PHP_INT_MAX - $rawTotal) {
                throw new \InvalidArgumentException('card writeoff allocation is invalid');
            }
            $rawTotal += $amount;
            $rows[] = [
                'id' => $cartId,
                'sequence' => $sequence,
                'amount' => intdiv($amount, 100) * 100,
                'remainder' => $amount % 100,
            ];
        }
        if ($rawTotal !== $paidCents) {
            throw new \InvalidArgumentException('card writeoff allocation does not reconcile');
        }
        $wholeTotal = array_sum(array_column($rows, 'amount'));
        $yuanLeft = intdiv($paidCents - $wholeTotal, 100);
        usort($rows, static function (array $left, array $right): int {
            return $right['remainder'] <=> $left['remainder']
                ?: $left['sequence'] <=> $right['sequence'];
        });
        for ($index = 0; $index < $yuanLeft; $index++) {
            if (!isset($rows[$index]) || $rows[$index]['remainder'] <= 0) {
                throw new \InvalidArgumentException('card writeoff remainder cannot be allocated');
            }
            $rows[$index]['amount'] += 100;
        }
        usort($rows, static fn(array $left, array $right): int => $left['sequence'] <=> $right['sequence']);
        $result = [];
        foreach ($rows as $row) $result[$row['id']] = $row['amount'];
        if (array_sum($result) !== $paidCents) {
            throw new \InvalidArgumentException('card writeoff allocation lost value');
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
