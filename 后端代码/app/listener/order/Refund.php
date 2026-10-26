<?php
declare(strict_types=1);

namespace app\listener\order;

use app\jobs\order\OrderStatusJob;
use app\jobs\order\RefundNoticeJob;
use app\jobs\product\ProductLogJob;
use app\jobs\store\StaffFinanceJob;
use app\jobs\store\StoreFinanceJob;
use app\jobs\supplier\SupplierFinanceJob;
use app\jobs\system\CapitalFlowJob;
use app\model\activity\coupon\StoreCouponUser;
use app\model\order\StoreOrder;
use app\model\order\StoreOrderWriteoff;
use app\model\yeji\StaffYeji;
use app\services\order\StoreDebtServices;
use app\services\order\StoreOrderGiftServices;
use app\services\order\StoreOrderInvoiceServices;
use app\services\order\StoreOrderServices;
use app\services\order\terminal\RefundSideEffectJobDispatcher;
use app\services\order\terminal\RefundSideEffectOnceServices;
use mohe\interfaces\ListenerInterface;

/**
 * 订单退款事件（终态路径：同步副作用幂等 + 入队强校验）
 */
class Refund implements ListenerInterface
{
    public function handle($event): void
    {
        [$data, $order] = $event;
        $order = is_array($order) ? $order : $order->toArray();
        $opNo = trim((string)($data['operation_no'] ?? $order['terminal_operation_no'] ?? ''));

        if ($opNo !== '' && !empty($order['terminal_refund_notice_key'])) {
            $this->handleTerminalOutbox([$data, $order]);
            return;
        }

        $this->runSyncSideEffects($data, $order, false);
        $this->dispatchLegacyJobs($data, $order);
        $this->syncSplitParentStatus($order, false);
    }

    public function handleTerminalOutbox(array $event): void
    {
        [$data, $order] = $event;
        $order = is_array($order) ? $order : $order->toArray();
        $this->normalizeTerminalKeys($data, $order);
        $this->runSyncSideEffects($data, $order, true);

        /** @var RefundSideEffectJobDispatcher $dispatcher */
        $dispatcher = app()->make(RefundSideEffectJobDispatcher::class);
        $dispatcher->dispatchRefundEventJobs($data, $order);

        $this->syncSplitParentStatus($order, true);
    }

    /** 充值退款 Outbox：无商品日志；财务 type=5 / capital refund_recharge */
    public function handleRechargeRefundOutbox(array $event): void
    {
        [$data, $order] = $event;
        $order = is_array($order) ? $order : $order->toArray();
        $data['recharge_refund'] = 1;
        $order['recharge_refund'] = 1;
        $this->normalizeTerminalKeys($data, $order);

        /** @var RefundSideEffectJobDispatcher $dispatcher */
        $dispatcher = app()->make(RefundSideEffectJobDispatcher::class);
        $dispatcher->dispatchRefundEventJobs($data, $order);
    }

    protected function normalizeTerminalKeys(array &$data, array &$order): void
    {
        $opNo = trim((string)($data['operation_no'] ?? $order['terminal_operation_no'] ?? ''));
        if ($opNo === '') {
            return;
        }
        $order['terminal_operation_no'] = $opNo;
        $order['terminal_operation_id'] = (int)($data['terminal_operation_id'] ?? $order['terminal_operation_id'] ?? 0);
        $order['terminal_store_finance_key'] = $order['terminal_store_finance_key'] ?? ($opNo . ':store_finance');
        $order['terminal_staff_finance_key'] = $order['terminal_staff_finance_key'] ?? ($opNo . ':staff_finance');
        $order['terminal_capital_flow_key'] = $order['terminal_capital_flow_key'] ?? ($opNo . ':capital_flow');
        $order['terminal_product_log_key'] = $order['terminal_product_log_key'] ?? ($opNo . ':product_log');
        $order['terminal_refund_notice_key'] = $order['terminal_refund_notice_key'] ?? ($opNo . ':refund_notice');
        $data['operation_no'] = $opNo;
        $data['terminal_operation_id'] = $order['terminal_operation_id'];
        $data['terminal_idempotency_key'] = $order['terminal_refund_notice_key'];
    }

