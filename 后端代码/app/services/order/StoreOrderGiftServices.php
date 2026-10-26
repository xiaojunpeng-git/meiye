<?php

namespace app\services\order;

use app\jobs\order\OrderStatusJob;
use app\model\order\StoreOrder;
use app\model\order\StoreOrderCartInfo;
use app\model\yeji\StaffYeji;
use app\services\BaseServices;
use app\services\user\UserCardHolderServices;

/**
 * 收银台赠送项目：拆单关联、主单退款联动撤销
 */
class StoreOrderGiftServices extends BaseServices
{
    /**
     * 订单商品是否全部为赠送（is_gift=1 或 cart_type=1）
     */
    public function isGiftOnlyOrder(int $orderId): bool
    {
        if ($orderId <= 0) {
            return false;
        }
        $cartRows = StoreOrderCartInfo::where('oid', $orderId)->field('is_gift,cart_type')->select();
        if ($cartRows->isEmpty()) {
            return false;
        }
        foreach ($cartRows as $row) {
            $row = is_array($row) ? $row : $row->toArray();
            if ((int)($row['is_gift'] ?? 0) !== 1 && (int)($row['cart_type'] ?? 0) !== 1) {
                return false;
            }
        }
        return true;
    }

    /**
     * 拆单后为赠送子订单绑定主购卡/主商品订单（link_order）
     */
    public function bindGiftOrderLink(int $giftOrderId): void
    {
        if (!$this->isGiftOnlyOrder($giftOrderId)) {
            return;
        }
        $giftOrder = StoreOrder::where('id', $giftOrderId)->find();
        if (!$giftOrder) {
            return;
        }
        $giftOrder = is_array($giftOrder) ? $giftOrder : $giftOrder->toArray();
        if (!empty($giftOrder['link_order'])) {
            return;
        }
        $parentId = (int)($giftOrder['pid'] ?? 0);
        if ($parentId <= 0) {
            return;
        }
        $mainOrderId = (int)(StoreOrder::where('pid', $parentId)
            ->where('id', '<>', $giftOrderId)
            ->where(function ($query) {
                $query->where('product_type', 5)->whereOr('type', 11);
            })
            ->where('refund_status', 0)
            ->order('id', 'asc')
            ->value('id') ?: 0);
        if ($mainOrderId <= 0) {
            // 兜底：同批拆单下第一个非赠送子订单
            $siblingIds = StoreOrder::where('pid', $parentId)
                ->where('id', '<>', $giftOrderId)
                ->where('refund_status', 0)
                ->order('id', 'asc')
                ->column('id');
            foreach ($siblingIds as $sid) {
                if (!$this->isGiftOnlyOrder((int)$sid)) {
                    $mainOrderId = (int)$sid;
                    break;
                }
            }
        }
        if ($mainOrderId <= 0) {
            return;
        }
        StoreOrder::where('id', $giftOrderId)->update(['link_order' => $mainOrderId]);
    }

    /**
     * 获取与主订单关联、尚未撤销的赠送子订单 ID
     */
    public function getLinkedGiftOrderIds(int $mainOrderId): array
    {
        if ($mainOrderId <= 0 || $this->isGiftOnlyOrder($mainOrderId)) {
            return [];
        }
        $giftIds = StoreOrder::where('link_order', $mainOrderId)
            ->where('refund_status', 0)
            ->where('paid', 1)
            ->column('id');
        $giftIds = array_map('intval', $giftIds ?: []);

        $order = StoreOrder::where('id', $mainOrderId)->find();
        if (!$order) {
            return array_values(array_unique($giftIds));
        }
        $order = is_array($order) ? $order : $order->toArray();
        $parentId = (int)($order['pid'] ?? 0);
        if ($parentId <= 0) {
            return array_values(array_unique($giftIds));
        }
        $siblingIds = StoreOrder::where('pid', $parentId)
            ->where('id', '<>', $mainOrderId)
            ->where('refund_status', 0)
            ->where('paid', 1)
            ->column('id');
        foreach ($siblingIds as $sid) {
            $sid = (int)$sid;
            if ($sid > 0 && $this->isGiftOnlyOrder($sid)) {
                $giftIds[] = $sid;
            }
        }
        return array_values(array_unique(array_filter($giftIds)));
    }

    /**
     * 主订单退款时联动撤销赠送子订单（退款语义：refund_status=2）
     */
    public function revokeLinkedGiftOrders(int $mainOrderId, string $reason = '主订单退款'): void
    {
        if ($mainOrderId <= 0 || $this->isGiftOnlyOrder($mainOrderId)) {
            return;
        }
        foreach ($this->getLinkedGiftOrderIds($mainOrderId) as $giftOrderId) {
            $this->revokeGiftOrder($giftOrderId, $reason, false);
        }
    }

    /**
     * 主订单作废时联动撤销赠送子订单（作废语义：terminal_action=2，不写退款状态）
     */
    public function revokeLinkedGiftOrdersForVoid(int $mainOrderId, string $reason = '主订单作废'): void
    {
        if ($mainOrderId <= 0 || $this->isGiftOnlyOrder($mainOrderId)) {
            return;
        }
        foreach ($this->getLinkedGiftOrderIds($mainOrderId) as $giftOrderId) {
            $this->revokeGiftOrder($giftOrderId, $reason, true);
        }
    }

    /**
     * 撤销单个赠送订单（0 元，不走支付退款）
     * @param bool $forVoid true=作废语义；false=退款语义
     */
    public function revokeGiftOrder(int $orderId, string $reason = '主订单退款', bool $forVoid = false): void
    {
        if ($orderId <= 0) {
            return;
        }
        $order = StoreOrder::where('id', $orderId)->find();
        if (!$order || (int)$order['paid'] !== 1) {
            return;
        }
        if (!$this->isGiftOnlyOrder($orderId)) {
            return;
        }
        if ($forVoid) {
            if ((int)($order['terminal_action'] ?? 0) === \app\model\order\StoreOrderTerminalOperation::ACTION_VOID) {
                return;
            }
            StoreOrder::where('id', $orderId)->update([
                'back_reason' => $reason,
                'terminal_action' => \app\model\order\StoreOrderTerminalOperation::ACTION_VOID,
                'terminal_action_time' => time(),
                // 作废不得伪写退款状态
                'refund_status' => 0,
                'refund_type' => 0,
            ]);
            $changeType = 'order_void';
        } else {
            if ((int)$order['refund_status'] !== 0) {
                return;
            }
            StoreOrder::where('id', $orderId)->update([
                'back_reason' => $reason,
                'refund_status' => 2,
                'refund_type' => 6,
            ]);
            $changeType = 'refund_split';
        }
        if ((int)($order['type'] ?? 0) === 11) {
            app()->make(UserCardHolderServices::class)->update(['oid' => $orderId], ['is_del' => 1]);
        }
        if ((int)($order['type'] ?? 0) === 12) {
            app()->make(StoreReservationOrderServices::class)->delete(['oid' => $orderId]);
        }
        StaffYeji::where('link_id', $orderId)->where('type', 2)->update(['status' => 1]);
        OrderStatusJob::dispatch([$orderId, $changeType, [
            'change_message' => $reason,
            'change_manager_type' => 'system',
        ]]);
    }
}
