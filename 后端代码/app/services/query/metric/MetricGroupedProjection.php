<?php

namespace app\services\query\metric;

/** Deterministic shared report projection of authorized, already grouped fact totals. */
final class MetricGroupedProjection
{
    public function trend(array $points, array $range, ?string $today = null): array
    {
        // 与AI读取视图同一日期口径：保留请求范围，仅投影已授权事实，不截到接入日期。
        MetricQueryDatePolicy::assertExecutable($range,$today);
        $totals = [];
        foreach ($points as $point) $totals[$point['business_date']] = $this->add($totals[$point['business_date']] ?? 0, $point['amount_cents']);
        $out = [];
        $day = new \DateTimeImmutable($range['start'], new \DateTimeZone('Asia/Shanghai'));
        for ($count = 0; $day->format('Y-m-d') <= $range['end']; ++$count, $day = $day->modify('+1 day')) {
            if ($count >= MetricQueryDatePolicy::MAX_DAYS) $this->fail();
            $date = $day->format('Y-m-d');
            $out[] = ['business_date' => $date, 'amount_cents' => $totals[$date] ?? 0];
        }
        return $out;
    }

    public function ranking(array $points, array $stores, array $ranking): array
    {
        // No facts means no ranking, not a fabricated zero-valued winner.
        // When facts exist, keep the existing authorized population and zero
        // totals (including real records that cancel out) unchanged.
        $totals = $points===[] ? [] : array_fill_keys($stores, 0);
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

    /**
     * Ranks the already-authorized daily projection without introducing a
     * second metric formula. Ties remain visible and the stable date order
     * makes repeated reads deterministic.
     */
    public function temporalRanking(array $points,array $range,array $ranking,?string $today=null): array
    {
        $rows=$this->trend($points,$range,$today);
        // Trend's zero-filled calendar is useful for display, but cannot create
        // a highest/lowest date when the requested interval contains no facts.
        if ($points===[]) $rows=[];
        $out=[];
        foreach (['top','bottom'] as $direction) {
            if (($ranking['direction']??null)!=='top_and_bottom' && ($ranking['direction']??null)!==$direction) continue;
            $sorted=$rows;
            usort($sorted,static function(array $a,array $b)use($direction):int {
                if ($a['amount_cents']===$b['amount_cents']) return strcmp($a['business_date'],$b['business_date']);
                return $direction==='top' ? $b['amount_cents']<=>$a['amount_cents'] : $a['amount_cents']<=>$b['amount_cents'];
            });
            $limit=$ranking['limit'];
            $selected=array_slice($sorted,0,$limit);
            // “最高/最低是哪天”不能在并列时只返回第一天。这里仅扩展
            // 截止值相同的已授权日聚合行，不改变指标、范围或排序方向。
            if ($selected!==[]) {
                $cutoff=$selected[count($selected)-1]['amount_cents'];
                for ($index=$limit,$count=count($sorted);$index<$count;++$index) {
                    if ($sorted[$index]['amount_cents']!==$cutoff) break;
                    $selected[]=$sorted[$index];
                }
            }
            $out[$direction]=$selected;
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
