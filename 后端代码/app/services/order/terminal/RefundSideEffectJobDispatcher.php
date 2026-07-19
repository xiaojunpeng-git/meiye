<?php
declare(strict_types=1);

namespace app\services\order\terminal;

use app\jobs\order\OrderStatusJob;
use app\jobs\order\RefundNoticeJob;
use app\jobs\product\ProductLogJob;
use app\jobs\store\StaffFinanceJob;
use app\jobs\store\StoreFinanceJob;
use app\jobs\supplier\SupplierFinanceJob;
use app\jobs\system\CapitalFlowJob;

/**
 * 退款终态副作用 Redis 入队（可校验返回值 + 可注入第 N 次失败）。
 */
class RefundSideEffectJobDispatcher
{
    /** 0=关闭；N=第 N 次 mustEnqueue/真实入队 失败 */
    public static int $failEnqueueAt = 0;
    public static int $enqueueCount = 0;

    public static function resetInject(): void
    {
        self::$failEnqueueAt = 0;
        self::$enqueueCount = 0;
    }

    public function dispatchRefundEventJobs(array $data, array $order): void
    {
        if (!empty($data['inject_fail_redis_enqueue']) || !empty($order['inject_fail_redis_enqueue'])) {
            throw new \RuntimeException('inject_fail_redis_enqueue');
        }
        $opNo = trim((string)($data['operation_no'] ?? $order['terminal_operation_no'] ?? ''));
        $isRecharge = !empty($data['recharge_refund']) || !empty($order['recharge_refund']);

        if (!empty($order['store_id'])) {
            $financeType = $isRecharge ? 5 : 4;
            $this->mustEnqueue('StoreFinanceJob', StoreFinanceJob::dispatch([
                $order,
                $financeType,
                $data['refund_price'] ?? 0.00,
            ]));
            if ($isRecharge) {
                $this->mustEnqueue('StaffFinanceJob', StaffFinanceJob::dispatch([$order, 5]));
            } else {
                $this->mustEnqueue('StaffFinanceJob', StaffFinanceJob::dispatch([
                    $order,
                    4,
                    $data['refund_price'] ?? 0.00,
                ]));
            }
            $this->mustEnqueue('StoreFinanceJob.takeDoJob', StoreFinanceJob::dispatchDo(
                'takeDoJob',
                [$order, time()],
                10
            ));
        } elseif (!empty($order['supplier_id'])) {
            $this->mustEnqueue('SupplierFinanceJob', SupplierFinanceJob::dispatch([
                (int)$order['id'],
                2,
                $opNo,
            ]));
            $this->mustEnqueue('SupplierFinanceJob.takeDoJob', SupplierFinanceJob::dispatchDo(
                'takeDoJob',
                [$order, time()],
                10
            ));
        }

        $capitalType = $isRecharge ? 'refund_recharge' : 'refund';
        $this->mustEnqueue('CapitalFlowJob', CapitalFlowJob::dispatch([
            array_merge($order, [
                'refund_price' => $data['refund_price'] ?? 0,
                'terminal_idempotency_key' => $order['terminal_capital_flow_key'] ?? ($opNo !== '' ? $opNo . ':capital_flow' : ''),
                'operation_no' => $opNo,
            ]),
            $capitalType,
        ]));

        if (!$isRecharge) {
            $this->mustEnqueue('ProductLogJob', ProductLogJob::dispatch(['refund', [
                'uid' => $order['uid'] ?? 0,
                'order_id' => $order['id'] ?? 0,
                'terminal_idempotency_key' => $order['terminal_product_log_key'] ?? '',
                'operation_no' => $opNo,
            ]]));
        }

        if ($opNo !== '' && !empty($order['terminal_refund_notice_key'])) {
            $this->mustEnqueue('RefundNoticeJob', RefundNoticeJob::dispatch([[
                'data' => $data,
                'order' => $order,
                'operation_no' => $opNo,
                'idempotency_key' => (string)$order['terminal_refund_notice_key'],
            ]]));
        }
    }

