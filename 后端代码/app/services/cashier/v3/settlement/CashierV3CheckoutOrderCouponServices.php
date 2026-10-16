<?php
declare(strict_types=1);

namespace app\services\cashier\v3\settlement;

use app\services\activity\coupon\StoreCouponOrderRule;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\order\settlement\CashierV3SalesOrderPlanV1;
use think\facade\Db;

/**
 * Order thresholds qualify coupon instances across the whole checkout.
 * Each full face amount is nonetheless bound to exactly one chosen sale line.
 * Browser hints never authorize consumption; final submit locks and rechecks.
 */
final class CashierV3CheckoutOrderCouponServices
{
    public const TABLE = 'cashier_v3_checkout_order_coupon';

    /**
     * Rebuild the candidate binding from the same sale lines that enter the
     * settlement kernel. Reject mixed modes and stale face amounts before a
     * checkout request can freeze them.
     *
     * @param array<int,array<string,mixed>> $saleLines
     * @return array<int,array<string,mixed>>
     */
    public function validateSnapshotInTx(array &$saleLines, int $memberId, int $storeId): array
    {
        CashierV3TransactionGuard::assertInTransaction('orderCoupon.snapshot');
        $selections = [];
        $receivable = 0;
        $detailCouponExists = false;
        foreach ($saleLines as $index => &$line) {
            $ids = $line['couponUserIds'] ?? [];
            if (!is_array($ids) || count($ids) > 100) throw self::failure('order_coupon_ids_invalid');
            $face = (int)($line['couponDiscountCents'] ?? 0);
            $net = (int)($line['lineAmountCents'] ?? -1);
            $debt = (int)($line['debtAmountCents'] ?? 0);
            $base = $net + $face;
            if ($net < 0 || $face < 0 || $debt < 0 || $debt > $base) {
                throw self::failure('order_coupon_line_amount_invalid');
            }
            $receivable += $base - $debt;
            if (!$ids) {
                $detailCouponExists = $detailCouponExists || (int)($line['couponUserId'] ?? 0) > 0;
                continue;
            }
            if ($memberId <= 0 || $face <= 0 || $face > $base - $debt
                || (int)($line['couponUserId'] ?? 0) !== (int)$ids[0]) {
                throw self::failure('order_coupon_line_capacity_invalid');
            }
            foreach ($ids as $id) {
                if (!is_int($id) && (!is_string($id) || preg_match('/^[1-9][0-9]*$/D', $id) !== 1)) {
                    throw self::failure('order_coupon_ids_invalid');
                }
                $id = (int)$id;
                if ($id <= 0 || isset($selections[$id])) throw self::failure('order_coupon_duplicate');
                $selections[$id] = ['lineId' => (string)($line['lineId'] ?? ''), 'couponUserId' => $id,
                    'lineIndex' => $index];
            }
        }
        unset($line);
        if (!$selections) return [];
        if ($detailCouponExists) throw self::failure('order_coupon_detail_mixed');

        $issueId = 0;
        $totals = [];
        $now = time();
        foreach ($selections as $id => &$selection) {
            $coupon = $this->lockCoupon($id, $memberId, $storeId, $now);
            if ((int)$coupon['use_mode'] !== StoreCouponOrderRule::ORDER
                || (int)$coupon['category'] !== 1 || (int)$coupon['type'] !== 0
                || (int)$coupon['coupon_type'] !== 1) throw self::failure('order_coupon_rule_invalid');
            if ($issueId !== 0 && $issueId !== (int)$coupon['cid']) throw self::failure('order_coupon_issue_mixed');
            $issueId = (int)$coupon['cid'];
            $selection['couponIssueId'] = $issueId;
            $selection['amountCents'] = self::moneyCents($coupon['coupon_price'] ?? '0');
            $selection['name'] = trim((string)$coupon['coupon_title']);
            $selection['tiersJson'] = (string)$coupon['order_tiers_json'];
            $lineIndex = $selection['lineIndex'];
            $totals[$lineIndex] = (int)($totals[$lineIndex] ?? 0) + $selection['amountCents'];
            if ($selection['amountCents'] <= 0) throw self::failure('order_coupon_face_invalid');
        }
        unset($selection);
        $firstSelection = reset($selections);
        $tiers = StoreCouponOrderRule::fromStored((string)$firstSelection['tiersJson']);
        if (count($selections) > StoreCouponOrderRule::maxCount($tiers, $receivable)) {
            throw self::failure('order_coupon_tier_limit');
        }
        foreach ($totals as $index => $amount) {
            if ($amount !== (int)$saleLines[$index]['couponDiscountCents']) {
                throw self::failure('order_coupon_face_changed');
            }
            foreach ($selections as $selection) {
                if ($selection['lineIndex'] === $index) {
                    $saleLines[$index]['couponNameSnapshot'] = (string)$selection['name'];
                    break;
                }
            }
        }
        return array_values($selections);
    }