    /**
     * @param bool $strict 终态路径：异常上抛，且用 once 包裹
     */
    protected function runSyncSideEffects(array $data, array $order, bool $strict): void
    {
        $opNo = trim((string)($data['operation_no'] ?? $order['terminal_operation_no'] ?? ''));
        /** @var RefundSideEffectOnceServices $once */
        $once = app()->make(RefundSideEffectOnceServices::class);

        $run = function (string $step, callable $fn) use ($once, $opNo, $strict) {
            if ($opNo === '' || !$strict) {
                try {
                    $fn();
                } catch (\Throwable $e) {
                    if ($strict) {
                        throw $e;
                    }
                }
                return;
            }
            $key = $opNo . ':' . $step;
            $once->runOnce($key, $fn, $opNo, $step);
        };

        if ((int)($order['refund_status'] ?? 0) === 2) {
            $run(RefundSideEffectOnceServices::STEP_DEBT_VOID, function () use ($order) {
                /** @var StoreDebtServices $debtServices */
                $debtServices = app()->make(StoreDebtServices::class);
                $debtServices->voidDebtByOrderId((int)$order['id'], '订单退款');
                $debtServices->voidRepayOrdersByOriginOrderId((int)$order['id'], '主订单退款');
            });
        }
        if (!empty($order['is_debt_repay']) && (float)($data['refund_price'] ?? 0) > 0) {
            $run(RefundSideEffectOnceServices::STEP_DEBT_REVERSE, function () use ($order, $data) {
                /** @var StoreDebtServices $debtServices */
                $debtServices = app()->make(StoreDebtServices::class);
                $debtServices->reverseRepayOnRefund((int)$order['id'], (float)$data['refund_price']);
            });
        }

        $run(RefundSideEffectOnceServices::STEP_COUPON_ROLLBACK, function () use ($order) {
            StoreCouponUser::where('use_time', 0)
                ->where('oid', $order['id'])
                ->where('type', 'order_get')
                ->delete();
        });

        $run(RefundSideEffectOnceServices::STEP_WRITEOFF_CHILD, function () use ($order) {
            if (($order['product_type'] ?? null) == 5) {
                return;
            }
            $hxOrder = StoreOrder::where('order_type', 2)->where('refund_status', 0)
                ->where('link_order', $order['id'])->select();
            foreach ($hxOrder as $v) {
                StoreOrder::where('id', $v['id'])->update(['back_reason' => '主订单退款', 'refund_status' => 2]);
                StaffYeji::where('link_id', $v['link_id'])->where('type', 3)->update(['status' => 1]);
                StoreOrderWriteoff::where('id', $v['link_id'])->update(['status' => 1]);
            }
        });

        $run(RefundSideEffectOnceServices::STEP_YEJI_ROLLBACK, function () use ($order) {
            StaffYeji::where('link_id', $order['id'])->where('type', 2)->update(['status' => 1]);
        });

        $run(RefundSideEffectOnceServices::STEP_GIFT_REVOKE, function () use ($order) {
            /** @var StoreOrderGiftServices $giftServices */
            $giftServices = app()->make(StoreOrderGiftServices::class);
            $giftServices->revokeLinkedGiftOrders((int)$order['id'], '主订单退款');
        });

        $run(RefundSideEffectOnceServices::STEP_INVOICE_REFUND, function () use ($order) {
            $orderInvoiceServices = app()->make(StoreOrderInvoiceServices::class);
            $orderInvoiceServices->update(['order_id' => $order['id']], ['is_refund' => 1]);
        });
    }

    protected function dispatchLegacyJobs(array $data, array $order): void
    {
        if (isset($order['store_id']) && $order['store_id']) {
            StoreFinanceJob::dispatch([$order, 4, $data['refund_price'] ?? 0.00]);
            StaffFinanceJob::dispatch([$order, 4, $data['refund_price'] ?? 0.00]);
            StoreFinanceJob::dispatchDo('takeDoJob', [$order, time()], 10);
        } else if (isset($order['supplier_id']) && $order['supplier_id']) {
            SupplierFinanceJob::dispatch([$order['id'], 2]);
            SupplierFinanceJob::dispatchDo('takeDoJob', [$order, time()], 10);
        }
        CapitalFlowJob::dispatch([array_merge($order, ['refund_price' => $data['refund_price']]), 'refund']);
        ProductLogJob::dispatch(['refund', [
            'uid' => $order['uid'],
            'order_id' => $order['id'],
        ]]);
        event('notice.notice', [['data' => $data, 'order' => $order], 'order_refund']);
    }

    protected function syncSplitParentStatus(array $order, bool $checkedEnqueue): void
    {
        if (empty($order['pid'])) {
            return;
        }
        $id = (int)$order['pid'];
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        $refund_data = ['refund_status' => '3', 'refund_type' => 4];
        $opNo = trim((string)($order['terminal_operation_no'] ?? ''));
        if ($orderServices->count(['pid' => $id]) == $orderServices->count(['pid' => $id, 'refund_status' => 2])) {
            $refund_data = ['refund_status' => 2, 'refund_type' => 6];
            $change_type = 'refund_split';
            $change_message = '已拆分退款';
        } else {
            $change_type = 'refund_part_split';
            $change_message = '已拆分部分退款';
        }
        $orderServices->update($id, $refund_data);
        $statusData = ['change_message' => $change_message];
        if ($opNo !== '') {
            $statusData['terminal_idempotency_key'] = $opNo . ':parent_status:' . $id;
            $statusData['operation_no'] = $opNo;
        }
        $res = OrderStatusJob::dispatch([$id, $change_type, $statusData]);
        if ($checkedEnqueue) {
            /** @var RefundSideEffectJobDispatcher $dispatcher */
            $dispatcher = app()->make(RefundSideEffectJobDispatcher::class);
            $dispatcher->mustEnqueue('OrderStatusJob.parent', $res);
        }
    }
}
