<?php
declare(strict_types=1);

namespace app\services\activity\coupon;

use think\exception\ValidateException;

/**
 * Ordinary universal fixed-value coupons may qualify against the whole
 * checkout, while each redeemed coupon still pays one selected sale line.
 * Tier money is stored in cents to keep issuance and settlement consistent.
 */
final class StoreCouponOrderRule
{
    public const DETAIL = 0;
    public const ORDER = 1;
    private const MAX_TIERS = 20;

    /** @param mixed $tiers @return array<int,array{min_amount_cents:int,max_count:int}> */
    public static function normalizeForSave($tiers): array
    {
        if (!is_array($tiers) || !$tiers || count($tiers) > self::MAX_TIERS) {
            throw new ValidateException('请设置1至20档整单用券门槛');
        }
        $result = [];
        $lastAmount = 0;
        $lastCount = 0;
        foreach ($tiers as $tier) {
            if (!is_array($tier)) throw new ValidateException('整单用券档位格式不正确');
            $amount = $tier['min_amount'] ?? null;
            $count = $tier['max_count'] ?? null;
            if (preg_match('/^[1-9][0-9]*$/D', (string)$amount) !== 1
                || preg_match('/^[1-9][0-9]*$/D', (string)$count) !== 1) {
                throw new ValidateException('整单用券门槛和张数须为正整数');
            }
            $amount = (int)$amount;
            $count = (int)$count;
            if ($amount > 100000000 || $count > 100 || $amount <= $lastAmount || $count < $lastCount) {
                throw new ValidateException('整单用券档位金额须递增，张数不能下降');
            }
            $result[] = ['min_amount_cents' => $amount * 100, 'max_count' => $count];
            $lastAmount = $amount;
            $lastCount = $count;
        }
        return $result;
    }

    /** @return array<int,array{min_amount_cents:int,max_count:int}> */
    public static function fromStored(string $json): array
    {
        $tiers = json_decode($json, true);
        if (!is_array($tiers) || !$tiers || count($tiers) > self::MAX_TIERS) {
            throw new ValidateException('整单券规则不完整，请联系管理员');
        }
        $lastAmount = 0;
        $lastCount = 0;
        foreach ($tiers as $tier) {
            if (!is_array($tier) || !is_int($tier['min_amount_cents'] ?? null)
                || !is_int($tier['max_count'] ?? null)
                || $tier['min_amount_cents'] <= $lastAmount
                || $tier['max_count'] < $lastCount || $tier['max_count'] > 100) {
                throw new ValidateException('整单券规则不完整，请联系管理员');
            }
            $lastAmount = $tier['min_amount_cents'];
            $lastCount = $tier['max_count'];
        }
        return $tiers;
    }

    /** Highest qualifying tier defines one total cap across all sale lines. */
    public static function maxCount(array $tiers, int $receivableCents): int
    {
        $limit = 0;
        foreach ($tiers as $tier) {
            if ($receivableCents < $tier['min_amount_cents']) break;
            $limit = $tier['max_count'];
        }
        return $limit;
    }
}
