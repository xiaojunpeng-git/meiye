<?php

namespace app\services\order;

use app\dao\order\StoreOrderDao;
use app\model\order\CombinationOrder;
use app\model\order\StoreOrder;
use app\model\order\StoreOrderCartInfo;
use app\model\user\UserRecharge;
use app\model\yeji\CashType;
use app\services\pay\PayServices;
use think\facade\Db;

/**
 * 现金业绩/收入统计：排除旧卡录入（cash_choose=9，含组合支付明细）
 * 核销劳动业绩（耗卡）不在此过滤范围内
 */
class ValidCashOrderServices
{
    /**
     * 订单 Query 追加有效现金范围（单笔 not_old + 组合支付含非旧卡现金明细）
     * @param mixed $query
     * @param string $alias 如 a 或 b（不含点）
     */
    public static function applyScope($query, string $alias = ''): void
    {
        $p = $alias !== '' ? rtrim($alias, '.') . '.' : '';
        $query->where(function ($orderFilter) use ($p) {
            $orderFilter->where(function ($nonComb) use ($p) {
                $nonComb->where($p . 'pay_type', '<>', PayServices::COMBINATION_PAY)
                    ->where($p . 'cash_choose', '<>', CashType::OLD_CARD_ENTRY)
                    ->where($p . 'cash_choose', '<>', CashType::DEBT_ENTRY);
            })->whereOr(function ($comb) use ($p) {
                $comb->where($p . 'pay_type', PayServices::COMBINATION_PAY)
                    ->whereIn($p . 'id', function ($sub) {
                        $sub->name('combination_order')
                            ->where('cash_choose', '<>', CashType::OLD_CARD_ENTRY)
                            ->where('cash_choose', '<>', CashType::DEBT_ENTRY)
                            ->where(function ($debtQ) {
                                $debtQ->whereNull('pay_sub_type')->whereOr('pay_sub_type', '<>', 'debt');
                            })
                            ->where('active_pay', '<>', 3)
                            ->field('order_id');
                    });
            });
        });
    }

    /**
     * 组合支付明细中旧卡录入（cash_choose=9）金额合计
     */
    public static function sumOldCardEntryInCombination(array $combinationInfo): string
    {
        $sum = '0.00';
        foreach ($combinationInfo as $row) {
            if ((int)($row['type'] ?? 0) === CashType::OLD_CARD_ENTRY) {
                $sum = bcadd($sum, (string)($row['price'] ?? 0), 2);
            }
        }
        return $sum;
    }

    /**
     * 组合支付明细中欠款金额合计（不计业绩）
     */
    public static function sumDebtEntryInCombination(array $combinationInfo): string
    {
        $sum = '0.00';
        foreach ($combinationInfo as $row) {
            if ((int)($row['type'] ?? 0) === CashType::DEBT_ENTRY
                || ($row['pay_sub_type'] ?? '') === 'debt') {
                $sum = bcadd($sum, (string)($row['price'] ?? 0), 2);
            }
        }
        return $sum;
    }

    /**
     * 组合支付明细中卡升级抵扣合计（不计现金业绩）
     */
    public static function sumCardUpgradeInCombination(array $combinationInfo): string
    {
        $sum = '0.00';
        foreach ($combinationInfo as $row) {
            if ((int)($row['activePay'] ?? 0) === 3
                && ($row['pay_sub_type'] ?? '') === 'card_upgrade') {
                $sum = bcadd($sum, (string)($row['price'] ?? 0), 2);
            }
        }
        return $sum;
    }

    /**
     * 组合支付明细中记账收款(activePay=2)合计，即实际现金业绩口径
     */
    public static function sumCashActivePayInCombination(array $combinationInfo): string
    {
        $sum = '0.00';
        foreach ($combinationInfo as $row) {
            if ((int)($row['activePay'] ?? 0) !== 2) {
                continue;
            }
            if ((int)($row['type'] ?? 0) === CashType::OLD_CARD_ENTRY) {
                continue;
            }
            $sum = bcadd($sum, (string)($row['price'] ?? 0), 2);
        }
        return $sum;
    }

