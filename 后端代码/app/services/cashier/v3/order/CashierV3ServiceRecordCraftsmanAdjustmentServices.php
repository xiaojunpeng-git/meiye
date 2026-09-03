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
 * 已完成服务记录的手艺人分配调整。
 *
 * 这里只调整员工归属的消耗业绩、手工费和工资项目数。会员权益次数、
 * 核销数量、销售订单及退款状态均不是本动作的写域。原业绩事实通过
 * reversal 抵消，新分配再以 forward 事实追加，历史始终可追溯。
 */
final class CashierV3ServiceRecordCraftsmanAdjustmentServices
{
    public const OPERATION_TABLE = 'cashier_v3_service_record_adjustment_operation';
    public const CONTRACT_VERSION = 'cashier-v3-service-record-craftsman-adjustment-v1';

    /** @return array<string,mixed> */
    public function entry(array $payload, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): array
    {
        $source = $this->source($payload, $operator, $scope, false);
        $facts = $this->activeLaborFacts($source, false);
        $totalCents = $this->writeoffAmountCents($source, $scope->tenantId());
        $allocations = $this->presentAllocations($facts, $source, $operator->storeId());
        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'serviceFactId' => (int)$source['id'],
            'serviceRecordNo' => (string)$source['service_record_no'],
            'serviceProject' => (string)$source['project_name_snapshot'],
            'projectId' => (int)$source['project_id'],
            'memberName' => (string)$source['member_name_snapshot'],
            'writeoffQuantity' => (int)$source['quantity'],
            'allocationTotalAmountCents' => $totalCents,
            'allocationTotalAmount' => $this->money($totalCents),
            'recordVersion' => $this->recordVersion($source, $scope->tenantId()),
            'allocations' => $allocations,
            'craftsmenCandidates' => $this->candidates($operator->storeId()),
            'projectCountStep' => '0.5',
            'projectCountDecimals' => 1,
        ];
    }

    /** @return array<string,mixed> */
    public function executeInTx(array $scope): array
    {
        CashierV3TransactionGuard::assertInTransaction('serviceRecordCraftsmanAdjustment.executeInTx');
        $operator = $scope['operator_scope'] ?? null;
        $dataScope = $scope['data_scope'] ?? null;
        $recorder = $scope['event_recorder'] ?? null;
        $execution = $scope['event_execution'] ?? null;
        if (!$operator instanceof CashierV3OperatorScope || !$dataScope instanceof CashierV3DataScopeContext
            || !$recorder instanceof CashierV3BusinessEventRecorder
            || !$execution instanceof CashierV3BusinessEventExecution) {
            throw self::failure('service_adjust_scope_incomplete');
        }
        $payload = (array)($scope['payload'] ?? []);
        $reason = trim((string)($payload['reason'] ?? ''));
        if ($reason === '' || mb_strlen($reason) > 255) {
            throw CashierV3CommandException::invalidContext('请填写修改原因（不超过255字）。', ['reason' => 'service_adjust_reason_required']);
        }
        $commandKey = trim((string)($scope['idempotency_key'] ?? ''));
        if ($commandKey === '') throw self::failure('service_adjust_idempotency_missing');
        $existing = Db::name(self::OPERATION_TABLE)->where('tenant_id', $dataScope->tenantId())
            ->where('command_idempotency_key', $commandKey)->lock(true)->find();
        if ($existing) return $this->result((array)$existing, true);

        $source = $this->source($payload, $operator, $dataScope, true);
        $expectedVersion = (int)($payload['recordVersion'] ?? $payload['expectedVersion'] ?? 0);
        $currentVersion = $this->recordVersion($source, $dataScope->tenantId());
        if ($expectedVersion <= 0 || $expectedVersion !== $currentVersion) {
            throw CashierV3CommandException::invalidContext('服务记录已被其他人修改，请刷新后重新编辑。', [
                'reason' => 'service_adjust_version_conflict',
                'expectedVersion' => $expectedVersion,
                'currentVersion' => $currentVersion,
            ]);
        }

        $currentFacts = $this->activeLaborFacts($source, true);
        $before = $this->presentAllocations($currentFacts, $source, $operator->storeId());
        $totalCents = $this->writeoffAmountCents($source, $dataScope->tenantId());
        $after = $this->normalizeAllocations($payload['allocations'] ?? null, $operator->storeId(), $totalCents);
        $now = time();
        $operationId = 'SRA-' . strtoupper(substr(hash_hmac(
            'sha256', $dataScope->tenantId() . '|' . $source['id'] . '|' . $commandKey, $this->secret()
        ), 0, 40));
        $operationNo = 'STY' . date('ymdHis', $now) . strtoupper(substr(hash('sha256', $operationId), 0, 6));
        $eventVersion = 1 + (int)Db::name(CashierV3BusinessEventRecorder::EVENT_TABLE)
            ->where('tenant_id', $dataScope->tenantId())->where('aggregate_type', 'service_record')
            ->where('aggregate_id', (string)$source['id'])->count();
        $event = $recorder->recordInTx($execution, (array)($scope['event_contract'] ?? []), [
            'event_type' => 'service_record.craftsmen_adjusted',
            'aggregate_type' => 'service_record', 'aggregate_id' => (string)$source['id'],
            'aggregate_version' => $eventVersion, 'event_version' => 1,
            'source_type' => 'adjust-service-record-craftsmen', 'source_id' => $operationId,
            'member_id' => (int)$source['member_id'], 'business_date' => (string)$source['business_date'],
            'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now,
            'aggregate_name_snapshot' => (string)$source['service_record_no'],
            'store_name_snapshot' => (string)$source['store_name_snapshot'],
            'payload' => [
                'contractVersion' => self::CONTRACT_VERSION, 'operationId' => $operationId,
                'operationNo' => $operationNo, 'serviceFactId' => (int)$source['id'],
                'reason' => $reason, 'allocationTotalAmountCents' => $totalCents,
            ],
        ]);

        $template = $this->performanceTemplate($source, $dataScope->tenantId());
        foreach ($currentFacts as $fact) {
            $this->insertReversal($fact, $operationId, $commandKey, $event, $operator, $now);
        }
        foreach ($after as $index => $allocation) {
            $this->insertAllocation($template, $allocation, $index, $source, $operationId, $commandKey, $event, $operator, $now, $totalCents);
        }

        $operatorName = $this->operatorName($operator);
        $row = [
            'operation_id' => $operationId, 'operation_no' => $operationNo,
            'tenant_id' => $dataScope->tenantId(), 'store_id' => $operator->storeId(),
            'member_id' => (int)$source['member_id'], 'service_fact_id' => (int)$source['id'],
            'service_record_no_snapshot' => (string)$source['service_record_no'],
            'checkout_request_id' => (string)$source['checkout_request_id'],
            'source_line_id' => (string)$source['source_line_id'],
            'command_idempotency_key' => $commandKey,
            'reason_snapshot' => $reason,
            'before_snapshot_json' => $this->json($before), 'after_snapshot_json' => $this->json($after),
            'allocation_total_amount_cents' => $totalCents,
            'operator_id' => $operator->operatorId(), 'operator_name_snapshot' => $operatorName,
            'business_event_no' => (string)$event['event_no'], 'status' => 'succeeded',
            'business_date' => (string)$source['business_date'], 'occurred_at' => $now,
            'settled_at' => $now, 'recorded_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ];
        $row['immutable_fingerprint'] = hash('sha256', $this->json($row));
        if ((int)Db::name(self::OPERATION_TABLE)->insert($row) !== 1) throw self::failure('service_adjust_operation_insert_failed');
        return $this->result($row, false);
    }

    /** @return array<string,mixed> */
    private function source(array $payload, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope, bool $lock): array
    {
        $id = trim((string)($payload['serviceFactId'] ?? $payload['service_fact_id'] ?? ''));
        if (preg_match('/^[1-9][0-9]*$/D', $id) !== 1) {
            throw CashierV3CommandException::invalidContext('服务记录标识无效，请重新打开记录。', ['reason' => 'service_adjust_identity_missing']);
        }
        $query = Db::name('cashier_v3_entitlement_service_fact')->alias('sf')
            ->leftJoin('cashier_v3_service_record_void_operation vo', 'vo.tenant_id=sf.tenant_id AND vo.service_fact_id=sf.id AND vo.status=\'succeeded\'')
            ->where('sf.id', (int)$id)->where('sf.tenant_id', $scope->tenantId())
            ->where('sf.store_id', $operator->storeId())->field('sf.*,vo.id AS void_operation_id');
        if ($lock) $query->lock(true);
        $row = $query->find();
        if (!$row || (string)($row['service_status'] ?? '') !== 'completed' || !empty($row['void_operation_id'])) {
            throw CashierV3CommandException::invalidContext('只有当前门店的正常服务记录可以修改手艺人。', ['reason' => 'service_adjust_record_not_normal']);
        }
        return (array)$row;
    }

    private function recordVersion(array $source, string $tenantId): int
    {
        return 1 + (int)Db::name(self::OPERATION_TABLE)->where('tenant_id', $tenantId)
            ->where('service_fact_id', (int)$source['id'])->where('status', 'succeeded')->count();
    }

    private function writeoffAmountCents(array $source, string $tenantId): int
    {
        $amount = max(0, (int)($source['actual_entitlement_amount_cents'] ?? 0));
        if ($amount > 0) return $amount;
        $rows = Db::name('cashier_v3_performance_fact')->where('tenant_id', $tenantId)
            ->where('checkout_request_id', (string)$source['checkout_request_id'])
            ->where('source_line_id', (string)$source['source_line_id'])
            ->where('performance_type', 'consumption_performance_recorded')->where('status', 'effective')
            ->field('amount_cents')->select()->toArray();
        foreach ($rows as $row) $amount += (int)($row['amount_cents'] ?? 0);
        if ($amount > 0) return $amount;

        // 兼容已存在的历史服务记录：旧事实表没有保存项目级核销金额时，
        // 以该服务有效劳动业绩的净额作为本次必须重分配的固定金额。后续
        // 调整写入的反向及新正向事实会相互抵消，净额仍保持原项目金额。
        $laborAmount = (int)Db::name('cashier_v3_performance_fact')->where('tenant_id', $tenantId)
            ->where('checkout_request_id', (string)$source['checkout_request_id'])
            ->where('source_line_id', (string)$source['source_line_id'])
            ->where('performance_type', 'labor_performance_allocated')->where('status', 'effective')
            ->sum('amount_cents');
        return max(0, $laborAmount);
    }

    /** @return array<int,array<string,mixed>> */
    private function activeLaborFacts(array $source, bool $lock): array
    {
        $query = Db::name('cashier_v3_performance_fact')->where('tenant_id', (string)$source['tenant_id'])
            ->where('checkout_request_id', (string)$source['checkout_request_id'])
            ->where('source_line_id', (string)$source['source_line_id'])
            ->where('performance_type', 'labor_performance_allocated')->where('status', 'effective')
            ->order('id', 'asc');
        if ($lock) $query->lock(true);
        $rows = $query->select()->toArray();
        $reversed = [];
        foreach ($rows as $row) {
            if ((string)($row['fact_direction'] ?? '') === 'reversal' && trim((string)($row['reversal_of'] ?? '')) !== '') {
                $reversed[(string)$row['reversal_of']] = true;
            }
        }
        return array_values(array_filter($rows, static function (array $row) use ($reversed): bool {
            return (string)($row['fact_direction'] ?? '') === 'forward'
                && !isset($reversed[(string)($row['fact_id'] ?? '')]);
        }));
    }

    /** @return array<int,array<string,mixed>> */
    private function presentAllocations(array $facts, array $source, int $storeId): array
    {
        $byEmployee = [];
        foreach ($facts as $fact) {
            $employeeId = (int)($fact['employee_id'] ?? 0);
            if ($employeeId <= 0) continue;
            $byEmployee[$employeeId] = [
                'employeeId' => $employeeId, 'employeeName' => (string)($fact['employee_name_snapshot'] ?? ''),
                'craftsmanPerformanceType' => $this->performanceTypeFromRole((string)($fact['role_snapshot'] ?? '')),
                'isPointCustomer' => strpos((string)($fact['role_snapshot'] ?? ''), ':point') !== false,
                'allocationAmountCents' => max(0, (int)($fact['amount_cents'] ?? 0)),
                'laborFeeCents' => max(0, (int)($fact['labor_fee_amount_cents'] ?? 0)),
                'projectCountHalfUnits' => max(0, (int)($fact['project_count_half_units'] ?? 0)),
                'hasExplicitProjectCount' =>
                    (string)($fact['rule_code_snapshot'] ?? '') === 'SERVICE-RECORD-CRAFTSMAN-ADJUST-V1'
                    || (int)($fact['project_count_half_units'] ?? 0) !== 0,
            ];
        }
        if ($byEmployee === []) return [];
        $staffRows = Db::name('system_store_staff')->where('store_id', $storeId)
            ->whereIn('employee_id', array_keys($byEmployee))->where('status', 1)->where('is_del', 0)
            ->field('id,employee_id,craftsman_performance_type')->select()->toArray();
        foreach ($staffRows as $staff) {
            $employeeId = (int)$staff['employee_id'];
            if (!isset($byEmployee[$employeeId])) continue;
            $byEmployee[$employeeId]['staffId'] = (int)$staff['id'];
            $type = trim((string)($staff['craftsman_performance_type'] ?? ''));
            if (in_array($type, ['commission', 'labor', 'commission_labor'], true)) {
                $byEmployee[$employeeId]['craftsmanPerformanceType'] = $type;
            }
        }
        $rows = array_values(array_filter($byEmployee, static fn(array $row): bool => !empty($row['staffId'])));
        // 调整后允许明确把所有人的项目数都设为 0；这种情况不能再回退旧服务次数。
        $hasSavedProjectCount = in_array(true, array_column($rows, 'hasExplicitProjectCount'), true);
        if (!$hasSavedProjectCount && $rows !== []) {
            $halfUnits = max(0, (int)($source['project_count'] ?? 0)) * 2;
            if ($halfUnits === 0) $halfUnits = max(0, (int)($source['quantity'] ?? 0)) * 2;
            $base = intdiv($halfUnits, count($rows)); $remainder = $halfUnits - $base * count($rows);
            foreach ($rows as $index => &$row) $row['projectCountHalfUnits'] = $base + ($index >= count($rows) - $remainder ? 1 : 0);
            unset($row);
        }
        $allocationTotal = array_sum(array_column($rows, 'allocationAmountCents'));
        foreach ($rows as &$row) {
            $row['id'] = $row['staffId']; $row['name'] = $row['employeeName'];
            $row['marked'] = $row['isPointCustomer'];
            $row['laborWeight'] = $allocationTotal > 0
                ? round((int)$row['allocationAmountCents'] * 100 / $allocationTotal, 2)
                : 0;
            $row['allocationAmount'] = $this->money((int)$row['allocationAmountCents']);
            $row['laborFeeAmount'] = $this->money((int)$row['laborFeeCents']);
            $row['projectCount'] = number_format((int)$row['projectCountHalfUnits'] / 2, 1, '.', '');
        }
        unset($row);
        return $rows;
    }

    /** @return array<int,array<string,mixed>> */
    private function candidates(int $storeId): array
    {
        $rows = Db::name('system_store_staff')->alias('ss')->join('employee e', 'e.id=ss.employee_id')
            ->where('ss.store_id', $storeId)->where('ss.status', 1)->where('ss.is_del', 0)
            ->where('ss.cashier_craftsman_enabled', 1)->where('e.status', 1)->where('e.is_del', 0)
            ->field('ss.id,ss.employee_id,ss.store_id,ss.account,ss.staff_name,ss.craftsman_performance_type,e.name employee_name,e.employment_type_code,e.employment_type_version')
            ->order('ss.id', 'asc')->select()->toArray();
        return array_map(static function (array $row): array {
            return [
                'id' => (int)$row['id'], 'staffId' => (int)$row['id'], 'employeeId' => (int)$row['employee_id'],
                'storeId' => (int)$row['store_id'], 'name' => trim((string)$row['employee_name']) ?: (string)$row['staff_name'],
                'staffNo' => (string)$row['account'], 'positionName' => '手艺人',
                'employeeTypeCode' => (string)$row['employment_type_code'],
                'employeeTypeAuthorityVersion' => (int)$row['employment_type_version'],
                'craftsmanPerformanceType' => (string)($row['craftsman_performance_type'] ?: 'commission_labor'),
                'craftsmanEligible' => true, 'selectable' => true,
            ];
        }, $rows);
    }

    /** @return array<int,array<string,mixed>> */
    private function normalizeAllocations($input, int $storeId, int $totalCents): array
    {
        if (!is_array($input) || array_keys($input) !== ($input === [] ? [] : range(0, count($input) - 1)) || $input === [] || count($input) > 20) {
            throw CashierV3CommandException::invalidContext('请至少选择一名有效手艺人。', ['reason' => 'service_adjust_allocations_invalid']);
        }
        $staffIds = []; $seen = [];
        foreach ($input as $raw) {
            $staffId = is_array($raw) ? (int)($raw['staffId'] ?? $raw['id'] ?? 0) : 0;
            if ($staffId <= 0 || isset($seen[$staffId])) throw CashierV3CommandException::invalidContext('所选手艺人无效或重复。');
            $seen[$staffId] = true; $staffIds[] = $staffId;
        }
        sort($staffIds, SORT_NUMERIC);
        $staffRows = Db::name('system_store_staff')->alias('ss')->join('employee e', 'e.id=ss.employee_id')
            ->whereIn('ss.id', $staffIds)->where('ss.store_id', $storeId)->where('ss.status', 1)->where('ss.is_del', 0)
            ->where('ss.cashier_craftsman_enabled', 1)->where('e.status', 1)->where('e.is_del', 0)
            ->field('ss.id,ss.employee_id,ss.store_id,ss.staff_name,ss.craftsman_performance_type,e.name,e.employment_type_code,e.employment_type_version')
            ->lock(true)->select()->toArray();
        $byId = []; foreach ($staffRows as $row) $byId[(int)$row['id']] = $row;
        if (count($byId) !== count($staffIds)) throw CashierV3CommandException::invalidContext('所选手艺人已停用或不属于当前门店。');
        $rows = []; $amountSum = 0; $commissionAllocationCount = 0;
        foreach ($input as $raw) {
            $staffId = (int)($raw['staffId'] ?? $raw['id']); $staff = $byId[$staffId];
            $amount = $this->nonnegativeInteger($raw['allocationAmountCents'] ?? null, '消耗业绩');
            if ($amount % 100 !== 0) {
                throw CashierV3CommandException::invalidContext('消耗业绩必须按整元分配。', [
                    'reason' => 'service_adjust_amount_not_whole_yuan', 'staffId' => $staffId, 'amountCents' => $amount,
                ]);
            }
            $fee = $this->nonnegativeInteger($raw['laborFeeCents'] ?? 0, '手工费');
            $halfUnits = $this->halfUnits($raw);
            $type = trim((string)($staff['craftsman_performance_type'] ?? ''));
            if (!in_array($type, ['commission', 'labor', 'commission_labor'], true)) $type = 'commission_labor';
            if ($type === 'labor' && $amount !== 0) throw CashierV3CommandException::invalidContext('只拿手工费的手艺人不能分配消耗业绩。');
            if ($type === 'commission' && $fee !== 0) throw CashierV3CommandException::invalidContext('只拿消耗业绩的手艺人不能填写手工费。');
            if ($type !== 'labor') $commissionAllocationCount++;
            $amountSum += $amount;
            $rows[] = [
                'staffId' => $staffId, 'employeeId' => (int)$staff['employee_id'],
                'employeeName' => trim((string)$staff['name']) ?: (string)$staff['staff_name'],
                'employeeType' => (string)$staff['employment_type_code'],
                'employeeTypeVersion' => max(1, (int)$staff['employment_type_version']),
                'craftsmanPerformanceType' => $type,
                'isPointCustomer' => !empty($raw['isPointCustomer']) || !empty($raw['marked']),
                'allocationAmountCents' => $amount, 'laborFeeCents' => $fee,
                'projectCountHalfUnits' => $halfUnits,
                'projectCount' => number_format($halfUnits / 2, 1, '.', ''),
            ];
        }
        if ($commissionAllocationCount > 0 && $totalCents % 100 !== 0) {
            throw CashierV3CommandException::invalidContext('项目核销金额不是整元，不能按整元分配消耗业绩。', [
                'reason' => 'service_adjust_total_not_whole_yuan', 'totalCents' => $totalCents,
            ]);
        }
        if (!self::allocationTotalMatches($amountSum, $totalCents, $commissionAllocationCount)) {
            throw CashierV3CommandException::invalidContext('各手艺人的消耗业绩合计必须等于项目核销金额。', [
                'reason' => 'service_adjust_amount_total_mismatch', 'expectedCents' => $totalCents, 'actualCents' => $amountSum,
            ]);
        }
        return $rows;
    }

    private static function allocationTotalMatches(int $amountSum, int $totalCents, int $commissionAllocationCount): bool
    {
        // 全部人员均为“只拿手工费”时，劳动业绩金额固定为 0；项目级消耗业绩
        // 仍保留在原 consumption_performance_recorded 事实中，不强塞给手艺人。
        return $commissionAllocationCount === 0 ? $amountSum === 0 : $amountSum === $totalCents;
    }

    private function halfUnits(array $raw): int
    {
        if (isset($raw['projectCountHalfUnits'])) return $this->nonnegativeInteger($raw['projectCountHalfUnits'], '项目数');
        $value = trim((string)($raw['projectCount'] ?? ''));
        if (preg_match('/^(?:0|[1-9][0-9]*)(?:\.[05])?$/D', $value) !== 1) {
            throw CashierV3CommandException::invalidContext('项目数只能按0.5递增，并保留一位小数。');
        }
        return (int)round((float)$value * 2);
    }

    private function nonnegativeInteger($value, string $label): int
    {
        $raw = trim((string)$value);
        if (preg_match('/^(?:0|[1-9][0-9]*)$/D', $raw) !== 1) throw CashierV3CommandException::invalidContext($label . '格式不正确。');
        return (int)$raw;
    }

    /** @return array<string,mixed> */
    private function performanceTemplate(array $source, string $tenantId): array
    {
        $row = Db::name('cashier_v3_performance_fact')->where('tenant_id', $tenantId)
            ->where('checkout_request_id', (string)$source['checkout_request_id'])
            ->where('source_line_id', (string)$source['source_line_id'])->order('id', 'asc')->lock(true)->find();
        if (!$row) throw self::failure('service_adjust_performance_template_missing');
        return (array)$row;
    }

    private function insertReversal(array $source, string $operationId, string $commandKey, array $event, CashierV3OperatorScope $operator, int $now): void
    {
        $row = $source; unset($row['id']);
        $row['fact_id'] = 'SAR-' . strtoupper(substr(hash_hmac('sha256', (string)$source['fact_id'] . '|' . $operationId, $this->secret()), 0, 40));
        $row['business_event_no'] = (string)$event['event_no']; $row['fact_direction'] = 'reversal';
        $row['natural_key'] = 'service_adjust:reversal:' . hash('sha256', (string)$source['fact_id'] . '|' . $operationId);
        $row['command_idempotency_key'] = $commandKey; $row['fact_version'] = 1; $row['reversal_of'] = (string)$source['fact_id'];
        $row['operator_id'] = $operator->operatorId(); $row['operator_name_snapshot'] = $this->operatorName($operator);
        $row['amount_cents'] = -(int)$source['amount_cents'];
        $row['allocation_base_amount_cents'] = -(int)$source['allocation_base_amount_cents'];
        $row['labor_fee_amount_cents'] = -(int)($source['labor_fee_amount_cents'] ?? 0);
        $row['project_count_half_units'] = -(int)($source['project_count_half_units'] ?? 0);
        $row['occurred_at'] = $now; $row['settled_at'] = $now; $row['recorded_at'] = $now;
        $row['immutable_fingerprint'] = hash('sha256', $this->json($row));
        if ((int)Db::name('cashier_v3_performance_fact')->insert($row) !== 1) throw self::failure('service_adjust_reversal_insert_failed');
    }

    private function insertAllocation(array $template, array $allocation, int $index, array $source, string $operationId, string $commandKey, array $event, CashierV3OperatorScope $operator, int $now, int $totalCents): void
    {
        $row = $template; unset($row['id']);
        $row['fact_id'] = 'SAA-' . strtoupper(substr(hash_hmac('sha256', $operationId . '|' . $allocation['staffId'], $this->secret()), 0, 40));
        $row['business_event_no'] = (string)$event['event_no']; $row['fact_type'] = 'labor_performance_allocated';
        $row['performance_type'] = 'labor_performance_allocated'; $row['fact_direction'] = 'forward';
        $row['natural_key'] = 'service_adjust:forward:' . hash('sha256', $operationId . '|' . $allocation['staffId']);
        $row['command_idempotency_key'] = $commandKey; $row['fact_version'] = 1; $row['reversal_of'] = ''; $row['status'] = 'effective';
        $row['operator_id'] = $operator->operatorId(); $row['operator_name_snapshot'] = $this->operatorName($operator);
        $row['business_date'] = (string)$source['business_date']; $row['occurred_at'] = $now; $row['settled_at'] = $now; $row['recorded_at'] = $now;
        $row['employee_id'] = (int)$allocation['employeeId']; $row['employee_name_snapshot'] = (string)$allocation['employeeName'];
        $row['employee_type_snapshot'] = (string)$allocation['employeeType'];
        $row['employee_type_authority_version'] = (int)$allocation['employeeTypeVersion'];
        $row['role_snapshot'] = 'craftsman:' . ($allocation['isPointCustomer'] ? 'point' : 'round') . ':' . $allocation['craftsmanPerformanceType'];
        $row['allocation_weight_numerator'] = (int)$allocation['allocationAmountCents'];
        $row['allocation_weight_denominator'] = max(1, $totalCents);
        $row['allocation_base_amount_cents'] = $totalCents; $row['amount_cents'] = (int)$allocation['allocationAmountCents'];
        $row['labor_fee_amount_cents'] = (int)$allocation['laborFeeCents'];
        $row['project_count_half_units'] = (int)$allocation['projectCountHalfUnits'];
        $row['rule_code_snapshot'] = 'SERVICE-RECORD-CRAFTSMAN-ADJUST-V1';
        $row['rule_name_snapshot'] = '服务记录手艺人调整'; $row['rule_version_snapshot'] = 'v1';
        $row['immutable_fingerprint'] = hash('sha256', $this->json($row));
        if ((int)Db::name('cashier_v3_performance_fact')->insert($row) !== 1) throw self::failure('service_adjust_allocation_insert_failed', ['index' => $index]);
    }

    private function performanceTypeFromRole(string $role): string
    {
        foreach (['commission_labor', 'commission', 'labor'] as $type) if (substr($role, -strlen($type)) === $type) return $type;
        return 'commission_labor';
    }

    private function result(array $row, bool $replayed): array
    {
        return [
            'contractVersion' => self::CONTRACT_VERSION, 'operationId' => (string)$row['operation_id'],
            'operationNo' => (string)$row['operation_no'], 'serviceFactId' => (int)$row['service_fact_id'],
            'reason' => (string)$row['reason_snapshot'], 'adjustedAt' => $this->dateTime((int)$row['occurred_at']),
            'operatorName' => (string)$row['operator_name_snapshot'],
            'allocationTotalAmount' => $this->money((int)$row['allocation_total_amount_cents']),
            'replayed' => $replayed, 'touchedRoles' => ['service_record'], 'message' => '服务记录手艺人已修改。',
        ];
    }

    private function operatorName(CashierV3OperatorScope $operator): string
    {
        $row = Db::name('system_store_staff')->alias('s')->leftJoin('employee e', 'e.id=s.employee_id')
            ->where('s.id', $operator->operatorId())->field('s.staff_name,e.name')->find();
        return trim((string)($row['name'] ?? '')) ?: trim((string)($row['staff_name'] ?? ''));
    }

    private function json(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) throw self::failure('service_adjust_json_failed');
        return $json;
    }

    private function money(int $cents): string { return number_format($cents / 100, 2, '.', ''); }
    private function dateTime(int $timestamp): string { return $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : ''; }
    private function secret(): string { $secret = trim((string)config('cashier_v3.checkout_namespace_secret')); if (strlen($secret) < 32) throw self::failure('service_adjust_secret_missing'); return $secret; }
    private static function failure(string $reason, array $detail = []): CashierV3CommandException { return new CashierV3CommandException(CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE, '服务记录手艺人修改未完成，请刷新后重试。', CashierV3ResultCode::STATUS_FAILED, array_merge(['reason' => $reason], $detail)); }
}