    /**
     * 作废财务副作用：Outbox 领取后同步执行 doJob（不依赖可能丢失的 Redis 消息）。
     * 幂等由流水 idempotency_key / once 兜底；Relay 重投也不会双写。
     */
    public function dispatchVoidFinanceJob(array $order, array $data, string $stepType): void
    {
        if (!empty($data['inject_fail_redis_enqueue']) || !empty($order['inject_fail_redis_enqueue'])) {
            throw new \RuntimeException('inject_fail_redis_enqueue');
        }
        $price = (float)($data['refund_price'] ?? $order['pay_price'] ?? 0);
        if ($stepType === RefundSideEffectOutboxServices::STEP_VOID_STORE_FINANCE) {
            if (!empty($order['store_id'])) {
                self::$enqueueCount++;
                if (self::$failEnqueueAt > 0 && self::$enqueueCount === self::$failEnqueueAt) {
                    throw new \RuntimeException('queue enqueue failed: StoreFinanceJob.void (injected)');
                }
                $ok = app()->make(StoreFinanceJob::class)->doJob($order, 16, $price);
                if ($ok === false) {
                    throw new \RuntimeException('void store finance write failed');
                }
            }
            return;
        }
        if ($stepType === RefundSideEffectOutboxServices::STEP_VOID_STAFF_FINANCE) {
            if (!empty($order['store_id'])) {
                self::$enqueueCount++;
                if (self::$failEnqueueAt > 0 && self::$enqueueCount === self::$failEnqueueAt) {
                    throw new \RuntimeException('queue enqueue failed: StaffFinanceJob.void (injected)');
                }
                $ok = app()->make(StaffFinanceJob::class)->doJob($order, 7, $price);
                if ($ok === false) {
                    throw new \RuntimeException('void staff finance write failed');
                }
            }
            return;
        }
        if ($stepType === RefundSideEffectOutboxServices::STEP_VOID_CAPITAL_FLOW) {
            $opNo = trim((string)($order['terminal_operation_no'] ?? $data['operation_no'] ?? ''));
            self::$enqueueCount++;
            if (self::$failEnqueueAt > 0 && self::$enqueueCount === self::$failEnqueueAt) {
                throw new \RuntimeException('queue enqueue failed: CapitalFlowJob.void (injected)');
            }
            $ok = app()->make(CapitalFlowJob::class)->doJob(array_merge($order, [
                'refund_price' => $price,
                'price' => $price,
                'terminal_capital_flow_key' => $order['terminal_capital_flow_key'] ?? ($opNo !== '' ? $opNo . ':capital_flow_void' : ''),
                'terminal_operation_no' => $opNo,
            ]), 'void');
            if ($ok === false) {
                throw new \RuntimeException('void capital flow write failed');
            }
        }
    }

    public function dispatchStatusNotify(array $payload): void
    {
        if (!empty($payload['inject_fail_redis_enqueue'])
            || !empty(($payload['data']['inject_fail_redis_enqueue'] ?? null))) {
            throw new \RuntimeException('inject_fail_redis_enqueue');
        }
        $data = (array)($payload['data'] ?? []);
        if (empty($data['terminal_idempotency_key']) && !empty($data['operation_no'])) {
            $data['terminal_idempotency_key'] = $data['operation_no'] . ':status_notify';
        }
        $this->mustEnqueue('OrderStatusJob', OrderStatusJob::dispatch([
            (int)($payload['order_id'] ?? 0),
            (string)($payload['change_type'] ?? 'refund_price'),
            $data,
        ]));
    }

    public function mustEnqueue(string $jobName, $res): void
    {
        self::$enqueueCount++;
        if (self::$failEnqueueAt > 0 && self::$enqueueCount === self::$failEnqueueAt) {
            throw new \RuntimeException('queue enqueue failed: ' . $jobName . ' (injected #' . self::$enqueueCount . ')');
        }
        if ($res === false || $res === null || $res === 0 || $res === '0') {
            throw new \RuntimeException('queue enqueue failed: ' . $jobName);
        }
    }
}
