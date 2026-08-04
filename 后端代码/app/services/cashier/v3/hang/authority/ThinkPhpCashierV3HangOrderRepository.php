<?php

namespace app\services\cashier\v3\hang\authority;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3TransactionGuard;
use think\facade\Db;

final class ThinkPhpCashierV3HangOrderRepository implements CashierV3HangOrderRepository
{
    public const HEADER_TABLE = 'cashier_v3_hang_order';
    public const LINE_TABLE = 'cashier_v3_hang_order_line';

    public function persistInTx(
        CashierV3HangOrderPlanV1 $plan,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        CashierV3TransactionGuard::assertInTransaction('hangOrderAuthority.persistInTx');
        $header = $plan->header();
        $this->assertScope($header, $operatorScope, $dataScope);

        $existing = $this->discoverAndLock($header);
        if ($existing) {
            $this->assertReplay($plan, $existing);
            return $this->result($existing, $plan, true, 0, 0);
        }

        $headerRow = $header;
        $headerRow['add_time'] = (int)$header['recorded_at'];
        $headerRow['update_time'] = (int)$header['recorded_at'];
        try {
            $affected = (int)Db::name(self::HEADER_TABLE)->insert($headerRow);
        } catch (\Throwable $exception) {
            if (!$this->isDuplicateKey($exception)) {
                throw $exception;
            }
            $existing = $this->discoverAndLock($header);
            if (!$existing) {
                throw self::failure('hang_order_identity_conflict');
            }
            $this->assertReplay($plan, $existing);
            return $this->result($existing, $plan, true, 0, 0);
        }
        if ($affected !== 1) {
            throw self::failure('hang_order_header_insert_incomplete');
        }

        $rows = [];
        foreach ($plan->lines() as $line) {
            $line['add_time'] = (int)$header['recorded_at'];
            $line['update_time'] = (int)$header['recorded_at'];
            $rows[] = $line;
        }
        $lineAffected = (int)Db::name(self::LINE_TABLE)->insertAll($rows);
        if ($lineAffected !== count($rows)) {
            throw self::failure('hang_order_line_insert_incomplete', [
                'expected' => count($rows),
                'affected' => $lineAffected,
            ]);
        }
        $persisted = $this->lockById((string)$header['tenant_id'], (string)$header['hang_order_id']);
        if (!$persisted) {
            throw self::failure('hang_order_insert_readback_missing');
        }
        $this->assertReplay($plan, $persisted);
        return $this->result($persisted, $plan, false, 1, $lineAffected);
    }

    private function discoverAndLock(array $header)
    {
        $row = Db::name(self::HEADER_TABLE)
            ->where('tenant_id', $header['tenant_id'])
            ->where('command_idempotency_key', $header['command_idempotency_key'])
            ->field('hang_order_id')
            ->find();
        if (!$row) {
            $row = Db::name(self::HEADER_TABLE)
                ->where('tenant_id', $header['tenant_id'])
                ->where('natural_key', $header['natural_key'])
                ->field('hang_order_id')
                ->find();
        }
        return $row
            ? $this->lockById((string)$header['tenant_id'], (string)$row['hang_order_id'])
            : null;
    }

    private function lockById(string $tenantId, string $hangOrderId)
    {
        return Db::name(self::HEADER_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('hang_order_id', $hangOrderId)
            ->lock(true)
            ->find();
    }

    private function assertReplay(CashierV3HangOrderPlanV1 $plan, array $existing): void
    {
        $expected = $plan->header();
        foreach (['hang_order_id', 'natural_key', 'immutable_fingerprint', 'tenant_id', 'store_id'] as $field) {
            if (!array_key_exists($field, $existing)
                || (string)$existing[$field] !== (string)$expected[$field]) {
                throw self::failure('hang_order_natural_key_payload_conflict', ['field' => $field]);
            }
        }
        $actualLines = $this->rows(Db::name(self::LINE_TABLE)
            ->where('tenant_id', $expected['tenant_id'])
            ->where('hang_order_id', $expected['hang_order_id'])
            ->order('line_no asc,id asc')
            ->lock(true)
            ->select());
        $expectedLines = $plan->lines();
        if (count($actualLines) !== count($expectedLines)) {
            throw self::failure('hang_order_replay_line_count_conflict');
        }
        foreach ($expectedLines as $index => $line) {
            $actual = $actualLines[$index] ?? [];
            foreach (['hang_order_line_id', 'natural_key', 'immutable_fingerprint', 'workspace_line_id'] as $field) {
                if (!array_key_exists($field, $actual)
                    || (string)$actual[$field] !== (string)$line[$field]) {
                    throw self::failure('hang_order_replay_line_payload_conflict', [
                        'workspaceLineId' => $line['workspace_line_id'],
                        'field' => $field,
                    ]);
                }
            }
        }
    }

    private function assertScope(
        array $header,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): void {
        if (!hash_equals($operatorScope->tenantId(), (string)$header['tenant_id'])
            || !hash_equals($operatorScope->tenantId(), $dataScope->tenantId())
            || !hash_equals($operatorScope->organizationId(), (string)$header['organization_id'])
            || !hash_equals($operatorScope->organizationId(), $dataScope->organizationId())
            || $operatorScope->storeId() !== (int)$header['store_id']
            || $operatorScope->storeId() !== $dataScope->forcedStoreId()
            || $operatorScope->operatorId() !== (int)$header['operator_id']
            || $operatorScope->operatorId() !== $dataScope->operatorId()
            || !$dataScope->allowsStore($operatorScope->storeId())) {
            throw self::failure('hang_order_data_scope_denied');
        }
    }

    private function result(
        array $header,
        CashierV3HangOrderPlanV1 $plan,
        bool $replayed,
        int $headerRows,
        int $lineRows
    ): array {
        return [
            'contractVersion' => CashierV3HangOrderPlanV1::CONTRACT_VERSION,
            'hangOrderId' => (string)$header['hang_order_id'],
            'hangOrderNo' => (string)$header['hang_order_no'],
            'hangMode' => (string)$header['hang_mode'],
            'hangStatus' => (string)$header['hang_status'],
            'hangVersion' => (int)$header['hang_version'],
            'roomId' => (int)$header['room_id'],
            'roomTimeSlotId' => (string)$header['room_time_slot_id'],
            'roomGuardFingerprint' => (string)$header['room_guard_fingerprint'],
            'planFingerprint' => $plan->fingerprint(),
            'commandIdempotencyKey' => (string)$header['command_idempotency_key'],
            'replayed' => $replayed,
            'affected' => ['headerRows' => $headerRows, 'lineRows' => $lineRows],
        ];
    }

    private function rows($rows): array
    {
        if (is_object($rows) && method_exists($rows, 'toArray')) {
            $rows = $rows->toArray();
        }
        return is_array($rows) ? array_values($rows) : [];
    }

    private function isDuplicateKey(\Throwable $exception): bool
    {
        for ($cursor = $exception; $cursor instanceof \Throwable; $cursor = $cursor->getPrevious()) {
            $message = strtolower($cursor->getMessage());
            if ((int)$cursor->getCode() === 1062
                || strpos($message, '1062') !== false
                || strpos($message, 'duplicate entry') !== false) {
                return true;
            }
        }
        return false;
    }

    private static function failure(
        string $reason,
        array $detail = []
    ): CashierV3HangOrderAuthorityException {
        return new CashierV3HangOrderAuthorityException($reason, $detail);
    }
}
