<?php
declare(strict_types=1);

namespace app\services\member;

use app\services\activity\coupon\StoreCouponOrderRule;
use app\services\product\brand\StoreBrandServices;
use app\services\product\category\StoreProductCategoryServices;
use app\services\user\UserBillServices;
use think\exception\ValidateException;
use think\facade\Db;

/**
 * Authoritative coupon and integral boundary for member self-checkout.
 * Quotes may describe benefits, while only a reserved commerce row can consume
 * them. All money written by this path is whole yuan and final consumption is
 * executed in the same transaction as the shared cashier V3 sale.
 */
final class MemberCommerceBenefitsServices
{
    public const TABLE = 'member_commerce_benefit';

    /**
     * Apply one member coupon and the configured integral deduction to the
     * server-owned sale lines. The returned amount is the only payable amount
     * accepted by MemberCommerceServices.
     *
     * @return array<string,mixed>
     */
    public function applyInTx(int $uid, int $storeId, array $built, array $input, bool $lock): array
    {
        if (($built['kind'] ?? '') !== 'purchase') {
            return $built + $this->emptyQuote((int)($built['amountCents'] ?? 0));
        }
        $gross = (int)$built['amountCents'];
        $couponOptions = $this->couponOptions($uid, $storeId, (array)$built['benefitLines'], $lock);
        $couponId = max(0, (int)($input['coupon_id'] ?? 0));
        $selected = null;
        foreach ($couponOptions as $option) {
            if ((int)$option['id'] === $couponId) {
                $selected = $option;
                break;
            }
        }
        if ($couponId > 0 && $selected === null) {
            throw new ValidateException('所选优惠券已失效或不适用于当前商品和门店。');
        }

        $couponCents = (int)($selected['discountCents'] ?? 0);
        $remainingAfterCoupon = max(0, $gross - $couponCents);
        $integralSaleCap = $selected !== null && (int)($selected['useMode'] ?? 0) === StoreCouponOrderRule::ORDER
            ? max(0, $gross - (int)($selected['minimumCents'] ?? 0))
            : $remainingAfterCoupon;
        $integral = $this->integralQuote($uid, $remainingAfterCoupon,
            !empty($input['use_integral']), $integralSaleCap);
        $integralCents = (int)$integral['discountCents'];
        $lines = (array)$built['lines'];

        // Integral is a price discount, while a coupon remains a non-cash
        // tender. Keep enough sale value on the selected line to settle the
        // coupon, then distribute the whole-yuan integral discount stably.
        $couponLine = $selected === null ? -1 : (int)$selected['lineIndex'];
        $left = $integralCents;
        $integralLineDiscounts = [];
        foreach ($lines as $index => &$line) {
            if ($index === $couponLine) continue;
            $available = (int)$line['lineAmountCents'];
            $take = min($available, $left);
            $line['lineAmountCents'] = $available - $take;
            if ($take > 0) $integralLineDiscounts[(int)$index] = $take;
            $left -= $take;
            if ($left === 0) break;
        }
        unset($line);
        if ($left > 0 && $couponLine >= 0) {
            $available = max(0, (int)$lines[$couponLine]['lineAmountCents'] - $couponCents);
            $take = min($available, $left);
            $lines[$couponLine]['lineAmountCents'] -= $take;
            if ($take > 0) $integralLineDiscounts[$couponLine] = $take;
            $left -= $take;
        }
        if ($left !== 0) throw new ValidateException('积分抵扣金额超过当前可支付金额。');
        if ($selected !== null) {
            $lines[$couponLine]['lineAmountCents'] -= $couponCents;
            $lines[$couponLine]['couponUserId'] = (int)$selected['id'];
            $lines[$couponLine]['couponNameSnapshot'] = (string)$selected['name'];
            $lines[$couponLine]['couponDiscountCents'] = $couponCents;
            if ((int)($selected['useMode'] ?? 0) === StoreCouponOrderRule::ORDER) {
                $lines[$couponLine]['couponUserIds'] = [(int)$selected['id']];
            }
        }

        $payable = $gross - $couponCents - $integralCents;
        $benefitLines = array_column((array)($built['benefitLines'] ?? []), null, 'index');
        $integralAllocations = [];
        foreach ($integralLineDiscounts as $index => $discountCents) {
            $source = (array)($benefitLines[$index] ?? []);
            $integralAllocations[] = [
                'lineIndex' => (int)$index,
                'productId' => (int)($source['productId'] ?? 0),
                'catalogSkuId' => (int)($lines[$index]['itemId'] ?? 0),
                'discountCents' => (int)$discountCents,
            ];
        }
        $selection = [
            'couponUserId' => $couponId,
            'couponDiscountCents' => $couponCents,
            'integralUsed' => (int)$integral['used'],
            'integralDiscountCents' => $integralCents,
            // Keep the exact product-line allocation even though the cashier
            // UI does not expose an integral entry in this round.
            'integralAllocations' => $integralAllocations,
            'couponFingerprint' => $selected['fingerprint'] ?? '',
        ];
        $built['lines'] = $lines;
        $built['grossAmountCents'] = $gross;
        $built['amountCents'] = $payable;
        $built['amount'] = $payable;
        $built['benefitSelection'] = $selection;
        $built['benefitQuote'] = [
            'coupons' => array_map(static function (array $row): array {
                unset($row['lineIndex'], $row['fingerprint']);
                return $row;
            }, $couponOptions),
            'selectedCouponId' => $couponId,
            'couponDiscountCents' => $couponCents,
            'integralEnabled' => (bool)$integral['enabled'],
            'integralAvailable' => (int)$integral['available'],
            'integralMaxUsable' => (int)$integral['maxUsable'],
            'integralMaxDiscountCents' => (int)$integral['maxDiscountCents'],
            'integralUsed' => (int)$integral['used'],
            'integralDiscountCents' => $integralCents,
            'useIntegral' => !empty($input['use_integral']),
            'grossAmountCents' => $gross,
            'payableAmountCents' => $payable,
        ];
        return $built;
    }

