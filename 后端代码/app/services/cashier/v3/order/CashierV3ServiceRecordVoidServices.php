<?php

namespace app\services\cashier\v3\order;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\event\CashierV3BusinessEventExecution;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use think\facade\Db;

/**
 * 服务记录作废的唯一写入口。
 *
 * 原服务事实永不覆盖；作废操作、权益返还和业绩冲销均作为追加事实写入
 * 同一事务。服务记录查询通过操作表派生“已作废”状态，因此历史原因可追溯。
 */
final class CashierV3ServiceRecordVoidServices
{
    public const OPERATION_TABLE = 'cashier_v3_service_record_void_operation';
    public const ENTITLEMENT_REVERSAL_TABLE = 'cashier_v3_entitlement_reversal_fact';
    public const CONTRACT_VERSION = 'cashier-v3-service-record-void-v1';

    /** @return array<string,mixed> */
    public function executeInTx(string $action, array $scope): array
    {
        CashierV3TransactionGuard::assertInTransaction('serviceRecordVoid.executeInTx');
        $operator = $scope['operator_scope'] ?? null;
        $dataScope = $scope['data_scope'] ?? null;
        $recorder = $scope['event_recorder'] ?? null;
        $execution = $scope['event_execution'] ?? null;
        if (!$operator instanceof CashierV3OperatorScope || !$dataScope instanceof CashierV3DataScopeContext
            || !$recorder instanceof CashierV3BusinessEventRecorder
            || !$execution instanceof CashierV3BusinessEventExecution) {
            throw self::failure('service_void_scope_incomplete');
        }
        $payload = (array)($scope['payload'] ?? []);
        $reason = trim((string)($payload['reason'] ?? $payload['voidReason'] ?? ''));
        if ($reason === '' || mb_strlen($reason) > 255) {
            throw CashierV3CommandException::invalidContext(
                '请填写作废原因（不超过255字）。',
                ['reason' => 'service_void_reason_required']
            );
        }
        $commandKey = trim((string)($scope['idempotency_key'] ?? ''));
        if ($commandKey === '') throw self::failure('service_void_idempotency_missing');

        if (!empty($payload['checkoutRequestId'])) {
            return $this->voidCheckoutGroupInTx($action, $scope, $operator, $dataScope);
        }

        $source = $this->source($payload, $operator, $dataScope, true);
        $existing = Db::name(self::OPERATION_TABLE)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('service_fact_id', (int)$source['id'])
            ->lock(true)->find();
        if ($existing) return $this->result((array)$existing, true);

        $now = time();
        $operationId = 'SRV-' . strtoupper(substr(hash_hmac(
            'sha256', $dataScope->tenantId() . '|' . $source['id'] . '|' . $commandKey, $this->secret()
        ), 0, 40));
        $operationNo = 'SZF' . date('ymdHis', $now) . strtoupper(substr(hash('sha256', $operationId), 0, 6));
        $eventVersion = 1 + (int)Db::name(CashierV3BusinessEventRecorder::EVENT_TABLE)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('aggregate_type', 'service_record')
            ->where('aggregate_id', (string)$source['id'])->count();
        $event = $recorder->recordInTx($execution, (array)($scope['event_contract'] ?? []), [
            'event_type' => 'service_record.voided',
            'aggregate_type' => 'service_record',
            'aggregate_id' => (string)$source['id'],
            'aggregate_version' => $eventVersion,
            'event_version' => 1,
            'source_type' => 'void-service-record',
            'source_id' => $operationId,
            'member_id' => (int)$source['member_id'],
            'business_date' => date('Y-m-d', $now),
            'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now,
            'aggregate_name_snapshot' => (string)$source['service_record_no'],
            'store_name_snapshot' => (string)$source['store_name_snapshot'],
            'payload' => [
                'contractVersion' => self::CONTRACT_VERSION,
                'operationId' => $operationId,
                'operationNo' => $operationNo,
                'serviceFactId' => (int)$source['id'],
                'reason' => $reason,
            ],
        ]);

        $restoredQuantity = $this->restoreEntitlement($source, $operationId, $commandKey, $event, $operator, $dataScope, $now);
        $performance = $this->reversePerformance($source, $operationId, $commandKey, $event, $operator, $dataScope, $now);
        $operatorName = $this->operatorName($operator);
        $row = [
            'operation_id' => $operationId, 'operation_no' => $operationNo,
            'tenant_id' => $dataScope->tenantId(), 'store_id' => $operator->storeId(),
            'member_id' => (int)$source['member_id'], 'service_fact_id' => (int)$source['id'],
            'service_record_no_snapshot' => (string)$source['service_record_no'],
            'checkout_request_id' => (string)$source['checkout_request_id'],
            'source_line_id' => (string)$source['source_line_id'],
            'command_idempotency_key' => $commandKey,
            'immutable_fingerprint' => hash('sha256', json_encode([$operationId, $commandKey, $reason], JSON_UNESCAPED_UNICODE)),
            'reason_snapshot' => $reason, 'operator_id' => $operator->operatorId(),
            'operator_name_snapshot' => $operatorName, 'business_event_no' => (string)$event['event_no'],
            'restored_quantity' => $restoredQuantity,
            'consumption_performance_amount_cents' => $performance['consumptionCents'],
            'labor_performance_amount_cents' => $performance['laborCents'],
            'labor_fee_amount_cents' => $performance['laborFeeCents'],
            'status' => 'succeeded', 'business_date' => date('Y-m-d', $now),
            'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ];
        if ((int)Db::name(self::OPERATION_TABLE)->insert($row) !== 1) {
            throw self::failure('service_void_operation_insert_failed');
        }
        return $this->result($row, false);
    }

    /**
     * 纯权益组只作为服务集合，不创建销售或退款事实。来源由服务端按门店/租户
     * 反查并稳定加锁，复用逐条冲销；任一失败由外层命令事务全部回滚。
     */
    private function voidCheckoutGroupInTx(string $action, array $scope, CashierV3OperatorScope $operator, CashierV3DataScopeContext $dataScope): array
    {
        $requestId = trim((string)$scope['payload']['checkoutRequestId']);
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $requestId) !== 1) {
            throw self::failure('service_void_group_identity_invalid');
        }
        $detail = (new CashierV3SalesOrderQueryServices())->salesOrderDetail(
            ['orderId' => 'service:' . $requestId], $operator, $dataScope
        );
        if (!$detail || empty($detail['entitlementOnly'])) {
            throw CashierV3CommandException::invalidContext('未找到可操作的纯权益订单。');
        }
        // 混合销售必须走销售单生命周期，禁止用服务组入口绕过资金校验。
        $sale = Db::name('cashier_v3_sales_order')->where('tenant_id', $dataScope->tenantId())
            ->where('checkout_request_id', $requestId)->lock(true)->find();
        if ($sale) throw CashierV3CommandException::invalidContext('该单包含销售项目，请从销售订单办理。');
        $rows = Db::name('cashier_v3_entitlement_service_fact')
            ->where('tenant_id', $dataScope->tenantId())->where('store_id', $operator->storeId())
            ->where('checkout_request_id', $requestId)->where('service_status', 'completed')
            ->order('id', 'asc')->lock(true)->select()->toArray();
        if (!$rows || count($rows) > 1000) throw self::failure('service_void_group_not_found_or_too_large');
        $voided = Db::name(self::OPERATION_TABLE)->where('tenant_id', $dataScope->tenantId())
            ->where('checkout_request_id', $requestId)->where('status', 'succeeded')->column('service_fact_id');
        $rows = array_values(array_filter($rows, static function (array $row) use ($voided): bool {
            return !in_array((int)$row['id'], array_map('intval', $voided), true);
        }));
        if (!$rows) throw CashierV3CommandException::invalidContext('本单服务已全部作废，无需重复操作。');
        $results = [];
        foreach ($rows as $row) {
            $child = $scope;
            unset($child['payload']['checkoutRequestId']);
            $child['payload']['serviceFactId'] = (string)$row['id'];
            $child['idempotency_key'] = (string)$scope['idempotency_key'] . ':service:' . $row['id'];
            $results[] = $this->executeInTx($action, $child);
        }
        $result = $results[0];
        $result['records'] = $results;
        $result['touchedRoles'] = array_values(array_unique(array_merge(...array_map(static function (array $item): array {
            return (array)($item['touchedRoles'] ?? []);
        }, $results))));
        $result['message'] = '纯权益订单已作废，相关权益已退回。';
        return $result;
    }

    /** @return array<string,mixed> */
    private function source(array $payload, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope, bool $lock): array
    {
        $id = trim((string)($payload['serviceFactId'] ?? $payload['service_fact_id'] ?? ''));
        if (preg_match('/^[1-9][0-9]*$/D', $id) !== 1) {
            throw CashierV3CommandException::invalidContext('服务记录标识无效，请重新打开记录。', ['reason' => 'service_void_identity_missing']);
        }
        $query = Db::name('cashier_v3_entitlement_service_fact')->alias('sf')
            ->leftJoin('cashier_v3_entitlement_writeoff_fact wf', 'wf.tenant_id=sf.tenant_id AND wf.checkout_request_id=sf.checkout_request_id AND wf.source_line_id=sf.source_line_id')
            ->where('sf.id', (int)$id)->where('sf.tenant_id', $scope->tenantId())->where('sf.store_id', $operator->storeId())
            ->field('sf.*,wf.writeoff_id,wf.holder_id,wf.source_detail_id,wf.origin_order_id,wf.source_kind,wf.source_name_snapshot,wf.source_code_snapshot');
        if ($lock) $query->lock(true);
        $row = $query->find();
        if (!$row || (string)($row['service_status'] ?? '') !== 'completed') {
            throw CashierV3CommandException::invalidContext('服务记录不存在或当前门店不可操作。', ['reason' => 'service_void_record_not_found']);
        }
        return (array)$row;
    }

    private function restoreEntitlement(array $source, string $operationId, string $commandKey, array $event, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope, int $now): int
    {
        $quantity = max(0, (int)($source['quantity'] ?? 0));
        $detailId = (int)($source['source_detail_id'] ?? 0);
        $holderId = (int)($source['holder_id'] ?? 0);
        if ($quantity <= 0 || ($detailId <= 0 && $holderId <= 0)) return 0;
        $detail = $detailId > 0 ? (array)Db::name('store_order_cart_info')->where('id', $detailId)->lock(true)->find() : [];
        $holder = $holderId > 0 ? (array)Db::name('user_card_holder')->where('id', $holderId)->lock(true)->find() : [];
        if ($detailId > 0 && (!$detail || (int)($detail['oid'] ?? 0) !== (int)($source['origin_order_id'] ?? 0))) throw self::failure('service_void_benefit_detail_missing');
        if ($holderId > 0 && (!$holder || (int)($holder['uid'] ?? 0) !== (int)$source['member_id'])) throw self::failure('service_void_card_holder_missing');
        // 时间卡以有效期作为可用条件，legacy 的百万次数仅是兼容投影，
        // 从未在核销时扣减。因此作废也不能把它当作普通次卡回加，否则
        // 每一次“核销后作废”都会虚增该投影次数。
        $unlimitedTimeCard = $holderId > 0 && $detailId > 0 && (bool)Db::name('cashier_v3_card_rule_component')
            ->alias('c')
            ->join('cashier_v3_card_rule_state s', 's.id=c.rule_state_id AND s.tenant_id=c.tenant_id')
            ->where('c.tenant_id', $scope->tenantId())
            ->where('c.card_holder_id', $holderId)
            ->where('c.legacy_detail_id', $detailId)
            ->where('s.member_id', (int)$source['member_id'])
            ->where('s.rule_type', 'time')
            ->lock(true)
            ->value('c.id');
        $restoredQuantity = $unlimitedTimeCard ? 0 : $quantity;
        if (!$unlimitedTimeCard && $detailId > 0) {
            $remaining = (int)($detail['write_surplus_times'] ?? 0) + $quantity;
            Db::name('store_order_cart_info')->where('id', $detailId)->update(['write_surplus_times' => $remaining, 'is_writeoff' => 0]);
        }
        if (!$unlimitedTimeCard && $holderId > 0) {
            $remaining = (int)($holder['write_surplus_times'] ?? 0) + $quantity;
            Db::name('user_card_holder')->where('id', $holderId)->update(['write_surplus_times' => $remaining]);
        }
        $reversalId = 'ER-' . strtoupper(substr(hash_hmac('sha256', $source['id'] . '|' . $operationId, $this->secret()), 0, 40));
        $reversal = [
            'reversal_fact_id' => $reversalId, 'tenant_id' => $scope->tenantId(), 'store_id' => $operator->storeId(),
            'member_id' => (int)$source['member_id'], 'operation_id' => $operationId,
            'original_writeoff_id' => (string)($source['writeoff_id'] ?? ''), 'service_fact_id' => (int)$source['id'],
            'holder_id' => $holderId, 'source_detail_id' => $detailId, 'project_id' => (int)($source['project_id'] ?? 0),
            'quantity' => $restoredQuantity, 'reversal_of' => (string)($source['service_fact_id'] ?? $source['id']),
            'business_event_no' => (string)$event['event_no'], 'command_idempotency_key' => $commandKey,
            'operator_id' => $operator->operatorId(), 'business_date' => date('Y-m-d', $now),
            'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now,
        ];
        if ((int)Db::name(self::ENTITLEMENT_REVERSAL_TABLE)->insert($reversal) !== 1) throw self::failure('service_void_entitlement_reversal_insert_failed');
        return $restoredQuantity;
    }

    /** @return array{consumptionCents:int,laborCents:int,laborFeeCents:int} */
    private function reversePerformance(array $source, string $operationId, string $commandKey, array $event, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope, int $now): array
    {
        $rows = Db::name('cashier_v3_performance_fact')->where('tenant_id', $scope->tenantId())
            ->where('checkout_request_id', (string)$source['checkout_request_id'])->where('source_line_id', (string)$source['source_line_id'])
            ->where('status', 'effective')
            ->whereIn('performance_type', ['consumption_performance_recorded', 'labor_performance_allocated'])
            ->lock(true)->select()->toArray();
        $reversed = [];
        foreach ((array)$rows as $row) {
            if ((string)($row['fact_direction'] ?? '') === 'reversal' && trim((string)($row['reversal_of'] ?? '')) !== '') {
                $reversed[(string)$row['reversal_of']] = true;
            }
        }
        $totals = ['consumptionCents' => 0, 'laborCents' => 0, 'laborFeeCents' => 0];
        foreach ((array)$rows as $row) {
            if ((string)($row['fact_direction'] ?? '') !== 'forward'
                || isset($reversed[(string)($row['fact_id'] ?? '')])) continue;
            $type = (string)($row['performance_type'] ?? '');
            $amount = (int)($row['amount_cents'] ?? 0);
            $laborFee = (int)($row['labor_fee_amount_cents'] ?? 0);
            if ($type === 'consumption_performance_recorded') $totals['consumptionCents'] += $amount;
            if ($type === 'labor_performance_allocated') { $totals['laborCents'] += $amount; $totals['laborFeeCents'] += $laborFee; }
            $copy = $row; unset($copy['id']);
            $copy['fact_id'] = 'SRV-' . strtoupper(substr(hash_hmac('sha256', (string)$row['fact_id'] . '|' . $operationId, $this->secret()), 0, 40));
            $copy['fact_direction'] = 'reversal'; $copy['reversal_of'] = (string)$row['fact_id'];
            $copy['natural_key'] = 'service_record_void:' . hash('sha256', (string)$row['fact_id'] . '|' . $operationId);
            $copy['command_idempotency_key'] = $commandKey; $copy['business_event_no'] = (string)$event['event_no'];
            $copy['operator_id'] = $operator->operatorId(); $copy['amount_cents'] = -$amount;
            $copy['allocation_base_amount_cents'] = -(int)($row['allocation_base_amount_cents'] ?? $amount);
            $copy['labor_fee_amount_cents'] = -$laborFee; $copy['fact_version'] = 1;
            $copy['business_date'] = date('Y-m-d', $now); $copy['occurred_at'] = $now; $copy['settled_at'] = $now; $copy['recorded_at'] = $now;
            $copy['immutable_fingerprint'] = hash('sha256', json_encode($copy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if ((int)Db::name('cashier_v3_performance_fact')->insert($copy) !== 1) throw self::failure('service_void_performance_reversal_insert_failed');
        }
        return $totals;
    }

    private function result(array $row, bool $replayed): array
    {
        $touchedRoles = [];
        $reversal = Db::name(self::ENTITLEMENT_REVERSAL_TABLE)
            ->where('operation_id', (string)$row['operation_id'])->find();
        if ((int)($reversal['source_detail_id'] ?? 0) > 0) $touchedRoles[] = 'member_benefit_pool';
        if ((int)($reversal['holder_id'] ?? 0) > 0) $touchedRoles[] = 'card_holder';
        return [
            'contractVersion' => self::CONTRACT_VERSION, 'operationId' => (string)$row['operation_id'],
            'operationNo' => (string)$row['operation_no'], 'status' => (string)$row['status'],
            'serviceFactId' => (int)$row['service_fact_id'], 'reason' => (string)$row['reason_snapshot'],
            'voidedAt' => $this->dateTime((int)$row['occurred_at']), 'operatorName' => (string)($row['operator_name_snapshot'] ?? ''),
            'restoredQuantity' => (int)($row['restored_quantity'] ?? 0),
            'consumptionPerformanceAmount' => $this->money((int)($row['consumption_performance_amount_cents'] ?? 0)),
            'laborPerformanceAmount' => $this->money((int)($row['labor_performance_amount_cents'] ?? 0)),
            'laborFeeAmount' => $this->money((int)($row['labor_fee_amount_cents'] ?? 0)),
            'replayed' => $replayed, 'touchedRoles' => $touchedRoles, 'message' => '服务记录已作废。',
        ];
    }

    private function operatorName(CashierV3OperatorScope $operator): string
    {
        $row = Db::name('system_store_staff')->alias('s')->leftJoin('employee e', 'e.id=s.employee_id')
            ->where('s.id', $operator->operatorId())->field('s.staff_name,e.name')->find();
        return trim((string)($row['name'] ?? '')) ?: trim((string)($row['staff_name'] ?? ''));
    }

    private function money(int $cents): string { return number_format($cents / 100, 2, '.', ''); }
    private function dateTime(int $timestamp): string { return $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : ''; }
    private function secret(): string { $secret = trim((string)config('cashier_v3.checkout_namespace_secret')); if (strlen($secret) < 32) throw self::failure('service_void_secret_missing'); return $secret; }
    private static function failure(string $reason, array $detail = []): CashierV3CommandException { return new CashierV3CommandException(CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE, '服务记录作废未完成，请刷新后重试。', CashierV3ResultCode::STATUS_FAILED, array_merge(['reason' => $reason], $detail)); }
}
