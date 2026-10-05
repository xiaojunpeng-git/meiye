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
 * 独立免除剩余欠款：store_debt 为余额权威，当前未恢复的取消事实为免除金额权威。
 * 不伪造补交、不改写原订单/已用权益/收款/业绩；状态3与整单冲销状态2分开。
 * R50：取消审计金额同时是不可使用的权益额度，由统一核销守卫扣除，不能当作赠送放行。
 * 确认金额快照仅用于检测期间发生的补交，最终状态由事务内行锁裁决。
 */
final class CashierV3DebtCancellationServices
{
    public const TABLE = 'cashier_v3_debt_cancellation';
    public const CANCELLED_STATUS = 3;

    /** 命令网关负责事务、操作权限与幂等回执；本服务再次强制校验欠款门店。 */
    public function executeInTx(array $scope): array
    {
        CashierV3TransactionGuard::assertInTransaction('debtCancellation.execute');
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
            throw self::failure('无权取消该欠款。', 'debt_cancel_permission');
        }
        $payload = (array)($scope['payload'] ?? []);
        $id = (string)($payload['debtId'] ?? '');
        if (!preg_match('/^[1-9][0-9]*$/D', $id)) throw self::failure('欠款标识无效。', 'debt_cancel_identity');
        $reason = trim((string)($payload['reason'] ?? ''));
        if ($reason === '' || mb_strlen($reason) > 255) throw self::failure('请填写取消原因（不超过255字）。', 'debt_cancel_reason');
        $key = (string)($scope['idempotency_key'] ?? '');
        if ($key === '') throw self::failure('取消请求无效，请重新操作。', 'debt_cancel_key');
        // 与补交及补交作废共用同一债务行锁，失败时事实和状态一起回滚。
        $debt = (array)Db::name('store_debt')->where('id', (int)$id)->lock(true)->find();
        if (!$debt || (int)$debt['store_id'] !== $operator->storeId()) throw self::failure('无权取消该欠款。', 'debt_cancel_store');
        // 同一债务允许“取消→恢复→再取消”；旧事实只能追加反向记录，不覆盖。
        $existing = (array)Db::name(self::TABLE)->where('debt_id', (int)$id)->order('id desc')->lock(true)->find();
        if ($existing) {
            if ((string)$existing['tenant_id'] !== $dataScope->tenantId()) {
                throw self::failure('欠款取消记录异常。', 'debt_cancel_audit_mismatch');
            }
            $restored = Db::name(CashierV3DebtRestorationServices::TABLE)
                ->where('cancellation_id', (string)$existing['cancellation_id'])->lock(true)->find();
            if ((int)$debt['status'] === self::CANCELLED_STATUS) {
                if ($restored) throw self::failure('欠款取消记录异常。', 'debt_cancel_audit_mismatch');
                // 新请求不能冒充原取消成功事件；同一请求由命令回执重放。
                if ((string)$existing['command_idempotency_key'] !== $key) throw self::failure('该欠款已取消。', 'debt_cancel_already_cancelled');
                return $this->result($existing, true);
            }
            if (!$restored || (int)$debt['status'] !== 0) {
                throw self::failure('欠款取消记录异常。', 'debt_cancel_audit_mismatch');
            }
        } elseif ((int)$debt['status'] === self::CANCELLED_STATUS) {
            throw self::failure('欠款取消记录异常。', 'debt_cancel_audit_missing');
        }
        $total = self::cents($debt['total_debt']);
        $repaid = self::cents($debt['repaid_debt']);
        $remaining = $total - $repaid;
        if ((int)$debt['status'] !== 0 || $remaining <= 0 || $repaid < 0) throw self::failure('该欠款已结清或已失效。', 'debt_cancel_inactive');
        if (self::cents($payload['remainingAmount'] ?? '') !== $remaining) throw self::failure('剩余欠款已变化，请刷新后重新取消。', 'debt_cancel_amount_changed');
        $now = time();
        $factId = 'DC-' . strtoupper(substr(hash('sha256', $dataScope->tenantId() . '|' . $key), 0, 40));
        $profile = $dataScope->operatorProfile();
        // 与业务事件使用相同的可信非空姓名快照，不能被空 staff_name 遮蔽。
        $operatorName = '';
        foreach (['staff_name', 'real_name', 'name', 'account'] as $field) {
            $operatorName = trim((string)($profile[$field] ?? ''));
            if ($operatorName !== '') break;
        }
        $row = [
            'cancellation_id' => $factId, 'debt_id' => (int)$id, 'tenant_id' => $dataScope->tenantId(),
            'store_id' => $operator->storeId(), 'member_id' => (int)$debt['uid'],
            'debt_no_snapshot' => (string)$debt['debt_no'], 'source_order_no_snapshot' => (string)$debt['order_sn'],
            'original_debt_cents' => $total, 'repaid_debt_cents' => $repaid, 'cancelled_debt_cents' => $remaining,
            'reason_snapshot' => $reason, 'operator_id' => $operator->operatorId(),
            'operator_name_snapshot' => $operatorName,
            'store_name_snapshot' => (string)Db::name('system_store')->where('id', $operator->storeId())->value('name'),
            'command_idempotency_key' => $key, 'business_date' => date('Y-m-d', $now),
            'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now,
        ];
        // 同一秒可经历取消/恢复/再取消；事件版本按锁定债务的已入账最大值递增。
        $aggregateVersion = max(1, (int)Db::name('cashier_v3_business_event')
            ->where('tenant_id', $dataScope->tenantId())->where('aggregate_type', 'debt')
            ->where('aggregate_id', $id)->max('aggregate_version') + 1);
        $event = $recorder->recordInTx($execution, (array)($scope['event_contract'] ?? []), [
            'event_type' => 'debt.cancelled', 'aggregate_type' => 'debt', 'aggregate_id' => $id,
            'aggregate_version' => $aggregateVersion, 'event_version' => 1,
            'source_type' => 'cancel-order-center-debt', 'source_id' => $factId,
            'member_id' => (int)$debt['uid'], 'business_date' => $row['business_date'],
            'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now,
            'aggregate_name_snapshot' => $row['debt_no_snapshot'], 'store_name_snapshot' => $row['store_name_snapshot'],
            'payload' => ['debtId' => (int)$id, 'cancelledDebtCents' => $remaining, 'reason' => $reason, 'cancellationId' => $factId],
        ]);
        $row['business_event_no'] = (string)$event['event_no'];
        if (Db::name(self::TABLE)->insert($row) !== 1
            || Db::name('store_debt')->where('id', (int)$id)->where('status', 0)
                ->update(['status' => self::CANCELLED_STATUS, 'update_time' => $now]) !== 1) {
            throw self::failure('欠款状态已变化，取消未完成。', 'debt_cancel_race');
        }
        return $this->result($row, false);
    }

    /** 无收款或业绩事实；金额不使用浮点转换，拒绝异常数据库值。 */
    public static function cents($value): int
    {
        $raw = (string)$value;
        if (!preg_match('/^[0-9]{1,10}(?:\.[0-9]{1,2})?$/D', $raw)) throw self::failure('欠款金额资料异常。', 'debt_cancel_money');
        [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
        return (int)$whole * 100 + (int)str_pad($fraction, 2, '0');
    }

    private function result(array $row, bool $replayed): array
    {
        return ['data' => ['debtCancellation' => ['cancellationId' => $row['cancellation_id'],
            'debtId' => (int)$row['debt_id'], 'cancelledDebtCents' => (int)$row['cancelled_debt_cents'], 'replayed' => $replayed]],
            'business_no' => $row['cancellation_id'], 'touched' => [], 'message' => '剩余欠款已取消。'];
    }

    private static function failure(string $message, string $reason): CashierV3CommandException
    {
        return new CashierV3CommandException(CashierV3ResultCode::INVALID_COMMAND_CONTEXT, $message,
            CashierV3ResultCode::STATUS_FAILED, ['reason' => $reason]);
    }
}
