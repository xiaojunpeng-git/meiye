<?php

namespace app\services\report;

use think\facade\Db;

/**
 * 门店报表“个人（本人参与）”的唯一后端参与关系出口。
 *
 * 员工 ID 只能由认证后的 DataScopeContext 注入。本类接收的字段
 * 表达式都是服务端硬编码，不允许从请求参数传入。
 */
final class StoreReportParticipantScopeServices
{
    /** @return array<int,int> */
    public function participatingStoreIds(string $tenantId, int $employeeId): array
    {
        $this->assertEmployee($employeeId);
        $storeIds = [];
        foreach ([
            ['cashier_v3_performance_fact', 'employee_id'],
            ['cashier_v3_customer_guide_round_fact', 'guide_employee_id'],
            ['cashier_v3_sales_manager_fact', 'sales_manager_employee_id'],
        ] as [$table, $employeeField]) {
            $ids = Db::name($table)->where('tenant_id', $tenantId)
                ->where($employeeField, $employeeId)->where('status', 'effective')
                ->where('store_id', '>', 0)->column('store_id');
            $storeIds = array_merge($storeIds, array_map('intval', $ids));
        }
        return array_values(array_unique(array_filter($storeIds)));
    }

    public function applyOrder($query, string $orderField, int $employeeId)
    {
        $this->assertEmployee($employeeId);
        $tenantField = $this->tenantFieldFor($orderField);
        return $query->where(function ($participant) use ($orderField, $tenantField, $employeeId) {
            $participant->whereExists(function ($fact) use ($orderField, $tenantField, $employeeId) {
                $fact->name('cashier_v3_performance_fact')->alias('report_participant_pf')
                    ->whereRaw('report_participant_pf.order_id=' . $orderField)
                    ->whereRaw('report_participant_pf.tenant_id=' . $tenantField)
                    ->where('report_participant_pf.employee_id', $employeeId)
                    ->where('report_participant_pf.status', 'effective');
            })->whereExists(function ($fact) use ($orderField, $tenantField, $employeeId) {
                $fact->name('cashier_v3_customer_guide_round_fact')->alias('report_participant_gf')
                    ->whereRaw('report_participant_gf.order_id=' . $orderField)
                    ->whereRaw('report_participant_gf.tenant_id=' . $tenantField)
                    ->where('report_participant_gf.guide_employee_id', $employeeId)
                    ->where('report_participant_gf.status', 'effective');
            }, 'OR')->whereExists(function ($fact) use ($orderField, $tenantField, $employeeId) {
                $fact->name('cashier_v3_sales_manager_fact')->alias('report_participant_mf')
                    ->whereRaw('report_participant_mf.order_id=' . $orderField)
                    ->whereRaw('report_participant_mf.tenant_id=' . $tenantField)
                    ->where('report_participant_mf.sales_manager_employee_id', $employeeId)
                    ->where('report_participant_mf.status', 'effective');
            }, 'OR');
        });
    }

    public function applyCheckout($query, string $checkoutField, int $employeeId)
    {
        $this->assertEmployee($employeeId);
        $tenantField = $this->tenantFieldFor($checkoutField);
        return $query->where(function ($participant) use ($checkoutField, $tenantField, $employeeId) {
            $participant->whereExists(function ($fact) use ($checkoutField, $tenantField, $employeeId) {
                $fact->name('cashier_v3_performance_fact')->alias('report_checkout_pf')
                    ->whereRaw('report_checkout_pf.checkout_request_id=' . $checkoutField)
                    ->whereRaw('report_checkout_pf.tenant_id=' . $tenantField)
                    ->where('report_checkout_pf.employee_id', $employeeId)
                    ->where('report_checkout_pf.status', 'effective');
            })->whereExists(function ($fact) use ($checkoutField, $tenantField, $employeeId) {
                $fact->name('cashier_v3_customer_guide_round_fact')->alias('report_checkout_gf')
                    ->whereRaw('report_checkout_gf.checkout_request_id=' . $checkoutField)
                    ->whereRaw('report_checkout_gf.tenant_id=' . $tenantField)
                    ->where('report_checkout_gf.guide_employee_id', $employeeId)
                    ->where('report_checkout_gf.status', 'effective');
            }, 'OR')->whereExists(function ($fact) use ($checkoutField, $tenantField, $employeeId) {
                $fact->name('cashier_v3_sales_manager_fact')->alias('report_checkout_mf')
                    ->whereRaw('report_checkout_mf.checkout_request_id=' . $checkoutField)
                    ->whereRaw('report_checkout_mf.tenant_id=' . $tenantField)
                    ->where('report_checkout_mf.sales_manager_employee_id', $employeeId)
                    ->where('report_checkout_mf.status', 'effective');
            }, 'OR');
        });
    }

