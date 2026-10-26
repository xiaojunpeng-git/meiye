<?php

namespace app\services\cashier\v3\report;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3TransactionGuard;
use think\facade\Db;

/** Immutable sales-manager allocation facts, independent from salesperson facts. */
final class CashierV3SalesManagerFactServices
{
    public const TABLE = 'cashier_v3_sales_manager_fact';
    public const VERSION = 'cashier-v3-sales-manager-v2';

    /** @param array<string,array<int,array{employeeId:int,allocationWeight:int}>> $selectionsByLine */
    public function persistInTx(array $authority, array $selectionsByLine, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): array
    {
        CashierV3TransactionGuard::assertInTransaction('salesManagerFact.persistInTx');
        $this->assertScope($authority, $operator, $scope);
        foreach (['tenant_id','organization_id','store_id','member_id','order_id','checkout_request_id','business_date','operator_id','command_idempotency_key'] as $key) {
            if (!array_key_exists($key, $authority) || (string)$authority[$key] === '') throw new \InvalidArgumentException('sales_manager_authority_missing_' . $key);
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', (string)$authority['business_date'])) throw new \InvalidArgumentException('sales_manager_business_date_invalid');
        $normalized = $this->normalize($selectionsByLine);
        if ($normalized === []) return ['inserted' => 0, 'replayed' => 0, 'manager_count' => 0];
        $ids = [];
        foreach ($normalized as $rows) foreach ($rows as $row) $ids[(int)$row['employeeId']] = true;
        $employees = Db::name('employee')->whereIn('id', array_keys($ids))->where('status', 1)->where('is_del', 0)->lock(true)->select()->toArray();
        $byId = [];
        foreach ($employees as $employee) $byId[(int)$employee['id']] = $employee;
        if (count($byId) !== count($ids)) throw new \InvalidArgumentException('sales_manager_employee_not_active');
        $inserted = 0; $replayed = 0; $count = 0; $now = time();
        $baseAmounts = $this->resolveBaseAmounts($authority, $normalized);
        foreach ($normalized as $line => $rows) foreach ($rows as $index => $row) {
            $id = (int)$row['employeeId']; $employee = $byId[$id]; $name = trim((string)($employee['name'] ?? ''));
            if ($name === '') throw new \InvalidArgumentException('sales_manager_employee_name_missing');
            $natural = implode(':', ['sales-manager', $authority['order_id'], $line, $id]);
            $baseAmount = max(0, (int)($baseAmounts[(string)$line] ?? 0));
            $weight = (int)$row['allocationWeight'];
            $amount = intdiv($baseAmount * $weight, 100);
            if ($index === count($rows) - 1) {
                $allocatedBefore = 0;
                foreach ($rows as $tailIndex => $tailRow) {
                    if ($tailIndex === $index) break;
                    $allocatedBefore += intdiv($baseAmount * (int)$tailRow['allocationWeight'], 100);
                }
                $amount = max(0, $baseAmount - $allocatedBefore);
            }
            $fact = [
                'fact_id' => 'SMF-' . substr(hash('sha256', $authority['tenant_id'] . '|' . $natural), 0, 40),
                'natural_key' => $natural, 'tenant_id' => (string)$authority['tenant_id'], 'organization_id' => (string)$authority['organization_id'],
                'store_id' => (int)$authority['store_id'], 'member_id' => (int)$authority['member_id'], 'order_id' => (string)$authority['order_id'],
                'order_no_snapshot' => (string)($authority['order_no_snapshot'] ?? ''), 'checkout_request_id' => (string)$authority['checkout_request_id'], 'source_line_id' => $line,
                'business_date' => (string)$authority['business_date'], 'sales_manager_employee_id' => $id, 'sales_manager_name_snapshot' => mb_substr($name, 0, 128),
                'sales_manager_type_snapshot' => mb_substr((string)($employee['employment_type_code'] ?? ''), 0, 16),
                'allocation_weight_numerator' => $weight, 'allocation_weight_denominator' => 100,
                'allocation_base_amount_cents' => $baseAmount, 'amount_cents' => $amount,
                'operator_id' => (int)$authority['operator_id'],
                'business_event_no' => (string)($authority['business_event_no'] ?? ''), 'command_idempotency_key' => (string)$authority['command_idempotency_key'],
                'occurred_at' => (int)($authority['occurred_at'] ?? $now), 'recorded_at' => (int)($authority['recorded_at'] ?? $now), 'status' => 'effective',
            ];
            $fact['immutable_fingerprint'] = hash('sha256', json_encode($fact, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $old = Db::name(self::TABLE)->where('tenant_id', $fact['tenant_id'])->where('natural_key', $natural)->lock(true)->find();
            if ($old) { if (!hash_equals((string)$old['immutable_fingerprint'], $fact['immutable_fingerprint'])) throw new \InvalidArgumentException('sales_manager_fact_replay_conflict'); $replayed++; $count++; continue; }
            try { Db::name(self::TABLE)->insert($fact); $inserted++; $count++; } catch (\Throwable $e) { if (strpos(strtolower($e->getMessage()), 'duplicate') === false && strpos($e->getMessage(), '1062') === false) throw $e; $replayed++; $count++; }
        }
        return ['inserted' => $inserted, 'replayed' => $replayed, 'manager_count' => $count];
    }

    /**
     * Resolve the manager allocation base from the same settled authorities
     * as the checkout facts.  The caller normally supplies the per-line map;
     * the database fallback protects against an older worker or an alternate
     * submission path silently writing a zero manager amount.
     *
     * @param array<string,mixed> $authority
     * @param array<string,array<int,array{employeeId:int,allocationWeight:int}>> $normalized
     * @return array<string,int>
     */
    private function resolveBaseAmounts(array $authority, array $normalized): array
    {
        $baseAmounts = [];
        foreach ((array)($authority['allocation_base_amounts_by_line'] ?? []) as $line => $amount) {
            $baseAmounts[(string)$line] = max(0, (int)$amount);
        }
        $cashPerformance = max(0, (int)($authority['cash_performance_amount_cents'] ?? 0));
        if ($cashPerformance <= 0) {
            $batch = Db::name('cashier_v3_payment_collection_batch')
                ->where('tenant_id', (string)$authority['tenant_id'])
                ->where('sales_order_id', (string)$authority['order_id'])
                ->whereIn('batch_status', ['effective', 'settled'])
                ->order('id', 'desc')
                ->field('cash_performance_amount_cents')
                ->find();
            $cashPerformance = max(0, (int)($batch['cash_performance_amount_cents'] ?? 0));
        }
        if ($cashPerformance <= 0) return $baseAmounts;

        $lines = Db::name('cashier_v3_sales_order_line')
            ->where('tenant_id', (string)$authority['tenant_id'])
            ->where('order_id', (string)$authority['order_id'])
            // A successfully settled order line is the authoritative source
            // for the manager allocation base as well.  Older code only
            // accepted `effective`, so freshly settled orders fell through
            // with a zero base and produced zero manager cash performance.
            ->whereIn('line_status', ['effective', 'settled'])
            ->field('checkout_line_id,sale_amount_cents,line_no,id')
            ->order('line_no', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        $totalSale = 0;
        foreach ($lines as $line) $totalSale += max(0, (int)$line['sale_amount_cents']);
        if ($totalSale <= 0) return $baseAmounts;

        $computed = [];
        $allocated = 0;
        $last = count($lines) - 1;
        foreach ($lines as $index => $line) {
            $lineId = (string)$line['checkout_line_id'];
            $amount = $index === $last
                ? max(0, $cashPerformance - $allocated)
                : intdiv($cashPerformance * max(0, (int)$line['sale_amount_cents']), $totalSale);
            $computed[$lineId] = $amount;
            $allocated += $amount;
        }
        foreach ($normalized as $line => $rows) {
            if (!array_key_exists($line, $baseAmounts) || $baseAmounts[$line] <= 0) {
                $baseAmounts[$line] = max(0, (int)($computed[$line] ?? 0));
            }
        }
        return $baseAmounts;
    }

    private function normalize(array $input): array
    {
        $result = [];
        foreach ($input as $line => $rows) {
            if (trim((string)$line) === '' || !is_array($rows)) throw new \InvalidArgumentException('sales_manager_selection_invalid');
            $seen = [];
            $weightTotal = 0;
            foreach ($rows as $row) {
                $id = is_array($row) ? (int)($row['employeeId'] ?? $row['employee_id'] ?? $row['id'] ?? 0) : 0;
                $weightValue = is_array($row) ? ($row['allocationWeight'] ?? $row['allocation_weight'] ?? $row['weight'] ?? null) : null;
                $weight = $weightValue === null && count($rows) === 1 ? 100 : (int)$weightValue;
                if ($id <= 0 || isset($seen[$id]) || $weight <= 0 || $weight > 100) throw new \InvalidArgumentException('sales_manager_selection_invalid');
                $seen[$id] = true; $weightTotal += $weight;
                $result[(string)$line][] = ['employeeId' => $id, 'allocationWeight' => $weight];
            }
            if ($rows !== [] && $weightTotal !== 100) throw new \InvalidArgumentException('sales_manager_weight_total_invalid');
        }
        return $result;
    }

    private function assertScope(array $a, CashierV3OperatorScope $o, CashierV3DataScopeContext $s): void
    {
        if (!hash_equals($o->tenantId(), (string)$a['tenant_id']) || !hash_equals($o->organizationId(), (string)$a['organization_id']) || $o->storeId() !== (int)$a['store_id'] || $o->operatorId() !== (int)$a['operator_id'] || !$s->allowsStore($o->storeId())) throw new \InvalidArgumentException('sales_manager_data_scope_denied');
    }
}
