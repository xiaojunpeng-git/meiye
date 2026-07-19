<?php
declare(strict_types=1);

namespace app\services\order;

use app\model\activity\coupon\StoreCouponUser;
use app\model\order\StoreOrder;
use app\model\order\StoreOrderWriteoff;
use app\model\yeji\StaffYeji;
use app\services\order\terminal\OrderTerminalError;
use app\services\order\terminal\RefundSideEffectOnceServices;
use think\facade\Db;
use think\facade\Log;

/**
 * 整单回滚编排：核销/库存/权益/业绩/券/赠送/欠款（退款与作废共用）
 */
class StoreOrderRollbackServices
{
    /**
     * 按原销售订单撤销全部有效核销（复用 cancelWriteoff 原子逻辑，含院装退料）
     * @return int 成功撤销条数
     */
    public function cancelAllWriteoffsForSalesOrder(int $salesOrderId, string $remark = '整单作废撤销核销', int $storeScope = 0): int
    {
        if ($salesOrderId <= 0) {
            return 0;
        }
        /** @var StoreOrderWriteOffServices $writeOff */
        $writeOff = app()->make(StoreOrderWriteOffServices::class);
        $count = 0;

        $subIds = StoreOrder::where('order_type', 2)
            ->where('link_order', $salesOrderId)
            ->where('refund_status', 0)
            ->column('id');
        foreach ($subIds as $subId) {
            try {
                $writeOff->cancelWriteoff((int)$subId, $remark, $storeScope);
                $count++;
            } catch (\Throwable $e) {
                // 已撤销等可跳过；其它失败上抛保证整单失败
                if (mb_strpos($e->getMessage(), '已撤销') !== false
                    || mb_strpos($e->getMessage(), '不允许撤销') !== false) {
                    continue;
                }
                throw $e;
            }
        }

        // 无核销子单、仅有 writeoff 行的场景（仍 status=0）
        $orphanIds = StoreOrderWriteoff::where('oid', $salesOrderId)
            ->where('status', 0)
            ->column('id');
        foreach ($orphanIds as $wid) {
            $subId = (int)StoreOrder::where('order_type', 2)
                ->where('link_id', (int)$wid)
                ->where('link_order', $salesOrderId)
                ->value('id');
            if ($subId > 0) {
                try {
                    $writeOff->cancelWriteoff($subId, $remark, $storeScope);
                    $count++;
                } catch (\Throwable $e) {
                    if (mb_strpos($e->getMessage(), '已撤销') !== false) {
                        continue;
                    }
                    throw $e;
                }
                continue;
            }
            // 无子单：直接按写销行回滚（与 cancelWriteoff 同事务语义）
            $this->cancelOrphanWriteoff((int)$wid, $remark);
            $count++;
        }

        return $count;
    }

    protected function cancelOrphanWriteoff(int $writeoffId, string $remark): void
    {
        Db::transaction(function () use ($writeoffId, $remark) {
            $writeoffModel = StoreOrderWriteoff::where('id', $writeoffId)->lock(true)->find();
            if (!$writeoffModel) {
                return;
            }
            $writeoff = $writeoffModel->toArray();
            if ((int)($writeoff['status'] ?? 0) === 1) {
                return;
            }
            StoreOrderWriteoff::where('id', $writeoffId)->update(['status' => 1]);
            StaffYeji::where('link_id', $writeoffId)->where('type', 3)->update(['status' => 1]);
            $cartId = (int)($writeoff['order_cart_id'] ?? 0);
            $number = $writeoff['writeoff_num'] ?? 1;
            if ($cartId > 0) {
                \app\model\order\StoreOrderCartInfo::where('id', $cartId)->inc('write_surplus_times', $number)->update();
                \app\model\order\StoreOrderCartInfo::where('id', $cartId)->update(['is_writeoff' => 0]);
            }
            \app\model\user\UserCardHolder::where('uid', $writeoff['uid'])
                ->where('oid', $writeoff['oid'])
                ->inc('write_surplus_times', $number)
                ->update();
            /** @var \app\services\product\inventory\SalonStockWriteoffServices $salon */
            $salon = app()->make(\app\services\product\inventory\SalonStockWriteoffServices::class);
            $salon->returnForWriteoff($writeoffId);
            Log::info('[void_orphan_writeoff] id=' . $writeoffId . ' remark=' . $remark);
        });
    }

