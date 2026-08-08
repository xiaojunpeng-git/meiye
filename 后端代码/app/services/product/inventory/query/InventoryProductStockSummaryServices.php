<?php
declare(strict_types=1);

namespace app\services\product\inventory\query;

/**
 * Produces product/SKU rows from already scoped batch balances. It is used only
 * where an operator is filtering the list by the displayed total quantity.
 */
final class InventoryProductStockSummaryServices
{
    /** @param array<int,array<string,mixed>> $batchRows @return array<int,array<string,mixed>> */
    public function summarize(array $batchRows): array
    {
        $groups = [];
        foreach ($batchRows as $row) {
            $productId = (int)($row['product_id'] ?? 0);
            $skuId = (int)($row['sku_id'] ?? 0);
            $scale = (int)($row['quantity_scale'] ?? -1);
            if ($productId <= 0 || $skuId <= 0 || $scale < 0 || $scale > 4) {
                throw new \RuntimeException('inventory_product_summary_row_invalid');
            }
            $key = $productId . ':' . $skuId;
            if (!isset($groups[$key])) {
                $groups[$key] = $row + [
                    'quantity_units' => 0,
                    'inventory_amount_cents' => ($row['inventory_amount'] ?? null) === null ? null : 0,
                ];
                $groups[$key]['batch_balance_id'] = 'product-summary-' . $key;
                $groups[$key]['quantity_units'] = 0;
            } elseif ((int)$groups[$key]['quantity_scale'] !== $scale) {
                throw new \RuntimeException('inventory_product_quantity_scale_mismatch');
            }

            $groups[$key]['quantity_units'] += $this->decimalUnits((string)($row['batch_balance_quantity'] ?? ''), $scale);
            if ($groups[$key]['inventory_amount_cents'] !== null) {
                $amount = $this->amountCents($row['inventory_amount'] ?? null);
                $groups[$key]['inventory_amount_cents'] = $amount === null
                    ? null
                    : (int)$groups[$key]['inventory_amount_cents'] + $amount;
            }
        }

        foreach ($groups as &$group) {
            $group['batch_balance_quantity'] = InventoryBatchStockQueryContract::unitsToDecimal(
                (int)$group['quantity_units'], (int)$group['quantity_scale']
            );
            $group['available_quantity'] = $group['batch_balance_quantity'];
            $group['inventory_amount'] = $group['inventory_amount_cents'] === null
                ? null
                : $this->amount((int)$group['inventory_amount_cents']);
            unset($group['quantity_units'], $group['inventory_amount_cents']);
        }
        unset($group);
        return array_values($groups);
    }

    private function decimalUnits(string $quantity, int $scale): int
    {
        if (!preg_match('/^\d+(?:\.\d{1,4})?$/D', $quantity)) {
            throw new \RuntimeException('inventory_product_summary_quantity_invalid');
        }
        [$whole, $fraction] = array_pad(explode('.', $quantity, 2), 2, '');
        $fraction = substr(str_pad($fraction, $scale, '0'), 0, $scale);
        return (int)$whole * (10 ** $scale) + (int)($fraction === '' ? '0' : $fraction);
    }

    private function amountCents($amount): ?int
    {
        if ($amount === null) {
            return null;
        }
        if (!preg_match('/^(\d+)\.(\d{2})$/D', (string)$amount, $match)) {
            throw new \RuntimeException('inventory_product_summary_amount_invalid');
        }
        return (int)$match[1] * 100 + (int)$match[2];
    }

    private function amount(int $cents): string
    {
        return intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
