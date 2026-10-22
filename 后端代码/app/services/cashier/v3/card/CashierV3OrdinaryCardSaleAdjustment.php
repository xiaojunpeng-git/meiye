<?php
declare(strict_types=1);

namespace app\services\cashier\v3\card;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\settlement\CashierV3CheckoutSettlementCanonicalizer;

/** R49: a signed sale definition permits increases, never edits the catalogue or existing cards. */
final class CashierV3OrdinaryCardSaleAdjustment
{
    public static function enabled(): bool
    {
        return (int)sys_config('cashier_ordinary_card_sale_edit_switch', 0) === 1;
    }

    /** Bind the original project rows and card price to this instance, store and SKU. */
    public static function seal(array $purchase, int $storeId, int $skuId, int $price): array
    {
        if (($purchase['sourceKind'] ?? '') !== 'card_package' || ($purchase['ruleType'] ?? '') !== 'normal') {
            return $purchase;
        }
        // Only business values belong to the unique checkout snapshot. Signing
        // catalogue versions would break when the final transport removes them.
        $purchase = self::withoutVersions($purchase);
        $baseline = ['storeId' => $storeId, 'skuId' => $skuId,
            'priceCents' => $price, 'purchase' => $purchase];
        $purchase['ordinarySaleBaseline'] = ['definition' => $baseline, 'signature' => self::signature($baseline)];
        return $purchase;
    }

    /**
     * Recheck at preparation AND issuance. The immutable definition survives hangs;
     * changed rows require the live switch. Whole-yuan totals do not imply integer unit prices.
     * Returns the per-card price increase; coupons/debt/balance retain their normal semantics.
     */
    public static function validate(array $purchase, array $current, int $storeId, int $skuId): int
    {
        $envelope = $purchase['ordinarySaleBaseline'] ?? null;
        // Multi-card upgrade has its own locked operation authority. Its metadata
        // is not a card definition edit; project floors remain checked below.
        unset($purchase['multiCardUpgrade'], $current['multiCardUpgrade']);
        if ($envelope === null) {
            // Pre-R49 carts remain usable only with unmodified normal definitions.
            // Removing the signed envelope cannot turn altered rows into legacy intent.
            if (($current['ruleType'] ?? '') === 'normal'
                && ($current['sourceKind'] ?? '') === 'card_package') {
                unset($current['ordinarySaleBaseline']);
                if (self::fingerprint(self::withoutVersions($purchase)) !== self::fingerprint(self::withoutVersions($current))) self::fail();
            } elseif (($purchase['sourceKind'] ?? '') === 'card_package'
                && (($purchase['ruleType'] ?? '') === 'normal' || self::containsAdjustedAmount($purchase))) {
                self::fail();
            }
            return 0;
        }
        if (!is_array($envelope) || !is_array($envelope['definition'] ?? null)) self::fail();
        $definition = $envelope['definition'];
        $signature = $envelope['signature'] ?? '';
        if (!is_string($signature) || !hash_equals(self::signature($definition), $signature)
            || ($definition['storeId'] ?? null) !== $storeId
            || ($definition['skuId'] ?? null) !== $skuId) self::fail();
        $base = $definition['purchase'] ?? [];
        if (($base['sourceKind'] ?? '') !== 'card_package' || ($base['ruleType'] ?? '') !== 'normal') self::fail();
        $rows = $purchase['components'] ?? [];
        $original = $base['components'] ?? [];
        if (!$original || !is_array($rows) || array_keys($rows) !== array_keys($original)) self::fail();
        $withoutRows = $purchase;
        unset($withoutRows['ordinarySaleBaseline'], $withoutRows['components']);
        $baseHeader = $base;
        unset($baseHeader['components']);
        if (self::fingerprint($withoutRows) !== self::fingerprint($baseHeader)) self::fail();
        $increase = 0;
        $changed = false;
        foreach ($original as $i => $row) {
            $selected = $rows[$i];
            if (!is_array($selected)) self::fail();
            $times = $selected['writeTimes'] ?? null;
            $baseTimes = $row['writeTimes'] ?? 0;
            $price = $row['configuredPriceCents'] ?? -1;
            if (!is_int($baseTimes) || $baseTimes <= 0 || !is_int($price) || $price < 0
                || $price > intdiv(PHP_INT_MAX, $baseTimes)) self::fail();
            $floor = $price * $baseTimes;
            $amount = $selected['configuredAmountCents'] ?? $floor;
            if (!is_int($times) || $times < $baseTimes || $times > 99999999
                || ($times !== $baseTimes && !array_key_exists('configuredAmountCents', $selected))
                || !is_int($amount) || $amount < $floor || $amount % 100 !== 0
                || $amount > 99999999900 || $increase > PHP_INT_MAX - ($amount - $floor)) self::fail();
            $identity = $selected;
            unset($identity['configuredAmountCents']);
            $identity['writeTimes'] = $baseTimes;
            if (self::fingerprint($identity) !== self::fingerprint($row)) self::fail();
            $changed = $changed || $times !== $baseTimes || $amount !== $floor;
            $increase += $amount - $floor;
        }
        if ($changed && !self::enabled()) self::fail('普通卡销售修改已关闭，请恢复原配置后重试。');
        return $increase;
    }

