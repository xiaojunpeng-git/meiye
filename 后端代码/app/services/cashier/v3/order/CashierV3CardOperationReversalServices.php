<?php
declare(strict_types=1);

namespace app\services\cashier\v3\order;

use app\model\order\StoreOrderTerminalOperation;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\cashier\CashierV3CashierReadinessGuard;
use app\services\cashier\v3\cashier\CashierV3EntitlementResourceVersionProvider;
use think\facade\Db;

/** Reverses the non-cash entitlement credit of a completed upgrade sale. */
final class CashierV3CardOperationReversalServices
{
    public const SETTLEMENT_TABLE = 'cashier_v3_card_operation_settlement';
    public const OPERATION_TABLE = 'cashier_v3_card_operation';
    public const OPERATION_LINE_TABLE = 'cashier_v3_card_operation_line';

    /** @return array<string,mixed> */
    public function prepare(array $source, string $action, CashierV3DataScopeContext $scope): array
    {
        CashierV3TransactionGuard::assertInTransaction('cardOperationReversal.prepare');
        $settlement = Db::name(self::SETTLEMENT_TABLE)
            ->where('tenant_id', $scope->tenantId())
            ->where('sales_order_id', (string)$source['sourceId'])
            ->lock(true)->find();
        if (!$settlement) return ['isUpgrade' => false, 'entitlementCreditCents' => 0];
        $settlement = (array)$settlement;
        if ((string)($settlement['settlement_status'] ?? '') !== 'settled'
            || (int)($settlement['current_version'] ?? 0) <= 0
            || !in_array((string)($settlement['operation_type'] ?? ''), ['card_upgrade', 'project_upgrade'], true)) {
            throw self::failure('card_operation_reversal_settlement_not_reversible');
        }
        $operation = (array)Db::name(self::OPERATION_TABLE)
            ->where('tenant_id', $scope->tenantId())
            ->where('operation_id', (string)$settlement['operation_id'])
            ->lock(true)->find();
        if (!$operation || (string)($operation['operation_status'] ?? '') !== 'succeeded') {
            throw self::failure('card_operation_reversal_operation_not_settled');
        }
        $lines = Db::name(self::OPERATION_LINE_TABLE)
            ->where('tenant_id', $scope->tenantId())
            ->where('operation_id', (string)$settlement['operation_id'])
            ->where('line_role', 'source_project')->order('line_no', 'asc')->lock(true)->select()->toArray();

        $type = (string)$settlement['operation_type'];
        if ($type === 'card_upgrade') {
            $this->assertCardUpgradeSourceRestorable($settlement, $operation, $scope);
        } else {
            $this->assertProjectUpgradeRestorable($settlement, $operation, $lines, $scope);
        }
        return [
            'isUpgrade' => true,
            'settlement' => $settlement,
            'operation' => $operation,
            'sourceLines' => $lines,
            'entitlementCreditCents' => (int)$settlement['entitlement_credit_cents'],
            'action' => $action,
        ];
    }

    public function apply(
        array $prepared,
        string $action,
        string $lifecycleOperationId,
        CashierV3OperatorScope $operator,
        CashierV3DataScopeContext $scope,
        int $now
    ): void {
        CashierV3TransactionGuard::assertInTransaction('cardOperationReversal.apply');
        if (empty($prepared['isUpgrade'])) return;
        $settlement = (array)$prepared['settlement'];
        $operation = (array)$prepared['operation'];
        if ((string)$settlement['operation_type'] === 'card_upgrade') {
            $this->restoreCardUpgradeSource($settlement, $operation, $lifecycleOperationId, $operator, $scope, $now);
        } else {
            $this->revokeProjectTarget($settlement, $action, $now);
            $this->restoreProjectSources((array)$prepared['sourceLines'], $lifecycleOperationId, $operator, $scope, $now);
        }
        $updated = Db::name(self::SETTLEMENT_TABLE)
            ->where('id', (int)$settlement['id'])
            ->where('settlement_status', 'settled')
            ->where('current_version', (int)$settlement['current_version'])
            ->update([
                'settlement_status' => 'reversed',
                'reversed_by_operation_id' => $lifecycleOperationId,
                'reversed_at' => $now,
                'current_version' => (int)$settlement['current_version'] + 1,
            ]);
        if ((int)$updated !== 1) throw self::failure('card_operation_reversal_settlement_race');
    }

    private function assertCardUpgradeSourceRestorable(array $settlement, array $operation, CashierV3DataScopeContext $scope): void
    {
        $oldOrder = (array)Db::name('store_order')->where('id', (int)$settlement['source_legacy_order_id'])->lock(true)->find();
        $state = (array)Db::name('cashier_v3_card_state')->where('tenant_id', $scope->tenantId())
            ->where('card_holder_id', (int)$settlement['source_card_holder_id'])->lock(true)->find();
        if (!$oldOrder || !$state
            || (int)($oldOrder['card_upgrade_use_oid'] ?? 0) !== (int)$settlement['target_legacy_order_id']
            || (string)($state['card_status'] ?? '') !== 'upgraded'
            || (string)($state['last_operation_id'] ?? '') !== (string)$operation['operation_id']) {
            throw self::failure('card_operation_reversal_card_source_changed');
        }
    }

