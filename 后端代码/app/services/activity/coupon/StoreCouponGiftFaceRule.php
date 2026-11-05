<?php
declare(strict_types=1);

namespace app\services\activity\coupon;

/** Only an enabled reusable voucher accepts a direct-gift face override.
 * The issued claim owns its amount; the template and other issuance channels do not change.
 */
final class StoreCouponGiftFaceRule
{
    public static function editable(array $issue): bool
    {
        return (int)($issue['coupon_type'] ?? 0) === StoreCouponAllowanceServices::TYPE
            && (int)($issue['allow_gift_face_edit'] ?? 0) === 1;
    }

    /** No template-price floor or ceiling: accept any existing valid whole-yuan amount.
     * Null means use the template, preserving old requests and their replay fingerprints.
     */
    public static function override(array $issue, $value): ?string
    {
        if (!self::editable($issue) || $value === null) return null;
        $cents = StoreCouponAllowanceServices::wholeCents($value);
        return (string)intdiv($cents, 100) . '.00';
    }
}
