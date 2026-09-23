<?php

namespace app\services\cashier\v3\reservation;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\event\CashierV3BusinessEventExecution;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use think\facade\Db;

/**
 * 结账后预约状态收口。
 *
 * 这个服务只把同门店、同会员、同业务日期的未结束预约主记录改为 COMPLETED。
 * 它故意不调用“结束服务”生命周期，因此不核销权益、不写服务/业绩事实、
 * 不释放或重建任何排班资源，也不会因后续订单作废而回滚预约状态。
 */
final class CashierV3CheckoutReservationClosureServices
{
    private const UNFINISHED_STATUSES = ['PENDING_CONFIRMATION', 'UNSTARTED', 'IN_SERVICE'];
    private const COMPLETED_STATUS = 'COMPLETED';

    /**
     * 只读判断是否需要向收银员提示。会员、门店和日期全部从已结算订单取值，
     * 不接受浏览器传入这些统计边界。
     */
    public function preview(array $scope): array
    {
        $order = $this->settledForwardOrder($scope, false);
        if ($order === null || (int)$order['member_id'] <= 0) {
            return $this->promptResult($order, 0);
        }

        $count = (int)Db::name('cashier_v3_reservation')
            ->where('tenant_id', (string)$order['tenant_id'])
            ->where('store_id', (int)$order['store_id'])
            ->where('member_id', (int)$order['member_id'])
            ->where('business_date', (string)$order['business_date'])
            ->where('lifecycle_generation', CashierV3ReservationLifecycleServices::GENERATION)
            ->whereIn('status', self::UNFINISHED_STATUSES)
            ->count();

        return $this->promptResult($order, $count);
    }

    /**
     * 在统一命令事务内重新锁定订单和候选预约。前端预览只决定是否展示提示，
     * 真正写入时仍以服务端当下权威状态为准，已取消、已拒绝或已完成的记录不会被覆盖。
     */
    public function complete(array $scope): array
    {
        $order = $this->settledForwardOrder($scope, true);
        if ($order === null || (int)$order['member_id'] <= 0) {
            return $this->completionResult($order, []);
        }

        $rows = $this->rows(Db::name('cashier_v3_reservation')
            ->where('tenant_id', (string)$order['tenant_id'])
            ->where('store_id', (int)$order['store_id'])
            ->where('member_id', (int)$order['member_id'])
            ->where('business_date', (string)$order['business_date'])
            ->where('lifecycle_generation', CashierV3ReservationLifecycleServices::GENERATION)
            ->whereIn('status', self::UNFINISHED_STATUSES)
            ->order('id asc')
            ->lock(true)
            ->select());

        $now = time();
        $completed = [];
        foreach ($rows as $row) {
            $beforeVersion = (int)($row['version'] ?? 0);
            $nextVersion = $beforeVersion + 1;
            if ($beforeVersion <= 0) {
                throw new \LogicException('checkout_reservation_version_invalid');
            }
            $affected = Db::name('cashier_v3_reservation')
                ->where('id', (int)$row['id'])
                ->where('tenant_id', (string)$order['tenant_id'])
                ->where('version', $beforeVersion)
                ->whereIn('status', self::UNFINISHED_STATUSES)
                ->update([
                    'status' => self::COMPLETED_STATUS,
                    'actual_service_ended_at' => $now,
                    'updated_at' => $now,
                    'version' => $nextVersion,
                ]);
            if ((int)$affected !== 1) {
                // 行锁下仍发生 CAS 失败说明权威记录已变更，整个批次回滚，
                // 避免“同一次点是只结束一部分”的不可解释结果。
                throw new \RuntimeException('checkout_reservation_update_conflict');
            }

            $this->recordCompletionEvent($scope, $order, $row, $nextVersion, $now);
            $this->recordOperation($scope, $order, $row, $nextVersion, $now);
            $completed[] = [
                'reservationId' => (int)$row['id'],
                'reservationNo' => (string)($row['reservation_no'] ?? ''),
                'version' => $nextVersion,
            ];
        }

        return $this->completionResult($order, $completed);
    }

