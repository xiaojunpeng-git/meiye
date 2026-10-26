<?php
declare(strict_types=1);

namespace app\services\cashier\v3\card;

use app\services\cashier\v3\cashier\CashierV3EntitlementActualAmountAllocator;

/**
 * One immutable component sale amount priority for member rights and refunds.
 * A configured legacy cart price cannot override a completed V3 allocation,
 * issued-card rule amount, or recorded imported source-line payment.
 */
final class CashierV3CardComponentPurchaseAmountAuthority
{
    /** @return array{purchaseAmountCents:int,allocationSnapshot:array} */
    public function resolve(array $cart, array $snapshot, array $saleAllocations, ?array $ruleAuthority): array
    {
        $cartId = (int)($cart['id'] ?? 0);
        $amount = $saleAllocations[$cartId] ?? null;
        if ($amount !== null) {
            $amount = (int)$amount;
            // Sale facts may keep cent-level weights for reporting, but every
            // caller of this writeoff authority must pass the normalized
            // whole-yuan pool. Rejecting a raw cent allocation here prevents
            // refunds or service facts from reviving the retired cent path.
            if ($amount < 0 || $amount % 100 !== 0) {
                throw new \InvalidArgumentException('card sale allocation must be whole yuan');
            }
            return ['purchaseAmountCents' => $amount, 'allocationSnapshot' => $snapshot];
        }
        if (!CashierV3EntitlementActualAmountAllocator::usesIndependentAmountSnapshot($snapshot)
            && is_array($ruleAuthority)) {
            $ruleAmount = (int)($ruleAuthority['purchaseAmountCents'] ?? -1);
            if ($ruleAmount >= 0 && $ruleAmount % 100 === 0) {
                return ['purchaseAmountCents' => $ruleAmount, 'allocationSnapshot' => $ruleAuthority];
            }
        }
        $legacy = is_array($snapshot['rh_source'] ?? null) ? $snapshot['rh_source'] : [];
        if (array_key_exists('source_line_paid_amount', $legacy)) {
            $raw = trim((string)$legacy['source_line_paid_amount']);
            if (!preg_match('/^(?:0|[1-9][0-9]*)(?:\.0{1,2})?$/D', $raw)) {
                throw new \InvalidArgumentException('imported paid amount is invalid');
            }
            $amount = (int)bcmul($raw, '100', 0);
            if ($amount % 100 !== 0) {
                throw new \InvalidArgumentException('imported paid amount must be normalized before use');
            }
            return ['purchaseAmountCents' => $amount, 'allocationSnapshot' => $snapshot];
        }
        $raw = trim((string)($cart['pay_price'] ?? '0'));
        if (!preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/D', $raw)) {
            throw new \InvalidArgumentException('card cart paid amount is invalid');
        }
        $amount = (int)bcmul($raw, '100', 0);
        if ($amount % 100 !== 0) {
            throw new \InvalidArgumentException('card cart paid amount must be normalized before use');
        }
        return ['purchaseAmountCents' => $amount, 'allocationSnapshot' => $snapshot];
    }
}
