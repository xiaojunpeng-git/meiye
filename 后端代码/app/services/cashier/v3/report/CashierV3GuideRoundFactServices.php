<?php

namespace app\services\cashier\v3\report;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3TransactionGuard;
use think\facade\Db;

/**
 * 正式结账导购轮次事实写入器。
 *
 * 导购和轮次先随购物车草稿保存；只有正式结账成功才写入本表。
 * 同一顾客的同一轮次允许在同一结账日期重复出现，跨日期则拒绝。
 * 每轮可以有任意多名导购，但本表不含金额、比例或业绩字段，避免
 * 把导购归属误当成现金业绩分配。
 */
final class CashierV3GuideRoundFactServices
{
    public const TABLE = 'cashier_v3_customer_guide_round_fact';
    public const VERSION = 'cashier-v3-guide-round-v1';

    /**
     * @param array{tenant_id:string,organization_id:string,store_id:int,member_id:int,member_name_snapshot?:string,order_id:string,order_no_snapshot?:string,checkout_request_id:string,business_date:string,operator_id:int,operator_name_snapshot?:string,business_event_no?:string,occurred_at?:int,recorded_at?:int} $authority
     * @param array<string,array<int,array{employeeId:int}>> $selectionsByLine
     * @return array{round_no:int,inserted:int,replayed:int,guide_count:int}
     */
    public function persistInTx(
        array $authority,
        array $selectionsByLine,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        CashierV3TransactionGuard::assertInTransaction('guideRoundFact.persistInTx');
        $this->assertScope($authority, $operatorScope, $dataScope);
        $this->assertAuthority($authority);
        if ($selectionsByLine === []) return ['round_no' => 0, 'inserted' => 0, 'replayed' => 0, 'guide_count' => 0];

        $tenant = (string)$authority['tenant_id'];
        $memberId = (int)$authority['member_id'];
        $normalized = $this->normalizeSelections($selectionsByLine);
        $rounds = [];
        foreach ($normalized as $rows) foreach ($rows as $row) $rounds[(int)$row['guideRoundNo']] = true;
        if (count($rounds) !== 1) throw $this->invalid('guide_round_conflict');
        $roundNo = (int)array_key_first($rounds);
        // 只把已成功结账且尚未退款/作废的销售单视为轮次历史。草稿、
        // 失败结账和之后已经撤销的单据均不占用本次导购轮次。
        $existing = Db::name(self::TABLE)->alias('guide_fact')
            ->where('guide_fact.tenant_id', $tenant)
            ->where('guide_fact.member_id', $memberId)
            ->where('guide_fact.status', 'effective')
            ->whereExists(function ($order) {
                $order->name('cashier_v3_sales_order')->alias('sales_order')
                    ->whereRaw('sales_order.tenant_id = guide_fact.tenant_id')
                    ->whereRaw('sales_order.order_id = guide_fact.order_id')
                    ->where('sales_order.order_status', 'settled')
                    ->where('sales_order.order_direction', 'forward')
                    ->where('sales_order.settled_at', '>', 0);
            })
            ->whereNotExists(function ($operation) {
                $operation->name('cashier_v3_order_lifecycle_operation')->alias('lifecycle')
                    ->whereRaw('lifecycle.tenant_id = guide_fact.tenant_id')
                    ->whereRaw('lifecycle.source_order_id = guide_fact.order_id')
                    ->where('lifecycle.source_type', 'sales')
                    ->where('lifecycle.status', 'succeeded')
                    ->whereIn('lifecycle.operation_type', ['refund', 'void']);
            })
            ->field('guide_fact.*')->lock(true)->select()->toArray();
        $businessDate = (string)$authority['business_date'];
        foreach ($existing as $row) {
            $round = (int)$row['guide_round_no'];
            if ($round < 1 || $round > 3) throw $this->invalid('guide_round_history_invalid');
            if ((string)$row['order_id'] === (string)$authority['order_id'] && $round !== $roundNo) {
                throw $this->invalid('guide_round_order_conflict');
            }
            if ($round === $roundNo && (string)$row['business_date'] !== $businessDate) {
                throw $this->invalid('guide_round_date_conflict:' . (string)$row['business_date']);
            }
        }
        $employeeIds = [];
        foreach ($normalized as $rows) foreach ($rows as $row) $employeeIds[(int)$row['employeeId']] = true;
        $employees = $this->lockEmployees(array_keys($employeeIds));
        $inserted = 0; $replayed = 0; $guideCount = 0;
        $now = time();
        foreach ($normalized as $sourceLineId => $rows) {
            foreach ($rows as $row) {
                $employeeId = (int)$row['employeeId'];
                $employee = $employees[$employeeId] ?? null;
                if (!$employee) throw $this->invalid('guide_employee_not_active');
                $employeeName = trim((string)$employee['name']);
                if ($employeeName === '') throw $this->invalid('guide_employee_name_missing');
                $naturalKey = implode(':', ['guide-round', $authority['order_id'], $sourceLineId, $roundNo, $employeeId]);
                $factId = 'GRF-' . substr(hash('sha256', $tenant . '|' . $naturalKey), 0, 40);
                $fact = [
                    'fact_id' => $factId, 'natural_key' => $naturalKey, 'tenant_id' => $tenant,
                    'organization_id' => (string)$authority['organization_id'], 'store_id' => (int)$authority['store_id'],
                    'member_id' => $memberId, 'member_name_snapshot' => mb_substr((string)($authority['member_name_snapshot'] ?? ''), 0, 128),
                    'order_id' => (string)$authority['order_id'], 'order_no_snapshot' => mb_substr((string)($authority['order_no_snapshot'] ?? ''), 0, 64),
                    'checkout_request_id' => (string)$authority['checkout_request_id'], 'source_line_id' => $sourceLineId,
                    'business_date' => $businessDate, 'guide_round_no' => $roundNo, 'guide_employee_id' => $employeeId,
                    'guide_employee_name_snapshot' => mb_substr($employeeName, 0, 128),
                    'guide_employee_type_snapshot' => mb_substr((string)($employee['employment_type_code'] ?? ''), 0, 16),
                    'operator_id' => (int)$authority['operator_id'], 'operator_name_snapshot' => mb_substr((string)($authority['operator_name_snapshot'] ?? ''), 0, 128),
                    'business_event_no' => mb_substr((string)($authority['business_event_no'] ?? ''), 0, 64),
                    'command_idempotency_key' => (string)$authority['command_idempotency_key'],
                    'occurred_at' => (int)($authority['occurred_at'] ?? $now), 'recorded_at' => (int)($authority['recorded_at'] ?? $now),
                    'status' => 'effective',
                ];
                $fact['immutable_fingerprint'] = hash('sha256', json_encode($fact, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $old = Db::name(self::TABLE)->where('tenant_id', $tenant)->where('natural_key', $naturalKey)->lock(true)->find();
                if (is_array($old)) {
                    if (!hash_equals((string)$old['immutable_fingerprint'], (string)$fact['immutable_fingerprint'])) throw $this->invalid('guide_round_fact_replay_conflict');
                    $replayed++; $guideCount++; continue;
                }
                try { Db::name(self::TABLE)->insert($fact); $inserted++; $guideCount++; }
                catch (\Throwable $exception) {
                    if (!$this->isDuplicate($exception)) throw $exception;
                    $old = Db::name(self::TABLE)->where('tenant_id', $tenant)->where('natural_key', $naturalKey)->lock(true)->find();
                    if (!$old || !hash_equals((string)$old['immutable_fingerprint'], (string)$fact['immutable_fingerprint'])) throw $this->invalid('guide_round_fact_replay_conflict');
                    $replayed++; $guideCount++;
                }
            }
        }
        return ['round_no' => $roundNo, 'inserted' => $inserted, 'replayed' => $replayed, 'guide_count' => $guideCount];
    }

    /**
     * Check a locked checkout snapshot before the cashier enters payment.
     * This is deliberately read-only: guide-round facts remain a successful
     * checkout effect and are never created by this preflight.
     *
     * @return array{round_no:int,guide_count:int}
     */
    public function assertAvailableInTx(
        array $authority,
        array $selectionsByLine,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        CashierV3TransactionGuard::assertInTransaction('guideRoundFact.assertAvailableInTx');
        if ($selectionsByLine === []) return ['round_no' => 0, 'guide_count' => 0];
        $this->assertPreflightScope($authority, $operatorScope, $dataScope);

        $normalized = $this->normalizeSelections($selectionsByLine);
        $rounds = [];
        $guideCount = 0;
        foreach ($normalized as $rows) {
            foreach ($rows as $row) {
                $rounds[(int)$row['guideRoundNo']] = true;
                $guideCount++;
            }
        }
        if (count($rounds) !== 1) throw $this->invalid('guide_round_conflict');
        $roundNo = (int)array_key_first($rounds);
        $existing = $this->effectiveHistoryRowsInTx(
            (string)$authority['tenant_id'],
            (int)$authority['member_id']
        );
        foreach ($existing as $row) {
            $round = (int)$row['guide_round_no'];
            if ($round < 1 || $round > 3) throw $this->invalid('guide_round_history_invalid');
            if ($round === $roundNo && (string)$row['business_date'] !== (string)$authority['business_date']) {
                throw $this->invalid('guide_round_date_conflict:' . (string)$row['business_date']);
            }
        }
        return ['round_no' => $roundNo, 'guide_count' => $guideCount];
    }

    /** Read-only helper used by report filters; no amount is returned. */
    public function employeeRoundFilter(array $context, int $employeeId, string $businessDate = ''): array
    {
        if ($employeeId <= 0) return [];
        $query = Db::name(self::TABLE)->where('tenant_id', (string)($context['tenant_id'] ?? ''))->where('guide_employee_id', $employeeId)->where('status', 'effective');
        if ((int)($context['store_id'] ?? 0) > 0) $query->where('store_id', (int)$context['store_id']);
        if ($businessDate !== '') $query->where('business_date', $businessDate);
        return $query->field('member_id,order_id,source_line_id,business_date,guide_round_no,guide_employee_id,guide_employee_name_snapshot')->select()->toArray();
    }

    private function normalizeSelections(array $selections): array
    {
        $result = [];
        foreach ($selections as $line => $rows) {
            $line = trim((string)$line);
            if ($line === '' || !is_array($rows) || array_keys($rows) !== range(0, count($rows) - 1)) throw $this->invalid('guide_selection_invalid');
            $seen = [];
            foreach ($rows as $row) {
                $employeeId = is_array($row) ? (int)($row['employeeId'] ?? $row['employee_id'] ?? 0) : 0;
                if ($employeeId <= 0 || isset($seen[$employeeId])) throw $this->invalid('guide_selection_duplicate_or_invalid');
                $roundNo = is_array($row) ? (int)($row['guideRoundNo'] ?? $row['guide_round_no'] ?? 0) : 0;
                if ($roundNo < 1 || $roundNo > 3) throw $this->invalid('guide_round_required');
                $seen[$employeeId] = true; $result[$line][] = ['employeeId' => $employeeId, 'guideRoundNo' => $roundNo];
            }
        }
        return $result;
    }

    private function lockEmployees(array $ids): array
    {
        if ($ids === []) throw $this->invalid('guide_selection_empty');
        sort($ids, SORT_NUMERIC);
        $rows = Db::name('employee')->whereIn('id', $ids)->where('status', 1)->where('is_del', 0)->where('employment_type_version', '>', 0)->lock(true)->select()->toArray();
        $result = [];
        foreach ($rows as $row) $result[(int)$row['id']] = $row;
        if (count($result) !== count($ids)) throw $this->invalid('guide_employee_not_active');
        return $result;
    }

    private function assertAuthority(array $authority): void
    {
        foreach (['tenant_id','organization_id','store_id','member_id','order_id','checkout_request_id','business_date','operator_id','command_idempotency_key'] as $key) {
            if (!array_key_exists($key, $authority) || (is_string($authority[$key]) && trim($authority[$key]) === '') || (is_int($authority[$key]) && $authority[$key] <= 0)) throw $this->invalid('guide_authority_missing_' . $key);
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', (string)$authority['business_date'])) throw $this->invalid('guide_business_date_invalid');
    }

    private function assertScope(array $authority, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): void
    {
        if (!hash_equals($operator->tenantId(), (string)$authority['tenant_id']) || !hash_equals($operator->organizationId(), (string)$authority['organization_id']) || $operator->storeId() !== (int)$authority['store_id'] || $operator->operatorId() !== (int)$authority['operator_id'] || !$scope->allowsStore($operator->storeId())) throw $this->invalid('guide_data_scope_denied');
    }

    private function assertPreflightScope(array $authority, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): void
    {
        foreach (['tenant_id', 'organization_id', 'store_id', 'member_id', 'business_date', 'operator_id'] as $key) {
            if (!array_key_exists($key, $authority)
                || (is_string($authority[$key]) && trim($authority[$key]) === '')
                || (is_int($authority[$key]) && $authority[$key] <= 0)) {
                throw $this->invalid('guide_preflight_authority_missing_' . $key);
            }
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', (string)$authority['business_date'])) {
            throw $this->invalid('guide_business_date_invalid');
        }
        $this->assertScope($authority, $operator, $scope);
    }

    private function effectiveHistoryRowsInTx(string $tenant, int $memberId): array
    {
        return Db::name(self::TABLE)->alias('guide_fact')
            ->where('guide_fact.tenant_id', $tenant)
            ->where('guide_fact.member_id', $memberId)
            ->where('guide_fact.status', 'effective')
            ->whereExists(function ($order) {
                $order->name('cashier_v3_sales_order')->alias('sales_order')
                    ->whereRaw('sales_order.tenant_id = guide_fact.tenant_id')
                    ->whereRaw('sales_order.order_id = guide_fact.order_id')
                    ->where('sales_order.order_status', 'settled')
                    ->where('sales_order.order_direction', 'forward')
                    ->where('sales_order.settled_at', '>', 0);
            })
            ->whereNotExists(function ($operation) {
                $operation->name('cashier_v3_order_lifecycle_operation')->alias('lifecycle')
                    ->whereRaw('lifecycle.tenant_id = guide_fact.tenant_id')
                    ->whereRaw('lifecycle.source_order_id = guide_fact.order_id')
                    ->where('lifecycle.source_type', 'sales')
                    ->where('lifecycle.status', 'succeeded')
                    ->whereIn('lifecycle.operation_type', ['refund', 'void']);
            })
            ->field('guide_fact.*')->lock(true)->select()->toArray();
    }

    private function invalid(string $reason): \InvalidArgumentException { return new \InvalidArgumentException($reason); }

    private function isDuplicate(\Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());
        return strpos($message, 'duplicate') !== false || strpos($message, '1062') !== false;
    }
}
