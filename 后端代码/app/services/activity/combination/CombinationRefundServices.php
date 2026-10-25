<?php
declare(strict_types=1);

namespace app\services\activity\combination;

use app\services\order\StoreOrderRefundDomainServices;
use app\services\pay\FuyouCombinationRefundGateway;
use app\services\pay\PayServices;
use think\exception\ValidateException;
use think\facade\Db;

/**
 * Per-participant whole refund. Application and query are outside DB transactions;
 * refund_status=1 fences use while result is unknown. The existing terminal domain
 * remains the only writer of refund money, inventory and reversal facts.
 */
class CombinationRefundServices
{
    private $gateway;
    public function __construct(?FuyouCombinationRefundGateway $gateway = null)
    {
        $this->gateway = $gateway;
    }

    private function gateway(): FuyouCombinationRefundGateway
    {
        return $this->gateway ?: FuyouCombinationRefundGateway::instance();
    }

    public static function isGroupOrder(int $orderId): bool
    {
        return (bool)Db::name('store_pink')->where('order_id_key', $orderId)->find();
    }

    /** No amount supplied by the browser is accepted. Admin identity comes from authenticated context. */
    public function refundOrder(int $orderId, string $reason, int $operatorId = 0, bool $retry = true, int $kickGroupId = 0): array
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 300) throw new ValidateException('请填写300字以内退款原因');
        $send = false;
        $claimed = false;
        $receipt = Db::transaction(function () use ($orderId, $reason, $operatorId, $retry, $kickGroupId, &$send, &$claimed) {
            $memberIdentity = Db::name('store_pink')->where('order_id_key', $orderId)->find();
            if (!$memberIdentity) throw new ValidateException('拼团订单不存在');
            $rootId = (int)$memberIdentity['k_id'] ?: (int)$memberIdentity['id'];
            Db::name('store_pink')->where('id', $rootId)->lock(true)->find();
            $order = Db::name('store_order')->where('id', $orderId)->lock(true)->find();
            if (!$order || !self::isGroupOrder($orderId)) throw new ValidateException('拼团订单不存在');
            $row = Db::name('combination_order_refund')->where('order_id', $orderId)->lock(true)->find();
            if ($row && $row['state'] === 'success') return $row;
            if ($row && (int)$row['lease_until'] > time()) return $row;
            if (!$row || ($row['state'] === 'failed' && $retry)) {
                if ($kickGroupId > 0) {
                    $root = Db::name('store_pink')->where('id', $kickGroupId)->lock(true)->find();
                    $member = Db::name('store_pink')->where('order_id_key', $orderId)->lock(true)->find();
                    if (!$root || (int)$root['status'] !== 1 || (int)$root['stop_time'] < time()
                        || (int)$root['is_refund'] !== 0 || !$member || (int)$member['k_id'] !== $kickGroupId
                        || (int)$member['is_virtual'] !== 0 || (int)$member['uid'] <= 0)
                        throw new ValidateException('只能踢出进行中拼团的真实非团长成员');
                }
                $this->assertUnused($order);
                $mapping = $this->paymentOrigin($order);
                $attempt = $row ? (int)$row['attempt'] + 1 : 1;
                // Prefix follows the actual original channel order, never a customer-name conditional.
                $prefix = $mapping['channel_order_no'] !== '' ? substr($mapping['channel_order_no'], 0, 5) : 'GROUP';
                $row = ['order_id' => $orderId, 'refund_no' => $prefix . str_pad((string)random_int(0, 999999999999999), 15, '0', STR_PAD_LEFT),
                    'attempt' => $attempt, 'state' => $mapping['channel'] === 'balance' ? 'confirmed' : 'sending',
                    'channel_order_no' => $mapping['channel_order_no'], 'channel' => $mapping['channel'],
                    'merchant_id' => $mapping['merchant_id'], 'amount_cents' => (int)bcmul((string)$order['pay_price'], '100', 0),
                    'channel_refund_id' => '', 'fuyou_refund_trace_no' => '', 'channel_transaction_id' => $mapping['channel'] === 'balance' ? '' : (string)($order['trade_no'] ?? ''),
                    'settled_on' => (string)($mapping['settled_on'] ?? ''), 'operator_id' => $operatorId, 'kick_group_id' => $kickGroupId, 'reason' => $reason,
                    'lease_until' => time() + 90, 'created_at' => time(), 'updated_at' => time()];
                if (Db::name('combination_order_refund')->where('order_id', $orderId)->find())
                    Db::name('combination_order_refund')->where('order_id', $orderId)->update($row);
                else Db::name('combination_order_refund')->insert($row);
                // This order row is shared with consumption writers; freeze commits before any network call.
                Db::name('store_order')->where('id', $orderId)->update(['refund_status' => 1]);
                $send = $row['state'] === 'sending';
            } elseif ($row['state'] === 'failed') return $row;
            else {
                Db::name('combination_order_refund')->where('order_id', $orderId)->update(['lease_until' => time() + 90]);
            }
            $claimed = true;
            return $row;
        });
        if (!$claimed) return $this->present($receipt);
        try {
            if ($send) {
                // Persist sending BEFORE calling; a crash can only query, never send again blindly.
                try { $this->gateway()->apply($receipt); }
                catch (\Throwable $error) { /* Unknown is not failure; query the same refund number below. */ }
                Db::name('combination_order_refund')->where('order_id', $orderId)->where('refund_no', $receipt['refund_no'])->update(['state' => 'pending']);
            }
            if (in_array($receipt['state'], ['sending','pending'], true)) {
                $result = $this->gateway()->query($receipt);
                Db::transaction(function () use ($orderId, $result, $receipt) {
                    Db::name('store_order')->where('id', $orderId)->lock(true)->find();
                    Db::name('combination_order_refund')->where('order_id', $orderId)
                        ->where('refund_no', $receipt['refund_no'])->update($result + ['updated_at' => time()]);
                    if ($result['state'] === 'failed') {
                        // Only a signed PAYERROR releases use. Unknown/invalid replies stay frozen.
                        Db::name('store_order')->where('id', $orderId)->where('refund_status', 1)->update(['refund_status' => 0]);
                    }
                });
                $receipt = array_merge($receipt, $result);
            }
            if ($receipt['state'] === 'confirmed') {
                $domain = app()->make(StoreOrderRefundDomainServices::class);
                $result = $domain->refundConfirmedCombination($orderId, [
                    'operator_id' => (int)$receipt['operator_id'], 'operator_type' => $operatorId ? 'admin' : 'system',
                    'source_type' => 4, 'refund_reason' => (string)$receipt['reason'],
                    'refund_explain' => (string)$receipt['reason'], 'request_token' => 'group:' . $orderId,
                ]);
                if (!empty($result['ok']) && ($result['message'] ?? '') === '退款成功') {
                    Db::transaction(function () use ($orderId) {
                        Db::name('store_order')->where('id', $orderId)->lock(true)->find();
                        // Historical project/card orders need the same explicit revocation as new ones.
                        Db::name('user_card_holder')->where('oid', $orderId)->update(['is_del' => 1]);
                        Db::name('store_order_cart_info')->where('oid', $orderId)
                            ->update(['write_surplus_times' => 0, 'surplus_num' => 0, 'is_writeoff' => 1]);
                        Db::name('combination_order_fulfillment')->where('order_id', $orderId)->update(['state' => 'refunded']);
                        Db::name('combination_order_refund')->where('order_id', $orderId)
                            ->update(['state' => 'success', 'updated_at' => time()]);
                    });
                }
            }
        } catch (\Throwable $error) {
            // Channel success survives local errors and is retried through the existing terminal domain.
            \think\facade\Log::warning('拼团退款待查询或收口 oid=' . $orderId . ' ' . get_class($error));
        } finally {
            Db::name('combination_order_refund')->where('order_id', $orderId)->where('refund_no', $receipt['refund_no'])->update(['lease_until' => 0, 'updated_at' => time()]);
        }
        return $this->present((array)Db::name('combination_order_refund')->where('order_id', $orderId)->find());
    }

    /** Used by generic refund entries as well: no second refund path may bypass this receipt. */
    public static function assertChannelConfirmed(array $order): void
    {
        $row = Db::name('combination_order_refund')->where('order_id', (int)$order['id'])->find();
        if (!$row || !in_array($row['state'], ['confirmed','success'], true)
            || (int)$row['amount_cents'] !== (int)bcmul((string)$order['pay_price'], '100', 0))
            throw new ValidateException('拼团退款尚未取得原支付渠道成功回执');
    }

    /** Full-unused policy is checked again under the same order/holder/cart locks as the freeze. */
    public function assertUnused(array $order): void
    {
        $id = (int)$order['id'];
        if ((int)$order['store_id'] <= 0 || !Db::name('system_store')->where('id', (int)$order['store_id'])->count())
            throw new ValidateException('订单缺少可核实的交易门店，不能快捷退款');
        // Preflight authoritative payment parts before any external request or freeze.
        app()->make(StoreOrderRefundDomainServices::class)->resolveAndValidateRefundMoney(
            $order, ['refund_amount' => (string)$order['pay_price']], false);
        if (!(int)$order['paid'] || (int)$order['refund_status'] !== 0 || (int)$order['terminal_action'] !== 0
            || (int)$order['is_del'] || (int)$order['is_system_del'] || (int)$order['pid'] !== 0
            || (int)$order['status'] !== 0 || (int)$order['card_upgrade_use_oid'] !== 0
            || bccomp((string)$order['pay_price'], '0', 2) <= 0)
            throw new ValidateException('仅支持已支付且未使用、未发货、未退款的完整拼团订单');
        $fulfillment = Db::name('combination_order_fulfillment')->where('order_id', $id)->find();
        if ($fulfillment && !in_array($fulfillment['state'], ['pending','issued'], true))
            throw new ValidateException('产品已进入发货或领取流程，不能快捷全额退款');
        if (Db::name('store_order')->where('pid', $id)->count()
            || Db::name('store_order_writeoff')->where('oid', $id)->where('status', 0)->count()
            || Db::name('store_reservation_order')->where('oid', $id)->where('is_del', 0)->where('is_system_del', 0)->count()
            || Db::name('store_order_refund')->where('store_order_id', $id)->where('is_cancel', 0)->where('is_del', 0)->count())
            throw new ValidateException('订单已有使用、预约或售后记录，不能快捷全额退款');
        foreach (Db::name('user_card_holder')->where('oid', $id)->lock(true)->select()->toArray() as $holder) {
            if ((int)$holder['uid'] !== (int)$order['uid'] || (int)$holder['write_surplus_times'] !== (int)$holder['write_times'])
                throw new ValidateException('卡项已使用或转让，不能快捷全额退款');
        }
        foreach (Db::name('store_order_cart_info')->where('oid', $id)->lock(true)->select()->toArray() as $cart) {
            if ((int)$cart['cart_type'] === 0) {
                // Verify restorable original activity/SKU before sending money, not after channel success.
                if ((int)$order['activity_id'] <= 0 || !Db::name('store_product_attr_value')
                    ->where('product_id', (int)$order['activity_id'])->where('type', 3)
                    ->where('unique', (string)$cart['sku_unique'])->lock(true)->find())
                    throw new ValidateException('原拼团规格凭据不完整，不能快捷退款');
                if (!(int)$cart['is_support_refund']) throw new ValidateException('该商品未开启退款，不能快捷退款');
            }
            if ((int)$cart['is_writeoff'] !== 0 || (int)$cart['refund_num'] > 0
                || ((int)$cart['write_times'] > 0 && (int)$cart['write_surplus_times'] !== (int)$cart['write_times']))
                throw new ValidateException('权益已有使用或变更，不能快捷全额退款');
        }
        if (Db::name('cashier_v3_card_operation')->where('origin_order_id', $id)->count())
            throw new ValidateException('卡项已有升级、替换或转余额操作，不能快捷全额退款');
    }

    /** Match successful channel mapping and original merchant proof; never infer FuYou from pay_type alone. */
    private function paymentOrigin(array $order): array
    {
        if ($order['pay_type'] === PayServices::YUE_PAY)
            return ['channel' => 'balance', 'channel_order_no' => '', 'merchant_id' => ''];
        $mappings = Db::name('fuyou_payment_attempt')->alias('p')
            ->join('combination_payment_origin o', 'o.channel_order_no=p.channel_order_no')
            ->where('p.business_order_no', (string)$order['order_id'])->where('p.business_type', 'product')
            ->where('p.status', 'paid')->where('p.transaction_id', (string)$order['trade_no'])
            ->where('p.amount_cents', (int)bcmul((string)$order['pay_price'], '100', 0))
            ->field('p.channel,p.channel_order_no,o.merchant_id,o.settled_on')->select()->toArray();
        if (count($mappings) !== 1 || !in_array($mappings[0]['channel'], ['wechat','alipay'], true))
            throw new ValidateException('原支付渠道凭据不完整，不能直接调用富友退款');
        if ((string)$mappings[0]['merchant_id'] !== $this->gateway()->merchantId())
            throw new ValidateException('原支付商户与当前配置不一致，不能跨商户退款');
        return $mappings[0];
    }

    public function present(array $row): array
    {
        $state = (string)($row['state'] ?? '');
        $participant = !empty($row['order_id']) ? Db::name('store_pink')->where('order_id_key', (int)$row['order_id'])->find() : null;
        return ['state' => $state, 'group_id' => $participant ? ((int)$participant['k_id'] ?: (int)$participant['id']) : 0, 'refund_no' => (string)($row['refund_no'] ?? ''),
            'channel_refund_id' => (string)($row['channel_refund_id'] ?? ''),
            'fuyou_refund_trace_no' => (string)($row['fuyou_refund_trace_no'] ?? ''),
            'amount' => bcdiv((string)($row['amount_cents'] ?? 0), '100', 2),
            'fuyou_order_no' => (string)($row['channel_order_no'] ?? ''),
            'fuyou_transaction_id' => ($row['channel'] ?? '') === 'balance' ? '' : (string)($row['channel_transaction_id'] ?? ''),
            'message' => $state === 'success' ? '退款成功' : ($state === 'failed' ? '退款失败，可重试' : '退款处理中，请查询结果')];
    }

    /** Read-only eligibility and receipt projection for exactly one participant order. */
    public function describe(int $orderId): array
    {
        $order = Db::name('store_order')->where('id', $orderId)->find();
        if (!$order) return ['refund_block_reason' => '订单不存在'];
        $receipt = Db::name('combination_order_refund')->where('order_id', $orderId)->find();
        $view = $this->present($receipt ?: []);
        $view['paid_amount'] = (string)$order['pay_price'];
        $view['refund_action'] = '';
        $view['refund_reason'] = (string)($receipt['reason'] ?? '');
        $view['refund_message'] = $receipt ? $view['message'] : ((int)$order['refund_status'] === 2 ? '已退款' : '未退款');
        if ($receipt && !in_array($receipt['state'], ['success','failed'], true)) $view['refund_action'] = 'query';
        elseif (!$receipt || $receipt['state'] === 'failed') {
            try {
                Db::transaction(function () use ($order) { $this->assertUnused($order); });
                $this->paymentOrigin($order);
                $view['refund_action'] = $receipt ? 'retry' : 'refund';
            } catch (\Throwable $error) {
                $view['refund_block_reason'] = $error instanceof ValidateException ? $error->getMessage() : '订单暂不满足直接退款条件';
            }
        }
        return $view;
    }
}
