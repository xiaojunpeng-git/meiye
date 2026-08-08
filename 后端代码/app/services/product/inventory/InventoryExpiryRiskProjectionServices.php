<?php
declare(strict_types=1);

namespace app\services\product\inventory;

/** Projects expiry risk buckets from already scoped, settled batch rows. */
final class InventoryExpiryRiskProjectionServices
{
    private const LABELS = [
        'OVERDUE' => '已过期',
        'WITHIN_30' => '30天内临期',
        'DAYS_31_60' => '31至60天临期',
        'DAYS_61_90' => '61至90天临期',
        'SAFE_OVER_90' => '90天以上',
        'UNKNOWN' => '未设置到期日',
    ];

    /**
     * @param array<int,array<string,mixed>> $rows Already scoped settled batch rows.
     * @return array{expiry_risk_buckets:array<int,array{code:string,label:string,count:int}>,expiring_90_batch_count:int}
     */
    public function project(array $rows, string $cutoffDate): array
    {
        $cutoff = $this->strictDate($cutoffDate);
        $counts = array_fill_keys(array_keys(self::LABELS), 0);

        foreach ($rows as $row) {
            $expire = $this->optionalDate((string)($row['expire_date'] ?? ''));
            if ($expire === null) {
                $counts['UNKNOWN']++;
                continue;
            }
            $days = (int)$cutoff->diff($expire)->format('%r%a');
            if ($days < 0) $counts['OVERDUE']++;
            elseif ($days <= 30) $counts['WITHIN_30']++;
            elseif ($days <= 60) $counts['DAYS_31_60']++;
            elseif ($days <= 90) $counts['DAYS_61_90']++;
            else $counts['SAFE_OVER_90']++;
        }

        $buckets = [];
        foreach (self::LABELS as $code => $label) {
            $buckets[] = ['code' => $code, 'label' => $label, 'count' => (int)$counts[$code]];
        }

        return [
            'expiry_risk_buckets' => $buckets,
            'expiring_90_batch_count' => $counts['WITHIN_30'] + $counts['DAYS_31_60'] + $counts['DAYS_61_90'],
        ];
    }

    private function strictDate(string $value): \DateTimeImmutable
    {
        $date = $this->optionalDate($value);
        if ($date === null) throw new \InvalidArgumentException('inventory_expiry_cutoff_date_invalid');
        return $date;
    }

    private function optionalDate(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') return null;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $value) {
            return null;
        }
        return $date;
    }
}
