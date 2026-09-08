<?php

namespace app\services\query\metric;

/** Deterministic shared report projection of authorized, already grouped fact totals. */
final class MetricGroupedProjection
{
    public function trend(array $points, array $range): array
    {
        $totals = [];
        foreach ($points as $point) $totals[$point['business_date']] = $this->add($totals[$point['business_date']] ?? 0, $point['amount_cents']);
        $out = [];
        $day = new \DateTimeImmutable($range['start'], new \DateTimeZone('Asia/Shanghai'));
        for ($count = 0; $day->format('Y-m-d') <= $range['end']; ++$count, $day = $day->modify('+1 day')) {
            if ($count > 366) $this->fail();
            $date = $day->format('Y-m-d');
            $out[] = ['business_date' => $date, 'amount_cents' => $totals[$date] ?? 0];
        }
        return $out;
    }

    public function ranking(array $points, array $stores, array $ranking): array
    {
        $totals = array_fill_keys($stores, 0);
        foreach ($points as $point) {
            if (!array_key_exists($point['store_id'], $totals)) $this->fail();
            $totals[$point['store_id']] = $this->add($totals[$point['store_id']], $point['amount_cents']);
        }
        $rows = [];
        foreach ($totals as $store => $value) $rows[] = ['store_id' => $store, 'amount_cents' => $value];
        $out = [];
        foreach (['top', 'bottom'] as $direction) {
            if ($ranking['direction'] !== 'top_and_bottom' && $ranking['direction'] !== $direction) continue;
            $sorted = $rows;
            usort($sorted, static function (array $a, array $b) use ($direction): int {
                if ($a['amount_cents'] === $b['amount_cents']) return $a['store_id'] <=> $b['store_id'];
                return $direction === 'top' ? $b['amount_cents'] <=> $a['amount_cents'] : $a['amount_cents'] <=> $b['amount_cents'];
            });
            $out[$direction] = array_slice($sorted, 0, $ranking['limit']);
        }
        return $out;
    }

    private function add($a, $b): int
    {
        if (!is_int($a) || !is_int($b) || ($b > 0 && $a > PHP_INT_MAX - $b) || ($b < 0 && $a < PHP_INT_MIN - $b)) $this->fail();
        return $a + $b;
    }

    private function fail(): void { throw new MetricQueryContractException('METRIC_PROJECTION_INVALID', '当前结果无法完整生成，请缩小范围后重试。'); }
}