    /** The request line ID is server-created from the validated authority key. */
    public function bindPreparedInTx(array $selections, string $requestId, int $version,
        string $tenantId, int $storeId, int $memberId): void
    {
        CashierV3TransactionGuard::assertInTransaction('orderCoupon.bind');
        $existing = Db::name(self::TABLE)->where('request_id', $requestId)->lock(true)->select()->toArray();
        foreach ($existing as $row) {
            if ((string)$row['status'] !== 'editing') throw self::failure('order_coupon_request_terminal');
        }
        if ($existing) Db::name(self::TABLE)->where('request_id', $requestId)->delete();
        foreach ($selections as $selection) {
            $line = Db::name('cashier_v3_checkout_line_draft')->where('request_id', $requestId)
                ->where('authority_key', 'sale:' . $selection['lineId'])->where('line_role', 'sale')->find();
            if (!$line || (int)$line['draft_version'] !== $version) throw self::failure('order_coupon_request_line_missing');
            Db::name(self::TABLE)->insert([
                'tenant_id' => $tenantId, 'store_id' => $storeId, 'member_id' => $memberId,
                'request_id' => $requestId, 'request_version' => $version,
                'checkout_line_id' => (string)$line['line_id'],
                'coupon_user_id' => $selection['couponUserId'],
                'coupon_issue_id' => $selection['couponIssueId'],
                'amount_cents' => $selection['amountCents'], 'status' => 'editing',
                'order_id' => '', 'order_line_id' => '', 'created_at' => time(),
            ]);
        }
    }

    /**
     * Final sale and all coupon statuses change in the caller's transaction.
     * The first coupon remains in the legacy line identity for existing sale
     * facts; this table is the queryable authority for every coupon instance.
     */
    public function consumeInTx(CashierV3SalesOrderPlanV1 $plan, int $now): array
    {
        CashierV3TransactionGuard::assertInTransaction('orderCoupon.consume');
        $header = $plan->header();
        $rows = Db::name(self::TABLE)->where('request_id', (string)$header['checkout_request_id'])
            ->where('status', 'editing')->order('id', 'asc')->lock(true)->select()->toArray();
        if (!$rows) return ['consumedCount' => 0, 'coupons' => [], 'lineIds' => []];
        $lines = [];
        $receivable = 0;
        foreach ($plan->lines() as $line) {
            $lines[(string)$line['checkout_line_id']] = $line;
            $receivable += (int)$line['sale_amount_cents'] - (int)$line['debt_amount_cents'];
        }
        $issueId = 0;
        $lineTotals = [];
        $lineFirst = [];
        $consumed = [];
        foreach ($rows as $row) {
            $lineId = (string)$row['checkout_line_id'];
            if (!isset($lines[$lineId])) throw self::failure('order_coupon_binding_line_missing');
            // Payment selection advances the request version after the sale
            // lines and coupon bindings were frozen. The binding may therefore
            // be older, while every final line amount and coupon identity is
            // still checked below against the locked sale plan.
            if ((int)$row['request_version'] > (int)$header['checkout_request_version']) {
                throw self::failure('order_coupon_binding_version_changed');
            }
            if ((int)$row['member_id'] !== (int)$header['member_id']) {
                throw self::failure('order_coupon_binding_member_changed');
            }
            if ((int)$row['store_id'] !== (int)$header['store_id']) {
                throw self::failure('order_coupon_binding_store_changed');
            }
            $coupon = $this->lockCoupon((int)$row['coupon_user_id'], (int)$header['member_id'],
                (int)$header['store_id'], $now);
            if ((int)$coupon['use_mode'] !== StoreCouponOrderRule::ORDER
                || (int)$coupon['category'] !== 1 || (int)$coupon['type'] !== 0
                || (int)$coupon['coupon_type'] !== 1
                || (int)$coupon['cid'] !== (int)$row['coupon_issue_id']
                || self::moneyCents($coupon['coupon_price']) !== (int)$row['amount_cents']) {
                throw self::failure('order_coupon_frozen_authority_changed');
            }
            if ($issueId && $issueId !== (int)$coupon['cid']) throw self::failure('order_coupon_issue_mixed');
            $issueId = (int)$coupon['cid'];
            $lineTotals[$lineId] = (int)($lineTotals[$lineId] ?? 0) + (int)$row['amount_cents'];
            if (!isset($lineFirst[$lineId])) $lineFirst[$lineId] = (int)$row['coupon_user_id'];
            $consumed[] = ['couponUserId' => (int)$row['coupon_user_id'],
                'couponDiscountCents' => (int)$row['amount_cents'],
                'salesOrderId' => (string)$header['order_id'],
                'salesOrderLineId' => (string)$lines[$lineId]['order_line_id']];
        }
        $tiers = StoreCouponOrderRule::fromStored((string)$coupon['order_tiers_json']);
        if (count($rows) > StoreCouponOrderRule::maxCount($tiers, $receivable)) {
            throw self::failure('order_coupon_tier_limit');
        }
        foreach ($lineTotals as $lineId => $amount) {
            $line = $lines[$lineId];
            if ($amount !== (int)$line['coupon_payment_cents']
                || $lineFirst[$lineId] !== (int)$line['coupon_user_id']
                || $amount > (int)$line['sale_amount_cents'] - (int)$line['debt_amount_cents']) {
                throw self::failure('order_coupon_line_amount_invalid');
            }
        }
        foreach ($rows as $row) {
            $updated = Db::name('store_coupon_user')->where('id', (int)$row['coupon_user_id'])
                ->where('uid', (int)$header['member_id'])->where('status', 0)
                ->where('is_fail', 0)->where('use_time', 0)
                ->update(['status' => 1, 'use_time' => $now]);
            if ((int)$updated !== 1) throw self::failure('order_coupon_consume_race');
            $line = $lines[(string)$row['checkout_line_id']];
            Db::name(self::TABLE)->where('id', (int)$row['id'])->where('status', 'editing')->update([
                'status' => 'consumed', 'order_id' => (string)$header['order_id'],
                'order_line_id' => (string)$line['order_line_id'], 'settled_at' => $now,
            ]);
        }
        return ['consumedCount' => count($rows), 'coupons' => $consumed,
            'lineIds' => array_keys($lineTotals)];
    }