    private function assertProjectUpgradeRestorable(array $settlement, array $operation, array $lines, CashierV3DataScopeContext $scope): void
    {
        $targetId = (int)$settlement['target_entitlement_detail_id'];
        $target = (array)Db::name('store_order_cart_info')->where('id', $targetId)
            ->where('oid', (int)$settlement['target_legacy_order_id'])->where('cart_type', 2)->where('product_type', 6)->lock(true)->find();
        if (!$target || (int)$target['write_surplus_times'] !== (int)$target['write_times']
            || (int)($target['surplus_num'] ?? 0) !== (int)$target['cart_num']
            || (int)($target['split_surplus_num'] ?? 0) !== (int)$target['cart_num']
            || (int)$target['is_writeoff'] !== 0 || (int)$target['refund_num'] !== 0) {
            throw self::failure('card_operation_reversal_project_target_used');
        }
        if (Db::name('cashier_v3_entitlement_writeoff_fact')->where('tenant_id', $scope->tenantId())->where('source_detail_id', $targetId)->count() > 0
            || Db::name('cashier_v3_service_order_line')->where('tenant_id', $scope->tenantId())->where('entitlement_source_detail_id', $targetId)->count() > 0
            || Db::name('store_reservation_order')->where('cart_info_id', $targetId)->where('is_del', 0)->where('is_system_del', 0)->count() > 0) {
            throw self::failure('card_operation_reversal_project_target_downstream_use');
        }
        if ($lines === []) throw self::failure('card_operation_reversal_project_source_lines_missing');
        foreach ($lines as $line) {
            $detail = (array)Db::name('store_order_cart_info')->where('id', (int)$line['source_detail_id'])
                ->where('oid', (int)$settlement['source_legacy_order_id'])->lock(true)->find();
            if (!$detail || (int)$detail['write_surplus_times'] !== (int)$line['quantity_after']) {
                throw self::failure('card_operation_reversal_project_source_changed');
            }
        }
    }

    private function restoreCardUpgradeSource(array $settlement, array $operation, string $reversalId, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope, int $now): void
    {
        if ((int)Db::name('store_order')->where('id', (int)$settlement['source_legacy_order_id'])
            ->where('card_upgrade_use_oid', (int)$settlement['target_legacy_order_id'])->update(['card_upgrade_use_oid' => 0]) !== 1) {
            throw self::failure('card_operation_reversal_card_order_restore_race');
        }
        $state = (array)Db::name('cashier_v3_card_state')->where('tenant_id', $scope->tenantId())
            ->where('card_holder_id', (int)$settlement['source_card_holder_id'])->lock(true)->find();
        $version = (int)($state['current_version'] ?? 0);
        if ($version <= 0 || (int)Db::name('cashier_v3_card_state')->where('id', (int)$state['id'])
            ->where('current_version', $version)->where('card_status', 'upgraded')->update([
                'card_status' => 'enabled', 'status_reason_snapshot' => '升级销售订单退款/作废恢复',
                'current_version' => $version + 1, 'last_operation_id' => $reversalId, 'updated_at' => $now,
            ]) !== 1) throw self::failure('card_operation_reversal_card_state_restore_race');
        (new CashierV3EntitlementResourceVersionProvider(new CashierV3CashierReadinessGuard()))
            ->synchronizeProjectionVersion(
                'card_holder', (string)$settlement['source_card_holder_id'], $operator, $scope
            );
    }

    private function revokeProjectTarget(array $settlement, string $action, int $now): void
    {
        $targetId = (int)$settlement['target_entitlement_detail_id'];
        $target = (array)Db::name('store_order_cart_info')->where('id', $targetId)->lock(true)->find();
        if ((int)Db::name('store_order_cart_info')->where('id', $targetId)
            ->where('write_surplus_times', (int)$target['write_times'])->where('refund_num', 0)->update([
                'write_surplus_times' => 0, 'surplus_num' => 0, 'split_surplus_num' => 0,
                'refund_num' => (int)$target['cart_num'], 'is_writeoff' => 0,
            ]) !== 1) throw self::failure('card_operation_reversal_project_target_revoke_race');
        $terminal = $action === 'void-sales-order' ? StoreOrderTerminalOperation::ACTION_VOID : StoreOrderTerminalOperation::ACTION_REFUND;
        $orderUpdate = ['terminal_action' => $terminal, 'terminal_action_time' => $now];
        if ($terminal === StoreOrderTerminalOperation::ACTION_REFUND) $orderUpdate['refund_status'] = 2;
        if ((int)Db::name('store_order')->where('id', (int)$settlement['target_legacy_order_id'])
            ->where('terminal_action', 0)->update($orderUpdate) !== 1) {
            throw self::failure('card_operation_reversal_project_order_revoke_race');
        }
    }

    private function restoreProjectSources(array $lines, string $reversalId, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope, int $now): void
    {
        foreach ($lines as $line) {
            $before = (int)$line['quantity_before'];
            $after = (int)$line['quantity_after'];
            if ((int)Db::name('store_order_cart_info')->where('id', (int)$line['source_detail_id'])
                ->where('write_surplus_times', $after)->update(['write_surplus_times' => $before, 'is_writeoff' => 0]) !== 1) {
                throw self::failure('card_operation_reversal_project_source_restore_race');
            }
            (new CashierV3EntitlementResourceVersionProvider(new CashierV3CashierReadinessGuard()))
                ->synchronizeProjectionVersion(
                    'member_benefit_pool', (string)$line['source_detail_id'], $operator, $scope
                );
        }
    }

    private static function failure(string $reason): CashierV3CommandException
    {
        return new CashierV3CommandException(
            CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
            '升级订单对应权益已经发生变化，本次退款/作废未提交。',
            CashierV3ResultCode::STATUS_FAILED,
            ['reason' => $reason]
        );
    }
}
