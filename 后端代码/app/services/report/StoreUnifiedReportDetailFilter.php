<?php

declare(strict_types=1);

namespace app\services\report;

/**
 * Narrows an already-authorized report detail cohort before its totals and page are calculated.
 * Display names and document numbers are lookup text, never permission or cohort selectors.
 */
final class StoreUnifiedReportDetailFilter
{
    public static function apply(array $rows, array $input, ?callable $orderNumber = null): array
    {
        $member = self::term($input['detail_member'] ?? '', '会员');
        $order = self::term($input['detail_order_no'] ?? '', '单号');
        if ($member === '' && $order === '') return $rows;

        return array_values(array_filter($rows, static function (array $row) use ($member, $order, $orderNumber): bool {
            $name = (string)($row['member_name'] ?? '');
            $number = $orderNumber ? (string)$orderNumber($row) : (string)($row['order_no'] ?? '');
            return ($member === '' || stripos($name, $member) !== false)
                && ($order === '' || stripos($number, $order) !== false);
        }));
    }

    private static function term($value, string $label): string
    {
        if (!is_scalar($value)) throw new \InvalidArgumentException($label . '筛选条件无效');
        $term = trim((string)$value);
        if (strlen($term) > 120 || preg_match('/[\r\n\x00-\x1F]/', $term)) {
            throw new \InvalidArgumentException($label . '筛选条件过长或含无效字符');
        }
        return $term;
    }
}
