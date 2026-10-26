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
 * 普通/混合结账从已结算销售订单取边界；纯权益结账没有销售订单，改从
 * 已成功结账请求、完成回执和未作废服务事实共同确认同一边界。
 * 它故意不调用“结束服务”生命周期，因此不核销权益、不写服务/业绩事实、
 * 不释放或重建任何排班资源，也不会因后续订单作废而回滚预约状态。
 */
final class CashierV3CheckoutReservationClosureServices
{
    private const UNFINISHED_STATUSES = ['PENDING_CONFIRMATION', 'UNSTARTED', 'IN_SERVICE'];
    private const COMPLETED_STATUS = 'COMPLETED';
    private const SERVICE_LINK_TABLE = 'cashier_v3_reservation_checkout_service_link';

    /**
     * 只读判断是否需要向收银员提示。会员、门店和日期全部从已结算订单，
     * 或纯权益结账权威事实取值，不接受浏览器传入这些统计边界。
     */
    public function preview(array $scope): array
    {
        $order = $this->checkoutAuthority($scope, false);
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
        $order = $this->checkoutAuthority($scope, true);
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
        // 结账项目已经在结账事务中形成唯一服务事实。预约收口只复制其不可变
        // 快照用于预约展示，绝不能再次生成服务、核销或业绩事实。
        $checkoutServices = $this->checkoutProjectServices($order);

        $now = time();
        $completed = [];
        foreach ($rows as $row) {
            $beforeVersion = (int)($row['version'] ?? 0);
            $nextVersion = $beforeVersion + 1;
            if ($beforeVersion <= 0) {
                throw new \LogicException('checkout_reservation_version_invalid');
            }
            $syncedServices = $this->syncCheckoutServices($order, $row, $checkoutServices, $now);
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

            $this->recordCompletionEvent($scope, $order, $row, $nextVersion, $now, $syncedServices);
            $this->recordOperation($scope, $order, $row, $nextVersion, $now, $syncedServices);
            $completed[] = [
                'reservationId' => (int)$row['id'],
                'reservationNo' => (string)($row['reservation_no'] ?? ''),
                'version' => $nextVersion,
                'syncedServiceCount' => count($syncedServices),
            ];
        }

        return $this->completionResult($order, $completed);
    }

    /** @return array<string,mixed>|null */
    private function checkoutAuthority(array $scope, bool $lock): ?array
    {
        $payload = (array)($scope['payload'] ?? []);
        $orderId = trim((string)($payload['salesOrderId'] ?? $payload['orderId'] ?? ''));
        $requestId = trim((string)($payload['checkoutRequestId'] ?? ''));
        if ($orderId !== '') {
            if (strlen($orderId) > 64 || !preg_match('/^[A-Za-z0-9_.:-]+$/', $orderId)) {
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
            if ($lock) $query->lock(true);
            $row = $query->field('id,order_id,order_no,checkout_request_id,tenant_id,store_id,member_id,business_date,order_status,order_direction')->find();
            if (!$row) return null;
            $authority = (array)$row;
            $authority['completion_reference_no'] = (string)$authority['order_no'];
            return $authority;
        }
        if (!preg_match('/^CKR-[0-9a-f]{40}$/D', $requestId)) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::INVALID_COMMAND_CONTEXT,
                '结账请求标识无效。'
            );
        }
        $operator = $scope['operator_scope'];
        $dataScope = $scope['data_scope'];
        $query = Db::name('cashier_v3_checkout_request')
            ->where('request_id', $requestId)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('store_id', $operator->storeId())
            ->where('request_status', 'succeeded')
            // 只有确实不产生销售订单的纯权益结账允许走请求回退；
            // 普通/混合结账必须继续使用销售订单，防止浏览器规避订单状态检查。
            ->where('composition', 'entitlement_only');
        if ($lock) $query->lock(true);
        $request = $query->field('id,request_id,tenant_id,store_id,member_id,business_date,request_status,composition')->find();
        if (!$request || (int)$request['member_id'] <= 0) return null;

