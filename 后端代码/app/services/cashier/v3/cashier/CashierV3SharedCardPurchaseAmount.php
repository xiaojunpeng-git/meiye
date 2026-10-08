<?php
declare(strict_types=1);

namespace app\services\cashier\v3\cashier;

/**
 * 任选次数卡的原购买金额属于整卡共享次数池，不属于任一可选项目。
 * 项目销售分摊事实仍用于销售与退款追溯，不能代替服务核销的单次金额。
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
        // 整元核销必须能完整分摊原购买金额；异常历史卡不能静默改成
        // 项目目录价、四舍五入后的购买价或按分计价。
        if (preg_match('/^(?:0|[1-9][0-9]*)(?:\.0{1,2})?$/D', $paid) !== 1
            || $total <= 0 || $remaining < 0 || $remaining > $total) {
            return null;
        }
        $purchaseAmount = bcadd($paid, '0', 2);
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
}
