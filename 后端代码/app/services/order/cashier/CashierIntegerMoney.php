<?php
namespace app\services\order\cashier;

use think\exception\ValidateException;

/**
 * 收银新单金额整数约束（展示/校验/落库）
 *
 * 规则：
 * 1. 支付/应付/行实付等为「非负整数」；改价一口价/行价同样非负（减价输入也是非负扣减额）。
 * 2. 落库以订单总额优先取整，行金额用「最大余数法」非负分摊，保证合计=总额且每行>=0。
 * 3. 行金额 pay_price 为权威落库；单价 truePrice：
 *    - 数量=1：truePrice = pay_price；
 *    - 数量>1：truePrice = floor(pay_price / 数量)（向下取整），余数留在行金额，
 *      不要求 单价×数量 = 行金额，且禁止四舍五入抬高单价导致 单价×数量 > 行金额。
 */
class CashierIntegerMoney
{
    /** 是否为非负整数额（允许空；允许 12 / 12.0 / 12.00） */
    public static function isNonNegativeIntegerAmount($value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }
        if (!is_numeric($value)) {
            return false;
        }
        $s = trim((string)$value);
        if ($s === '' || $s[0] === '-') {
            return false;
        }
        return preg_match('/^\d+(\.0+)?$/', $s) === 1;
    }

    /** @deprecated 兼容旧名：现为非负整数 */
    public static function isIntegerAmount($value): bool
    {
        return self::isNonNegativeIntegerAmount($value);
    }

    public static function assertNonNegativeIntegerAmount($value, string $label = '金额'): void
    {
        if (!self::isNonNegativeIntegerAmount($value)) {
            throw new ValidateException($label . '须为非负整数');
        }
    }

    /** @deprecated 兼容旧名 */
    public static function assertIntegerAmount($value, string $label = '金额'): void
    {
        self::assertNonNegativeIntegerAmount($value, $label);
    }

    /**
     * 转为非负整数字符串（四舍五入后若为负则置 0）
     */
    public static function toIntegerString($value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }
        $n = (int)round((float)$value);
        return (string)max(0, $n);
    }

    /**
     * 校验收银请求：改价/行价/余额/卡升级/欠款/组合分项均为非负整数
     */
    public static function assertRequestAmounts(array $payload): void
    {
        if (array_key_exists('change_price', $payload)) {
            // 整单改价金额（一口价结果或减价扣减额）均为非负
            self::assertNonNegativeIntegerAmount($payload['change_price'], '改价金额');
        }
        $cartInfo = $payload['cart_info'] ?? $payload['changeCartInfo'] ?? [];
        if (is_array($cartInfo)) {
            foreach ($cartInfo as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if (array_key_exists('true_price', $row)) {
                    self::assertNonNegativeIntegerAmount($row['true_price'], '商品价格');
                }
                if (array_key_exists('price', $row)) {
                    self::assertNonNegativeIntegerAmount($row['price'], '商品价格');
                }
                foreach (['yue_pay_amount', 'card_upgrade_amount', 'debt_pay_amount'] as $field) {
                    if (!array_key_exists($field, $row)) {
                        continue;
                    }
                    $v = $row[$field];
                    if ($v === '' || $v === null || (float)$v == 0.0) {
                        continue;
                    }
                    self::assertNonNegativeIntegerAmount($v, '支付金额');
                }
            }
        }
        $combination = $payload['combination_info'] ?? $payload['combinationInfo'] ?? [];
        if (is_array($combination)) {
            foreach ($combination as $k => $v) {
                if (is_array($v)) {
                    foreach ($v as $kk => $vv) {
                        if (is_numeric($vv) && (float)$vv != 0.0) {
                            self::assertNonNegativeIntegerAmount($vv, '组合支付金额');
                        }
                    }
                } elseif (is_numeric($v) && (float)$v != 0.0) {
                    self::assertNonNegativeIntegerAmount($v, '组合支付金额');
                }
            }
        }
    }

    /**
     * 校验结算结果：非负整数，行合计=订单总额，且无负行金额
     */
    public static function assertComputeData(array $computeData): void
    {
        foreach (['payPrice', 'totalPrice', 'sumPrice', 'couponPrice', 'deductionPrice', 'promotionsPrice'] as $key) {
            if (!array_key_exists($key, $computeData)) {
                continue;
            }
            self::assertNonNegativeIntegerAmount($computeData[$key], '订单金额');
        }
        $cartInfo = $computeData['cartInfo'] ?? [];
        if (!is_array($cartInfo)) {
            return;
        }
        foreach ($cartInfo as $cart) {
            if (!is_array($cart)) {
                continue;
            }
            foreach (['pay_price', 'truePrice', 'total_price', 'sum_price', 'price'] as $field) {
                if (!array_key_exists($field, $cart)) {
                    continue;
                }
                self::assertNonNegativeIntegerAmount($cart[$field], '商品金额');
            }
        }
        self::assertLineSumMatchesTotal($cartInfo, 'pay_price', $computeData['payPrice'] ?? null, '应付金额');
        self::assertLineSumMatchesTotal($cartInfo, 'total_price', $computeData['totalPrice'] ?? null, '订单金额');
        self::assertLineSumMatchesTotal($cartInfo, 'sum_price', $computeData['sumPrice'] ?? null, '订单金额');
    }

    /**
     * 落库前整数化：订单总额优先；行金额最大余数法非负分摊；
     * 再按权威行金额推导单价（qty=1 相等；qty>1 向下取整）。
     */
    public static function normalizeComputeData(array $computeData): array
    {
        foreach (['payPrice', 'totalPrice', 'sumPrice', 'couponPrice', 'deductionPrice', 'promotionsPrice'] as $key) {
            if (array_key_exists($key, $computeData)) {
                $computeData[$key] = self::toIntegerString($computeData[$key]);
            }
        }

        if (empty($computeData['cartInfo']) || !is_array($computeData['cartInfo'])) {
            return $computeData;
        }

        // 标价/改价字段先非负取整（与行实付分摊无关）
        foreach ($computeData['cartInfo'] as $i => $cart) {
            if (!is_array($cart)) {
                continue;
            }
            foreach (['price', 'change_price'] as $field) {
                if (array_key_exists($field, $cart)) {
                    $computeData['cartInfo'][$i][$field] = self::toIntegerString($cart[$field]);
                }
            }
        }

        if (array_key_exists('payPrice', $computeData)) {
            self::allocateLineFieldToTotal($computeData['cartInfo'], 'pay_price', $computeData['payPrice']);
        } else {
            self::roundLineField($computeData['cartInfo'], 'pay_price');
        }
        if (array_key_exists('totalPrice', $computeData)) {
            self::allocateLineFieldToTotal($computeData['cartInfo'], 'total_price', $computeData['totalPrice']);
        } else {
            self::roundLineField($computeData['cartInfo'], 'total_price');
        }
        if (array_key_exists('sumPrice', $computeData)) {
            self::allocateLineFieldToTotal($computeData['cartInfo'], 'sum_price', $computeData['sumPrice']);
        } else {
            self::roundLineField($computeData['cartInfo'], 'sum_price');
        }

        // 单价随权威行金额推导：禁止 round(pay/qty) 抬高单价
        foreach ($computeData['cartInfo'] as $i => $cart) {
            if (!is_array($cart) || !array_key_exists('pay_price', $cart)) {
                continue;
            }
            if (!array_key_exists('truePrice', $cart)) {
                continue;
            }
            $qty = max((int)($cart['cart_num'] ?? 1), 1);
            $line = (int)$cart['pay_price'];
            if ($qty === 1) {
                $computeData['cartInfo'][$i]['truePrice'] = (string)$line;
            } else {
                $computeData['cartInfo'][$i]['truePrice'] = (string)(int)floor($line / $qty);
            }
        }

        return $computeData;
    }

    /**
     * 最大余数法（Hamilton）：先按比例取 floor，剩余单位分给余数最大的行。
     * 保证：每行 >= 0，合计 = target。
     */
    public static function allocateLineFieldToTotal(array &$cartInfo, string $field, $total): void
    {
        $indices = [];
        $raw = [];
        foreach ($cartInfo as $i => $cart) {
            if (!is_array($cart) || !array_key_exists($field, $cart)) {
                continue;
            }
            $indices[] = $i;
            $raw[] = max(0.0, (float)$cart[$field]);
        }
        if ($indices === []) {
            return;
        }

        $target = (int)self::toIntegerString($total);
        $n = count($indices);
        if ($target <= 0) {
            foreach ($indices as $i) {
                $cartInfo[$i][$field] = '0';
            }
            return;
        }

        $rawSum = array_sum($raw);
        if ($rawSum <= 0) {
            // 原行均为 0：把总额全部落在最后一行，其余 0
            foreach ($indices as $j => $i) {
                $cartInfo[$i][$field] = ($j === $n - 1) ? (string)$target : '0';
            }
            return;
        }

        $floors = [];
        $fracs = [];
        $allocated = 0;
        for ($j = 0; $j < $n; $j++) {
            $exact = $target * ($raw[$j] / $rawSum);
            $floor = (int)floor($exact + 1e-12);
            $floors[$j] = $floor;
            $fracs[$j] = $exact - $floor;
            $allocated += $floor;
        }
        $remain = $target - $allocated;
        if ($remain < 0) {
            // 理论不应发生；兜底从最大行扣回
            while ($remain < 0) {
                $maxJ = 0;
                for ($j = 1; $j < $n; $j++) {
                    if ($floors[$j] > $floors[$maxJ]) {
                        $maxJ = $j;
                    }
                }
                if ($floors[$maxJ] <= 0) {
                    break;
                }
                $floors[$maxJ]--;
                $remain++;
            }
        }
        // 余数优先分给小数部分更大的行；同分按原序
        $order = range(0, $n - 1);
        usort($order, function ($a, $b) use ($fracs) {
            if ($fracs[$a] == $fracs[$b]) {
                return $a <=> $b;
            }
            return ($fracs[$b] <=> $fracs[$a]);
        });
        for ($k = 0; $k < $remain; $k++) {
            $floors[$order[$k % $n]]++;
        }

        foreach ($indices as $j => $i) {
            $cartInfo[$i][$field] = (string)max(0, (int)$floors[$j]);
        }

        // 最终合计校正（浮点边界）：差额补/扣到最后一行，且不把最后一行扣成负
        $sum = 0;
        foreach ($indices as $i) {
            $sum += (int)$cartInfo[$i][$field];
        }
        $diff = $target - $sum;
        if ($diff !== 0) {
            $last = $indices[$n - 1];
            $lastVal = (int)$cartInfo[$last][$field] + $diff;
            if ($lastVal < 0) {
                // 从前往后借，保证非负
                $need = -$lastVal;
                $cartInfo[$last][$field] = '0';
                for ($j = 0; $j < $n - 1 && $need > 0; $j++) {
                    $idx = $indices[$j];
                    $v = (int)$cartInfo[$idx][$field];
                    $take = min($v, $need);
                    $cartInfo[$idx][$field] = (string)($v - $take);
                    $need -= $take;
                }
                if ($need > 0) {
                    throw new ValidateException('订单金额无法按非负整数分摊到明细');
                }
            } else {
                $cartInfo[$last][$field] = (string)$lastVal;
            }
        }
    }

    protected static function roundLineField(array &$cartInfo, string $field): void
    {
        foreach ($cartInfo as $i => $cart) {
            if (!is_array($cart) || !array_key_exists($field, $cart)) {
                continue;
            }
            $cartInfo[$i][$field] = self::toIntegerString($cart[$field]);
        }
    }

    protected static function assertLineSumMatchesTotal(array $cartInfo, string $field, $total, string $label): void
    {
        if ($total === null || $total === '') {
            return;
        }
        $hasField = false;
        $sum = 0;
        foreach ($cartInfo as $cart) {
            if (!is_array($cart) || !array_key_exists($field, $cart)) {
                continue;
            }
            $hasField = true;
            $v = (int)self::toIntegerString($cart[$field]);
            if ($v < 0) {
                throw new ValidateException($label . '明细不能为负数');
            }
            $sum += $v;
        }
        if (!$hasField) {
            return;
        }
        $target = (int)self::toIntegerString($total);
        if ($sum !== $target) {
            throw new ValidateException($label . '与明细合计不一致');
        }
    }
}