        $receiptQuery = Db::name('cashier_v3_entitlement_completion_receipt')
            ->where('tenant_id', (string)$request['tenant_id'])
            ->where('store_id', (int)$request['store_id'])
            ->where('member_id', (int)$request['member_id'])
            ->where('checkout_request_id', (string)$request['request_id'])
            ->where('status', 'completed');
        if ($lock) $receiptQuery->lock(true);
        $receipt = $receiptQuery->field('receipt_id')->find();
        if (!$receipt || !$this->hasCompletedCheckoutService((array)$request, $lock)) return null;

        return [
            'id' => (int)$request['id'], 'order_id' => '', 'order_no' => '',
            'checkout_request_id' => (string)$request['request_id'],
            'completion_reference_no' => (string)$receipt['receipt_id'],
            'tenant_id' => (string)$request['tenant_id'], 'store_id' => (int)$request['store_id'],
            'member_id' => (int)$request['member_id'], 'business_date' => (string)$request['business_date'],
            'order_status' => 'settled', 'order_direction' => 'forward',
        ];
    }

    /** 纯权益回退必须至少存在一条同结账请求的完成且未作废服务事实。 */
    private function hasCompletedCheckoutService(array $request, bool $lock): bool
    {
        $query = Db::name('cashier_v3_entitlement_service_fact')->alias('service')
            ->leftJoin(
                'cashier_v3_service_record_void_operation service_void',
                "service_void.tenant_id=service.tenant_id AND service_void.service_fact_id=service.id AND service_void.status='succeeded'"
            )
            ->where('service.tenant_id', (string)$request['tenant_id'])
            ->where('service.store_id', (int)$request['store_id'])
            ->where('service.member_id', (int)$request['member_id'])
            ->where('service.business_date', (string)$request['business_date'])
            ->where('service.checkout_request_id', (string)$request['request_id'])
            ->where('service.service_status', 'completed')
            ->whereNull('service_void.id');
        if ($lock) $query->lock(true);
        return (bool)$query->field('service.id')->find();
    }

    /** @param array<string,mixed>|null $order */
    private function promptResult(?array $order, int $count): array
    {
        return [
            'required' => $count > 0,
            'reservationCount' => max(0, $count),
            'salesOrderId' => (string)($order['order_id'] ?? ''),
            'salesOrderNo' => (string)($order['order_no'] ?? ''),
            'checkoutRequestId' => (string)($order['checkout_request_id'] ?? ''),
            'checkoutReferenceNo' => (string)($order['completion_reference_no'] ?? ''),
            'businessDate' => (string)($order['business_date'] ?? ''),
        ];
    }

    /** @param array<int,array<string,mixed>> $completed */
    private function completionResult(?array $order, array $completed): array
    {
        $syncedServiceLinkCount = 0;
        foreach ($completed as $item) $syncedServiceLinkCount += (int)($item['syncedServiceCount'] ?? 0);
        return [
            'status' => 'succeeded',
            'salesOrderId' => (string)($order['order_id'] ?? ''),
            'salesOrderNo' => (string)($order['order_no'] ?? ''),
            'checkoutRequestId' => (string)($order['checkout_request_id'] ?? ''),
            'checkoutReferenceNo' => (string)($order['completion_reference_no'] ?? ''),
            'businessDate' => (string)($order['business_date'] ?? ''),
            'completedCount' => count($completed),
            'syncedServiceLinkCount' => $syncedServiceLinkCount,
            'completedReservations' => array_values($completed),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function checkoutProjectServices(array $order): array
    {
        return $this->rows(Db::name('cashier_v3_entitlement_service_fact')->alias('service')
            ->leftJoin(
                'cashier_v3_service_record_void_operation service_void',
                "service_void.tenant_id=service.tenant_id AND service_void.service_fact_id=service.id AND service_void.status='succeeded'"
            )
            ->where('service.tenant_id', (string)$order['tenant_id'])
            ->where('service.store_id', (int)$order['store_id'])
            ->where('service.member_id', (int)$order['member_id'])
            ->where('service.business_date', (string)$order['business_date'])
            // checkout_request_id 同时存在于销售订单和纯权益服务事实，
            // 是两种结账组成共享且不会由浏览器拼接的服务批次边界。
            ->where('service.checkout_request_id', (string)$order['checkout_request_id'])
            ->where('service.service_status', 'completed')
            ->whereNull('service_void.id')
            ->field('service.*')
            ->order('service.id asc')
            ->lock(true)
            ->select());
    }

    /**
     * 同一笔结账服务关联到当天每条未结束预约。唯一键保证命令重试不会重复，
     * 指纹冲突则整批回滚，避免预约详情出现半新半旧的实际服务快照。
     *
     * @return array<int,array<string,mixed>>
     */
    private function syncCheckoutServices(array $order, array $reservation, array $services, int $now): array
    {
        $synced = [];
        foreach ($services as $service) {
            $craftsmenJson = (string)($service['craftsmen_snapshot_json'] ?? '[]');
            $craftsmen = json_decode($craftsmenJson, true);
            if (!is_array($craftsmen)) throw new \LogicException('checkout_reservation_craftsmen_snapshot_invalid');
            $row = [
                'tenant_id' => (string)$order['tenant_id'],
                'reservation_id' => (int)$reservation['id'],
                'sales_order_id' => (string)$order['order_id'],
                'sales_order_no_snapshot' => (string)$order['order_no'],
                'service_fact_id' => (string)($service['service_fact_id'] ?? ''),
                'source_line_id' => (string)($service['source_line_id'] ?? ''),
                'project_id' => (int)($service['project_id'] ?? 0),
                'project_name_snapshot' => mb_substr((string)($service['project_name_snapshot'] ?? ''), 0, 128),
                'quantity' => max(1, (int)($service['quantity'] ?? 1)),
                'service_object' => mb_substr((string)($service['service_object'] ?? ''), 0, 24),
                'primary_craftsman_staff_id' => (int)($service['primary_craftsman_staff_id'] ?? 0),
                'craftsmen_snapshot_json' => $craftsmenJson,
                'business_date' => (string)$order['business_date'],
                'occurred_at' => $now,
                'recorded_at' => $now,
                'created_at' => $now,
            ];
            if ($row['service_fact_id'] === '' || $row['source_line_id'] === '' || $row['project_id'] <= 0) {
                throw new \LogicException('checkout_reservation_service_snapshot_invalid');
            }
            $fingerprintPayload = $row;
            unset($fingerprintPayload['occurred_at'], $fingerprintPayload['recorded_at'], $fingerprintPayload['created_at']);
            ksort($fingerprintPayload, SORT_STRING);
            $row['immutable_fingerprint'] = hash('sha256', json_encode($fingerprintPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $existing = Db::name(self::SERVICE_LINK_TABLE)
                ->where('tenant_id', $row['tenant_id'])
                ->where('reservation_id', $row['reservation_id'])
                ->where('service_fact_id', $row['service_fact_id'])
                ->lock(true)->find();
            if ($existing) {
                if ((string)($existing['immutable_fingerprint'] ?? '') !== $row['immutable_fingerprint']) {
                    throw new \RuntimeException('checkout_reservation_service_link_conflict');
                }
            } elseif ((int)Db::name(self::SERVICE_LINK_TABLE)->insert($row) !== 1) {
                throw new \RuntimeException('checkout_reservation_service_link_insert_failed');
            }
            $synced[] = $row;
        }
        return $synced;
    }

    private function recordCompletionEvent(array $scope, array $order, array $row, int $nextVersion, int $now, array $syncedServices): void
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
                'checkoutRequestId' => (string)$order['checkout_request_id'],
                'salesOrderNo' => (string)$order['order_no'],
                'completionReason' => 'checkout_prompt_confirmation',
                'sideEffectsApplied' => false,
                'syncedServiceFactIds' => array_values(array_column($syncedServices, 'service_fact_id')),
            ],
        ]);
    }

    private function recordOperation(array $scope, array $order, array $row, int $nextVersion, int $now, array $syncedServices): void
    {
        $key = 'CHK-APPT-' . hash('sha256', (string)($scope['idempotency_key'] ?? '') . ':' . (int)$row['id']);
        $result = [
            'status' => 'succeeded',
            'reservationId' => (int)$row['id'],
            'reservationNo' => (string)($row['reservation_no'] ?? ''),
            'reservationStatus' => self::COMPLETED_STATUS,
            'version' => $nextVersion,
            'salesOrderId' => (string)$order['order_id'],
            'checkoutRequestId' => (string)$order['checkout_request_id'],
            'syncedServiceFactIds' => array_values(array_column($syncedServices, 'service_fact_id')),
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