    /**
     * 本地业务副作用回滚（幂等 once；不作废渠道、不写退款申请表）
     *
     * @param array $order 销售订单
     * @param string $operationNo 终态操作号
     * @param string $reason 人话原因（赠送/欠款备注）
     * @param bool $recoverCoupon 是否退回订单用券
     * @param int $stockInType 已发货入库口径（作废默认按未发货整单回库存）
     */
    public function runLocalBusinessRollback(
        array $order,
        string $operationNo,
        string $reason = '整单作废',
        bool $recoverCoupon = true,
        int $stockInType = 0,
        int $storeScope = 0,
        bool $forVoid = true
    ): void {
        $orderId = (int)($order['id'] ?? 0);
        if ($orderId <= 0) {
            OrderTerminalError::throw(OrderTerminalError::ORDER_NOT_FOUND);
        }
        /** @var RefundSideEffectOnceServices $once */
        $once = app()->make(RefundSideEffectOnceServices::class);
        /** @var StoreOrderRefundServices $refundServices */
        $refundServices = app()->make(StoreOrderRefundServices::class);

        $orderModel = StoreOrder::where('id', $orderId)->find();
        if (!$orderModel) {
            OrderTerminalError::throw(OrderTerminalError::ORDER_NOT_FOUND);
        }

        $run = function (string $step, callable $fn) use ($once, $operationNo) {
            $once->runOnce($operationNo . ':' . $step, $fn, $operationNo, $step);
        };

        $run('void_writeoff', function () use ($orderId, $reason, $storeScope) {
            $this->cancelAllWriteoffsForSalesOrder($orderId, $reason, $storeScope);
        });

        $run('void_entitlement', function () use ($refundServices, $orderModel, $recoverCoupon) {
            $refundServices->setItem('terminal_authorized', 1)
                ->setItem('recover_coupon', $recoverCoupon ? 1 : 0);
            $refundServices->runEntitlementRollback($orderModel, 0, $recoverCoupon ? 1 : 0);
            $refundServices->reset();
        });

        $run('void_inventory', function () use ($refundServices, $orderModel, $order, $operationNo, $stockInType) {
            // 活动/Redis 占用回退（不写退款单）；实物库存走作废专用幂等回库
            $refundServices->setItem('terminal_authorized', 1);
            $refundServices->regressionStock($orderModel, 0, false);
            $refundServices->reset();

            /** @var \app\services\order\StoreOrderCartInfoServices $cartServices */
            $cartServices = app()->make(\app\services\order\StoreOrderCartInfoServices::class);
            $rows = $cartServices->getCartInfoList(
                ['oid' => (int)$order['id'], 'cart_type' => [0, 1]],
                ['product_id', 'sku_unique', 'cart_num', 'cart_info', 'product_type']
            );
            $cartLines = [];
            foreach ($rows as $row) {
                $info = is_string($row['cart_info'] ?? null)
                    ? json_decode($row['cart_info'], true)
                    : ($row['cart_info'] ?? []);
                if (!is_array($info)) {
                    $info = [];
                }
                $info['cart_num'] = $row['cart_num'] ?? ($info['cart_num'] ?? 0);
                $info['product_id'] = $row['product_id'] ?? ($info['product_id'] ?? 0);
                $info['sku_unique'] = $row['sku_unique'] ?? '';
                $info['product_type'] = $row['product_type'] ?? ($info['product_type'] ?? 0);
                $cartLines[] = $info;
            }
            /** @var \app\services\product\inventory\ProductInventoryChangeServices $inventory */
            $inventory = app()->make(\app\services\product\inventory\ProductInventoryChangeServices::class);
            $inventory->handleVoidInventory(
                is_array($orderModel) ? $orderModel : $orderModel->toArray(),
                $cartLines,
                $operationNo,
                $stockInType
            );
        });

        $run('void_performance', function () use ($refundServices, $orderModel) {
            $refundServices->setItem('terminal_authorized', 1);
            $refundServices->runPerformanceRollback($orderModel);
            $refundServices->reset();
        });

        $run(RefundSideEffectOnceServices::STEP_YEJI_ROLLBACK, function () use ($orderId) {
            StaffYeji::where('link_id', $orderId)->where('type', 2)->update(['status' => 1]);
        });

        $run(RefundSideEffectOnceServices::STEP_COUPON_ROLLBACK, function () use ($orderId) {
            StoreCouponUser::where('use_time', 0)
                ->where('oid', $orderId)
                ->where('type', 'order_get')
                ->delete();
        });

        $run(RefundSideEffectOnceServices::STEP_GIFT_REVOKE, function () use ($orderId, $reason, $forVoid) {
            /** @var StoreOrderGiftServices $gift */
            $gift = app()->make(StoreOrderGiftServices::class);
            if ($forVoid) {
                $gift->revokeLinkedGiftOrdersForVoid($orderId, $reason);
            } else {
                $gift->revokeLinkedGiftOrders($orderId, $reason);
            }
        });

        $run(RefundSideEffectOnceServices::STEP_DEBT_VOID, function () use ($orderId, $reason, $forVoid, $operationNo) {
            /** @var StoreDebtServices $debt */
            $debt = app()->make(StoreDebtServices::class);
            $debt->voidDebtByOrderId($orderId, $reason);
            // 作废联动补交：走统一作废编排（terminal_action=2，不写退款状态）
            $debt->voidRepayOrdersByOriginOrderId($orderId, $reason, $forVoid, $operationNo);
        });

        if (!empty($order['is_debt_repay']) && bccomp((string)($order['pay_price'] ?? '0'), '0', 2) > 0) {
            $run(RefundSideEffectOnceServices::STEP_DEBT_REVERSE, function () use ($order) {
                /** @var StoreDebtServices $debt */
                $debt = app()->make(StoreDebtServices::class);
                $debt->reverseRepayOnRefund((int)$order['id'], (float)$order['pay_price']);
            });
        }

        $run(RefundSideEffectOnceServices::STEP_INVOICE_REFUND, function () use ($orderId) {
            app()->make(StoreOrderInvoiceServices::class)->update(['order_id' => $orderId], ['is_refund' => 1]);
        });
    }
}