    private function lockCoupon(int $id, int $memberId, int $storeId, int $now): array
    {
        $row = (array)Db::name('store_coupon_user')->alias('cu')
            ->leftJoin('store_coupon_issue ci', 'ci.id=cu.cid')->where('cu.id', $id)
            ->field('cu.id,cu.uid,cu.cid,cu.coupon_title,cu.coupon_price,cu.status,cu.is_fail,cu.use_time,cu.start_time,cu.end_time,ci.category,ci.type,ci.coupon_type,ci.use_mode,ci.order_tiers_json,ci.coupon_issue_type,ci.relation_id,ci.applicable_type,ci.applicable_store_id')
            ->lock(true)->find();
        if (!$row || (int)$row['uid'] !== $memberId || (int)$row['status'] !== 0
            || (int)$row['is_fail'] !== 0 || (int)$row['use_time'] !== 0
            || (int)$row['start_time'] > $now
            || ((int)$row['end_time'] > 0 && (int)$row['end_time'] < $now)
            || !$this->allowsStore($row, $storeId)) throw self::failure('order_coupon_unavailable');
        return $row;
    }

    private function allowsStore(array $row, int $storeId): bool
    {
        if ((int)$row['coupon_issue_type'] === 1 && (int)$row['relation_id'] !== $storeId) return false;
        if ((int)$row['applicable_type'] === 0) return false;
        if ((int)$row['applicable_type'] !== 2) return true;
        $raw = trim((string)$row['applicable_store_id']);
        $decoded = json_decode($raw, true);
        // Existing issuance rows also store selected shops as comma text.
        // Checkout must accept exactly the same scope as the selector.
        $ids = is_array($decoded) ? $decoded
            : preg_split('/\s*,\s*/', trim($raw, "[] \t\n\r\0\x0B"));
        return in_array($storeId, array_map('intval', $ids ?: []), true);
    }

    private static function moneyCents($raw): int
    {
        $raw = trim((string)$raw);
        if (preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]{1,2})(?:0{0,4})?)?$/D', $raw, $match) !== 1) {
            throw self::failure('order_coupon_money_invalid');
        }
        return (int)$match[1] * 100 + (int)str_pad((string)($match[2] ?? ''), 2, '0');
    }

    private static function failure(string $reason): CashierV3CommandException
    {
        return new CashierV3CommandException(CashierV3ResultCode::RESOURCE_VERSION_CONFLICT,
            '整单门槛券状态或订单金额已变化，请重新选券后结账。',
            CashierV3ResultCode::STATUS_CONFLICT, ['reason' => $reason]);
    }
}
