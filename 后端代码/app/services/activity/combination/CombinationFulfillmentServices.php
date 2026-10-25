<?php
declare(strict_types=1);

namespace app\services\activity\combination;

use app\jobs\order\OrderPayHandelJob;
use app\services\order\StoreOrderCreateServices;
use think\exception\ValidateException;
use think\facade\Db;

/** The receipt gates use; original order/cart snapshots remain the sole rights authority. */
class CombinationFulfillmentServices
{
    /** Called in order creation's transaction, never inferred later from editable activities. */
    public function register(int $orderId): void
    {
        Db::name('combination_order_fulfillment')->insert(['order_id' => $orderId,
            'state' => 'pending', 'created_at' => time(), 'issued_at' => 0]);
    }

    public function managed(int $orderId): bool
    {
        return (bool)Db::name('combination_order_fulfillment')->where('order_id', $orderId)->find();
    }

    /** Order lock serializes issue, refund and consumption; a replay never reactivates a used card. */
    public function issue(int $orderId): void
    {
        Db::transaction(function () use ($orderId) {
            $order = Db::name('store_order')->where('id', $orderId)->lock(true)->find();
            $receipt = Db::name('combination_order_fulfillment')->where('order_id', $orderId)->lock(true)->find();
            if (!$receipt || $receipt['state'] !== 'pending') return;
            if (!$order || !(int)$order['paid'] || (int)$order['refund_status'] !== 0
                || (int)$order['terminal_action'] !== 0) return;
            $pink = Db::name('store_pink')->where('order_id_key', $orderId)->find();
            if (!$pink || (int)$pink['status'] !== 2 || (int)$pink['is_refund'] !== 0) return;
            $type = (int)$order['product_type'];
            if ($type === 5) {
                // Synchronous issuance keeps holder creation and receipt in one transaction.
                // The job has its own retry-safe holder allocation; no queue gap at completion.
                (new OrderPayHandelJob())->doJob($order);
                if (!Db::name('user_card_holder')->where('oid', $orderId)->find())
                    throw new ValidateException('成团卡权益发放失败，请重试');
                app()->make(StoreOrderCreateServices::class)->changeOrder(5, $orderId);
            } elseif ($type === 6) {
                // Only activate the purchased unconsumed project; do not create a reservation or deduct uses.
                app()->make(StoreOrderCreateServices::class)->changeOrder(6, $orderId);
            }
            Db::name('combination_order_fulfillment')->where('order_id', $orderId)
                ->update(['state' => 'issued', 'issued_at' => time()]);
        });
    }

    /** Claim physical fulfillment before calling a carrier; an uncertain delivery must not race a cash refund. */
    public function claimDelivery(int $orderId): bool
    {
        return Db::transaction(function () use ($orderId) {
            $order = Db::name('store_order')->where('id', $orderId)->lock(true)->find();
            $receipt = Db::name('combination_order_fulfillment')->where('order_id', $orderId)->lock(true)->find();
            if (!$receipt) return false;
            $this->assertUsable($orderId);
            if ((int)$order['product_type'] !== 0 || (int)$order['status'] !== 0) throw new ValidateException('该拼团订单不能重复发货');
            Db::name('combination_order_fulfillment')->where('order_id', $orderId)->update(['state' => 'delivering']);
            return true;
        });
    }

    /** Defense at final writers, not just disabled UI. Caller may request an order row lock. */
    public function assertUsable(int $orderId, bool $lock = false): void
    {
        $order = Db::name('store_order')->where('id', $orderId)->lock($lock)->find();
        $receipt = Db::name('combination_order_fulfillment')->where('order_id', $orderId)->find();
        if (!$receipt) return;
        if ($receipt['state'] !== 'issued' || !$order || (int)$order['refund_status'] !== 0
            || (int)$order['terminal_action'] !== 0) {
            throw new ValidateException('拼团尚未完成或订单正在退款，暂不可使用');
        }
    }
}
