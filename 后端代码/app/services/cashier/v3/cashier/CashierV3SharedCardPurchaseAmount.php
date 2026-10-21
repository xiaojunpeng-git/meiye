<?php
declare(strict_types=1);

namespace app\services\cashier\v3\cashier;

use app\services\cashier\v3\card\CashierV3CardSaleActualAmountServices;
use think\facade\Db;

/**
 * 任选次数权益的购买金额权威。
 *
 * 普通任选卡以整卡成交额作为共享次数池，项目销售分摊事实只用于销售和退款追溯，
 * 不能直接作为某个可选项目的核销金额。组合卡先把销售分摊权重按整卡成交额归整
 * 为整数元，再汇总出各组的独立次数池；所有卡项核销都执行整元分摊，尾差只落到
 * 本次数池最后一次，不能把分金额或其他组的次数带入当前服务。
 */
final class CashierV3SharedCardPurchaseAmount
{
    public const CALCULATION_VERSION = 'shared-card-purchase-amount-'
        . CashierV3EntitlementActualAmountAllocator::CALCULATION_VERSION;

    /**
     * @return array{purchaseAmount:string,remainingAmount:string,totalPurchaseTimes:int,
     *     consumedTimesAtSelection:int,calculationVersion:string}|null
     */
    public static function project(array $order, array $ruleAuthority, array $snapshot): ?array
    {
        if ((string)($ruleAuthority['ruleType'] ?? '') !== 'choice_count'
            || CashierV3EntitlementActualAmountAllocator::usesIndependentAmountSnapshot($snapshot)) {
            return null;
        }
        $paid = trim((string)($order['pay_price'] ?? ''));
        $total = (int)($ruleAuthority['totalTimes'] ?? 0);
        $remaining = (int)($ruleAuthority['remainingTimes'] ?? -1);
        // 所有卡项核销都必须是整数元。组合卡只用销售分摊事实确定各组权重，
        // 随后按整卡实付归整；异常历史卡不能改用目录价或按分进入核销。
        $combination = (string)($ruleAuthority['cardRuleType'] ?? '') === 'combination';
        if (preg_match($combination ? '/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/D'
            : '/^(?:0|[1-9][0-9]*)(?:\.0{1,2})?$/D', $paid) !== 1
            || $total <= 0 || $remaining < 0 || $remaining > $total) {
            return null;
        }
        $purchaseAmount = bcadd($paid, '0', 2);
        if ($combination) {
            $groupNo = (int)($ruleAuthority['combinationGroupNo'] ?? 0);
            $groupCents = self::combinationGroupActualCents($order, $groupNo);
            if ($groupCents === null) return null;
            $purchaseAmount = bcdiv((string)$groupCents, '100', 2);
        }
        $removed = $snapshot['r26RefundRemovedTimes'] ?? 0;
        if (!is_int($removed) || $removed < 0 || $removed > $total - $remaining) {
            return null;
        }
        $snapshot['amountCalculationVersion'] = CashierV3EntitlementActualAmountAllocator::CALCULATION_VERSION;
        try {
            $remainingAmount = CashierV3EntitlementActualAmountAllocator::remainingForSnapshot(
                $purchaseAmount, $total, $total - $remaining, $snapshot
            );
        } catch (\InvalidArgumentException $error) {
            return null;
        }
        return [
            'purchaseAmount' => $purchaseAmount,
            'remainingAmount' => $remainingAmount,
            'totalPurchaseTimes' => $total,
            // 退款扣减的是未来可用次数，不可推进服务单价的末次尾差。
            'consumedTimesAtSelection' => $total - $remaining - $removed,
            'calculationVersion' => self::CALCULATION_VERSION,
        ];
    }

    /**
     * 组金额以原销售分摊事实为权重，先按整卡实付归整到整数元后再逐项合计。
     * 返回的是组共享次数池总额，不是任一项目的单次核销额；单次金额仍由组总额、
     * 组总次数和已核销次数确定，不能改用当前商品价格或保留分金额进入核销。
     */
    private static function combinationGroupActualCents(array $order, int $targetNo): ?int
    {
        $orderId = (int)($order['id'] ?? 0);
        if ($orderId <= 0 || $targetNo <= 0) return null;
        $carts = Db::name('store_order_cart_info')->where('oid', $orderId)
            ->where('cart_type', 2)->field('id,oid,product_id,cart_info')->order('id asc')->select()->toArray();
        if (!$carts) return null;
        $amounts = (new CashierV3CardSaleActualAmountServices())->wholeYuanForOrders([$order], $carts);
        $total = 0;
        $groupTotal = 0;
        foreach ($carts as $cart) {
            $snapshot = json_decode((string)($cart['cart_info'] ?? ''), true);
            $snapshot = is_array($snapshot) ? $snapshot : [];
            $no = (int)($snapshot['combination_group_no'] ?? 0);
            $id = (int)$cart['id'];
            if ($no <= 0 || !array_key_exists($id, $amounts)) return null;
            $amount = (int)$amounts[$id];
            if ($amount < 0 || $amount > PHP_INT_MAX - $total) return null;
            $total += $amount;
            if ($no === $targetNo) $groupTotal += $amount;
        }
        $paidCents = (int)bcmul((string)($order['pay_price'] ?? '0'), '100', 0);
        return $total === $paidCents ? $groupTotal : null;
    }
}
