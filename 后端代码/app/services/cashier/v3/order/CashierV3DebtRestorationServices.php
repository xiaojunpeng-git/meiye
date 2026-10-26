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

/** 恢复一次尚未逆转的欠款取消；不创建收款或卡次数事实。 */
final class CashierV3DebtRestorationServices
{
    public const TABLE = 'cashier_v3_debt_restoration';

    /** 详情仅按授权门店读取不可变操作事实，不从页面状态推断历史。 */
    public function history(array $payload, CashierV3OperatorScope $operator, CashierV3DataScopeContext $dataScope): array
    {
        $id = (string)($payload['debtId'] ?? '');
        if (!preg_match('/^[1-9][0-9]*$/D', $id)
            || $operator->tenantId() !== $dataScope->tenantId()
            || !$dataScope->hasFeature('cashier.v3.order_center')) {
            throw self::failure('无权查看该欠款变更记录。', 'debt_history_permission');
        }
        $debt = (array)Db::name('store_debt')->where('id', (int)$id)->find();
        if (!$debt || !$dataScope->allowsStore((int)$debt['store_id'])) {
            throw self::failure('无权查看该欠款变更记录。', 'debt_history_store');
        }
        $cancellations = Db::name(CashierV3DebtCancellationServices::TABLE)
            ->where('debt_id', (int)$id)->where('tenant_id', $dataScope->tenantId())
            ->where('store_id', (int)$debt['store_id'])->order('id desc')->limit(100)->select()->toArray();
        $restorations = Db::name(self::TABLE)->where('debt_id', (int)$id)
            ->where('tenant_id', $dataScope->tenantId())->where('store_id', (int)$debt['store_id'])
            ->order('id desc')->limit(100)->select()->toArray();
        $restoredByCancellation = [];
        foreach ($restorations as $row) {
            $restoredByCancellation[(string)$row['cancellation_id']] = $row;
        }
        $changes = [];
        // 倒序按取消轮次重建，每次恢复必在其取消之后；秒级时间相同时也不倒置。
        foreach ($cancellations as $row) {
            $cancellationId = (string)$row['cancellation_id'];
            if (isset($restoredByCancellation[$cancellationId])) {
                $restore = $restoredByCancellation[$cancellationId];
                $changes[] = ['type' => 'restore', 'factId' => (string)$restore['restoration_id'],
                    'cancellationId' => $cancellationId,
                    'amountCents' => (int)$restore['restored_debt_cents'], 'reason' => '',
                    'operatorId' => (int)$restore['operator_id'],
                    'operatorName' => (string)$restore['operator_name_snapshot'],
                    'occurredAt' => (int)$restore['occurred_at']];
            }
            $changes[] = ['type' => 'cancel', 'factId' => $cancellationId,
                'amountCents' => (int)$row['cancelled_debt_cents'],
                'reason' => (string)$row['reason_snapshot'],
                'operatorId' => (int)$row['operator_id'],
                'operatorName' => (string)$row['operator_name_snapshot'],
                'occurredAt' => (int)$row['occurred_at']];
        }
        return ['changes' => array_slice($changes, 0, 100)];
    }

