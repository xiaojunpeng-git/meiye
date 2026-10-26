<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\services\product\inventory\query\InventoryBatchStockQueryContract;

/**
 * Builds the fixed first-row totals from the complete, permission-scoped product result.
 * Callers must pass rows after all business filters and before pagination.
 */
final class InventoryStockSummaryTotalsServices
{
    /** @param array<int,array<string,mixed>> $rows */
    public function totals(array $rows, bool $canViewCost): array
    {
        $quantityUnits = 0;
        $amountCents = 0;
        foreach ($rows as $row) {
            $quantityUnits += $this->quantityUnits((string)($row['available_quantity'] ?? '0'));
            if ($canViewCost) $amountCents += (int)($row['inventory_amount_cents'] ?? 0);
        }
        return [
            'available_quantity' => InventoryBatchStockQueryContract::unitsToDecimal($quantityUnits, 4),
            'inventory_amount_cents' => $canViewCost ? $amountCents : null,
        ];
    }

    /** Declares the fixed query/header/summary behavior consumed by the stock table. */
    public static function tableLayout(): array
    {
        return [
            'fixed' => true,
            'summaryRow' => true,
            'fixedColumns' => ['product_name'],
        ];
    }

    private function quantityUnits(string $quantity): int
    {
        if (!preg_match('/^(\d+)(?:\.(\d{1,4}))?$/D', $quantity, $match)) {
            throw new \RuntimeException('inventory_stock_summary_quantity_invalid');
        }
        $fraction = str_pad((string)($match[2] ?? ''), 4, '0');
        return (int)$match[1] * 10000 + (int)$fraction;
    }
}
