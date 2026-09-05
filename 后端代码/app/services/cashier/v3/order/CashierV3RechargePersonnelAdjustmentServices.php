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
 * 充值订单销售人调整。
 *
 * 充值主表上的 staff_id 是收银操作人，不能当作销售人。销售人以统一
 * performance_fact 为权威；调整只追加反向事实和新的正向事实，原记录永不覆盖。
 */
final class CashierV3RechargePersonnelAdjustmentServices
{
    public const CONTRACT_VERSION = 'cashier-v3-recharge-personnel-adjustment-v1';
    private const OPERATION_TABLE = CashierV3OrderLifecycleServices::OPERATION_TABLE;

    public function discover(array $scope): array
    {
        [$operator, $dataScope] = $this->scope($scope);
        $source = $this->source((array)($scope['payload'] ?? []), $operator, $dataScope, false);
        $version = 1 + (int)Db::name(self::OPERATION_TABLE)->where('tenant_id', $dataScope->tenantId())
            ->where('source_type', 'recharge')->where('source_order_id', (string)$source['rechargeId'])->count();
        return ['resources' => [[
            'kind' => CashierV3RechargeOrderLifecycleVersionProvider::KIND,
            'id' => (string)$source['rechargeId'], 'expectedVersion' => $version,
            'roles' => ['recharge_order'], 'accessMode' => 'mutate',
            'providerContractVersion' => self::CONTRACT_VERSION,
            'authorityFingerprint' => hash('sha256', implode('|', [$dataScope->tenantId(), $source['rechargeId'], $version])),
        ]]];
    }

