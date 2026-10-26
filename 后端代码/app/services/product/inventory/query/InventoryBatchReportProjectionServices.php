<?php
declare(strict_types=1);

namespace app\services\product\inventory\query;

/** Adds expiry and age reporting projections to already scoped batch rows. */
final class InventoryBatchReportProjectionServices
{
    public function project(array $rows, string $cutoffDate): array
    {
        $cutoffDate = InventoryBatchStockQueryContract::assertCutoffDate($cutoffDate);
        $expiryBuckets = [];
        $ageBuckets = [];
        $list = [];
        foreach ($rows as $row) {
            $expireDays = $this->daysUntil($cutoffDate, $row['expire_date'] ?? null);
            $ageDays = $this->daysSince($row['received_date'] ?? null, $cutoffDate);
            $projected = $row + [
                'remaining_shelf_life_days' => $expireDays,
                'remaining_shelf_life_band' => $this->expiryBand($expireDays),
                'inventory_age_days' => $ageDays,
                'inventory_age_band' => $this->ageBand($ageDays),
            ];
            $list[] = $projected;
            $this->addBucket($expiryBuckets, $projected['remaining_shelf_life_band'], $projected['inventory_amount'] ?? null);
            $this->addBucket($ageBuckets, $projected['inventory_age_band'], $projected['inventory_amount'] ?? null);
        }
        return [
            'query_cutoff_date' => $cutoffDate,
            'list' => $list,
            'count' => count($list),
            'expiry_buckets' => array_values($expiryBuckets),
            'age_buckets' => array_values($ageBuckets),
        ];
    }

    private function daysUntil(string $cutoff, $date): ?int
    {
        return $date === null || $date === '' ? null : $this->days($cutoff, (string)$date);
    }

    private function daysSince($date, string $cutoff): ?int
    {
        return $date === null || $date === '' ? null : $this->days((string)$date, $cutoff);
    }

    private function days(string $from, string $to): int
    {
        $from = InventoryBatchStockQueryContract::assertCutoffDate($from);
        $to = InventoryBatchStockQueryContract::assertCutoffDate($to);
        return (int)(new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->format('%r%a');
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

    private function addBucket(array &$buckets, string $name, $amount): void
    {
        if (!isset($buckets[$name])) {
            $buckets[$name] = ['name' => $name, 'batch_count' => 0, 'inventory_amount' => null];
        }
        $buckets[$name]['batch_count']++;
        if ($amount === null) return;
        $cents = $this->amountToCents((string)$amount);
        $existing = $buckets[$name]['inventory_amount'] === null ? 0 : $this->amountToCents($buckets[$name]['inventory_amount']);
        $buckets[$name]['inventory_amount'] = InventoryBatchStockQueryContract::centsToAmount($existing + $cents);
    }

    private function amountToCents(string $amount): int
    {
        if (!preg_match('/^\d+\.\d{2}$/D', $amount)) {
            throw new \InvalidArgumentException('inventory_report_amount_invalid');
        }
        return ((int)substr($amount, 0, -3)) * 100 + (int)substr($amount, -2);
    }
}
