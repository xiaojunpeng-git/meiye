<?php
declare(strict_types=1);

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
 * 补交记录销售人调整。补交事实和销售业绩事实均为追加式，原事实只写
 * reversal，不覆盖历史行；列表永远读取 status=effective 的最新分配。
 */
final class CashierV3SupplementSalespersonAdjustmentServices
{
    public const CONTRACT_VERSION = 'cashier-v3-supplement-salesperson-adjustment-v1';
    private const FACT_TABLE = 'cashier_v3_performance_fact';
    private const OPERATION_TABLE = CashierV3OrderLifecycleServices::OPERATION_TABLE;

    public function entry(array $payload, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): array
    {
        $source = $this->source($payload, $operator, $scope, false);
        $rows = array_values(array_filter($this->effectivePerformanceFacts($scope->tenantId(), $source['repaymentId'], $source['sourceDocumentType']), static fn(array $row): bool => (string)($row['performance_type'] ?? '') === 'sales_performance_allocated'));
        // 当前职位的独立核算配置是销售人调整的权限权威。补交事实本身保留
        // 历史金额，但不保存职位配置，因此打开编辑器时必须重新读取在职岗位。
        $candidates = Db::name('system_store_staff')->alias('s')->join('employee e', 'e.id=s.employee_id')
            ->leftJoin('staff_job_position sjp', 'sjp.staff_id = s.id AND sjp.status = 1 AND sjp.is_del = 0 AND sjp.end_time = 0')
            ->leftJoin('position p', 'p.id = sjp.position_id AND p.status = 1')
            ->where('s.store_id', $operator->storeId())->where('s.status', 1)->where('s.is_del', 0)
            ->where('s.cashier_salesperson_enabled', 1)->where('e.status', 1)->where('e.is_del', 0)
            ->field('s.id,s.employee_id,s.staff_name,e.name,e.employment_type_code,e.employment_type_version,sjp.position_id,p.name as position_name,p.performance_independent')
            ->order('s.id asc')->select()->toArray();
        $candidateByEmployee = [];
        foreach ($candidates as $candidate) $candidateByEmployee[(int)($candidate['employee_id'] ?? 0)] = $candidate;
        $selected = [];
        foreach ($rows as $row) {
            $employeeId = (int)($row['employee_id'] ?? 0);
            if ($employeeId <= 0) continue;
            $candidate = (array)($candidateByEmployee[$employeeId] ?? []);
            $positionId = (int)($candidate['position_id'] ?? 0);
            $independent = (int)($candidate['performance_independent'] ?? 0) === 1;
            $selected[$employeeId] = [
                'employeeId' => $employeeId,
                'name' => (string)($row['employee_name_snapshot'] ?? ''),
                'allocationWeight' => (int)($row['allocation_weight_numerator'] ?? 0),
                'isPreSale' => str_ends_with((string)($row['role_snapshot'] ?? ''), ':presale'),
                // 已落账事实金额用于订单中心回显，绝不能因前端缺少实时
                // 收银基数而重新计算为 0。
                'performanceAmountCents' => abs((int)($row['amount_cents'] ?? 0)),
                'performanceAmountLocked' => true,
                'positionId' => $positionId,
                'positionName' => (string)($candidate['position_name'] ?? ''),
                'performanceIndependent' => $independent,
                'allocationGroupKey' => $independent ? 'independent:' . ($positionId > 0 ? $positionId : $employeeId) : 'normal',
            ];
        }
        $salesRows = array_values(array_filter($rows, static fn(array $row): bool => (string)($row['performance_type'] ?? '') === 'sales_performance_allocated'));
        $total = array_sum(array_map(static fn(array $row): int => abs((int)($row['amount_cents'] ?? 0)), $salesRows));
        $recordVersion = $this->version($scope->tenantId(), $source['repaymentId']);
        return [
            'contractVersion' => self::CONTRACT_VERSION, 'recordId' => $source['repaymentId'], 'recordVersion' => $recordVersion,
            'totalAmountCents' => $total, 'performanceBaseAmountCents' => (int)$source['repaymentAmountCents'], 'repaymentId' => $source['repaymentId'], 'supplementOrderNo' => $source['repaymentNo'], 'memberId' => $source['memberId'],
            'salespeople' => array_values(array_map(static function (array $row): array {
                $positionId = (int)($row['position_id'] ?? 0);
                $independent = (int)($row['performance_independent'] ?? 0) === 1;
                return ['staffId' => (int)$row['id'], 'employeeId' => (int)$row['employee_id'], 'name' => trim((string)$row['name']) ?: (string)$row['staff_name'], 'employeeTypeCode' => (string)$row['employment_type_code'], 'employeeTypeAuthorityVersion' => (int)$row['employment_type_version'], 'positionId' => $positionId, 'positionName' => (string)($row['position_name'] ?? ''), 'performanceIndependent' => $independent, 'allocationGroupKey' => $independent ? 'independent:' . ($positionId > 0 ? $positionId : (int)$row['id']) : 'normal'];
            }, $candidates)),
            'currentSalespeople' => array_values($selected),
            'lines' => [['orderLineId' => $source['repaymentId'] . ':performance', 'itemName' => $source['repaymentNo'], 'currentSalespeople' => array_values($selected)]],
        ];
    }

