<?php
declare(strict_types=1);
namespace app\services\activity\coupon;

/** Shared business predicates: no database writes, no frontend-supplied eligibility. */
final class StoreCouponEligibilityRule
{
    public const ISSUING_STORE = 3;

    /** Validate new template combinations before any issuer persists them. */
    public static function validate(array $rule): void
    {
        if (!in_array((int)($rule['usage_target'] ?? 0), [0, 1], true)
            || !in_array((int)($rule['new_user_only'] ?? 0), [0, 1], true)
            || !in_array((int)($rule['applicable_type'] ?? 1), [0, 1, 2, 3], true)) {
            throw new \InvalidArgumentException('优惠券使用规则无效');
        }
        if ((int)($rule['usage_target'] ?? 0) === 1 && (int)($rule['allow_transfer'] ?? 0) !== 1) {
            throw new \InvalidArgumentException('使用对象为他人时必须允许会员转赠');
        }
        if ((int)($rule['coupon_type'] ?? 1) === 3
            && ((int)($rule['usage_target'] ?? 0) === 1 || (int)($rule['new_user_only'] ?? 0) === 1)) {
            throw new \InvalidArgumentException('多次抵用券暂不支持仅他人使用或新用户限制');
        }
    }

    /** Reasons accumulate so fixing one condition does not conceal another. */
    public static function reasons(array $coupon, int $memberId, int $storeId, bool $hasConsumed): array
    {
        $reasons = [];
        if ((int)($coupon['usage_target'] ?? 0) === 1) {
            $original = (int)($coupon['original_recipient_uid'] ?? 0);
            if ($original <= 0) $reasons[] = '原领取人信息缺失，暂不可使用';
            elseif ($original === $memberId) $reasons[] = '仅限转赠他人后使用，本人不可使用';
        }
        if ((int)($coupon['new_user_only'] ?? 0) === 1 && $hasConsumed) {
            $reasons[] = '仅限从未消费的新用户使用';
        }
        if ((int)($coupon['applicable_type'] ?? 1) === self::ISSUING_STORE) {
            $issuedStore = (int)($coupon['issuance_store_id'] ?? 0);
            if ($issuedStore <= 0) $reasons[] = '发放门店信息缺失，暂不可使用';
            elseif ($issuedStore !== $storeId) $reasons[] = '仅限发放门店使用';
        }
        return $reasons;
    }
}
