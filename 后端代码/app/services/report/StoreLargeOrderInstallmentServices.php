<?php

namespace app\services\report;

use think\facade\Db;

/** R52 monthly accrual projection, not a payment ledger. No business facts or payouts are written. */
final class StoreLargeOrderInstallmentServices
{
    /** Legacy day links select that day's whole month; explicit month wins. */
    public static function monthRange(array $input, array $fallback = []): array
    {
        $month = trim((string)($input['month'] ?? ''));
        if ($month === '') $month = substr((string)($input['start_date'] ?? $fallback['start'] ?? ''), 0, 7);
        if ($month === '') $month = date('Y-m');
        if (!preg_match('/^[1-9][0-9]{3}-(0[1-9]|1[0-2])$/D', $month)) {
            throw new \InvalidArgumentException('请选择有效的统计月份');
        }
        $first = new \DateTimeImmutable($month . '-01', new \DateTimeZone('Asia/Shanghai'));
        return ['start' => $first->format('Y-m-d'), 'end' => $first->format('Y-m-t')];
    }

    /** Current platform switches choose eligibility only; never reapply partner profit ratios to cash. */
    public function categories(array $components): array
    {
        if (!$components) return [];
        $links = [];
        foreach ($components as $i => $row) $links[$i] = ['store_id' => (int)$row['store_id'], 'product_id' => (int)$row['component_product_id']];
        $resolved = (new StoreReportCurrentProductCategoryServices())->resolve($links);
        $categories = [];
        foreach (Db::name('store_product_category')->where('type', 0)->where('relation_id', 0)
            ->field('id,pid,cate_name')->select()->toArray() as $row) $categories[(int)$row['id']] = $row;
        $enabled = [];
        foreach (Db::name('cashier_v3_report_category_config')->whereIn('tenant_id', array_values(array_unique(array_column($components, 'tenant_id'))))
            ->where('enabled', 1)->field('tenant_id,category_id')->select()->toArray() as $row) {
            $enabled[(string)$row['tenant_id']][(int)$row['category_id']] = true;
        }
        $result = [];
        foreach ($resolved as $i => $category) {
            $result[$i] = self::classify((int)$category['id'], $categories, $enabled[(string)$components[$i]['tenant_id']] ?? []);
        }
        return $result;
    }

    /** Beauty wins overlaps. Partner first/second-level inheritance matches existing report configuration. */
    public static function classify(int $id, array $categories, array $enabled): array
    {
        $chain = []; $seen = [];
        while ($id > 0 && isset($categories[$id]) && !isset($seen[$id]) && count($chain) < 12) {
            $seen[$id] = true;
            array_unshift($chain, $categories[$id]);
            $id = (int)$categories[$id]['pid'];
        }
        if ($id !== 0 || !$chain) return [];
        $root = $chain[0]; $second = $chain[1] ?? null;
        if (trim((string)$root['cate_name']) === '生美') {
            if (!$second) return [];
            return ['rule_type' => 'beauty', 'classification' => '生美', 'award_category_id' => (int)$second['id'], 'beauty_category' => (string)$second['cate_name']];
        }
        $effective = $second && isset($enabled[(int)$second['id']]) ? $second : null;
        if (!$effective && isset($enabled[(int)$root['id']])) {
            $hasEnabledChild = false;
            foreach ($categories as $candidate) {
                if ((int)$candidate['pid'] === (int)$root['id'] && isset($enabled[(int)$candidate['id']])) $hasEnabledChild = true;
            }
            if (!$hasEnabledChild) $effective = $root;
        }
        if (!$effective) return [];
        return ['rule_type' => 'partner', 'classification' => '合作方', 'award_category_id' => (int)$effective['id'],
            'beauty_category' => (string)$root['cate_name'] . ((int)$effective['id'] === (int)$root['id'] ? '' : '/' . (string)$effective['cate_name'])];
    }

    /** Actual qualifying amount is not capped. All truncation remainders go into the last month. */
    public static function schedule(int $amount, string $rule): array
    {
        $minimum = $rule === 'partner' ? 5000000 : 3000000;
        $high = $rule === 'partner' ? 10000000 : 5000000;
        if ($amount < $minimum) return [];
        $rates = $amount >= $high ? [40, 12, 12, 12, 12, 12] : [40, 30, 30];
        $values = []; $allocated = 0;
        foreach ($rates as $i => $rate) {
            $value = $i === count($rates) - 1 ? $amount - $allocated : (int)bcdiv(bcmul((string)$amount, (string)$rate, 0), '100', 0);
            $values[] = $value; $allocated += $value;
        }
        return $values;
    }

    /** One selected month's accrual per qualifying origin month; historical principals are not current cash. */
    public static function dueRows(array $facts, array $awards, string $month): array
    {
        $target = (int)substr($month, 0, 4) * 12 + (int)substr($month, 5, 2);
        $rows = [];
        foreach ($awards as $index => $award) {
            $row = $facts[$index];
            $origin = substr((string)$row['business_date'], 0, 7);
            $offset = $target - ((int)substr($origin, 0, 4) * 12 + (int)substr($origin, 5, 2));
            $schedule = self::schedule((int)$award['amount_cents'], (string)($row['rule_type'] ?? 'beauty'));
            if ($offset < 0 || !isset($schedule[$offset])) continue;
            $row['award_month'] = $origin;
            $row['accrual_month'] = $month;
            $row['installment_number'] = $offset + 1;
            $row['installment_count'] = count($schedule);
            $row['award_amount_cents'] = (int)$award['amount_cents'];
            $row['due_amount_cents'] = $schedule[$offset];
            $row['threshold_cents'] = (int)$award['threshold_cents'];
            // 会员加入应计行身份：同一人员、月份、分类下两名会员不能共用行键。
            $row['row_key'] = hash('sha256', json_encode([$row['tenant_id'], $row['role_snapshot'], $row['employee_id'],
                (int)($row['member_id'] ?? 0), $origin,
                $row['rule_type'] ?? 'beauty', $row['award_category_id'] ?? $row['beauty_category'], $month]));
            $rows[] = $row;
        }
        usort($rows, static function (array $a, array $b): int {
            return strcmp($b['award_month'], $a['award_month']) ?: strcmp($a['row_key'], $b['row_key']);
        });
        return $rows;
    }
}