    /**
     * 组合支付明细是否有效（非组合支付应忽略残留 combination_info）
     */
    public static function isCombinationPayDetail(array $combinationInfo, string $payType = ''): bool
    {
        if ($payType !== '' && $payType !== PayServices::COMBINATION_PAY) {
            return false;
        }
        foreach ($combinationInfo as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (bccomp((string)($row['price'] ?? 0), '0', 2) > 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * 订单有效现金支付金额（落库 cash_pay_price / 销售业绩分配口径）
     * 排除余额、卡升级抵扣、旧卡录入；单笔 cash_choose=9 整单为 0
     */
    public static function calcOrderCashPayPrice($payPrice, $yuePayPrice, int $cashChoose, array $combinationInfo = [], string $payType = '', $debtPayPrice = 0): float
    {
        $isCombination = self::isCombinationPayDetail($combinationInfo, $payType);
        // 组合支付：以明细中 activePay=2 的记账收款合计为准，不受订单级 cash_choose 影响
        if ($isCombination) {
            $cashFromLines = self::sumCashActivePayInCombination($combinationInfo);
            if (bccomp($cashFromLines, '0', 2) >= 0) {
                return (float)$cashFromLines;
            }
        }
        // 单笔旧数据录入：不计现金业绩，与纯余额一致
        if ((int)$cashChoose === CashType::OLD_CARD_ENTRY) {
            return 0.00;
        }
        $oldCard = '0.00';
        $debtEntry = '0.00';
        $cardUpgrade = '0.00';
        if ($isCombination) {
            $oldCard = self::sumOldCardEntryInCombination($combinationInfo);
            $debtEntry = self::sumDebtEntryInCombination($combinationInfo);
            $cardUpgrade = self::sumCardUpgradeInCombination($combinationInfo);
        }
        // 欠款只扣一次：组合明细走 debtEntry，非组合走 debtPayPrice
        $debtDeduction = $isCombination ? $debtEntry : bcadd((string)$debtPayPrice, '0', 2);
        $cash = bcsub(
            bcsub(
                bcsub(
                    bcsub(bcadd((string)$payPrice, '0', 2), bcadd((string)$yuePayPrice, '0', 2), 2),
                    $cardUpgrade,
                    2
                ),
                $debtDeduction,
                2
            ),
            $oldCard,
            2
        );
        if (bccomp($cash, '0', 2) < 0) {
            return 0.00;
        }
        return (float)$cash;
    }

    /**
     * 按订单有效现金占比缩放购物车行 cash_pay_amount
     */
    public static function scaleCartCashPayAmounts(array &$cartInfo, $effectiveCashPayPrice): void
    {
        $grossCash = '0.00';
        $keys = [];
        foreach ($cartInfo as $idx => $cart) {
            if (isset($cart['cart_type']) && (int)$cart['cart_type'] > 0) {
                continue;
            }
            $keys[] = $idx;
            $grossCash = bcadd($grossCash, bcadd((string)($cart['cash_pay_amount'] ?? 0), '0', 2), 2);
        }
        $effectiveCash = bcadd((string)$effectiveCashPayPrice, '0', 2);
        if (bccomp($grossCash, '0', 2) <= 0) {
            return;
        }
        if (bccomp($effectiveCash, '0', 2) <= 0) {
            foreach ($keys as $idx) {
                $cartInfo[$idx]['cash_pay_amount'] = 0.00;
            }
            return;
        }
        if (bccomp($effectiveCash, $grossCash, 2) === 0) {
            return;
        }
        $ratio = bcdiv($effectiveCash, $grossCash, 6);
        $scaledSum = '0.00';
        $lastIdx = null;
        foreach ($keys as $idx) {
            $lastIdx = $idx;
            $scaled = bcmul(bcadd((string)($cartInfo[$idx]['cash_pay_amount'] ?? 0), '0', 2), $ratio, 2);
            $cartInfo[$idx]['cash_pay_amount'] = (float)$scaled;
            $scaledSum = bcadd($scaledSum, $scaled, 2);
        }
        if ($lastIdx !== null && bccomp($effectiveCash, $scaledSum, 2) !== 0) {
            $tail = bcsub($effectiveCash, $scaledSum, 2);
            $cartInfo[$lastIdx]['cash_pay_amount'] = (float)bcadd((string)$cartInfo[$lastIdx]['cash_pay_amount'], $tail, 2);
        }
    }

    /**
     * 有效现金金额 SQL 表达式（兼容历史 gross 与新版 net cash_pay_price）
     * @param string $orderAlias 如 a、b
     * @param string $amountExpr 如 a.cash_pay_price、c.pay_price
     */
    public static function buildAmountExpr(string $orderAlias, string $amountExpr): string
    {
        $o = rtrim($orderAlias, '.');
        $col = static function (string $name) use ($o): string {
            return $o !== '' ? "{$o}.{$name}" : $name;
        };
        $combTable = Db::name('combination_order')->getTable();
        $oldCard = (int)CashType::OLD_CARD_ENTRY;
        $combPay = PayServices::COMBINATION_PAY;
        $oldCardSub = "(SELECT SUM(co.price) FROM {$combTable} co WHERE co.order_id = {$col('id')} AND co.cash_choose = {$oldCard})";
        $effectiveComb = "GREATEST(0, LEAST({$amountExpr}, {$col('pay_price')} - IFNULL({$col('yue_pay_price')}, 0) - IFNULL({$oldCardSub}, 0)))";
        return "CASE
            WHEN {$col('cash_choose')} = {$oldCard} THEN 0
            WHEN {$col('pay_type')} = '{$combPay}' THEN {$effectiveComb}
            ELSE {$amountExpr} END";
    }

    /**
     * 有效现金须大于 0（单笔 cash_pay_price 或组合含非旧卡明细）
     * @param mixed $query
     * @param string $alias
     */
    public static function applyHasValidCash($query, string $alias = ''): void
    {
        $p = $alias !== '' ? rtrim($alias, '.') . '.' : '';
        $query->where(function ($cashQuery) use ($p) {
            $cashQuery->where($p . 'cash_pay_price', '>', 0)
                ->whereOr(function ($combQuery) use ($p) {
                    $combQuery->where($p . 'pay_type', PayServices::COMBINATION_PAY)
                        ->whereIn($p . 'id', function ($sub) {
                            $sub->name('combination_order')
                                ->where('cash_choose', '<>', CashType::OLD_CARD_ENTRY)
                                ->where('active_pay', '<>', 3)
                                ->field('order_id');
                        });
                });
        });
    }

    /**
     * @param mixed $query
     * @param array $where
     * @param string $timeColumn 如 add_time、b.add_time
     */
    public static function applyTimeFilter($query, array $where, string $timeColumn = 'add_time'): void
    {
        if (!empty($where['time'])) {
            $query->whereBetween($timeColumn, $where['time']);
        } elseif (!empty($where['date_range_time'])) {
            $query->whereBetween($timeColumn, [
                strtotime($where['date_range_time'][0]),
                strtotime($where['date_range_time'][1]),
            ]);
        } elseif (!empty($where['data'])) {
            $range = explode('-', (string)$where['data']);
            $query->whereBetween($timeColumn, [strtotime($range[0]), strtotime($range[1] . ' 23:59:59')]);
        }
        if (!empty($where['agent_time'])) {
            $query->where(function ($q) use ($where, $timeColumn) {
                $validDates = array_filter($where['agent_time'], function ($date) {
                    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date);
                });
                if ($validDates) {
                    $dateStr = "'" . implode("','", $validDates) . "'";
                    $q->whereRaw("FROM_UNIXTIME({$timeColumn}, '%Y-%m-%d') IN ({$dateStr})");
                }
            });
        }
    }

    /**
     * 门店现金收入 SUM(cash_pay_price) 口径（与 storeapi/home/header store_income 一致）
     * @param array $where 支持 store_id、time、date_range_time、data、agent_time、link_type
     */
    public static function sumStoreCashIncome(array $where): string
    {
        $orderWhere = [
            'paid' => 1,
            'valid_cash_only' => 1,
            'pid' => -3,
            'is_system_del' => 0,
            'refund_status' => 0,
        ];
        if (isset($where['link_type'])) {
            $orderWhere['link_type'] = $where['link_type'];
        } else {
            $orderWhere['link_type'] = [0, 1];
        }
        /** @var StoreOrderDao $orderDao */
        $orderDao = app()->make(StoreOrderDao::class);
        $amountExpr = self::buildAmountExpr('', 'cash_pay_price');
        return (string)$orderDao->search($orderWhere + $where)->sum(Db::raw($amountExpr));
    }

    /**
     * 按支付方式(cash_choose)汇总现金收入（收款报表 order_data，与 store_income 同口径）
     * @param array $where 须含 cash_choose；支持 store_id、time、data
     */
    public static function sumStoreCashIncomeByCashChoose(array $where): string
    {
        $payTypes = [
            PayServices::WEIXIN_PAY,
            PayServices::ALIAPY_PAY,
            PayServices::CASH_PAY,
            PayServices::OFFLINE_PAY,
        ];
        $orderWhere = [
            'paid' => 1,
            'pay_type' => $payTypes,
            'not_old' => 1,
            'pid' => -3,
            'is_system_del' => 0,
            'refund_status' => 0,
            'link_type' => [0, 1],
        ];
        /** @var StoreOrderDao $orderDao */
        $orderDao = app()->make(StoreOrderDao::class);
        $amountExpr = self::buildAmountExpr('', 'cash_pay_price');
        $income = (string)$orderDao->search($orderWhere + $where)->sum(Db::raw($amountExpr));
        $comb = self::sumCombinationCashByCashChoose($where);
        if (bccomp($comb, '0', 2) > 0) {
            $income = bcadd($income, $comb, 2);
        }
        return $income;
    }

    /**
     * 组合支付按 cash_choose 汇总（排除旧卡录入、余额明细）
     */
    public static function sumCombinationCashByCashChoose(array $where): string
    {
        return (string)CombinationOrder::alias('a')
            ->join('store_order b', 'b.id=a.order_id', 'inner')
            ->where(function ($query) {
                $query->where('b.pid', '>=', 0)->whereOr('b.pid', -2);
            })
            ->where('a.cash_choose', '<>', CashType::OLD_CARD_ENTRY)
            ->where('a.cash_choose', '<>', CashType::DEBT_ENTRY)
            ->where(function ($q) {
                $q->whereNull('a.pay_sub_type')->whereOr('a.pay_sub_type', '<>', 'debt');
            })
            ->where('b.pay_type', PayServices::COMBINATION_PAY)
            ->where('b.paid', 1)
            ->where('b.is_system_del', 0)
            ->where('b.refund_status', 0)
            ->whereIn('b.order_type', [0, 1])
            ->where('a.active_pay', '<>', 3)
            ->when(isset($where['cash_choose']) && $where['cash_choose'] !== '' && $where['cash_choose'] !== null, function ($query) use ($where) {
                $query->where('a.active_pay', 2)->where('a.cash_choose', $where['cash_choose']);
            })
            ->when(isset($where['store_id']) && $where['store_id'] !== '' && $where['store_id'] !== null, function ($query) use ($where) {
                if (is_array($where['store_id'])) {
                    $query->whereIn('b.store_id', $where['store_id']);
                } else {
                    $query->where('b.store_id', $where['store_id']);
                }
            })
            ->when(true, function ($query) use ($where) {
                self::applyTimeFilter($query, $where, 'b.add_time');
            })
            ->sum(Db::raw(self::buildCombinationLineAmountExpr('b', 'a.price')));
    }

    /**
     * 组合支付明细行金额 × 与 buildAmountExpr 相同的旧卡折算系数
     * 使按 cash_choose 分行汇总后与 sumStoreCashIncome 合计一致
     */
    public static function buildCombinationLineAmountExpr(string $orderAlias = 'b', string $linePriceExpr = 'a.price'): string
    {
        $oldCard = (int)CashType::OLD_CARD_ENTRY;
        $debtEntry = (int)CashType::DEBT_ENTRY;
        return "CASE WHEN a.cash_choose IN ({$oldCard}, {$debtEntry}) OR a.pay_sub_type = 'debt' THEN 0 ELSE ({$linePriceExpr}) END";
    }

    /**
     * 组合支付明细合计须等于订单应付金额
     */
    public static function validateCombinationTotal(array $combinationInfo, $payPrice): void
    {
        if ($combinationInfo === []) {
            return;
        }
        $sum = '0.00';
        foreach ($combinationInfo as $row) {
            $sum = bcadd($sum, (string)($row['price'] ?? 0), 2);
        }
        if (bccomp($sum, bcadd((string)$payPrice, '0', 2), 2) !== 0) {
            throw new \think\exception\ValidateException(
                '组合支付明细合计(' . $sum . ')与订单应付金额(' . bcadd((string)$payPrice, '0', 2) . ')不一致，请重新确认支付明细'
            );
        }
    }

    /**
     * 订单金额变更时，按占比同步组合支付明细（拆单/改价后避免明细合计与 pay_price 脱节）
     */
    public static function scaleCombinationOrderPrices(int $orderId, string $newPayPrice, string $oldPayPrice): void
    {
        if ($orderId <= 0 || bccomp($oldPayPrice, '0', 2) <= 0 || bccomp($newPayPrice, $oldPayPrice, 2) === 0) {
            return;
        }
        $rows = CombinationOrder::where('order_id', $orderId)->select();
        if ($rows->isEmpty()) {
            return;
        }
        $ratio = bcdiv($newPayPrice, $oldPayPrice, 6);
        $scaledSum = '0.00';
        $updates = [];
        foreach ($rows as $row) {
            $rowArr = $row->toArray();
            $scaled = bcmul((string)($rowArr['price'] ?? 0), $ratio, 2);
            $updates[] = ['id' => (int)$rowArr['id'], 'price' => $scaled];
            $scaledSum = bcadd($scaledSum, $scaled, 2);
        }
        $tail = bcsub($newPayPrice, $scaledSum, 2);
        if ($updates && bccomp($tail, '0', 2) !== 0) {
            $last = count($updates) - 1;
            $updates[$last]['price'] = bcadd($updates[$last]['price'], $tail, 2);
        }
        foreach ($updates as $item) {
            CombinationOrder::where('id', $item['id'])->update(['price' => $item['price']]);
        }
    }

    /**
     * 拆单后将主单组合支付明细按比例分摊到子单，并清除主单明细
     * @param array $childOrders 子订单数组/模型列表
     */
    public static function redistributeCombinationAfterSplit(int $parentOrderId, array $childOrders): void
    {
        if ($parentOrderId <= 0 || !$childOrders) {
            return;
        }
        $rows = CombinationOrder::where('order_id', $parentOrderId)->select()->toArray();
        if (!$rows) {
            return;
        }
        $basePay = '0.00';
        $normalized = [];
        foreach ($childOrders as $child) {
            $childArr = is_array($child) ? $child : (method_exists($child, 'toArray') ? $child->toArray() : []);
            $childPay = bcadd((string)($childArr['pay_price'] ?? 0), '0', 2);
            if (bccomp($childPay, '0', 2) <= 0) {
                continue;
            }
            $normalized[] = [
                'id' => (int)($childArr['id'] ?? 0),
                'pay_price' => $childPay,
            ];
            $basePay = bcadd($basePay, $childPay, 2);
        }
        if (!$normalized || bccomp($basePay, '0', 2) <= 0) {
            return;
        }
        foreach ($normalized as $child) {
            $ratio = bcdiv($child['pay_price'], $basePay, 6);
            $lineSum = '0.00';
            $batch = [];
            foreach ($rows as $row) {
                $newPrice = bcmul((string)($row['price'] ?? 0), $ratio, 2);
                $lineSum = bcadd($lineSum, $newPrice, 2);
                unset($row['id']);
                $row['order_id'] = $child['id'];
                $row['price'] = $newPrice;
                $batch[] = $row;
            }
            $tail = bcsub($child['pay_price'], $lineSum, 2);
            if ($batch && bccomp($tail, '0', 2) !== 0) {
                $batch[count($batch) - 1]['price'] = bcadd($batch[count($batch) - 1]['price'], $tail, 2);
            }
            foreach ($batch as $ins) {
                CombinationOrder::create($ins);
            }
        }
        CombinationOrder::where('order_id', $parentOrderId)->delete();
    }

    /**
     * 组合支付明细中的旧卡录入金额
     */
    public static function sumCombinationOldCardCash(array $where): string
    {
        return (string)CombinationOrder::alias('a')
            ->join('store_order b', 'b.id=a.order_id', 'inner')
            ->where(function ($query) {
                $query->where('b.pid', '>=', 0)->whereOr('b.pid', -2);
            })
            ->where('a.cash_choose', CashType::OLD_CARD_ENTRY)
            ->where('b.pay_type', PayServices::COMBINATION_PAY)
            ->where('b.paid', 1)
            ->where('b.is_system_del', 0)
            ->where('b.refund_status', 0)
            ->when(isset($where['store_id']) && $where['store_id'] !== '' && $where['store_id'] !== null, function ($query) use ($where) {
                if (is_array($where['store_id'])) {
                    $query->whereIn('b.store_id', $where['store_id']);
                } else {
                    $query->where('b.store_id', $where['store_id']);
                }
            })
            ->when(true, function ($query) use ($where) {
                self::applyTimeFilter($query, $where, 'b.add_time');
            })
            ->sum('a.price');
    }

    /**
     * 同步商品订单 cash_choose / cash_pay_price 及行级 cash_pay_amount
     */
    public static function syncStoreOrderCashPay(int $orderId, int $newCashChoose): float
    {
        $order = StoreOrder::where('id', $orderId)->find();
        if (!$order) {
            return 0.00;
        }
        $orderArr = $order->toArray();
        $cashPayPrice = self::calcOrderCashPayPrice(
            $orderArr['pay_price'] ?? 0,
            $orderArr['yue_pay_price'] ?? 0,
            $newCashChoose,
            [],
            (string)($orderArr['pay_type'] ?? '')
        );
        StoreOrder::where('id', $orderId)->update([
            'cash_choose' => $newCashChoose,
            'cash_pay_price' => $cashPayPrice,
        ]);
        self::syncCartLineCashPayAmounts($orderId, $cashPayPrice);
        return $cashPayPrice;
    }

    /**
     * 按订单有效现金重算各行 cash_pay_amount
     */
    public static function syncCartLineCashPayAmounts(int $orderId, float $orderCashPayPrice): void
    {
        $rows = StoreOrderCartInfo::where('oid', $orderId)->where('cart_type', 0)->select()->toArray();
        if (!$rows) {
            return;
        }
        $cartInfo = [];
        foreach ($rows as $arr) {
            $pp = (string)($arr['pay_price'] ?? 0);
            $yue = (string)($arr['yue_pay_amount'] ?? 0);
            $cu = (string)($arr['card_upgrade_amount'] ?? 0);
            $cash = bcsub(bcsub($pp, $yue, 2), $cu, 2);
            if (bccomp($cash, '0', 2) < 0) {
                $cash = '0.00';
            }
            $arr['cash_pay_amount'] = (float)$cash;
            $cartInfo[] = $arr;
        }
        self::scaleCartCashPayAmounts($cartInfo, $orderCashPayPrice);
        foreach ($cartInfo as $item) {
            StoreOrderCartInfo::where('id', $item['id'])->update([
                'cash_pay_amount' => $item['cash_pay_amount'],
            ]);
        }
    }

    /**
     * 同步储值单 cash_choose 及关联业绩单的 cash_pay_price
     */
    public static function syncRechargeOrderCashPay(int $rechargeId, int $newCashChoose): float
    {
        $recharge = UserRecharge::where('id', $rechargeId)->find();
        if (!$recharge) {
            return 0.00;
        }
        UserRecharge::where('id', $rechargeId)->update(['cash_choose' => $newCashChoose]);
        $cashPayPrice = self::calcOrderCashPayPrice(
            $recharge['price'] ?? 0,
            0,
            $newCashChoose,
            [],
            PayServices::CASH_PAY
        );
        StoreOrder::where('link_id', $rechargeId)->where('order_type', 1)->update([
            'cash_choose' => $newCashChoose,
            'cash_pay_price' => $cashPayPrice,
        ]);
        return $cashPayPrice;
    }
}