    /** @return array<string,mixed>|null */
    private function settledForwardOrder(array $scope, bool $lock): ?array
    {
        $payload = (array)($scope['payload'] ?? []);
        $orderId = trim((string)($payload['salesOrderId'] ?? $payload['orderId'] ?? ''));
        if ($orderId === '' || strlen($orderId) > 64 || !preg_match('/^[A-Za-z0-9_.:-]+$/', $orderId)) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::INVALID_COMMAND_CONTEXT,
                '结账订单标识无效。'
            );
        }
        $operator = $scope['operator_scope'];
        $dataScope = $scope['data_scope'];
        $query = Db::name('cashier_v3_sales_order')
            ->where('order_id', $orderId)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('store_id', $operator->storeId())
            ->where('order_status', 'settled')
            ->where('order_direction', 'forward');
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->field('id,order_id,order_no,tenant_id,store_id,member_id,business_date,order_status,order_direction')->find();
        return $row ? (array)$row : null;
    }

    /** @param array<string,mixed>|null $order */
    private function promptResult(?array $order, int $count): array
    {
        return [
            'required' => $count > 0,
            'reservationCount' => max(0, $count),
            'salesOrderId' => (string)($order['order_id'] ?? ''),
            'salesOrderNo' => (string)($order['order_no'] ?? ''),
            'businessDate' => (string)($order['business_date'] ?? ''),
        ];
    }

    /** @param array<int,array<string,mixed>> $completed */
    private function completionResult(?array $order, array $completed): array
    {
        return [
            'status' => 'succeeded',
            'salesOrderId' => (string)($order['order_id'] ?? ''),
            'salesOrderNo' => (string)($order['order_no'] ?? ''),
            'businessDate' => (string)($order['business_date'] ?? ''),
            'completedCount' => count($completed),
            'completedReservations' => array_values($completed),
        ];
    }

    private function recordCompletionEvent(array $scope, array $order, array $row, int $nextVersion, int $now): void
    {
        $recorder = $scope['event_recorder'] ?? null;
        $execution = $scope['event_execution'] ?? null;
        if (!$recorder instanceof CashierV3BusinessEventRecorder || !$execution instanceof CashierV3BusinessEventExecution) {
            throw new \LogicException('checkout_reservation_event_services_missing');
        }
        $recorder->recordInTx($execution, (array)($scope['event_contract'] ?? []), [
            'event_type' => 'reservation.checkout_completed',
            'aggregate_type' => 'reservation',
            'aggregate_id' => (string)$row['id'],
            'aggregate_version' => $nextVersion,
            'source_type' => (string)$scope['action'],
            'source_id' => (string)$row['id'],
            'member_id' => (int)$row['member_id'],
            'aggregate_name_snapshot' => (string)($row['reservation_no'] ?? ''),
            'store_name_snapshot' => (string)($row['store_name_snapshot'] ?? ''),
            'occurred_at' => $now,
            'settled_at' => $now,
            'recorded_at' => $now,
            // 事实发生于收银员点“是”的时刻，但统计日仍继承触发它的结账业务日期。
            'business_date' => (string)$order['business_date'],
            'payload' => [
                'reservationNo' => (string)($row['reservation_no'] ?? ''),
                'statusBefore' => (string)($row['status'] ?? ''),
                'statusAfter' => self::COMPLETED_STATUS,
                'actualEndAt' => $now,
                'salesOrderId' => (string)$order['order_id'],
                'salesOrderNo' => (string)$order['order_no'],
                'completionReason' => 'checkout_prompt_confirmation',
                'sideEffectsApplied' => false,
            ],
        ]);
    }

    private function recordOperation(array $scope, array $order, array $row, int $nextVersion, int $now): void
    {
        $key = 'CHK-APPT-' . hash('sha256', (string)($scope['idempotency_key'] ?? '') . ':' . (int)$row['id']);
        $result = [
            'status' => 'succeeded',
            'reservationId' => (int)$row['id'],
            'reservationNo' => (string)($row['reservation_no'] ?? ''),
            'reservationStatus' => self::COMPLETED_STATUS,
            'version' => $nextVersion,
            'salesOrderId' => (string)$order['order_id'],
        ];
        $encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new \RuntimeException('checkout_reservation_operation_encode_failed');
        }
        Db::name('cashier_v3_reservation_operation')->insert([
            'tenant_id' => (string)$order['tenant_id'],
            'command_idempotency_key' => $key,
            'reservation_id' => (int)$row['id'],
            'operation_type' => 'CHECKOUT_COMPLETE',
            'version_before' => (int)$row['version'],
            'version_after' => $nextVersion,
            'result_json' => $encoded,
            'occurred_at' => $now,
            'recorded_at' => $now,
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    private function rows($rows): array
    {
        return is_object($rows) && method_exists($rows, 'toArray') ? $rows->toArray() : (array)$rows;
    }
}