    public function discover(array $scope): array
    {
        $operator = $scope['operator_scope'] ?? null; $data = $scope['data_scope'] ?? null;
        if (!$operator instanceof CashierV3OperatorScope || !$data instanceof CashierV3DataScopeContext) throw self::failure('supplement_personnel_scope_invalid');
        $source = $this->source((array)($scope['payload'] ?? []), $operator, $data, false);
        return ['resources' => [[
            'kind' => CashierV3SupplementSalespersonAdjustmentVersionProvider::KIND, 'id' => $source['repaymentId'],
            'expectedVersion' => $this->version($data->tenantId(), $source['repaymentId']), 'roles' => ['supplement_record'], 'accessMode' => 'mutate',
            'providerContractVersion' => CashierV3SupplementSalespersonAdjustmentVersionProvider::CONTRACT_VERSION,
            'authorityFingerprint' => hash('sha256', $data->tenantId() . '|' . $source['repaymentId']),
        ]]];
    }

    public function executeInTx(array $scope): array
    {
        CashierV3TransactionGuard::assertInTransaction('supplementSalespersonAdjustment.executeInTx');
        $operator = $scope['operator_scope'] ?? null; $data = $scope['data_scope'] ?? null;
        $recorder = $scope['event_recorder'] ?? null; $execution = $scope['event_execution'] ?? null;
        if (!$operator instanceof CashierV3OperatorScope || !$data instanceof CashierV3DataScopeContext || !$recorder instanceof CashierV3BusinessEventRecorder || !$execution instanceof CashierV3BusinessEventExecution) throw self::failure('supplement_personnel_scope_invalid');
        $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
        $source = $this->source($payload, $operator, $data, true);
        $personnel = $this->normalizePersonnel($payload['personnel'] ?? $payload['salespeople'] ?? [], $operator);
        $key = trim((string)($scope['idempotency_key'] ?? '')); if ($key === '') throw self::failure('supplement_personnel_idempotency_missing');
        $fingerprint = hash('sha256', json_encode([$source['repaymentId'], $personnel], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $existing = Db::name(self::OPERATION_TABLE)->where('tenant_id', $data->tenantId())->where('command_idempotency_key', $key)->lock(true)->find();
        if ($existing) { if ((string)$existing['immutable_fingerprint'] !== $fingerprint) throw self::failure('supplement_personnel_idempotency_conflict'); return ['operationNo' => (string)$existing['operation_no'], 'replayed' => true, 'message' => '补交销售人调整已完成。']; }
        $expectedVersion = (int)($payload['recordVersion'] ?? $payload['expectedVersion'] ?? 0);
        $currentVersion = $this->version($data->tenantId(), $source['repaymentId']);
        if ($expectedVersion <= 0 || $expectedVersion !== $currentVersion) {
            throw CashierV3CommandException::invalidContext('补交记录已被其他人修改，请刷新后重新编辑。', [
                'reason' => 'supplement_personnel_version_conflict',
                'expectedVersion' => $expectedVersion,
                'currentVersion' => $currentVersion,
            ]);
        }
        $old = $this->effectivePerformanceFacts($data->tenantId(), $source['repaymentId'], $source['sourceDocumentType']);
        if ($old === []) throw self::failure('supplement_personnel_sales_fact_missing');
        $salesFacts = array_values(array_filter($old, static fn(array $row): bool => (string)($row['performance_type'] ?? '') === 'sales_performance_allocated'));
        // 每个独立核算组各自以本次补交金额为业绩基数；不能把所有已分配
        // 事实金额相加后再按全局比例重分，否则“店长 100% + 普通组 100%”
        // 会让普通组被放大、独立组被吞掉。
        $baseAmount = (int)($source['repaymentAmountCents'] ?? 0);
        if ($baseAmount <= 0) throw self::failure('supplement_personnel_base_invalid');
        $template = $salesFacts[0]; $operationId = 'SPA-' . strtoupper(substr(hash('sha256', $data->tenantId() . '|' . $source['repaymentId'] . '|' . $key), 0, 40));
        $operationNo = 'RY' . date('ymd') . strtoupper(substr(hash('sha256', $operationId), 0, 5)); $now = time();
        $event = $recorder->recordInTx($execution, (array)($scope['event_contract'] ?? []), [
            'event_type' => 'supplement.personnel_adjusted', 'aggregate_type' => 'debt_repayment', 'aggregate_id' => $source['repaymentId'],
            'aggregate_version' => $this->version($data->tenantId(), $source['repaymentId']) + 1, 'event_version' => 1, 'source_type' => 'adjust-supplement-personnel', 'source_id' => $operationId,
            'member_id' => $source['memberId'], 'business_date' => date('Y-m-d', $now), 'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now,
            'aggregate_name_snapshot' => $source['repaymentNo'], 'store_name_snapshot' => $source['storeName'], 'payload' => ['contractVersion' => self::CONTRACT_VERSION, 'personnel' => $personnel],
        ]);
        foreach ($old as $row) $this->insertReversal($row, $operationId, $key, (array)$event, $operator, $now);
        $groups = [];
        foreach ($personnel as $index => $person) $groups[(string)$person['allocationGroupKey']][] = $index;
        $shares = array_fill(0, count($personnel), 0);
        foreach ($groups as $indexes) {
            $manualIndexes = array_values(array_filter($indexes, static fn(int $index): bool => !empty($personnel[$index]['performanceAmountManual'])));
            if ($manualIndexes) {
                // 手动填写的是该人员的最终业绩，不应被本次补交金额重新折算。
                // 未手填人员仍按原比例自动分配，避免编辑一人意外改写其他人。
                $autoIndexes = array_values(array_filter($indexes, static fn(int $index): bool => empty($personnel[$index]['performanceAmountManual'])));
                $autoWeight = 0; foreach ($autoIndexes as $index) $autoWeight += (int)$personnel[$index]['allocationWeight'];
                $autoBase = intdiv($baseAmount * $autoWeight, 100);
                $allocated = 0; $lastAuto = $autoIndexes[count($autoIndexes) - 1] ?? null;
                foreach ($autoIndexes as $index) {
                    $weight = (int)$personnel[$index]['allocationWeight'];
                    $share = $index === $lastAuto ? $autoBase - $allocated : intdiv($baseAmount * $weight, 100);
                    if ($share <= 0) throw self::failure('supplement_personnel_amount_invalid');
                    $allocated += $share;
                    $shares[$index] = $share;
                }
                foreach ($manualIndexes as $index) $shares[$index] = (int)$personnel[$index]['performanceAmountCents'];
                continue;
            }
            $allocated = 0;
            $last = $indexes[count($indexes) - 1];
            foreach ($indexes as $index) {
                $weight = (int)$personnel[$index]['allocationWeight'];
                $share = $index === $last ? $baseAmount - $allocated : intdiv($baseAmount * $weight, 100);
                if ($share <= 0) throw self::failure('supplement_personnel_amount_invalid');
                $allocated += $share;
                $shares[$index] = $share;
            }
            if ($allocated !== $baseAmount) throw self::failure('supplement_personnel_amount_invalid');
        }
        $external = 0;
        foreach ($personnel as $index => $person) {
            $share = $shares[$index];
            if (in_array($person['employeeTypeCodeSnapshot'], ['partner', 'outsourced'], true)) $external += $share;
            $this->insertForward($template, $source, $person, $share, $operationId, $key, (array)$event, $operator, $now, 'sales_performance_allocated', $index + 1);
        }
        $actual = array_values(array_filter($old, static fn(array $row): bool => (string)($row['performance_type'] ?? '') === 'actual_performance_recorded'));
        if ($actual !== []) $this->insertForward($actual[0], $source, ['employeeId' => 0, 'name' => '', 'employeeTypeCodeSnapshot' => '', 'employeeTypeAuthorityVersion' => 0, 'allocationWeight' => 0], $baseAmount - $external, $operationId, $key, (array)$event, $operator, $now, 'actual_performance_recorded', 0);
        Db::name(self::OPERATION_TABLE)->insert(['operation_id' => $operationId, 'operation_no' => $operationNo, 'tenant_id' => $data->tenantId(), 'store_id' => $operator->storeId(), 'member_id' => $source['memberId'], 'operator_id' => $operator->operatorId(), 'source_type' => 'debt_repayment', 'source_order_id' => $source['repaymentId'], 'source_order_no_snapshot' => $source['repaymentNo'], 'operation_type' => 'personnel_adjustment', 'command_idempotency_key' => $key, 'immutable_fingerprint' => $fingerprint, 'reason_snapshot' => trim((string)($payload['reason'] ?? '销售人调整')), 'request_json' => json_encode(['personnel' => $personnel], JSON_UNESCAPED_UNICODE), 'business_event_no' => (string)$event['event_no'], 'status' => 'succeeded', 'version' => 1, 'business_date' => date('Y-m-d', $now), 'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        return ['operationNo' => $operationNo, 'replayed' => false, 'personnelCount' => count($personnel), 'message' => '补交销售人已更新。'];
    }

    private function source(array $payload, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope, bool $lock): array
    {
        $id = trim((string)($payload['repaymentId'] ?? $payload['supplementId'] ?? $payload['recordId'] ?? ''));
        if (strpos($id, ':') !== false) $id = substr($id, strrpos($id, ':') + 1);
        if ($id === '') throw self::failure('supplement_personnel_record_missing');
        foreach ([['cashier_v3_debt_repayment', 'repayment_amount_cents', 'repayment_id', 'operator_id', 'sales_order_no_snapshot', 'debt_repayment'], ['cashier_v3_recharge_debt_repayment', 'amount_cents', 'repayment_id', 'operator_id', 'repayment_no', 'recharge_debt_repayment']] as $definition) {
            $q = Db::name($definition[0])->where('tenant_id', $scope->tenantId())->where('repayment_id', $id)->where('store_id', $operator->storeId()); if ($lock) $q->lock(true); $row = (array)$q->find();
            if ($row && (string)($row['status'] ?? '') === 'succeeded') {
                // Both V3 authorities expose repayment_no today, but sales
                // rows also retain the immutable sales-order snapshot. Keep
                // the fallback explicit so older rows still produce a stable
                // display number instead of an empty itemName in the editor.
                $repaymentNo = trim((string)($row['repayment_no'] ?? ''));
                if ($repaymentNo === '') $repaymentNo = trim((string)($row[$definition[4]] ?? ''));
                if ($repaymentNo === '') $repaymentNo = $id;
                return ['repaymentId' => $id, 'repaymentNo' => $repaymentNo, 'repaymentAmountCents' => max(0, (int)($row[$definition[1]] ?? 0)), 'memberId' => (int)$row['member_id'], 'storeName' => (string)Db::name('system_store')->where('id', $operator->storeId())->value('name'), 'sourceDocumentType' => $definition[5]];
            }
        }
        throw self::failure('supplement_personnel_record_invalid');
    }

    private function normalizePersonnel($rows, CashierV3OperatorScope $operator): array
    {
        if (!is_array($rows) || $rows === [] || count($rows) > 20) throw self::failure('supplement_personnel_empty');
        $out = []; $seen = []; $weightsByGroup = [];
        foreach (array_values($rows) as $row) {
            $staffId = (int)($row['staffId'] ?? $row['employeeId'] ?? 0);
            $weight = (int)($row['allocationWeight'] ?? $row['performance'] ?? 0);
            if ($staffId <= 0 || $weight <= 0 || $weight > 100 || isset($seen[$staffId])) throw self::failure('supplement_personnel_invalid');
            [$performanceAmountManual, $performanceAmountCents] = self::manualPerformanceAmount($row);
            $seen[$staffId] = true;
            $staff = (array)Db::name('system_store_staff')->alias('s')->join('employee e', 'e.id=s.employee_id')
                ->leftJoin('staff_job_position sjp', 'sjp.staff_id = s.id AND sjp.status = 1 AND sjp.is_del = 0 AND sjp.end_time = 0')
                ->leftJoin('position p', 'p.id = sjp.position_id AND p.status = 1')
                ->where(function ($q) use ($staffId) { $q->where('s.id', $staffId)->whereOr('s.employee_id', $staffId); })
                ->where('s.store_id', $operator->storeId())->where('s.status', 1)->where('s.is_del', 0)
                ->where('s.cashier_salesperson_enabled', 1)->where('e.status', 1)->where('e.is_del', 0)
                ->field('s.id,s.employee_id,s.staff_name,e.name,e.employment_type_code,e.employment_type_version,sjp.position_id,p.name as position_name,p.performance_independent')->find();
            if (!$staff || !in_array((string)$staff['employment_type_code'], ['internal', 'partner', 'outsourced'], true) || (int)$staff['employment_type_version'] <= 0) throw self::failure('supplement_personnel_staff_ineligible');
            $positionId = (int)($staff['position_id'] ?? 0);
            $independent = (int)($staff['performance_independent'] ?? 0) === 1;
            $groupKey = $independent ? 'independent:' . ($positionId > 0 ? $positionId : (int)$staff['id']) : 'normal';
            $out[] = ['employeeId' => (int)$staff['employee_id'], 'name' => trim((string)$staff['name']) ?: (string)$staff['staff_name'], 'employeeTypeCodeSnapshot' => (string)$staff['employment_type_code'], 'employeeTypeAuthorityVersion' => (int)$staff['employment_type_version'], 'allocationWeight' => $weight, 'performanceAmountManual' => $performanceAmountManual, 'performanceAmountCents' => $performanceAmountCents, 'isPreSale' => !empty($row['isPreSale']) || !empty($row['marked']), 'positionId' => $positionId, 'positionName' => (string)($staff['position_name'] ?? ''), 'performanceIndependent' => $independent, 'allocationGroupKey' => $groupKey];
            $weightsByGroup[$groupKey] = ($weightsByGroup[$groupKey] ?? 0) + $weight;
        }
        foreach ($weightsByGroup as $weight) if ($weight !== 100) throw self::failure('supplement_personnel_group_weight_invalid');
        return $out;
    }

    private static function manualPerformanceAmount(array $row): array
    {
        $manual = !empty($row['performanceAmountManual']) || !empty($row['performance_amount_manual']);
        if (!$manual) return [false, 0];
        $raw = $row['performanceAmountCents'] ?? $row['performance_amount_cents'] ?? null;
        if ((!is_int($raw) && !is_string($raw)) || preg_match('/^(?:0|[1-9][0-9]*)$/D', trim((string)$raw)) !== 1) throw self::failure('supplement_personnel_manual_amount_invalid');
        $cents = (int)$raw;
        if ($cents < 0 || $cents % 100 !== 0) throw self::failure('supplement_personnel_manual_amount_invalid');
        return [true, $cents];
    }

    private function effectivePerformanceFacts(string $tenant, string $repaymentId, string $sourceType): array
    {
        $rows = Db::name(self::FACT_TABLE)->where('tenant_id', $tenant)->where('order_id', $repaymentId)
            ->where('source_document_type', $sourceType)
            ->whereIn('performance_type', ['sales_performance_allocated', 'actual_performance_recorded'])
            ->where('status', 'effective')->where('fact_direction', 'forward')->order('id asc')->select()->toArray();
        if ($rows === []) return [];
        $ids = [];
        foreach ($rows as $row) { $id = (string)($row['fact_id'] ?? ''); if ($id !== '') $ids[] = $id; }
        if ($ids === []) return $rows;
        $reversed = Db::name(self::FACT_TABLE)->where('tenant_id', $tenant)->where('fact_direction', 'reversal')->whereIn('reversal_of', $ids)->column('reversal_of');
        $skip = array_fill_keys(array_map('strval', $reversed), true);
        return array_values(array_filter($rows, static function (array $row) use ($skip): bool {
            return !isset($skip[(string)($row['fact_id'] ?? '')]);
        }));
    }
    private function version(string $tenant, string $id): int { return 1 + (int)Db::name(self::OPERATION_TABLE)->where('tenant_id', $tenant)->where('source_type', 'debt_repayment')->where('source_order_id', $id)->where('operation_type', 'personnel_adjustment')->count(); }
    private function insertReversal(array $source, string $operationId, string $key, array $event, CashierV3OperatorScope $operator, int $now): void { $row = $source; unset($row['id'], $row['created_at'], $row['updated_at']); $row['fact_id'] = 'REV-' . strtoupper(substr(hash('sha256', (string)$source['fact_id'] . '|' . $operationId), 0, 40)); $row['natural_key'] = 'supplement_personnel_reversal:' . hash('sha256', (string)$source['fact_id'] . '|' . $operationId); $row['fact_direction'] = 'reversal'; $row['reversal_of'] = (string)$source['fact_id']; $row['command_idempotency_key'] = $key; $row['business_event_no'] = (string)$event['event_no']; $row['operator_id'] = $operator->operatorId(); $row['occurred_at'] = $now; $row['settled_at'] = $now; $row['recorded_at'] = $now; Db::name(self::FACT_TABLE)->insert($row); }
    private function insertForward(array $template, array $source, array $person, int $amount, string $operationId, string $key, array $event, CashierV3OperatorScope $operator, int $now, string $type, int $sequence): void { $row = $template; unset($row['id'], $row['created_at'], $row['updated_at']); $row['fact_id'] = 'SPA-' . strtoupper(substr(hash('sha256', $operationId . '|' . $type . '|' . $sequence . '|' . (int)($person['employeeId'] ?? 0)), 0, 40)); $row['natural_key'] = 'supplement_personnel:' . $operationId . ':' . $type . ':' . $sequence; $row['fact_direction'] = 'forward'; $row['reversal_of'] = ''; $row['status'] = 'effective'; $row['performance_type'] = $type; $row['employee_id'] = (int)($person['employeeId'] ?? 0); $row['employee_name_snapshot'] = (string)($person['name'] ?? ''); $row['employee_type_snapshot'] = (string)($person['employeeTypeCodeSnapshot'] ?? ''); $row['employee_type_authority_version'] = (int)($person['employeeTypeAuthorityVersion'] ?? 0); $row['role_snapshot'] = $type === 'sales_performance_allocated' ? ('salesperson:' . (!empty($person['isPreSale']) ? 'presale' : 'postsale')) : ''; $row['allocation_weight_numerator'] = (int)($person['allocationWeight'] ?? 0); $row['allocation_weight_denominator'] = $type === 'sales_performance_allocated' ? 100 : 1; $row['allocation_base_amount_cents'] = $amount; $row['amount_cents'] = $amount; $row['command_idempotency_key'] = $key; $row['business_event_no'] = (string)$event['event_no']; $row['operator_id'] = $operator->operatorId(); $row['occurred_at'] = $now; $row['settled_at'] = $now; $row['recorded_at'] = $now; Db::name(self::FACT_TABLE)->insert($row); }
    private static function failure(string $reason): CashierV3CommandException { return new CashierV3CommandException(CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE, '补交销售人资料不完整，请刷新后重试。', CashierV3ResultCode::STATUS_FAILED, ['reason' => $reason]); }
}
