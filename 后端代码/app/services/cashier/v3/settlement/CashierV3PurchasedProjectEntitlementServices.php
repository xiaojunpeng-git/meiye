<?php
declare(strict_types=1);

namespace app\services\cashier\v3\settlement;

use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\order\settlement\CashierV3SalesOrderPlanV1;
use think\facade\Db;

/**
 * Issues a standalone project's unused right through the existing holder/detail
 * authority consumed by store redemption. Carriers are technical projections of
 * the V3 sale, never an additional sale or an already completed service.
 */
final class CashierV3PurchasedProjectEntitlementServices
{
    /** Called only after final catalog locks; inserts no second sale or collection. */
    public function issueInTx(CashierV3SalesOrderPlanV1 $plan, int $now): array
    {
        CashierV3TransactionGuard::assertInTransaction('memberProjectRights');
        $header = $plan->header();
        $memberPurchase = ($header['source_document_type'] ?? '') === 'member_checkout';
        $result = [];
        foreach ($plan->lines() as $line) {
            if ($line['item_type'] !== 'project') continue;
            CashierV3ProjectPurchasePolicy::assertMember((int)$header['member_id']);
            $technicalNo = 'v3p' . substr(hash('sha256', $line['order_line_id']), 0, 28);
            $existing = Db::name('store_order')->where('order_id', $technicalNo)->lock(true)->find();
            if ($existing) {
                if ((int)$existing['uid'] !== (int)$header['member_id']) throw new \RuntimeException('Member right replay mismatch');
                $result[] = (int)$existing['id'];
                continue;
            }
            $sku = Db::name('store_product_attr_value')->where('id', $line['catalog_sku_id'])->lock(true)->find();
            $times = (int)$line['quantity'] * max(1, (int)($sku['write_times'] ?? 1));
            $start = $now;
            $end = 0;
            if ((int)($sku['write_valid'] ?? 1) === 2) $end = $now + max(0, (int)$sku['write_days']) * 86400;
            if ((int)($sku['write_valid'] ?? 1) === 3) {
                $start = (int)$sku['write_start'];
                $end = (int)$sku['write_end'];
            }
            $sale = (int)$line['sale_amount_cents'];
            $name = (string)$line['item_name_snapshot'];
            $member = Db::name('user')->where('uid', $header['member_id'])->field('real_name,nickname,phone')->find();
            $oid = (int)Db::name('store_order')->insertGetId([
                'order_id' => $technicalNo, 'uid' => (int)$header['member_id'], 'store_id' => (int)$header['store_id'],
                'type' => 12, 'product_type' => 6, 'paid' => 1, 'status' => 0, 'pay_type' => 'cashier_v3',
                'real_name' => (string)($member['real_name'] ?: $member['nickname']), 'user_phone' => $member['phone'],
                'total_num' => (int)$line['quantity'], 'total_price' => self::money((int)$line['original_amount_cents']),
                'pay_price' => self::money($sale), 'settle_price' => self::money($sale),
                'cash_pay_price' => '0.00', 'yue_pay_price' => '0.00', 'shipping_type' => 2,
                'pay_time' => $now, 'add_time' => $now, 'unique' => md5($technicalNo),
                'channel_type' => $memberPurchase ? 'member_v3' : 'cashier_v3', 'mark' => '已购项目待用权益', 'sales_handled' => 1,
                'verify_code' => substr(hash('sha256', $technicalNo), 0, 12),
                'custom_form' => '[[]]', 'remark' => 'V3 sales order ' . $header['order_no']]);
            $snapshot = ['sourceType' => $memberPurchase ? 'member_v3_project_purchase' : 'cashier_v3_project_purchase', 'salesOrderId' => $header['order_id'],
                'salesOrderLineId' => $line['order_line_id'], 'product_id' => (int)$line['item_id'],
                'product_type' => 6, 'product_attr_unique' => (string)$sku['unique'], 'write_times' => $times,
                'writeoff_amount_cents' => $sale, 'pay_price' => self::money($sale),
                'productInfo' => ['id' => (int)$line['item_id'], 'store_name' => $name, 'product_type' => 6,
                    'attrInfo' => ['unique' => (string)$sku['unique']]]];
            $detailId = (int)Db::name('store_order_cart_info')->insertGetId([
                'uid' => (int)$header['member_id'], 'oid' => $oid, 'cart_id' => $technicalNo,
                'cart_type' => 2, 'type' => 1, 'relation_id' => (int)$header['store_id'],
                'product_id' => (int)$line['item_id'], 'product_type' => 6, 'sku_unique' => (string)$sku['unique'],
                'cart_num' => $times, 'surplus_num' => $times, 'split_surplus_num' => $times,
                'pay_price' => self::money($sale), 'total_price' => self::money($sale),
                'settle_price' => self::money($sale), 'write_times' => $times,
                'write_surplus_times' => $times, 'write_start' => $start, 'write_end' => $end,
                'cart_info' => json_encode($snapshot, JSON_UNESCAPED_UNICODE), 'unique' => md5('detail:' . $technicalNo),
                'source_type' => $memberPurchase ? 'member_v3' : 'cashier_v3', 'add_time' => $now]);
            Db::name('store_order')->where('id', $oid)->update(['cart_id' => json_encode([(string)$detailId])]);
            $holderData = [
                'uid' => (int)$header['member_id'], 'oid' => $oid, 'card_name' => $name,
                'store_id' => (int)$header['store_id'],
                'product_id' => (int)$line['item_id'], 'product_type' => 6, 'write_times' => $times,
                'write_surplus_times' => $times, 'write_start' => $start, 'write_end' => $end,
                'add_time' => $now];
            // Holder numbers have a legacy seven-digit storage contract. Reuse
            // the shared allocator; SJ- belongs to sales/recharge documents only.
            (new \app\services\user\CardNumberServices())->withAllocateRetry(static function (string $cardNo) use ($holderData): int {
                return (int)Db::name('user_card_holder')->insertGetId($holderData + ['card_no' => $cardNo]);
            });
            $result[] = $oid;
        }
        return $result;
    }

    /** Currency storage format only; final settlement has already enforced whole yuan. */
    private static function money(int $cents): string
    {
        return bcdiv((string)$cents, '100', 2);
    }
}