    public function applyEmployeeFact($query, string $employeeField, int $employeeId)
    {
        $this->assertEmployee($employeeId);
        return $query->where($employeeField, $employeeId);
    }

    /**
     * Resolve a persisted report subject. employeeId=0 validates existence and
     * tenant only; a positive employee id additionally enforces participant scope.
     *
     * @return array{store_id:int,source_fact_id:int,source_order_id:string,source_line_id:string}|null
     */
    public function resolveSubject(string $tenantId, string $subjectType, string $subjectKey, int $employeeId = 0): ?array
    {
        if ($subjectType === 'market_member_day') {
            // 合并行不能信任客户端提交的门店和会员 ID；复用报表查询验证
            // 当前日期与来源下确实存在这一条门店范围内的记录。
            // 个人参与视图可能只看得到组内部分订单，不能保存全店合并值。
            if ($employeeId > 0) return null;
            if (preg_match('/^market-day-v1:([1-9][0-9]*):(\d{4}-\d{2}-\d{2}):([1-9][0-9]*):([1-9][0-9]*)$/D', $subjectKey, $parts) !== 1) return null;
            [$storeId, $date, $memberId, $sourceId] = [(int)$parts[1], $parts[2], (int)$parts[3], (int)$parts[4]];
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date) return null;
            // 门店 ID 虽由行键解析，仍须确认归属当前租户，避免跨实例伪造。
            if (!Db::name('cashier_v3_sales_order')->where('tenant_id', $tenantId)->where('store_id', $storeId)->value('id')) return null;
            $input = [
                'report' => 'market_detail', 'start_date' => $date, 'end_date' => $date,
                'dimension_code' => (string)$sourceId, '_internal_all' => true,
            ];
            if ($employeeId > 0) $input['_report_scope'] = ['mode' => 'self_participant', 'employee_id' => $employeeId];
            $result = (new StoreUnifiedReportServices())->query([$storeId], $input);
            foreach ((array)($result['records'] ?? []) as $row) {
                if ((string)($row['annotation_subject_key'] ?? '') !== $subjectKey
                    || (int)($row['member_id'] ?? 0) !== $memberId) continue;
                return ['store_id' => $storeId, 'source_fact_id' => 0, 'source_order_id' => '', 'source_line_id' => ''];
            }
            return null;
        }
        if ($subjectType === 'market_guest_order') {
            // 游客没有会员 ID，只能由服务端用“门店+业务日+来源+原单”
            // 重建报表行后反查。客户端自报的 store_id/source_order_id 均不可信。
            if ($employeeId > 0) return null;
            // 历史游客单可能未选择市场来源，此时 sourceId=0 仍是真实行维度，
            // 不能因为页面显示“-”就拒绝保存；门店 ID 仍必须为正数。
            if (preg_match('/^market-guest-v1:([1-9][0-9]*):(\d{4}-\d{2}-\d{2}):([0-9]+):([a-f0-9]{64})$/D', $subjectKey, $parts) !== 1) return null;
            [$storeId, $date, $sourceId] = [(int)$parts[1], $parts[2], (int)$parts[3]];
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date) return null;
            if (!Db::name('cashier_v3_sales_order')->where('tenant_id', $tenantId)->where('store_id', $storeId)->value('id')) return null;
            $result = (new StoreUnifiedReportServices())->query([$storeId], [
                'report' => 'market_detail', 'start_date' => $date, 'end_date' => $date,
                'dimension_code' => (string)$sourceId, '_internal_all' => true,
            ]);
            foreach ((array)($result['records'] ?? []) as $row) {
                if ((string)($row['annotation_subject_key'] ?? '') !== $subjectKey
                    || (int)($row['member_id'] ?? 0) > 0) continue;
                $orders = array_values((array)($row['_market_orders'] ?? []));
                if (count($orders) !== 1 || trim((string)($orders[0]['order_id'] ?? '')) === '') return null;
                return [
                    'store_id' => $storeId, 'source_fact_id' => 0,
                    'source_order_id' => (string)$orders[0]['order_id'], 'source_line_id' => '',
                ];
            }
            return null;
        }
        if (in_array($subjectType, ['sale_line', 'report_row'], true)) {
            $line = Db::name('cashier_v3_sale_fact')->alias('subject_sale')
                ->where('subject_sale.tenant_id', $tenantId)->where('subject_sale.source_line_id', $subjectKey)
                ->where('subject_sale.status', 'effective');
            if ($employeeId > 0) $this->applyOrder($line, 'subject_sale.order_id', $employeeId);
            $row = $line->field('subject_sale.id,subject_sale.store_id,subject_sale.order_id,subject_sale.source_line_id')->find();
            if (is_array($row)) return [
                'store_id' => (int)$row['store_id'],
                'source_fact_id' => (int)$row['id'],
                'source_order_id' => (string)$row['order_id'],
                'source_line_id' => (string)$row['source_line_id'],
            ];
        }
        if (in_array($subjectType, ['sales_order', 'report_row'], true)) {
            $order = Db::name('cashier_v3_sales_order')->alias('subject_order')
                ->where('subject_order.tenant_id', $tenantId)->where('subject_order.order_id', $subjectKey);
            if ($employeeId > 0) $this->applyOrder($order, 'subject_order.order_id', $employeeId);
            $row = $order->field('subject_order.id,subject_order.store_id,subject_order.order_id')->find();
            if (is_array($row)) return [
                'store_id' => (int)$row['store_id'],
                'source_fact_id' => (int)$row['id'],
                'source_order_id' => (string)$row['order_id'],
                'source_line_id' => '',
            ];
        }
        if ($subjectType === 'business_event_line') {
            if (strpos($subjectKey, 'payment-allocation:') === 0) {
                $parts = explode(':', substr($subjectKey, strlen('payment-allocation:')), 2);
                $allocationId = (string)($parts[0] ?? '');
                $itemAllocationId = (string)($parts[1] ?? '');
                $payment = Db::name('cashier_v3_payment_sale_allocation_fact')->alias('subject_payment')
                    ->where('subject_payment.tenant_id', $tenantId)
                    ->where('subject_payment.allocation_fact_id', $allocationId)
                    ->where('subject_payment.status', 'effective');
                if ($employeeId > 0) $this->applyOrder($payment, 'subject_payment.order_id', $employeeId);
                $row = $payment->field('subject_payment.id,subject_payment.store_id,subject_payment.order_id,subject_payment.source_line_id')->find();
                if (is_array($row) && $itemAllocationId !== '') {
                    $itemExists = Db::name('cashier_v3_card_sale_item_allocation_fact')
                        ->where('tenant_id', $tenantId)->where('allocation_fact_id', $itemAllocationId)
                        ->where('order_id', (string)$row['order_id'])
                        ->where('source_line_id', (string)$row['source_line_id'])
                        ->where('status', 'effective')->value('id');
                    if (!$itemExists) return null;
                }
                if (is_array($row)) return [
                    'store_id' => (int)$row['store_id'], 'source_fact_id' => (int)$row['id'],
                    'source_order_id' => (string)$row['order_id'],
                    'source_line_id' => (string)$row['source_line_id'],
                ];
            }
            if (strpos($subjectKey, 'service:') === 0) {
                $serviceFactId = substr($subjectKey, strlen('service:'));
                $service = Db::name('cashier_v3_entitlement_service_fact')->alias('subject_service')
                    ->leftJoin(
                        'cashier_v3_entitlement_writeoff_fact subject_writeoff',
                        "subject_writeoff.tenant_id=subject_service.tenant_id"
                        . " AND subject_writeoff.checkout_request_id=subject_service.checkout_request_id"
                        . " AND subject_writeoff.source_line_id=subject_service.source_line_id"
                        . " AND subject_writeoff.status='effective'"
                    )
                    ->where('subject_service.tenant_id', $tenantId)
                    ->where('subject_service.service_fact_id', $serviceFactId)
                    ->where('subject_service.service_status', 'completed');
                if ($employeeId > 0) $this->applyCheckout($service, 'subject_service.checkout_request_id', $employeeId);
                $row = $service->field('subject_service.id,subject_service.store_id,subject_writeoff.origin_order_id,subject_service.source_line_id')->find();
                if (is_array($row)) return [
                    'store_id' => (int)$row['store_id'], 'source_fact_id' => (int)$row['id'],
                    'source_order_id' => (string)$row['origin_order_id'],
                    'source_line_id' => (string)$row['source_line_id'],
                ];
            }
            if (strpos($subjectKey, 'card-operation:') === 0) {
                $operationId = substr($subjectKey, strlen('card-operation:'));
                $operation = Db::name('cashier_v3_card_operation')->alias('subject_operation')
                    ->where('subject_operation.tenant_id', $tenantId)
                    ->where('subject_operation.operation_id', $operationId)
                    ->where('subject_operation.operation_status', 'succeeded')
                    ->where('subject_operation.operation_type', 'card_upgrade');
                if ($employeeId > 0) $this->applyCheckout($operation, 'subject_operation.checkout_request_id', $employeeId);
                $row = $operation->field('subject_operation.id,subject_operation.store_id,subject_operation.origin_order_id,subject_operation.operation_id')->find();
                if (is_array($row)) return [
                    'store_id' => (int)$row['store_id'], 'source_fact_id' => (int)$row['id'],
                    'source_order_id' => (string)$row['origin_order_id'],
                    'source_line_id' => (string)$row['operation_id'],
                ];
            }
            if (strpos($subjectKey, 'card-operation-line:') === 0) {
                $operationLineId = substr($subjectKey, strlen('card-operation-line:'));
                $operation = Db::name('cashier_v3_card_operation_line')->alias('subject_operation_line')
                    ->join('cashier_v3_card_operation subject_operation', 'subject_operation.tenant_id=subject_operation_line.tenant_id AND subject_operation.operation_id=subject_operation_line.operation_id')
                    ->where('subject_operation_line.tenant_id', $tenantId)
                    ->where('subject_operation_line.operation_line_id', $operationLineId)
                    ->where('subject_operation_line.line_role', 'source_project')
                    ->where('subject_operation.operation_status', 'succeeded')
                    ->whereIn('subject_operation.operation_type', ['project_upgrade', 'project_replacement']);
                if ($employeeId > 0) $this->applyCheckout($operation, 'subject_operation.checkout_request_id', $employeeId);
                $row = $operation->field('subject_operation_line.id,subject_operation.store_id,subject_operation.origin_order_id,subject_operation_line.operation_line_id')->find();
                if (is_array($row)) return [
                    'store_id' => (int)$row['store_id'], 'source_fact_id' => (int)$row['id'],
                    'source_order_id' => (string)$row['origin_order_id'],
                    'source_line_id' => (string)$row['operation_line_id'],
                ];
            }
        }
        return null;
    }

    public function annotationIsVisible(array $annotation, string $tenantId, int $employeeId): bool
    {
        return $this->resolveSubject(
            $tenantId,
            (string)($annotation['subject_type'] ?? ''),
            (string)($annotation['subject_key'] ?? ''),
            $employeeId
        ) !== null;
    }

    private function assertEmployee(int $employeeId): void
    {
        if ($employeeId <= 0) throw new \InvalidArgumentException('个人数据权限缺少有效员工身份');
    }

    private function tenantFieldFor(string $factField): string
    {
        if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\.[A-Za-z_][A-Za-z0-9_]*$/D', $factField, $matches) !== 1) {
            throw new \InvalidArgumentException('报表参与范围缺少明确事实别名');
        }
        return $matches[1] . '.tenant_id';
    }
}
