<?php

namespace app\services\cashier\v3\hang;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\hang\authority\CashierV3HangOrderPlanV1;
use app\services\cashier\v3\hang\authority\ThinkPhpCashierV3HangOrderRepository;
use app\services\room\guard\RoomOpenServiceGuardAuthority;
use think\facade\Db;

/** ThinkPHP recovery reader. It only reads rows already owned by the caller. */
final class ThinkPhpCashierV3HangOrderResultReadRepository implements CashierV3HangOrderResultReadRepository
{
    private const RECEIPT_TABLE = 'cashier_v3_command_receipt';
    private const GUARD_TABLE = 'cashier_v3_room_open_service_guard';

    public function findReceiptForActor(string $idempotencyKey, int $storeId, int $operatorId)
    {
        return $this->row(Db::name(self::RECEIPT_TABLE)
            ->where('idempotency_key', $idempotencyKey)
            ->where('store_id', $storeId)
            ->where('operator_id', $operatorId)
            ->field(
                'idempotency_key,action,store_id,operator_id,state_context_id,status,'
                . 'result_code,result_message,result_json,business_no,add_time,finish_time'
            )
            ->find());
    }

    public function findCommittedHangOrder(
        array $receipt,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ) {
        if (!$this->allowsCurrentScope($operatorScope, $dataScope)) {
            return null;
        }
        $idempotencyKey = trim((string)($receipt['idempotency_key'] ?? ''));
        $stateContextId = trim((string)($receipt['state_context_id'] ?? ''));
        if ($idempotencyKey === '' || $stateContextId === '' || strlen($stateContextId) > 64) {
            return null;
        }

        $header = $this->row(Db::name(ThinkPhpCashierV3HangOrderRepository::HEADER_TABLE)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('organization_id', $dataScope->organizationId())
            ->where('store_id', $operatorScope->storeId())
            ->where('operator_id', $operatorScope->operatorId())
            ->where('state_context_id', $stateContextId)
            ->where('command_idempotency_key', $idempotencyKey)
            ->field(
                'hang_order_id,hang_order_no,contract_version,command_idempotency_key,'
                . 'tenant_id,organization_id,store_id,operator_id,state_context_id,hang_mode,'
                . 'hang_status,hang_version,room_id,room_name_snapshot,room_time_slot_id,'
                . 'room_guard_fingerprint,line_count,total_quantity,occurred_at'
            )
            ->find());
        if ($header === null) {
            return null;
        }

        $lineCount = (int)Db::name(ThinkPhpCashierV3HangOrderRepository::LINE_TABLE)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('store_id', $operatorScope->storeId())
            ->where('hang_order_id', (string)$header['hang_order_id'])
            ->where('command_idempotency_key', $idempotencyKey)
            ->where('line_status', 'held')
            ->count();

        $guard = null;
        if ((string)($header['hang_mode'] ?? '') === CashierV3HangOrderPlanV1::MODE_START_SERVICE) {
            $guard = $this->row(Db::name(self::GUARD_TABLE)
                ->where('tenant_id', $dataScope->tenantId())
                ->where('store_id', $operatorScope->storeId())
                ->where('room_id', (int)$header['room_id'])
                ->field('room_id,slot_key,current_version,occupation_status,owner_kind,owner_id,occupied_at')
                ->find());
        }

        return [
            'header' => $header,
            'heldLineCount' => $lineCount,
            'roomGuard' => $guard,
        ];
    }

    private function allowsCurrentScope(
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): bool {
        return $operatorScope->tenantId() !== ''
            && hash_equals($operatorScope->tenantId(), $dataScope->tenantId())
            && hash_equals($operatorScope->organizationId(), $dataScope->organizationId())
            && $operatorScope->storeId() === $dataScope->forcedStoreId()
            && $operatorScope->operatorId() === $dataScope->operatorId()
            && $dataScope->allowsStore($operatorScope->storeId());
    }

    private function row($row): ?array
    {
        if (is_object($row) && method_exists($row, 'toArray')) {
            $row = $row->toArray();
        }
        return is_array($row) ? $row : null;
    }
}
