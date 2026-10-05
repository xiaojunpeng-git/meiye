<?php
declare(strict_types=1);

namespace app\services\cashier\v3\card;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\cashier\CashierV3CardOriginDebtResolver;

/** 原卡升级只允许已结清或已取消的债务；已取消金额不能抵扣新卡价款。 */
final class CashierV3CardUpgradeDebtPolicy
{
    /**
     * 原订单会员是债务归属人，即使卡已转让，也以该订单的债务终态判断。
     * 调用方须已锁定原卡和原订单，最终结账重读，不能信任页面上的余额。
     */
    public function cancelledCentsAfterPendingGuard(array $originOrder, string $tenantId): int
    {
        $resolver = new CashierV3CardOriginDebtResolver();
        $memberId = (int)($originOrder['uid'] ?? 0);
        $cancelled = $resolver->cancelledCardDebt($originOrder, $memberId, $tenantId);
        $unavailable = $resolver->unavailableCardDebt($originOrder, $memberId, $tenantId);
        if (bccomp($unavailable, $cancelled, 2) > 0) {
            throw CashierV3CommandException::versionConflict(
                '请先补交原卡的欠款或者取消原卡的欠款，才能升级。',
                ['reason' => 'card_upgrade_origin_debt_pending', 'origin_order_id' => (int)($originOrder['id'] ?? 0)]
            );
        }
        return (int)bcmul($cancelled, '100', 0);
    }

    /** 取消欠款不等于收款，剩余物理金额中的该部分不得成为升级抵扣。 */
    public static function creditAfterCancellation(int $remainingCents, int $cancelledCents): int
    {
        if ($remainingCents < 0 || $cancelledCents < 0) {
            throw new \LogicException('card_upgrade_debt_credit_invalid');
        }
        return max(0, $remainingCents - $cancelledCents);
    }
}