    public function entry(array $payload, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): array
    {
        $source = $this->source($payload, $operator, $scope, false);
        $rows = Db::name('cashier_v3_performance_fact')->where('tenant_id', $scope->tenantId())
            ->where('order_id', $source['orderId'])->where('source_document_type', 'recharge')
            ->where('performance_type', 'sales_performance_allocated')->where('fact_direction', 'forward')
            ->where('status', 'effective')->field('fact_id,employee_id,employee_name_snapshot,amount_cents,allocation_base_amount_cents,allocation_weight_numerator')
            ->order('id', 'asc')->select()->toArray();
        $factIds = array_values(array_filter(array_map(static fn(array $row): string => (string)($row['fact_id'] ?? ''), $rows)));
        $reversed = $factIds === [] ? [] : Db::name('cashier_v3_performance_fact')
            ->where('tenant_id', $scope->tenantId())->where('fact_direction', 'reversal')
            ->whereIn('reversal_of', $factIds)->column('reversal_of');
        $reversed = array_fill_keys(array_map('strval', $reversed), true);
        $candidates = $this->candidates($operator);
        $candidateByEmployee = [];
        foreach ($candidates as $candidate) {
            $candidateByEmployee[(int)($candidate['employeeId'] ?? 0)] = $candidate;
        }
        $current = [];
        foreach ($rows as $row) {
            if (isset($reversed[(string)($row['fact_id'] ?? '')])) continue;
            $id = (int)($row['employee_id'] ?? 0);
            if ($id <= 0) continue;
            $base = max(0, (int)($row['allocation_base_amount_cents'] ?? 0));
            $amount = abs((int)($row['amount_cents'] ?? 0));
            $snapshotWeight = (int)($row['allocation_weight_numerator'] ?? 0);
            $allocationWeight = $snapshotWeight > 0 && $snapshotWeight <= 100
                ? $snapshotWeight
                : ($base > 0 ? (int)round($amount * 100 / $base) : 0);
            $candidate = (array)($candidateByEmployee[$id] ?? []);
            $positionId = (int)($candidate['positionId'] ?? 0);
            $independent = !empty($candidate['performanceIndependent']);
            // 订单中心回显的是结账时已经落账的业绩事实。即使当时使用了手工
            // 业绩金额，它也不能被当前编辑器的比例或空基数重新算成 0。
            $current[$id] = [
                'employeeId' => $id,
                'name' => (string)($row['employee_name_snapshot'] ?? ''),
                'amountCents' => $amount,
                'performanceAmountCents' => $amount,
                'performanceAmountLocked' => true,
                'allocationWeight' => $allocationWeight,
                'positionId' => $positionId,
                'positionName' => (string)($candidate['positionName'] ?? ''),
                'performanceIndependent' => $independent,
                'allocationGroupKey' => $independent ? 'independent:' . ($positionId > 0 ? $positionId : $id) : 'normal',
            ];
        }
        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'recordId' => (string)$source['rechargeId'], 'rechargeId' => (int)$source['rechargeId'], 'rechargeOrderNo' => (string)$source['orderNo'],
            'recordVersion' => 1 + (int)Db::name(self::OPERATION_TABLE)->where('tenant_id', $scope->tenantId())
                ->where('source_type', 'recharge')->where('source_order_id', (string)$source['rechargeId'])->count(),
            'totalAmountCents' => $this->performanceBase($source, $scope),
            'cashPerformanceCents' => $this->performanceBase($source, $scope),
            'salespeople' => $candidates,
            'lines' => [['lineId' => $source['orderId'] . ':payment', 'itemName' => '充值', 'currentSalespeople' => array_values($current)]],
        ];
    }

    public function executeInTx(array $scope): array
    {
        CashierV3TransactionGuard::assertInTransaction('rechargePersonnelAdjustment.executeInTx');
        [$operator, $dataScope, $recorder, $execution] = $this->executionScope($scope);
        $payload = (array)($scope['payload'] ?? []);
        $source = $this->source($payload, $operator, $dataScope, true);
        $input = $this->input($payload, $operator);
        $key = trim((string)($scope['idempotency_key'] ?? ''));
        if ($key === '') throw self::failure('recharge_personnel_idempotency_missing');
        $fingerprint = hash('sha256', json_encode([$source['rechargeId'], $input], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $existing = Db::name(self::OPERATION_TABLE)->where('tenant_id', $dataScope->tenantId())->where('command_idempotency_key', $key)->lock(true)->find();
        if ($existing) {
            if ((string)($existing['immutable_fingerprint'] ?? '') !== $fingerprint) throw self::failure('recharge_personnel_idempotency_conflict');
            return ['operationId' => (string)$existing['operation_id'], 'operationNo' => (string)$existing['operation_no'], 'status' => (string)$existing['status'], 'replayed' => true, 'message' => '充值订单销售人已更新。'];
        }
        $now = time();
        $operationId = 'RPO-' . strtoupper(substr(hash_hmac('sha256', implode('|', [$dataScope->tenantId(), $source['rechargeId'], $key]), $this->secret()), 0, 40));
        $operationNo = 'TZ' . date('ymd', $now) . strtoupper(substr(hash('sha256', $operationId), 0, 5));
        $event = $recorder->recordInTx($execution, (array)($scope['event_contract'] ?? []), [
            'event_type' => 'recharge_order.personnel_adjusted', 'aggregate_type' => 'recharge_order', 'aggregate_id' => $source['orderId'],
            'aggregate_version' => 1 + (int)Db::name(CashierV3BusinessEventRecorder::EVENT_TABLE)->where('tenant_id', $dataScope->tenantId())->where('aggregate_type', 'recharge_order')->where('aggregate_id', $source['orderId'])->count(),
            'event_version' => 1, 'source_type' => 'adjust-recharge-personnel', 'source_id' => $operationId,
            'member_id' => $source['memberId'], 'business_date' => date('Y-m-d', $now), 'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now,
            'aggregate_name_snapshot' => $source['orderNo'], 'store_name_snapshot' => $source['storeName'],
            'payload' => ['contractVersion' => self::CONTRACT_VERSION, 'operationId' => $operationId, 'operationNo' => $operationNo, 'rechargeId' => $source['rechargeId'], 'salespeople' => $input['salespeople']],
        ]);
        // 只冲销当前有效快照。此前已经被冲销的历史事实只用于审计，绝不能
        // 再次参与本次调整，否则会生成多组并列的销售人快照。
        $facts = $this->effectiveForwardFacts($dataScope->tenantId(), $source['orderId'], true);
        $base = 0; $actualTemplate = null; $salesTemplate = null;
        foreach ($facts as $fact) {
            if ((string)$fact['performance_type'] === 'actual_performance_recorded') { $actualTemplate = $fact; $base = (int)$fact['allocation_base_amount_cents']; }
            elseif ($salesTemplate === null) { $salesTemplate = $fact; if ($base <= 0) $base = (int)$fact['allocation_base_amount_cents']; }
            $this->reverseFact($fact, $operationId, $key, (array)$event, $operator, $now);
        }
        if ($base < 0) throw self::failure('recharge_personnel_base_invalid');
        $amounts = $this->allocate($base, $input['salespeople']);
        $external = 0; $newSales = [];
        foreach ($input['salespeople'] as $i => $person) {
            $amount = (int)$amounts[$i];
            $staff = $person['_staff'];
            if (in_array((string)$staff['employment_type_code'], ['partner', 'outsourced'], true)) $external += $amount;
            $salesFactTemplate = $salesTemplate ?: $actualTemplate;
            if ($salesFactTemplate !== null) {
                $newSales[] = $this->newFact($salesFactTemplate, $operationId, $key, (array)$event, $operator, $now, $staff, $amount, $base, $i + 1, 'sales', !empty($person['isPreSale']), (int)$person['allocationWeight']);
            }
        }
        if ($base > 0) {
            $template = $actualTemplate ?: $salesTemplate;
            if ($template !== null) {
                $actual = $template; $actual['employee_id'] = 0; $actual['employee_name_snapshot'] = ''; $actual['employee_type_snapshot'] = ''; $actual['employee_type_authority_version'] = 0; $actual['role_snapshot'] = '';
                $this->newFact($actual, $operationId, $key, (array)$event, $operator, $now, null, $base - $external, $base, 0, 'actual');
            }
        }
        $row = ['operation_id' => $operationId, 'operation_no' => $operationNo, 'tenant_id' => $dataScope->tenantId(), 'store_id' => $operator->storeId(), 'member_id' => $source['memberId'], 'operator_id' => $operator->operatorId(), 'source_type' => 'recharge', 'source_order_id' => (string)$source['rechargeId'], 'source_order_no_snapshot' => $source['orderNo'], 'operation_type' => 'personnel_adjustment', 'command_idempotency_key' => $key, 'immutable_fingerprint' => $fingerprint, 'reason_snapshot' => $input['reason'], 'request_json' => json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'business_event_no' => (string)$event['event_no'], 'amount_cents' => $base, 'status' => 'succeeded', 'version' => 1, 'business_date' => date('Y-m-d', $now), 'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now, 'created_at' => $now, 'updated_at' => $now];
        if ((int)Db::name(self::OPERATION_TABLE)->insert($row) !== 1) throw self::failure('recharge_personnel_operation_insert_failed');
        return ['operationId' => $operationId, 'operationNo' => $operationNo, 'status' => 'succeeded', 'replayed' => false, 'message' => '充值订单销售人已更新。'];
    }

    private function source(array $payload, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope, bool $lock): array
    {
        $rawId = trim((string)($payload['rechargeId'] ?? $payload['recordId'] ?? $payload['sourceRechargeId'] ?? ''));
        if (preg_match('/^recharge:([1-9][0-9]*)$/D', $rawId, $m)) $rawId = $m[1];
        $id = (int)$rawId;
        if ($id <= 0) throw self::failure('recharge_personnel_identity_missing');
        $q = Db::name('user_recharge')->where('id', $id)->where('store_id', $operator->storeId())->where('paid', 1); if ($lock) $q->lock(true);
        $row = (array)$q->find(); if (!$row) throw self::failure('recharge_personnel_source_unavailable');
        return ['rechargeId' => $id, 'orderId' => 'RCH:' . $id, 'orderNo' => (string)$row['order_id'], 'memberId' => (int)$row['uid'], 'storeName' => (string)Db::name('system_store')->where('id', $operator->storeId())->value('name')];
    }

    private function input(array $payload, CashierV3OperatorScope $operator): array
    {
        $reason = trim((string)($payload['reason'] ?? '')); if ($reason === '' || mb_strlen($reason) > 255) throw self::failure('recharge_personnel_reason_invalid');
        $raw = $payload['personnel'] ?? $payload['salespeople'] ?? null; if (!is_array($raw) || $raw === [] || count($raw) > 50) throw self::failure('recharge_personnel_empty');
        $out = []; $seen = []; $weightsByGroup = [];
        foreach (array_values($raw) as $item) {
            if (!is_array($item)) throw self::failure('recharge_personnel_item_invalid');
            $staffId = (int)($item['staffId'] ?? 0); $id = (int)($item['employeeId'] ?? $item['employee_id'] ?? $staffId); $w = (int)($item['allocationWeight'] ?? $item['weight'] ?? $item['ratio'] ?? 0);
            if ($id <= 0 || $w <= 0 || isset($seen[$id])) throw self::failure('recharge_personnel_item_invalid');
            [$manualAmount, $manualAmountCents] = self::manualPerformanceAmount($item);
            $staffQuery = Db::name('system_store_staff')->alias('s')->join('employee e', 'e.id=s.employee_id')
                ->leftJoin('staff_job_position sjp', 'sjp.staff_id = s.id AND sjp.status = 1 AND sjp.is_del = 0 AND sjp.end_time = 0')
                ->leftJoin('position p', 'p.id = sjp.position_id AND p.status = 1')
                ->where('s.store_id', $operator->storeId())->where('s.status', 1)->where('s.is_del', 0)->where('s.cashier_salesperson_enabled', 1)->where('e.status', 1)->where('e.is_del', 0);
            if ($staffId > 0) $staffQuery->where(function ($q) use ($staffId, $id): void { $q->where('s.id', $staffId)->whereOr('s.employee_id', $id); }); else $staffQuery->where('s.employee_id', $id);
            $staff = (array)$staffQuery->field('s.id,s.employee_id,s.staff_name,e.name,e.employment_type_code,e.employment_type_version,sjp.position_id,p.name as position_name,p.performance_independent')->lock(true)->find();
            if (!$staff) throw self::failure('recharge_personnel_staff_ineligible');
            $id = (int)$staff['employee_id']; if (isset($seen[$id])) throw self::failure('recharge_personnel_item_invalid');
            $positionId = (int)($staff['position_id'] ?? 0);
            $independent = (int)($staff['performance_independent'] ?? 0) === 1;
            $groupKey = $independent ? 'independent:' . ($positionId > 0 ? $positionId : (int)$staff['id']) : 'normal';
            $seen[$id] = true;
            $weightsByGroup[$groupKey] = ($weightsByGroup[$groupKey] ?? 0) + $w;
            $out[] = [
                'staffId' => (int)$staff['id'], 'employeeId' => $id,
                'allocationWeight' => $w, 'isPreSale' => !empty($item['isPreSale']),
                'performanceAmountManual' => $manualAmount,
                'performanceAmountCents' => $manualAmountCents,
                'positionId' => $positionId, 'performanceIndependent' => $independent,
                'allocationGroupKey' => $groupKey, '_staff' => $staff,
            ];
        }
        foreach ($weightsByGroup as $weight) {
            if ($weight !== 100) throw self::failure('recharge_personnel_weight_total_invalid');
        }
        return ['reason' => $reason, 'salespeople' => $out];
    }

    private function candidates(CashierV3OperatorScope $operator): array
    {
        $rows = Db::name('system_store_staff')->alias('s')->join('employee e', 'e.id=s.employee_id')
            ->leftJoin('staff_job_position sjp', 'sjp.staff_id = s.id AND sjp.status = 1 AND sjp.is_del = 0 AND sjp.end_time = 0')
            ->leftJoin('position p', 'p.id = sjp.position_id AND p.status = 1')
            ->where('s.store_id', $operator->storeId())->where('s.status', 1)->where('s.is_del', 0)->where('s.cashier_salesperson_enabled', 1)->where('e.status', 1)->where('e.is_del', 0)
            ->field('s.id,s.employee_id,e.name,s.staff_name,e.employment_type_code,sjp.position_id,p.name as position_name,p.performance_independent')->order('s.id', 'asc')->select()->toArray();
        return array_map(static function (array $row): array {
            $positionId = (int)($row['position_id'] ?? 0);
            $independent = (int)($row['performance_independent'] ?? 0) === 1;
            return [
                'staffId' => (int)$row['id'], 'employeeId' => (int)$row['employee_id'],
                'name' => trim((string)$row['name']) ?: (string)$row['staff_name'],
                'employeeTypeCode' => (string)$row['employment_type_code'],
                'positionId' => $positionId, 'positionName' => (string)($row['position_name'] ?? ''),
                'performanceIndependent' => $independent,
                'allocationGroupKey' => $independent ? 'independent:' . ($positionId > 0 ? $positionId : (int)$row['id']) : 'normal',
            ];
        }, $rows);
    }

    private function performanceBase(array $source, CashierV3DataScopeContext $scope): int
    {
        return (int)Db::name('cashier_v3_performance_fact')->where('tenant_id', $scope->tenantId())->where('order_id', $source['orderId'])->where('source_document_type', 'recharge')->where('performance_type', 'actual_performance_recorded')->where('fact_direction', 'forward')->where('status', 'effective')->value('allocation_base_amount_cents');
    }

    /** @return array<int,array<string,mixed>> */
    private function effectiveForwardFacts(string $tenantId, string $orderId, bool $lock = false): array
    {
        $query = Db::name('cashier_v3_performance_fact')->where('tenant_id', $tenantId)->where('order_id', $orderId)
            ->where('source_document_type', 'recharge')->whereIn('performance_type', ['sales_performance_allocated', 'actual_performance_recorded'])
            ->where('fact_direction', 'forward')->where('status', 'effective')->order('id', 'asc');
        if ($lock) $query->lock(true);
        $rows = $query->select()->toArray();
        $factIds = array_values(array_filter(array_map(static fn(array $row): string => (string)($row['fact_id'] ?? ''), $rows)));
        if ($factIds === []) return [];
        $reversed = Db::name('cashier_v3_performance_fact')->where('tenant_id', $tenantId)
            ->where('fact_direction', 'reversal')->whereIn('reversal_of', $factIds)->column('reversal_of');
        $reversed = array_fill_keys(array_map('strval', $reversed), true);
        return array_values(array_filter($rows, static fn(array $row): bool => !isset($reversed[(string)($row['fact_id'] ?? '')])));
    }

    private function reverseFact(array $source, string $operationId, string $key, array $event, CashierV3OperatorScope $operator, int $now): void
    {
        $row = $source; unset($row['id'], $row['created_at'], $row['updated_at']); $row['fact_id'] = 'RPR-' . strtoupper(substr(hash('sha256', $source['fact_id'] . '|' . $operationId), 0, 40)); $row['natural_key'] = 'recharge_personnel_adjust:reversal:' . hash('sha256', $source['fact_id'] . '|' . $operationId); $row['business_event_no'] = (string)$event['event_no']; $row['fact_direction'] = 'reversal'; $row['reversal_of'] = (string)$source['fact_id']; $row['command_idempotency_key'] = $key; $row['operator_id'] = $operator->operatorId(); $row['business_date'] = date('Y-m-d', $now); $row['occurred_at'] = $now; $row['settled_at'] = $now; $row['recorded_at'] = $now; $row['amount_cents'] = -(int)$source['amount_cents']; $row['immutable_fingerprint'] = hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); if ((int)Db::name('cashier_v3_performance_fact')->insert($row) !== 1) throw self::failure('recharge_personnel_reversal_insert_failed');
    }

    private function newFact(array $template, string $operationId, string $key, array $event, CashierV3OperatorScope $operator, int $now, ?array $staff, int $amount, int $base, int $sequence, string $kind = 'sales', bool $isPreSale = false, int $allocationWeight = 0): array
    {
        $row = $template;
        unset($row['id'], $row['created_at'], $row['updated_at']);
        $suffix = $kind . ':' . ($staff ? (int)$staff['employee_id'] : 0) . ':' . $sequence;
        $row['fact_id'] = 'RPN-' . strtoupper(substr(hash('sha256', $operationId . '|' . $suffix), 0, 40));
        $row['natural_key'] = 'recharge_personnel_adjust:forward:' . hash('sha256', $operationId . '|' . $suffix);
        $row['business_event_no'] = (string)$event['event_no'];
        $row['fact_direction'] = 'forward'; $row['reversal_of'] = ''; $row['command_idempotency_key'] = $key;
        $row['operator_id'] = $operator->operatorId(); $row['business_date'] = date('Y-m-d', $now);
        $row['occurred_at'] = $now; $row['settled_at'] = $now; $row['recorded_at'] = $now;
        $row['allocation_base_amount_cents'] = $base; $row['amount_cents'] = $amount;
        $row['fact_type'] = $kind === 'actual' ? 'actual_performance_recorded' : 'sales_performance_allocated';
        $row['performance_type'] = $row['fact_type'];
        if ($staff) {
            $row['employee_id'] = (int)$staff['employee_id'];
            $row['employee_name_snapshot'] = trim((string)$staff['name']) ?: (string)$staff['staff_name'];
            $row['employee_type_snapshot'] = (string)$staff['employment_type_code'];
            $row['employee_type_authority_version'] = max(1, (int)$staff['employment_type_version']);
            $row['role_snapshot'] = 'salesperson' . ($isPreSale ? ':presale' : ':postsale');
        } else {
            $row['employee_id'] = 0; $row['employee_name_snapshot'] = ''; $row['employee_type_snapshot'] = '';
            $row['employee_type_authority_version'] = 0; $row['role_snapshot'] = '';
        }
        $row['allocation_weight_numerator'] = $staff ? $allocationWeight : 0;
        $row['allocation_weight_denominator'] = $staff ? 100 : 1;
        $row['immutable_fingerprint'] = hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ((int)Db::name('cashier_v3_performance_fact')->insert($row) !== 1) throw self::failure('recharge_personnel_fact_insert_failed');
        return $row;
    }

    /**
     * 每个独立核算组各自使用同一笔充值业绩基数。店长独立核算与普通销售组
     * 都可以是 100%，不能再把它们合并成全局 200% 后判为非法。
     *
     * 手填金额是唯一当前快照的最终值；本次调整会反向冲销旧事实并写入一组
     * 新事实，不保留可并列使用的旧快照。
     */
    private function allocate(int $total, array $people): array
    {
        $groups = [];
        foreach ($people as $index => $person) {
            $groups[(string)($person['allocationGroupKey'] ?? 'normal')][] = $index;
        }
        $out = array_fill(0, count($people), 0);
        foreach ($groups as $indexes) {
            $weight = 0;
            foreach ($indexes as $index) $weight += (int)($people[$index]['allocationWeight'] ?? 0);
            if ($weight !== 100) throw self::failure('recharge_personnel_weight_total_invalid');
            $manualIndexes = array_values(array_filter($indexes, static fn(int $index): bool => !empty($people[$index]['performanceAmountManual'])));
            if ($manualIndexes) {
                $autoIndexes = array_values(array_filter($indexes, static fn(int $index): bool => empty($people[$index]['performanceAmountManual'])));
                $autoWeight = 0;
                foreach ($autoIndexes as $index) $autoWeight += (int)$people[$index]['allocationWeight'];
                $autoBase = intdiv($total * $autoWeight, 100);
                $allocated = 0;
                $lastAuto = $autoIndexes[count($autoIndexes) - 1] ?? null;
                foreach ($autoIndexes as $index) {
                    $share = $index === $lastAuto
                        ? $autoBase - $allocated
                        : intdiv($total * (int)$people[$index]['allocationWeight'], 100);
                    if ($share < 0) throw self::failure('recharge_personnel_amount_invalid');
                    $allocated += $share;
                    $out[$index] = $share;
                }
                foreach ($manualIndexes as $index) {
                    $out[$index] = (int)$people[$index]['performanceAmountCents'];
                }
                continue;
            }
            $allocated = 0;
            $last = $indexes[count($indexes) - 1];
            foreach ($indexes as $index) {
                $share = $index === $last
                    ? $total - $allocated
                    : intdiv($total * (int)$people[$index]['allocationWeight'], 100);
                if ($share < 0) throw self::failure('recharge_personnel_amount_invalid');
                $allocated += $share;
                $out[$index] = $share;
            }
        }
        return $out;
    }

    private static function manualPerformanceAmount(array $row): array
    {
        $manual = !empty($row['performanceAmountManual']) || !empty($row['performance_amount_manual']);
        if (!$manual) return [false, 0];
        $raw = $row['performanceAmountCents'] ?? $row['performance_amount_cents'] ?? null;
        if ((!is_int($raw) && !is_string($raw)) || preg_match('/^(?:0|[1-9][0-9]*)$/D', trim((string)$raw)) !== 1) {
            throw self::failure('recharge_personnel_manual_amount_invalid');
        }
        $cents = (int)$raw;
        if ($cents < 0 || $cents % 100 !== 0) throw self::failure('recharge_personnel_manual_amount_invalid');
        return [true, $cents];
    }
    private function scope(array $scope): array { $operator = $scope['operator_scope'] ?? null; $data = $scope['data_scope'] ?? null; if (!$operator instanceof CashierV3OperatorScope || !$data instanceof CashierV3DataScopeContext) throw self::failure('recharge_personnel_scope_incomplete'); return [$operator, $data]; }
    private function executionScope(array $scope): array { [$operator, $data] = $this->scope($scope); $recorder = $scope['event_recorder'] ?? null; $execution = $scope['event_execution'] ?? null; if (!$recorder instanceof CashierV3BusinessEventRecorder || !$execution instanceof CashierV3BusinessEventExecution) throw self::failure('recharge_personnel_event_scope_incomplete'); return [$operator, $data, $recorder, $execution]; }
    private function secret(): string { $secret = trim((string)config('cashier_v3.checkout_namespace_secret')); if (strlen($secret) < 32) throw self::failure('recharge_personnel_secret_missing'); return $secret; }
    private static function failure(string $reason): CashierV3CommandException { return new CashierV3CommandException(CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE, '充值订单销售人资料不完整，请刷新后重试。', CashierV3ResultCode::STATUS_FAILED, ['reason' => $reason]); }
}
