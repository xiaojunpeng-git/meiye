<?php

declare(strict_types=1);

namespace app\services\report;

/**
 * 将已按权限和业务口径读取的门店、支付类型金额投影为对账矩阵。
 * 本类不读取或推断事实、退款与刷单规则；金额始终以整数分传入和汇总。
 */
final class StoreReconciliationMatrix
{
    /**
     * 007 单店模式按自然月完整展开日期列，零交易日同样保留。
     * 这里只生成日历身份；金额仍统一由build汇总，不维护第二套逐日算法。
     */
    public static function days(string $month): array
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month)) {
            throw new \InvalidArgumentException('月份无效');
        }
        $first = \DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01', new \DateTimeZone('Asia/Shanghai'));
        if (!$first || $first->format('Y-m') !== $month) throw new \InvalidArgumentException('月份无效');
        $days = (int) $first->format('t');
        $axes = [];
        for ($day = 1; $day <= $days; $day++) {
            $axes[] = ['id' => $day, 'name' => $first->setDate((int)$first->format('Y'), (int)$first->format('m'), $day)->format('Y-m-d')];
        }
        return $axes;
    }

    /**
     * @param array $stores 已授权门店，元素包含 id、name，按期望显示顺序排列
     * @param array $methods 类型目录，元素包含 code、name，按期望显示顺序排列
     * @param array $facts 已过滤的金额事实，每行包含 store_id、method_code、amount_cents
     * @return array 门店列与类型行；每格与行合计均为整数分
     */
    public static function build(array $stores, array $methods, array $facts): array
    {
        $storeMap = [];
        foreach ($stores as $store) {
            $id = (int) ($store['id'] ?? 0);
            $name = trim((string) ($store['name'] ?? ''));
            if ($id <= 0 || $name === '' || isset($storeMap[$id])) {
                throw new \InvalidArgumentException('授权门店目录无效或重复');
            }
            $storeMap[$id] = $name;
        }

        $rows = [];
        foreach ($methods as $method) {
            $code = trim((string) ($method['code'] ?? ''));
            $name = trim((string) ($method['name'] ?? ''));
            if ($code === '' || $name === '' || isset($rows[$code])) {
                throw new \InvalidArgumentException('支付方式目录无效或重复');
            }
            $rows[$code] = ['code' => $code, 'name' => $name, 'total_cents' => 0,
                'stores' => array_fill_keys(array_keys($storeMap), 0)];
        }

        foreach ($facts as $fact) {
            $store = (int) ($fact['store_id'] ?? 0);
            $code = (string) ($fact['method_code'] ?? '');
            $amount = $fact['amount_cents'] ?? null;
            // 任何越权或未识别类型均拒绝，不能静默丢账或扩充表头。
            if (!isset($storeMap[$store]) || !isset($rows[$code]) || !is_int($amount)) {
                throw new \InvalidArgumentException('对账事实门店、支付方式或金额无效');
            }
            $rows[$code]['stores'][$store] = self::add($rows[$code]['stores'][$store], $amount);
            $rows[$code]['total_cents'] = self::add($rows[$code]['total_cents'], $amount);
        }

        return ['stores' => $stores, 'rows' => array_values($rows)];
    }

    /** 防止整数溢出转换为浮点数，破坏金额精度。 */
    private static function add(int $left, int $right): int
    {
        if (($right > 0 && $left > PHP_INT_MAX - $right)
            || ($right < 0 && $left < PHP_INT_MIN - $right)) {
            throw new \OverflowException('对账金额超出支持范围');
        }
        return $left + $right;
    }
}
