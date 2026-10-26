<?php

declare(strict_types=1);

namespace app\services\cashier\v3\settlement;

/**
 * Keeps voucher tender separate from a price discount. The card's purchase
 * value is the price before voucher, while collection and refundable cash
 * exclude the voucher; all inputs and outputs are exact cents.
 */
final class CashierV3CouponTenderAmounts
{
    /** @return array{originalCents:int,discountCents:int,saleCents:int,couponPaymentCents:int} */
    public static function fromNetSale(int $originalCents, int $netCents, int $couponCents): array
    {
        if ($originalCents < 0 || $netCents < 0 || $couponCents < 0
            || $netCents > PHP_INT_MAX - $couponCents) {
            throw new \InvalidArgumentException('coupon_tender_amount_invalid');
        }
        $saleCents = $netCents + $couponCents;
        $originalCents = max($originalCents, $saleCents);
        return [
            'originalCents' => $originalCents,
            'discountCents' => $originalCents - $saleCents,
            'saleCents' => $saleCents,
            'couponPaymentCents' => $couponCents,
        ];
    }

    public static function receivable(int $saleCents, int $couponCents, int $debtCents): int
    {
        if ($saleCents < 0 || $couponCents < 0 || $debtCents < 0
            || $couponCents > $saleCents || $debtCents > $saleCents - $couponCents) {
            throw new \InvalidArgumentException('coupon_tender_receivable_invalid');
        }
        return $saleCents - $couponCents - $debtCents;
    }
}
