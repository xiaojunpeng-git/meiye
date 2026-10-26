<?php

namespace app\services\report;

/**
 * Filters already-calculated report rows by server-declared visible columns.
 * It never changes fact selection or a report's metric calculation.
 */
final class StoreReportResultRowFilterServices
{
    private const MAX_CONDITIONS = 16;
    private const MAX_PAYLOAD_BYTES = 8192;
    private const MAX_TEXT_LENGTH = 100;

    /**
     * Only a report's declared, static identity columns may narrow calculated rows.
     * Dynamic categories, amounts, counts and editable values never become query predicates.
     */
    public static function declareFixedDimensions(array $columns): array
    {
        $keys = [];
        foreach ($columns as $column) {
            if (($column['fixed'] ?? '') === 'left') $keys[] = (string)($column['key'] ?? '');
        }
        return self::declareDimensions($columns, $keys);
    }

    /** The report owner explicitly lists static dimensions that are not sticky columns. */
    public static function declareDimensions(array $columns, array $keys): array
    {
        $allowed = array_fill_keys($keys, true);
        foreach ($columns as &$column) {
            $key = (string)($column['key'] ?? '');
            if (!isset($allowed[$key]) || $key === '' || in_array($key, ['year', 'serial_no', 'sequence'], true)
                || !empty($column['summable']) || !empty($column['manual_input'])
                || in_array((string)($column['value_type'] ?? ''), ['integer', 'money', 'decimal'], true)) {
                continue;
            }
            if (!empty($column['filter'])) continue;
            $column['filter'] = in_array($key, ['month', 'award_month', 'accrual_month'], true)
                ? ['type' => 'month', 'operators' => ['eq']]
                : ['type' => 'text', 'operators' => ['contains']];
        }
        unset($column);
        return $columns;
    }

    /** @return array<int,array<string,mixed>> */
    public static function filter(array $rows, array $columns, string $payload): array
    {
        if ($payload === '') return $rows;
        if (strlen($payload) > self::MAX_PAYLOAD_BYTES) {
            throw new \InvalidArgumentException('筛选条件过长');
        }
        $conditions = json_decode($payload, true);
        if (!is_array($conditions) || array_values($conditions) !== $conditions
            || count($conditions) > self::MAX_CONDITIONS) {
            throw new \InvalidArgumentException('筛选条件格式不正确');
        }
        $allowed = [];
        foreach ($columns as $column) {
            if (!empty($column['filter']) && !empty($column['key'])) {
                $allowed[(string)$column['key']] = $column['filter'];
            }
        }
        $compiled = [];
        foreach ($conditions as $condition) {
            if (!is_array($condition)) throw new \InvalidArgumentException('筛选条件格式不正确');
            $key = (string)($condition['key'] ?? '');
            $operator = (string)($condition['op'] ?? '');
            $definition = $allowed[$key] ?? null;
            if (!is_array($definition) || !in_array($operator, (array)($definition['operators'] ?? []), true)) {
                throw new \InvalidArgumentException('筛选字段或条件不受支持');
            }
            $type = (string)($definition['type'] ?? '');
            if (!in_array($type, ['text', 'month'], true)) {
                throw new \InvalidArgumentException('筛选字段类型不受支持');
            }
            $compiled[] = [$key, $operator, $type, self::value($condition['value'] ?? null, $type)];
        }
        if ($compiled === []) return $rows;

        // Match the same guest name shown by query/export, while returning original
        // rows so identity keys, historical snapshots and metric inputs stay intact.
        $visibleRows = StoreReportGuestDisplayServices::project([
            'columns' => array_map(static fn(array $condition): array => ['key' => $condition[0]], $compiled),
            'records' => $rows,
        ])['records'];
        return array_values(array_filter($rows, static function (array $row, $index) use ($compiled, $visibleRows): bool {
            foreach ($compiled as [$key, $operator, $type, $value]) {
                if (!self::matches($visibleRows[$index][$key] ?? null, $operator, $type, $value)) return false;
            }
            return true;
        }, ARRAY_FILTER_USE_BOTH));
    }

    /** Validate a visible fixed-dimension value, never a fact amount. */
    private static function value($raw, string $type)
    {
        if (!is_scalar($raw)) throw new \InvalidArgumentException('筛选值不正确');
        $value = trim((string)$raw);
        if ($type === 'month') {
            if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) throw new \InvalidArgumentException('请输入有效月份');
            return $value;
        }
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($value === '' || $length > self::MAX_TEXT_LENGTH) {
            throw new \InvalidArgumentException('请输入有效文字筛选值');
        }
        return $value;
    }

    private static function matches($actual, string $operator, string $type, string $value): bool
    {
        $text = trim((string)($actual ?? ''));
        if ($type === 'month') return $operator === 'eq' && $text === $value;
        if ($operator !== 'contains') return false;
        return function_exists('mb_stripos')
            ? mb_stripos($text, $value, 0, 'UTF-8') !== false
            : stripos($text, $value) !== false;
    }
}
