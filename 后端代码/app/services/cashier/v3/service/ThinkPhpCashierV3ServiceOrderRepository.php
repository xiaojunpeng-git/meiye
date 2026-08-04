<?php

namespace app\services\cashier\v3\service;

use think\facade\Db;

final class ThinkPhpCashierV3ServiceOrderRepository implements CashierV3ServiceOrderRepository
{
    public const LINE_SOURCE_ENTITLEMENT = 'ENTITLEMENT';
    public const LINE_SOURCE_SALE_PROJECT = 'SALE_PROJECT';
    public const ORDER_TABLE = 'cashier_v3_service_order';
    public const LINE_TABLE = 'cashier_v3_service_order_line';
    public const GUARD_TABLE = 'cashier_v3_service_order_entitlement_guard';
    public const OPERATION_TABLE = 'cashier_v3_service_order_operation';

    /** @return mixed */
    public function transaction(callable $callback)
    {
        return Db::transaction($callback);
    }

    public function lockOrCreateEntitlementGuard(string $tenantId, int $entitlementSourceDetailId): array
    {
        $now = time();
        Db::execute(
            'INSERT IGNORE INTO `eb_cashier_v3_service_order_entitlement_guard`'
            . ' (`tenant_id`,`entitlement_source_detail_id`,`current_version`,`last_action`,`created_at`,`updated_at`)'
            . ' VALUES (?,?,1,?,?,?)',
            [$tenantId, $entitlementSourceDetailId, 'guard_created', $now, $now]
        );
        $row = $this->row(Db::name(self::GUARD_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('entitlement_source_detail_id', $entitlementSourceDetailId)
            ->lock(true)
            ->find());
        if ($row === null || (int)($row['current_version'] ?? 0) <= 0) {
            throw self::failure('service_order_entitlement_guard_invalid');
        }
        return $row;
    }

    public function lockOccupationSet(
        string $tenantId,
        int $entitlementSourceDetailId,
        int $includeServiceOrderId = 0
    ): array {
        // The guard already serializes every writer for this entitlement. This
        // first read only discovers ids so locks can follow the global order:
        // guard -> service order id -> complete line set.
        $discovered = $this->rows(Db::name(self::LINE_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('source_type', self::LINE_SOURCE_ENTITLEMENT)
            ->where('entitlement_source_detail_id', $entitlementSourceDetailId)
            ->order('service_order_id asc,id asc')
            ->select());

        $orderIds = [];
        foreach ($discovered as $line) {
            $orderId = (int)($line['service_order_id'] ?? 0);
            if ($orderId > 0) {
                $orderIds[$orderId] = $orderId;
            }
        }
        if ($includeServiceOrderId > 0) {
            $orderIds[$includeServiceOrderId] = $includeServiceOrderId;
        }
        ksort($orderIds, SORT_NUMERIC);

        $orders = [];
        $lines = [];
        $targetLines = [];
        if ($orderIds) {
            $ids = array_values($orderIds);
            $orders = $this->rows(Db::name(self::ORDER_TABLE)
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $ids)
                ->order('id asc')
                ->lock(true)
                ->select());
            $lines = $this->rows(Db::name(self::LINE_TABLE)
                ->where('tenant_id', $tenantId)
                ->whereIn('service_order_id', $ids)
                ->order('service_order_id asc,id asc')
                ->lock(true)
                ->select());
            foreach ($lines as $line) {
                if ((string)($line['source_type'] ?? self::LINE_SOURCE_ENTITLEMENT)
                        === self::LINE_SOURCE_ENTITLEMENT
                    && (int)($line['entitlement_source_detail_id'] ?? 0)
                        === $entitlementSourceDetailId) {
                    $targetLines[] = $line;
                }
            }
        }
        return compact('orders', 'lines', 'targetLines');
    }

    public function findOperation(string $tenantId, string $idempotencyKey): ?array
    {
        return $this->row(Db::name(self::OPERATION_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('command_idempotency_key', $idempotencyKey)
            ->find());
    }

    public function insertServiceOrder(array $row): array
    {
        return $this->insert(self::ORDER_TABLE, $row, 'service_order');
    }

    public function insertLine(array $row): array
    {
        return $this->insert(self::LINE_TABLE, $row, 'service_order_line');
    }

    public function updateServiceOrderCas(
        string $tenantId,
        int $serviceOrderId,
        int $expectedVersion,
        array $fields
    ): bool {
        $this->assertFields($fields, [
            'status', 'version', 'participant_employee_ids_json',
            'service_started_at', 'pending_checkout_at', 'completed_at',
            'cancelled_at', 'voided_at',
            'updated_at',
        ]);
        return (int)Db::name(self::ORDER_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('id', $serviceOrderId)
            ->where('version', $expectedVersion)
            ->update($fields) === 1;
    }

    public function updateLineCas(
        string $tenantId,
        int $lineId,
        int $expectedVersion,
        array $fields
    ): bool {
        $this->assertFields($fields, [
            'occupied_times', 'status', 'version', 'artisan_staff_id',
            'artisan_employee_id', 'artisan_name_snapshot', 'updated_at',
        ]);
        return (int)Db::name(self::LINE_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('id', $lineId)
            ->where('version', $expectedVersion)
            ->update($fields) === 1;
    }

    public function bumpEntitlementGuardCas(
        string $tenantId,
        int $entitlementSourceDetailId,
        int $expectedVersion,
        string $action,
        int $now
    ): int {
        if ($expectedVersion <= 0 || $expectedVersion >= PHP_INT_MAX) {
            throw self::failure('service_order_entitlement_guard_version_invalid');
        }
        $affected = Db::name(self::GUARD_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('entitlement_source_detail_id', $entitlementSourceDetailId)
            ->where('current_version', $expectedVersion)
            ->update([
                'current_version' => Db::raw('current_version + 1'),
                'last_action' => substr($action, 0, 64),
                'updated_at' => $now,
            ]);
        if ((int)$affected !== 1) {
            throw self::failure('service_order_entitlement_guard_version_conflict');
        }
        return $expectedVersion + 1;
    }

    public function insertOperation(array $row): array
    {
        return $this->insert(self::OPERATION_TABLE, $row, 'service_order_operation');
    }

    private function insert(string $table, array $row, string $resource): array
    {
        try {
            $id = (int)Db::name($table)->insertGetId($row);
        } catch (\Throwable $exception) {
            if ($this->isDuplicateKey($exception)) {
                throw self::failure($resource . '_unique_conflict');
            }
            throw $exception;
        }
        if ($id <= 0) {
            throw self::failure($resource . '_insert_failed');
        }
        $row['id'] = $id;
        return $row;
    }

    private function assertFields(array $fields, array $allowed): void
    {
        foreach (array_keys($fields) as $field) {
            if (!in_array($field, $allowed, true)) {
                throw self::failure('service_order_repository_field_not_allowed', ['field' => $field]);
            }
        }
    }

    private function row($row): ?array
    {
        if (is_object($row) && method_exists($row, 'toArray')) {
            $row = $row->toArray();
        }
        return is_array($row) && $row ? $row : null;
    }

    private function rows($rows): array
    {
        if (is_object($rows) && method_exists($rows, 'toArray')) {
            $rows = $rows->toArray();
        }
        if (!is_array($rows)) {
            throw self::failure('service_order_repository_result_invalid');
        }
        return array_values($rows);
    }

    private function isDuplicateKey(\Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());
        return strpos($message, 'duplicate') !== false
            || strpos($message, '1062') !== false
            || (string)$exception->getCode() === '23000';
    }

    private static function failure(string $reason, array $detail = []): CashierV3ServiceOrderAuthorityException
    {
        return new CashierV3ServiceOrderAuthorityException($reason, $detail);
    }
}