    /** 与取消、补交共用债务行锁；恢复金额及权益限额在同一事务提交后由权威事实重算。 */
    public function executeInTx(array $scope): array
    {
        CashierV3TransactionGuard::assertInTransaction('debtRestoration.execute');
        $operator = $scope['operator_scope'] ?? null;
        $dataScope = $scope['data_scope'] ?? null;
        $recorder = $scope['event_recorder'] ?? null;
        $execution = $scope['event_execution'] ?? null;
        if (!$operator instanceof CashierV3OperatorScope || !$dataScope instanceof CashierV3DataScopeContext
            || !$recorder instanceof CashierV3BusinessEventRecorder || !$execution instanceof CashierV3BusinessEventExecution
            || $operator->tenantId() !== $dataScope->tenantId()
            || $operator->storeId() !== $dataScope->forcedStoreId()
            || !$dataScope->allowsStore($operator->storeId())
            || $dataScope->isReadOnlySession()
            || !$dataScope->hasFeature('cashier.v3.order.void')) {
            throw self::failure('无权恢复该欠款。', 'debt_restore_permission');
        }
        $payload = (array)($scope['payload'] ?? []);
        $id = (string)($payload['debtId'] ?? '');
        $cancellationId = trim((string)($payload['cancellationId'] ?? ''));
        $key = (string)($scope['idempotency_key'] ?? '');
        if (!preg_match('/^[1-9][0-9]*$/D', $id) || $cancellationId === '' || $key === '') {
            throw self::failure('恢复请求无效，请刷新后重试。', 'debt_restore_identity');
        }
        $debt = (array)Db::name('store_debt')->where('id', (int)$id)->lock(true)->find();
        if (!$debt || (int)$debt['store_id'] !== $operator->storeId()) {
            throw self::failure('无权恢复该欠款。', 'debt_restore_store');
        }
        // Cancellation reduced the transferred value; restoring that debt after
        // conversion would leave a new receivable with no surviving card rights.
        \app\services\cashier\v3\card\CashierV3CardConversionGuard::assertDebtNotConverted($debt, $dataScope->tenantId());
        // 旧卡退款已不可逆地终止剩余权益；恢复其关联欠款会让会员重新背债，
        // 却无法恢复原卡，必须在服务端阻止（页面显隐不能承担此约束）。
        $originOrder = (array)Db::name('store_order')->where('id', (int)($debt['order_id'] ?? 0))->find();
        if ($originOrder && (string)($originOrder['pay_type'] ?? '') === 'migration'
            && (int)($originOrder['refund_status'] ?? 0) === 2) {
            throw self::failure('该欠款关联的旧卡已退款，不能恢复欠款。', 'debt_restore_imported_card_refunded');
        }
        $cancel = (array)Db::name(CashierV3DebtCancellationServices::TABLE)
            ->where('debt_id', (int)$id)->order('id desc')->lock(true)->find();
        if (!$cancel || (string)$cancel['tenant_id'] !== $dataScope->tenantId()
            || (string)$cancel['cancellation_id'] !== $cancellationId
            || (int)$cancel['member_id'] !== (int)$debt['uid']) {
            throw self::failure('取消记录已变化，请刷新后重试。', 'debt_restore_cancel_changed');
        }
        $restored = (array)Db::name(self::TABLE)->where('cancellation_id', $cancellationId)->lock(true)->find();
        if ($restored) {
            if ((string)$restored['command_idempotency_key'] === $key && (int)$debt['status'] === 0) {
                return $this->result($restored, true);
            }
            throw self::failure('该欠款已恢复。', 'debt_restore_already_restored');
        }
        $remaining = CashierV3DebtCancellationServices::cents($debt['total_debt'])
            - CashierV3DebtCancellationServices::cents($debt['repaid_debt']);
        if ((int)$debt['status'] !== CashierV3DebtCancellationServices::CANCELLED_STATUS
            || $remaining <= 0 || (int)$cancel['cancelled_debt_cents'] !== $remaining) {
            throw self::failure('欠款状态或金额已变化，请刷新后重试。', 'debt_restore_state_changed');
        }
        $now = time();
        $factId = 'DR-' . strtoupper(substr(hash('sha256', $dataScope->tenantId() . '|' . $key), 0, 40));
        $profile = $dataScope->operatorProfile();
        $operatorName = '';
        foreach (['staff_name', 'real_name', 'name', 'account'] as $field) {
            $operatorName = trim((string)($profile[$field] ?? ''));
            if ($operatorName !== '') break;
        }
        $row = [
            'restoration_id' => $factId, 'cancellation_id' => $cancellationId,
            'debt_id' => (int)$id, 'tenant_id' => $dataScope->tenantId(),
            'store_id' => $operator->storeId(), 'member_id' => (int)$debt['uid'],
            'restored_debt_cents' => $remaining, 'operator_id' => $operator->operatorId(),
            'operator_name_snapshot' => $operatorName,
            'store_name_snapshot' => (string)Db::name('system_store')->where('id', $operator->storeId())->value('name'),
            'command_idempotency_key' => $key, 'business_date' => date('Y-m-d', $now),
            'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now,
        ];
        // 与取消共用债务行锁和单调事件版本，不能因同秒反复操作碰撞事件键。
        $aggregateVersion = max(1, (int)Db::name('cashier_v3_business_event')
            ->where('tenant_id', $dataScope->tenantId())->where('aggregate_type', 'debt')
            ->where('aggregate_id', $id)->max('aggregate_version') + 1);
        $event = $recorder->recordInTx($execution, (array)($scope['event_contract'] ?? []), [
            'event_type' => 'debt.restored', 'aggregate_type' => 'debt', 'aggregate_id' => $id,
            'aggregate_version' => $aggregateVersion, 'event_version' => 1,
            'source_type' => 'restore-order-center-debt', 'source_id' => $factId,
            'member_id' => (int)$debt['uid'], 'business_date' => $row['business_date'],
            'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now,
            'aggregate_name_snapshot' => (string)$debt['debt_no'],
            'store_name_snapshot' => $row['store_name_snapshot'],
            'payload' => ['debtId' => (int)$id, 'restoredDebtCents' => $remaining,
                'cancellationId' => $cancellationId, 'restorationId' => $factId],
        ]);
        $row['business_event_no'] = (string)$event['event_no'];
        if (Db::name(self::TABLE)->insert($row) !== 1
            || Db::name('store_debt')->where('id', (int)$id)
                ->where('status', CashierV3DebtCancellationServices::CANCELLED_STATUS)
                ->update(['status' => 0, 'update_time' => $now]) !== 1) {
            throw self::failure('欠款状态已变化，恢复未完成。', 'debt_restore_race');
        }
        return $this->result($row, false);
    }

    private function result(array $row, bool $replayed): array
    {
        return ['data' => ['debtRestoration' => ['restorationId' => $row['restoration_id'],
            'debtId' => (int)$row['debt_id'], 'restoredDebtCents' => (int)$row['restored_debt_cents'],
            'replayed' => $replayed]], 'business_no' => $row['restoration_id'],
            'touched' => [], 'message' => '欠款已恢复。'];
    }

    private static function failure(string $message, string $reason): CashierV3CommandException
    {
        return new CashierV3CommandException(CashierV3ResultCode::INVALID_COMMAND_CONTEXT, $message,
            CashierV3ResultCode::STATUS_FAILED, ['reason' => $reason]);
    }
}
