<?php

declare(strict_types=1);

namespace app\services\report;

/**
 * 仅裁剪报表的展示列契约，不修改事实行、指标计算或下钻上下文。
 * 必须逐行判断完整已授权结果，不能用可能正负抵消的合计值代替。
 */
final class StoreReportZeroColumnVisibilityServices
{
    public static function hideAllZeroColumns(array $result, array $candidateKeys): array
    {
        $records = $result['records'] ?? [];
        if ($records === [] || $candidateKeys === []) {
            return $result;
        }

        $candidates = array_fill_keys($candidateKeys, true);
        $hidden = [];
        foreach ($result['columns'] ?? [] as $column) {
            $key = (string)($column['key'] ?? '');
            if ($key === '' || !isset($candidates[$key])) {
                continue;
            }
            $allZero = true;
            foreach ($records as $record) {
                // Missing, blank and dash values are not numeric zero.
                if (!array_key_exists($key, $record) || !self::isDisplayedZero($record[$key])) {
                    $allZero = false;
                    break;
                }
            }
            if ($allZero) {
                $hidden[$key] = true;
            }
        }
        if ($hidden === []) {
            return $result;
        }

        $result['columns'] = array_values(array_filter(
            $result['columns'],
            static fn(array $column): bool => !isset($hidden[(string)($column['key'] ?? '')])
        ));
        if (isset($result['column_groups'])) {
            $visibleKeys = array_fill_keys(array_column($result['columns'], 'key'), true);
            $groups = [];
            foreach ($result['column_groups'] as $group) {
                $keys = array_values(array_filter(
                    $group['column_keys'] ?? [],
                    static fn($key): bool => isset($visibleKeys[(string)$key])
                ));
                if ($keys === []) {
                    continue;
                }
                $group['column_keys'] = $keys;
                $groups[] = $group;
            }
            $result['column_groups'] = $groups;
        }
        if (isset($result['summary_row'])) {
            $result['summary_row'] = array_diff_key($result['summary_row'], $hidden);
        }
        return $result;
    }

    private static function isDisplayedZero($value): bool
    {
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            return false;
        }
        return preg_match('/^[+-]?0+(?:\.0+)?$/D', trim((string)$value)) === 1;
    }
}
