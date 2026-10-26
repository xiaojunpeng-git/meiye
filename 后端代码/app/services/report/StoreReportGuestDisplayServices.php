<?php

declare(strict_types=1);

namespace app\services\report;

/**
 * 统一报表的顾客姓名展示投影。游客以事实中的 member_id=0 判定；
 * 只改已授权明细行的展示值，不改事实、汇总、计数键或下钻身份。
 */
final class StoreReportGuestDisplayServices
{
    private const NAME_KEYS = [
        'member_name', 'member_name_snapshot', 'customer', 'customer_name', 'customer_name_snapshot',
    ];

    /** 查询与导出共用的结果投影；没有明确会员身份的旧行保持原值。 */
    public static function project(array $result): array
    {
        if (!isset($result['columns'], $result['records'])
            || !is_array($result['columns']) || !is_array($result['records'])) return $result;

        $nameKeys = [];
        foreach ($result['columns'] as $column) {
            $key = is_array($column) ? (string)($column['key'] ?? '') : '';
            if (in_array($key, self::NAME_KEYS, true)) $nameKeys[$key] = true;
        }
        if ($nameKeys === []) return $result;

        foreach ($result['records'] as &$row) {
            if (!is_array($row) || !self::isGuest($row)) continue;
            foreach ($nameKeys as $key => $_) {
                if (array_key_exists($key, $row)) $row[$key] = '游客';
            }
        }
        unset($row);
        return $result;
    }

    /** 明细姓名筛选使用同一展示规则，避免“游客”在页面可见但搜不到。 */
    public static function memberName(array $row): string
    {
        return self::isGuest($row) ? '游客' : (string)($row['member_name'] ?? '');
    }

    private static function isGuest(array $row): bool
    {
        // 缺失、NULL 和空字符串不能被强转成 0；它们不是可证明的游客。
        return array_key_exists('member_id', $row)
            && ($row['member_id'] === 0 || $row['member_id'] === '0');
    }
}
