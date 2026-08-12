<?php

namespace app\services\cashier\v3\report;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3TransactionGuard;
use think\facade\Db;

/** Immutable group-wide sales-manager attribution; deliberately contains no amount allocation. */
final class CashierV3SalesManagerFactServices
{
    public const TABLE = 'cashier_v3_sales_manager_fact';
    public const VERSION = 'cashier-v3-sales-manager-v1';

    /** @param array<string,array<int,array{employeeId:int}>> $selectionsByLine */
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
        foreach ($normalized as $line => $rows) foreach ($rows as $row) {
            $id = (int)$row['employeeId']; $employee = $byId[$id]; $name = trim((string)($employee['name'] ?? ''));
            if ($name === '') throw new \InvalidArgumentException('sales_manager_employee_name_missing');
            $natural = implode(':', ['sales-manager', $authority['order_id'], $line, $id]);
            $fact = [
                'fact_id' => 'SMF-' . substr(hash('sha256', $authority['tenant_id'] . '|' . $natural), 0, 40),
                'natural_key' => $natural, 'tenant_id' => (string)$authority['tenant_id'], 'organization_id' => (string)$authority['organization_id'],
                'store_id' => (int)$authority['store_id'], 'member_id' => (int)$authority['member_id'], 'order_id' => (string)$authority['order_id'],
                'order_no_snapshot' => (string)($authority['order_no_snapshot'] ?? ''), 'checkout_request_id' => (string)$authority['checkout_request_id'], 'source_line_id' => $line,
                'business_date' => (string)$authority['business_date'], 'sales_manager_employee_id' => $id, 'sales_manager_name_snapshot' => mb_substr($name, 0, 128),
                'sales_manager_type_snapshot' => mb_substr((string)($employee['employment_type_code'] ?? ''), 0, 16), 'operator_id' => (int)$authority['operator_id'],
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

    private function normalize(array $input): array
    {
        $result = [];
        foreach ($input as $line => $rows) {
            if (trim((string)$line) === '' || !is_array($rows)) throw new \InvalidArgumentException('sales_manager_selection_invalid');
            $seen = [];
            foreach ($rows as $row) { $id = is_array($row) ? (int)($row['employeeId'] ?? $row['employee_id'] ?? $row['id'] ?? 0) : 0; if ($id <= 0 || isset($seen[$id])) throw new \InvalidArgumentException('sales_manager_selection_invalid'); $seen[$id] = true; $result[(string)$line][] = ['employeeId' => $id]; }
        }
        return $result;
    }

    private function assertScope(array $a, CashierV3OperatorScope $o, CashierV3DataScopeContext $s): void
    {
        if (!hash_equals($o->tenantId(), (string)$a['tenant_id']) || !hash_equals($o->organizationId(), (string)$a['organization_id']) || $o->storeId() !== (int)$a['store_id'] || $o->operatorId() !== (int)$a['operator_id'] || !$s->allowsStore($o->storeId())) throw new \InvalidArgumentException('sales_manager_data_scope_denied');
    }
}
