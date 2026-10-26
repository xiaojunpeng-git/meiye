<?php
declare(strict_types=1);

namespace app\services\product\inventory\query;

/**
 * Builds inventory detail and expiry/age analysis from the authoritative
 * batch-balance projection. Callers must inject DataScope before this service.
 */
final class InventoryBatchAnalyticsServices
{
    public function analyze(array $rows, string $cutoffDate, bool $canViewCost): array
    {
        $cutoffDate = InventoryBatchStockQueryContract::assertCutoffDate($cutoffDate);
        $detailRows = [];
        $expiryBuckets = [];
        $ageBuckets = [];
        foreach ($rows as $row) {
            $detail = $this->detailRow($row, $cutoffDate, $canViewCost);
            $detailRows[] = $detail;
            $this->addBucket($expiryBuckets, $detail['remaining_shelf_life_band'], $detail);
            $this->addBucket($ageBuckets, $detail['inventory_age_band'], $detail);
        }
        return [
            'query_cutoff_date' => $cutoffDate,
            'detail_rows' => $detailRows,
            'expiry_buckets' => $this->normalizeBuckets($expiryBuckets),
            'age_buckets' => $this->normalizeBuckets($ageBuckets),
        ];
    }

    private function detailRow(array $row, string $cutoffDate, bool $canViewCost): array
    {
        foreach (['batch_balance_quantity_units', 'quantity_scale', 'unit_cost_cents', 'expire_date', 'received_date'] as $key) {
            if (!array_key_exists($key, $row)) {
                throw new \InvalidArgumentException('inventory_batch_analytics_row_invalid');
            }
        }
        $units = $this->integer($row['batch_balance_quantity_units']);
        $scale = $this->integer($row['quantity_scale']);
        $costCents = $this->integer($row['unit_cost_cents']);
        if ($units < 0 || $scale < 0 || $scale > 4 || $costCents < 0) {
            throw new \InvalidArgumentException('inventory_batch_analytics_value_invalid');
        }
        $expireDate = $this->nullableDate($row['expire_date']);
        $receivedDate = $this->nullableDate($row['received_date']);
        $expiryDays = $expireDate === null ? null : $this->daysBetween($cutoffDate, $expireDate);
        $ageDays = $receivedDate === null ? null : $this->daysBetween($receivedDate, $cutoffDate);
        return [
            'batch_balance_id' => $row['batch_balance_id'] ?? null,
            'remaining_shelf_life_days' => $expiryDays,
            'remaining_shelf_life_band' => $this->expiryBand($expiryDays),
            'inventory_age_days' => $ageDays,
            'inventory_age_band' => $this->ageBand($ageDays),
            'inventory_amount' => $canViewCost
                ? $this->amount($units, $scale, $costCents)
                : null,
        ];
    }

    private function expiryBand(?int $days): string
    {
        if ($days === null) return '到期日未知';
        foreach ([[-1, '已过期'], [30, '0-30天'], [45, '31-45天'], [60, '46-60天'], [90, '61-90天'], [180, '91-180天'], [365, '1年内'], [730, '1-2年'], [1095, '2-3年']] as $rule) {
            if ($days <= $rule[0]) return $rule[1];
        }
        return '3年以上';
    }

    private function ageBand(?int $days): string
    {
        if ($days === null) return '正式入库日期未知';
        if ($days <= 30) return '0-30天';
        if ($days <= 90) return '31-90天';
        if ($days <= 180) return '91-180天';
        if ($days <= 365) return '181-365天';
        return '1年以上';
    }

    private function amount(int $units, int $scale, int $unitCostCents): string
    {
        $divisor = 10 ** $scale;
        $cents = intdiv($units * $unitCostCents, $divisor);
        return InventoryBatchStockQueryContract::centsToAmount($cents);
    }

    private function addBucket(array &$buckets, string $name, array $detail): void
    {
        if (!isset($buckets[$name])) $buckets[$name] = ['name' => $name, 'batch_count' => 0, 'inventory_amount' => null];
        $buckets[$name]['batch_count']++;
        if ($detail['inventory_amount'] !== null) {
            $buckets[$name]['inventory_amount'] = $this->addAmount($buckets[$name]['inventory_amount'], $detail['inventory_amount']);
        }
    }

    private function normalizeBuckets(array $buckets): array
    {
        return array_values($buckets);
    }

    private function addAmount(?string $left, string $right): string
    {
        $leftCents = $left === null ? 0 : $this->integer(str_replace('.', '', $left));
        return InventoryBatchStockQueryContract::centsToAmount($leftCents + $this->integer(str_replace('.', '', $right)));
    }

    private function nullableDate($value): ?string
    {
        if ($value === null || $value === '') return null;
        return InventoryBatchStockQueryContract::assertCutoffDate((string)$value);
    }

    private function daysBetween(string $from, string $to): int
    {
        return (int)(new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->format('%r%a');
    }

    private function integer($value): int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^-?\d+$/D', $value))) {
            throw new \InvalidArgumentException('inventory_batch_analytics_value_invalid');
        }
        return (int)$value;
    }
}