    /** Reserve selected assets after the commerce order identity exists. */
    public function reserveInTx(int $commerceId, int $uid, array $selection): void
    {
        $couponId = (int)($selection['couponUserId'] ?? 0);
        $integral = (int)($selection['integralUsed'] ?? 0);
        if ($couponId === 0 && $integral === 0) return;
        if ($couponId > 0 && Db::name(self::TABLE)->where('coupon_user_id', $couponId)
            ->where('status', 'reserved')->lock(true)->find()) {
            throw new ValidateException('优惠券已被其他待支付订单占用。');
        }
        $row = Db::name('user')->where('uid', $uid)->lock(true)->find();
        if (!$row || $integral > max(0, (int)$row['integral'] - $this->heldIntegral($uid))) {
            throw new ValidateException('可用积分已经变化，请刷新后重试。');
        }
        $now = time();
        Db::name(self::TABLE)->insert([
            'commerce_id' => $commerceId, 'member_id' => $uid,
            'coupon_user_id' => $couponId,
            'coupon_cents' => (int)($selection['couponDiscountCents'] ?? 0),
            'integral_used' => $integral,
            'integral_discount_cents' => (int)($selection['integralDiscountCents'] ?? 0),
            'status' => 'reserved', 'sales_order_id' => '',
            'snapshot_json' => json_encode($selection, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => $now, 'updated_at' => $now, 'refunded_at' => 0,
        ]);
    }

    /** Release assets only while no terminal settlement has consumed them. */
    public function releaseInTx(int $commerceId): void
    {
        Db::name(self::TABLE)->where('commerce_id', $commerceId)->where('status', 'reserved')
            ->update(['status' => 'released', 'updated_at' => time()]);
    }

    /**
     * Deduct points after V3 has consumed the coupon. The outer transaction
     * rolls both actions back together if any assertion fails.
     */
    public function consumeInTx(int $commerceId, int $uid, string $salesOrderId): void
    {
        $benefit = Db::name(self::TABLE)->where('commerce_id', $commerceId)->lock(true)->find();
        if (!$benefit) return;
        if ((string)$benefit['status'] === 'consumed'
            && hash_equals((string)$benefit['sales_order_id'], $salesOrderId)) return;
        if ((string)$benefit['status'] !== 'reserved' || (int)$benefit['member_id'] !== $uid) {
            throw new ValidateException('优惠与积分占用状态已变化，请人工核对订单。');
        }
        $couponId = (int)$benefit['coupon_user_id'];
        if ($couponId > 0) {
            $coupon = Db::name('store_coupon_user')->where('id', $couponId)->lock(true)->find();
            if (!$coupon || (int)$coupon['uid'] !== $uid || (int)$coupon['status'] !== 1 || (int)$coupon['use_time'] <= 0) {
                throw new ValidateException('优惠券未在销售订单中完成核销。');
            }
        }
        $used = (int)$benefit['integral_used'];
        if ($used > 0) {
            $member = Db::name('user')->where('uid', $uid)->lock(true)->find();
            $memberIntegral = (int)($member['integral'] ?? 0);
            $ledgerAvailable = $member ? (int)app()->make(UserBillServices::class)
                ->countIntegralBalance($memberIntegral, $uid) : 0;
            // Exclude this order's own reservation but retain every other
            // pending order hold when the provider callback arrives later.
            $otherHeld = max(0, $this->heldIntegral($uid) - $used);
            if (!$member || min($memberIntegral, $ledgerAvailable) - $otherHeld < $used) {
                throw new ValidateException('会员积分不足，结算已回滚。');
            }
            $balance = (int)$member['integral'] - $used;
            if ((int)Db::name('user')->where('uid', $uid)->where('integral', (int)$member['integral'])
                ->update(['integral' => $balance]) !== 1) {
                throw new ValidateException('会员积分状态已变化，结算已回滚。');
            }
            app()->make(UserBillServices::class)->income('deduction', $uid, [
                'number' => $used,
                'deductionPrice' => MemberCommerceServices::money((int)$benefit['integral_discount_cents']),
            ], $balance, $commerceId, 0, '会员自助订单积分抵扣');
        }
        $snapshot = json_decode((string)$benefit['snapshot_json'], true);
        if (!is_array($snapshot)) throw new ValidateException('优惠与积分明细快照异常，结算已回滚。');
        $snapshot['integralAllocations'] = $this->bindSalesOrderLines(
            (array)($snapshot['integralAllocations'] ?? []),
            $salesOrderId
        );
        $changed = Db::name(self::TABLE)->where('id', (int)$benefit['id'])->where('status', 'reserved')
            ->update(['status' => 'consumed', 'sales_order_id' => $salesOrderId,
                'snapshot_json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => time()]);
        if ((int)$changed !== 1) throw new ValidateException('优惠与积分结算状态写入失败。');
    }

    /** Lock the member-commerce integral fact that belongs to a full V3 void. */
    public function prepareVoidInTx(string $salesOrderId, int $memberId): array
    {
        $row = Db::name(self::TABLE)->where('sales_order_id', $salesOrderId)
            ->where('status', 'consumed')->lock(true)->find();
        if (!$row) return [];
        if ((int)$row['member_id'] !== $memberId) throw new ValidateException('会员积分抵扣归属异常，不能作废。');
        return $row;
    }

    /** Restore consumed points once during a full sale void. */
    public function restoreVoidInTx(array $benefit, string $salesOrderId, int $memberId): void
    {
        $used = (int)($benefit['integral_used'] ?? 0);
        if (!$benefit) return;
        if ($used > 0) {
            $member = Db::name('user')->where('uid', $memberId)->lock(true)->find();
            if (!$member) throw new ValidateException('会员不存在，积分无法退回。');
            $balance = (int)$member['integral'] + $used;
            Db::name('user')->where('uid', $memberId)->update(['integral' => $balance]);
            app()->make(UserBillServices::class)->income('pay_product_integral_back', $memberId, $used,
                $balance, (int)$benefit['commerce_id'], 0, '会员自助订单作废退回积分');
        }
        // Coupon restoration is owned by cashier V3. This row still advances
        // for coupon-only orders so the cross-system audit cannot remain paid.
        $changed = Db::name(self::TABLE)->where('id', (int)$benefit['id'])
            ->where('status', 'consumed')->where('sales_order_id', $salesOrderId)
            ->update(['status' => 'refunded', 'refunded_at' => time(), 'updated_at' => time()]);
        if ((int)$changed !== 1) throw new ValidateException('会员积分退回状态写入失败。');
    }

    private function couponOptions(int $uid, int $storeId, array $lines, bool $lock): array
    {
        $now = time();
        $query = Db::name('store_coupon_user')->alias('cu')->leftJoin('store_coupon_issue ci', 'ci.id=cu.cid')
            ->where('cu.uid', $uid)->where('cu.status', 0)->where('cu.is_fail', 0)->where('cu.use_time', 0)
            ->where(function ($q) use ($now) { $q->where('cu.start_time', 0)->whereOr('cu.start_time', '<=', $now); })
            ->where(function ($q) use ($now) { $q->where('cu.end_time', 0)->whereOr('cu.end_time', '>=', $now); })
            ->where('ci.category', 1)
            ->field('cu.id,cu.cid,cu.coupon_title,cu.coupon_price,cu.use_min_price,cu.end_time,'
                . 'ci.coupon_type,ci.top_discount_price,ci.type as applicable_scope,ci.product_id,ci.category_id,'
                . 'ci.brand_id,ci.applicable_type,ci.applicable_store_id,ci.coupon_issue_type,ci.relation_id,'
                . 'ci.use_mode,ci.order_tiers_json');
        if ($lock) $query->lock(true);
        $rows = $query->order('cu.end_time asc,cu.id asc')->select()->toArray();
        $reserved = array_fill_keys(array_map('intval', Db::name(self::TABLE)
            ->where('coupon_user_id', '>', 0)->where('status', 'reserved')->column('coupon_user_id')), true);
        $result = [];
        foreach ($rows as $row) {
            if (isset($reserved[(int)$row['id']]) || !$this->allowsStore($row, $storeId)) continue;
            $eligible = $this->eligibleLines($row, $lines);
            if (!$eligible) continue;
            $eligibleTotal = array_sum(array_map(static fn(array $line): int => (int)$line['amountCents'], $eligible));
            if ((int)$row['use_mode'] === StoreCouponOrderRule::ORDER) {
                $tiers = StoreCouponOrderRule::fromStored((string)$row['order_tiers_json']);
                if (StoreCouponOrderRule::maxCount($tiers, $eligibleTotal) < 1) continue;
                $discount = self::wholeYuan(self::moneyCents($row['coupon_price']));
                $minimum = (int)$tiers[0]['min_amount_cents'];
            } else {
                $minimum = self::moneyCents($row['use_min_price']);
                if ($eligibleTotal < $minimum) continue;
                $discount = self::moneyCents($row['coupon_price']);
                if ((int)$row['coupon_type'] === 2) {
                    $rate = self::percentHundredths($row['coupon_price']);
                    $discount = intdiv($eligibleTotal * (10000 - $rate), 10000);
                    $top = self::moneyCents($row['top_discount_price']);
                    if ($top > 0) $discount = min($discount, $top);
                }
                $discount = self::wholeYuan($discount);
            }
            if ($discount <= 0) continue;
            usort($eligible, static fn(array $a, array $b): int => (int)$b['amountCents'] <=> (int)$a['amountCents']);
            $target = null;
            foreach ($eligible as $line) {
                if ((int)$line['amountCents'] >= $discount
                    && ((int)$row['use_mode'] === StoreCouponOrderRule::ORDER
                        || (int)$line['amountCents'] >= self::moneyCents($row['use_min_price']))) {
                    $target = $line;
                    break;
                }
            }
            if ($target === null) continue;
            $fingerprint = hash('sha256', json_encode([$row['id'], $row['cid'], $row['coupon_title'],
                $discount, $storeId, $target['index']], JSON_UNESCAPED_UNICODE));
            $result[] = ['id' => (int)$row['id'], 'name' => trim((string)$row['coupon_title']) ?: '优惠券',
                'discountCents' => $discount, 'expiresAt' => (int)$row['end_time'],
                'useMode' => (int)$row['use_mode'], 'minimumCents' => $minimum,
                'lineIndex' => (int)$target['index'], 'fingerprint' => $fingerprint];
        }
        return $result;
    }

    private function eligibleLines(array $coupon, array $lines): array
    {
        $scope = (int)$coupon['applicable_scope'];
        if ($scope === 0) return $lines;
        if ($scope === 2) {
            $ids = self::idList((string)$coupon['product_id']);
            return array_values(array_filter($lines, static fn(array $line): bool =>
                in_array((int)$line['productId'], $ids, true) || in_array((int)$line['masterProductId'], $ids, true)));
        }
        if ($scope === 1) {
            $service = app()->make(StoreProductCategoryServices::class);
            $ids = array_map('intval', array_column($service->getAllById((int)$coupon['category_id'], 'id'), 'id'));
            return array_values(array_filter($lines, static fn(array $line): bool =>
                (bool)array_intersect($ids, array_map('intval', (array)$line['categoryIds']))));
        }
        if ($scope === 3) {
            $service = app()->make(StoreBrandServices::class);
            $ids = array_map('intval', array_column($service->getAllById((int)$coupon['brand_id'], 'id'), 'id'));
            return array_values(array_filter($lines, static fn(array $line): bool =>
                in_array((int)$line['brandId'], $ids, true)));
        }
        return [];
    }

    private function integralQuote(int $uid, int $payableCents, bool $use, ?int $saleDiscountCap = null): array
    {
        $enabled = (bool)sys_config('integral_ratio_status', 1);
        $memberIntegral = (int)Db::name('user')->where('uid', $uid)->value('integral');
        $ledgerAvailable = (int)app()->make(UserBillServices::class)
            ->countIntegralBalance($memberIntegral, $uid);
        $available = max(0, min($memberIntegral, $ledgerAvailable) - $this->heldIntegral($uid));
        $ratioCents = self::moneyCents(sys_config('integral_ratio', 0));
        if (!$enabled || $ratioCents <= 0 || $payableCents <= 0) {
            return ['enabled' => false, 'available' => $available, 'maxUsable' => 0,
                'maxDiscountCents' => 0, 'used' => 0, 'discountCents' => 0];
        }
        $pointCap = $available;
        if ((int)sys_config('integral_max_type', 1) === 1) {
            $configured = max(0, (int)sys_config('integral_max_num', 200));
            if ($configured > 0) $pointCap = min($pointCap, $configured);
            $moneyCap = $payableCents;
        } else {
            $rate = max(0, min(100, (int)sys_config('integral_max_rate', 0)));
            $moneyCap = $rate > 0 ? self::wholeYuan(intdiv($payableCents * $rate, 100)) : $payableCents;
        }
        if ($saleDiscountCap !== null) $moneyCap = min($moneyCap, max(0, $saleDiscountCap));
        $pointCap = min($pointCap, intdiv($moneyCap, $ratioCents));
        $step = intdiv(100, self::gcd(100, $ratioCents));
        $maxUsable = intdiv($pointCap, $step) * $step;
        $maxDiscount = min($payableCents, $maxUsable * $ratioCents);
        return ['enabled' => true, 'available' => $available, 'maxUsable' => $maxUsable,
            'maxDiscountCents' => $maxDiscount, 'used' => $use ? $maxUsable : 0,
            'discountCents' => $use ? $maxDiscount : 0];
    }

    private function heldIntegral(int $uid): int
    {
        return (int)Db::name(self::TABLE)->where('member_id', $uid)
            ->where('status', 'reserved')->sum('integral_used');
    }

    /**
     * Bind the member-side allocation to immutable V3 sale lines. Line order is
     * frozen by direct snapshot settlement, so future store views can consume
     * the recorded fact without reopening an integral editing entry.
     */
    private function bindSalesOrderLines(array $allocations, string $salesOrderId): array
    {
        if (!$allocations) return [];
        $saleLines = Db::name('cashier_v3_sales_order_line')->where('order_id', $salesOrderId)
            ->where('line_status', 'settled')->where('line_direction', 'forward')
            ->order('line_no asc')->field('order_line_id,checkout_line_id,line_no,item_id')->select()->toArray();
        foreach ($allocations as &$allocation) {
            $index = (int)($allocation['lineIndex'] ?? -1);
            if ($index < 0 || !isset($saleLines[$index])) {
                throw new ValidateException('积分抵扣商品明细无法对应销售单，结算已回滚。');
            }
            $saleLine = $saleLines[$index];
            $allocation['salesOrderLineId'] = (string)$saleLine['order_line_id'];
            $allocation['checkoutLineId'] = (string)$saleLine['checkout_line_id'];
            $allocation['salesOrderLineNo'] = (int)$saleLine['line_no'];
            $allocation['salesOrderItemId'] = (int)$saleLine['item_id'];
        }
        unset($allocation);
        return $allocations;
    }

    private function allowsStore(array $row, int $storeId): bool
    {
        if ((int)$row['coupon_issue_type'] === 1 && (int)$row['relation_id'] !== $storeId) return false;
        if ((int)$row['applicable_type'] === 0) return false;
        if ((int)$row['applicable_type'] !== 2) return true;
        return in_array($storeId, self::idList((string)$row['applicable_store_id']), true);
    }

    private function emptyQuote(int $amount): array
    {
        return ['grossAmountCents' => $amount, 'benefitSelection' => [], 'benefitQuote' => [
            'coupons' => [], 'selectedCouponId' => 0, 'couponDiscountCents' => 0,
            'integralEnabled' => false, 'integralAvailable' => 0, 'integralMaxUsable' => 0,
            'integralMaxDiscountCents' => 0, 'integralUsed' => 0, 'integralDiscountCents' => 0,
            'useIntegral' => false, 'grossAmountCents' => $amount, 'payableAmountCents' => $amount,
        ]];
    }

    private static function idList(string $raw): array
    {
        $decoded = json_decode($raw, true);
        $values = is_array($decoded) ? $decoded : preg_split('/\s*,\s*/', trim($raw, "[] \t\n\r\0\x0B"));
        return array_values(array_unique(array_filter(array_map('intval', $values ?: []))));
    }

    private static function moneyCents($value): int
    {
        $raw = trim((string)$value);
        if (preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]{1,2})(?:0{0,4})?)?$/D', $raw, $match) !== 1) {
            throw new ValidateException('优惠或积分金额配置无效。');
        }
        return (int)$match[1] * 100 + (int)str_pad((string)($match[2] ?? ''), 2, '0');
    }

    private static function percentHundredths($value): int
    {
        $cents = self::moneyCents($value);
        if ($cents > 10000) throw new ValidateException('折扣券比例配置无效。');
        return $cents;
    }

    private static function wholeYuan(int $cents): int { return intdiv(max(0, $cents), 100) * 100; }
    private static function gcd(int $a, int $b): int
    {
        while ($b !== 0) { $next = $a % $b; $a = $b; $b = $next; }
        return max(1, $a);
    }
}