    /** Signed original price plus independent row increases; never replace it with the row sum. */
    public static function minimumPrice(array $purchase, int $increase): ?int
    {
        $original = $purchase['ordinarySaleBaseline']['definition']['purchase']['components'] ?? [];
        $changed = $increase > 0;
        foreach ((array)($purchase['components'] ?? []) as $i => $row) {
            $changed = $changed || ($row['writeTimes'] ?? null) !== ($original[$i]['writeTimes'] ?? null);
        }
        // Ordinary cards that have not been edited keep the existing manual pricing path.
        if (!$changed) return null;
        $price = $purchase['ordinarySaleBaseline']['definition']['priceCents'] ?? null;
        if ($price === null) return null;
        if (!is_int($price) || $price < 0 || $price % 100 !== 0 || $price > PHP_INT_MAX - $increase) self::fail();
        return $price + $increase;
    }

    private static function containsAdjustedAmount(array $purchase): bool
    {
        foreach ((array)($purchase['components'] ?? []) as $row) {
            if (is_array($row) && array_key_exists('configuredAmountCents', $row)) return true;
        }
        return false;
    }

    /** Check the pre-coupon sale base without treating balance payment or debt as discounts. */
    public static function assertSaleAmount(array $purchase, int $increase, int $quantity, int $amount, int $couponDiscount): void
    {
        $minimum = self::minimumPrice($purchase, $increase);
        if ($minimum === null) return;
        if ($quantity <= 0 || $amount < 0 || $couponDiscount < 0
            || $amount > PHP_INT_MAX - $couponDiscount || $minimum > intdiv(PHP_INT_MAX, $quantity)
            || $amount + $couponDiscount < $minimum * $quantity) {
            self::fail('普通卡修改后的售价不能低于原售价加项目增额；优惠券仍按正常规则抵扣。');
        }
    }

    private static function signature(array $definition): string
    {
        $secret = (string)config('cashier_v3.checkout_namespace_secret');
        if (strlen($secret) < 32) self::fail('普通卡销售校验配置未就绪，请联系管理员。');
        return hash_hmac('sha256', 'ordinary-card-sale-snapshot|' . self::fingerprint($definition), $secret);
    }

    /** Versions/revisions are never sale intent, including inside the original-row proof. */
    private static function withoutVersions(array $value): array
    {
        foreach ($value as $key => $item) {
            $normalized = str_replace(['_', '-'], '', (string)$key);
            if (preg_match('/(?:version|revision)$/i', $normalized)) unset($value[$key]);
            elseif (is_array($item)) $value[$key] = self::withoutVersions($item);
        }
        return $value;
    }

    private static function fingerprint(array $value): string
    {
        return CashierV3CheckoutSettlementCanonicalizer::fingerprint($value);
    }

    private static function fail(string $message = '普通卡每个项目的金额和次数不能低于原配置，且不得更换或删减项目。'): void
    {
        throw new CashierV3CommandException(CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE, $message,
            'failed', ['reason' => 'ordinary_card_sale_adjustment_invalid']);
    }
}
