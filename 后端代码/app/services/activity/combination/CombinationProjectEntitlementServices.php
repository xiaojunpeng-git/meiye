<?php
declare(strict_types=1);

namespace app\services\activity\combination;

use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\user\CardNumberServices;
use think\exception\ValidateException;
use think\facade\Db;

/** 成团项目沿用原购买单建立可核销载体，不新增销售、收款、服务或库存事实。 */
final class CombinationProjectEntitlementServices
{
    /** 调用方须在发放事务内；原订单锁串行化回调、退款和修复，重放不重置剩余次数。 */
    public function issueInTx(int $orderId, int $now): void
    {
        CashierV3TransactionGuard::assertInTransaction('combinationProjectRights');
        $order = Db::name('store_order')->where('id', $orderId)->lock(true)->find();
        $receipt = Db::name('combination_order_fulfillment')->where('order_id', $orderId)->lock(true)->find();
        $pink = Db::name('store_pink')->where('order_id_key', $orderId)->lock(true)->find();
        if (!$order || !$receipt || !in_array($receipt['state'], ['pending', 'issued'], true)
            || !$pink || (int)$pink['status'] !== 2 || (int)$pink['is_refund'] !== 0
            || (int)$order['product_type'] !== 6 || !(int)$order['paid']
            || (int)$order['refund_status'] !== 0 || (int)$order['terminal_action'] !== 0
            || !empty($order['is_system_del'])
            || (int)$order['store_id'] <= 0) {
            throw new ValidateException('仅可发放已付款、已成团且未退款的项目权益');
        }
        $holders = Db::name('user_card_holder')->where('oid', $orderId)->lock(true)->select()->toArray();
        $details = Db::name('store_order_cart_info')->where('oid', $orderId)->where('cart_type', 2)->lock(true)->select()->toArray();
        if ($holders || $details) {
            // 已存在的载体可能已核销、转赠或停用；不能重新发卡或恢复其次数。
            if (count($holders) !== 1 || count($details) !== 1
                || (int)$holders[0]['uid'] !== (int)$order['uid']
                || (int)$holders[0]['store_id'] !== (int)$order['store_id']
                || (int)$holders[0]['product_type'] !== 6
                || (int)$details[0]['uid'] !== (int)$order['uid']
                || (int)$details[0]['product_id'] !== (int)$holders[0]['product_id']) {
                throw new ValidateException('拼团项目权益载体不完整，请先核对');
            }
            return;
        }
        $lines = Db::name('store_order_cart_info')->where('oid', $orderId)->where('cart_type', 0)->lock(true)->select()->toArray();
        if (count($lines) !== 1 || (int)$lines[0]['product_type'] !== 6) {
            throw new ValidateException('拼团项目购买快照不完整');
        }
        $line = $lines[0];
        $snapshot = json_decode((string)$line['cart_info'], true);
        $name = trim((string)($snapshot['productInfo']['store_name'] ?? ''));
        $times = (int)$line['write_times'];
        if (!is_array($snapshot) || $name === '' || $times <= 0
            || (int)$line['uid'] !== (int)$order['uid']
            || (int)$line['write_surplus_times'] !== $times || (int)$line['is_writeoff'] !== 0
            || (int)$line['refund_num'] !== 0 || (int)$line['product_id'] <= 0
            || bccomp((string)$line['pay_price'], (string)$order['pay_price'], 2) !== 0
            || bccomp((string)$line['pay_price'], '0', 2) < 0
            || bccomp((string)$line['pay_price'], bcadd((string)(int)$line['pay_price'], '0', 2), 2) !== 0) {
            throw new ValidateException('项目次数、整元金额或购买快照不一致，不能发放');
        }
        // 有效期、项目身份和金额只取购买快照，不回读后来可编辑的活动或目录。
        $attr = (array)($snapshot['productInfo']['attrInfo'] ?? []);
        $start = 0; $end = 0;
        if ((int)($attr['write_valid'] ?? 1) === 2) {
            $start = $now; $end = $now + max(0, (int)($attr['write_days'] ?? 0)) * 86400;
        } elseif ((int)($attr['write_valid'] ?? 1) === 3) {
            $start = (int)($attr['write_start'] ?? 0); $end = (int)($attr['write_end'] ?? 0);
        }
        $snapshot['sourceType'] = 'combination_project_purchase';
        $snapshot['originPurchaseDetailId'] = (int)$line['id'];
        $snapshot['productInfo']['id'] = (int)$line['product_id'];
        $snapshot['productInfo']['product_type'] = 6;
        $snapshot['write_times'] = $times;
        // 保留原 cart_type=0 购买明细供订单和退款读取；cart_type=2 仅承载核销权益。
        $detail = $line;
        unset($detail['id']);
        $detail = array_replace($detail, ['cart_id' => 'gpr:' . $line['id'], 'cart_type' => 2,
            'is_card' => 1, 'is_support_refund' => 0, 'cart_num' => $times,
            'surplus_num' => $times, 'split_surplus_num' => $times,
            // 权益金额是原购买金额的核销分摊依据；不能复制成第二笔现金/余额收款。
            'cash_pay_amount' => '0.00', 'yue_pay_amount' => '0.00',
            'card_upgrade_amount' => '0.00', 'debt_amount' => '0.00',
            'repaid_debt_amount' => '0.00',
            'write_start' => $start, 'write_end' => $end,
            'cart_info' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
            'unique' => md5('group-project-right:' . $line['id'])]);
        Db::name('store_order_cart_info')->insert($detail);
        $holder = ['uid' => (int)$order['uid'], 'oid' => $orderId, 'store_id' => (int)$order['store_id'],
            'card_name' => $name, 'product_id' => (int)$line['product_id'], 'product_type' => 6,
            'write_times' => $times, 'write_surplus_times' => $times,
            'write_start' => $start, 'write_end' => $end, 'add_time' => $now];
        (new CardNumberServices())->withAllocateRetry(static function (string $cardNo) use ($holder): int {
            return (int)Db::name('user_card_holder')->insertGetId($holder + ['card_no' => $cardNo]);
        });
    }
}
