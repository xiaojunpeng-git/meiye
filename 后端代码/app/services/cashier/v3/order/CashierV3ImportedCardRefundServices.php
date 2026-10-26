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
use app\model\order\StoreOrderTerminalOperation;
use think\facade\Db;

/**
 * 旧卡退款候选源仅是已迁入的卡实例；导入单的金额不是 V3 历史收款事实。
 * 本服务不把旧卡混入正式销售订单，也不按导入权益估值生成正向业绩。
 */
final class CashierV3ImportedCardRefundServices
{
    /**
     * 按已选择会员读取未退款的导入卡。转赠卡和已禁用卡不进入候选，不把原
     * 办卡人的欠款归给当前持卡人；候选数据只供选择，提交时仍重新锁定。
     */
    public function candidates(array $payload, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): array
    {
        $this->assertRefundAccess($operator, $scope);
        $memberId = $this->positiveId($payload['memberId'] ?? null);
        if ($memberId <= 0 || !Db::name('user')->where('uid', $memberId)->where('is_del', 0)->find()) {
            throw self::failure('请选择有效会员。', 'imported_card_member_invalid');
        }
        $page = max(1, (int)($payload['page'] ?? 1));
        $pageSize = min(50, max(1, (int)($payload['pageSize'] ?? 20)));
        $base = Db::name('user_card_holder')->alias('h')
            ->join('store_order o', 'o.id=h.oid')
            ->where('h.uid', $memberId)->where('h.is_del', 0)
            ->where('o.type', 11)->where('o.product_type', 5)
            ->where('o.pay_type', 'migration')->where('o.channel_type', 'migration')
            ->where('o.paid', 1)->where('o.is_del', 0)
            ->where('o.is_system_del', 0)->where('o.is_user_del', 0)->where('o.card_upgrade_use_oid', 0)
            ->where('o.refund_status', 0)->where('o.terminal_action', 0)
            ->whereRaw('o.uid=h.uid')
            ->whereNotExists(function ($state) use ($scope, $memberId): void {
                $state->name('cashier_v3_card_state')->alias('candidate_state')
                    ->whereRaw('candidate_state.card_holder_id=h.id')
                    ->where('candidate_state.tenant_id', $scope->tenantId())
                    ->where(function ($invalid) use ($memberId): void {
                        $invalid->where('candidate_state.current_member_id', '<>', $memberId)
                            ->whereOr('candidate_state.card_status', '<>', 'enabled');
                    });
            });
        $total = (int)(clone $base)->count('h.id');
        $rows = $base->field('h.id,h.uid,h.oid,h.card_name,h.card_no,h.store_id,h.write_times,h.write_surplus_times,o.uid AS origin_member_id,o.order_id AS origin_order_no,o.store_id AS origin_store_id')
            ->order('h.id', 'desc')->page($page, $pageSize)->select()->toArray();
        $orderIds = array_values(array_map('intval', array_column($rows, 'oid')));
        $stores = $rows ? Db::name('system_store')->whereIn('id', array_values(array_unique(array_map('intval', array_column($rows, 'origin_store_id')))))
            ->column('name', 'id') : [];
        $debts = $orderIds ? Db::name('store_debt')->whereIn('order_id', $orderIds)
            ->field('id,order_id,status,total_debt,repaid_debt')->select()->toArray() : [];
        $debtByOrder = [];
        foreach ($debts as $debt) {
            $orderId = (int)$debt['order_id'];
            // 一张导入卡对应一笔来源欠款；重复映射不应静默相加并误导退款人。
            if (isset($debtByOrder[$orderId])) throw self::failure('旧卡欠款关联异常，请联系管理员核对。', 'imported_card_duplicate_debt');
            $debtByOrder[$orderId] = $debt;
        }
        $cards = [];
        foreach ($rows as $row) {
            $holderId = (int)$row['id'];
            $debt = (array)($debtByOrder[(int)$row['oid']] ?? []);
            $remainingDebt = (int)($debt['status'] ?? -1) === 0
                ? max(0, CashierV3DebtCancellationServices::cents($debt['total_debt'])
                    - CashierV3DebtCancellationServices::cents($debt['repaid_debt'])) : 0;
            $cards[] = [
                'holderId' => $holderId, 'memberId' => $memberId,
                'cardName' => (string)$row['card_name'], 'cardNo' => (string)$row['card_no'],
                'originOrderNo' => (string)$row['origin_order_no'],
                'originStoreName' => (string)($stores[(int)$row['origin_store_id']] ?? ''),
                'totalTimes' => (int)$row['write_times'], 'remainingTimes' => (int)$row['write_surplus_times'],
                'remainingDebtCents' => $remainingDebt,
                'canRefund' => true, 'unavailableReason' => '',
                // 退款不受跨店核销开关限制；用权威快照保护确认时的数据，
                // 提交时由本服务锁卡复核，不借用核销场景的权益版本适配器。
                'snapshotFingerprint' => $this->snapshotFingerprint($this->loadSnapshot($holderId, $scope->tenantId(), false)),
            ];
        }
        return ['cards' => $cards, 'total' => $total, 'page' => $page, 'pageSize' => $pageSize,
            'versions' => []];
    }

    /**
     * The gateway owns the transaction and command receipt. Lock the original card and linked
     * debt again: the selector projection is never authority for money or remaining rights.
     */
    public function refundInTx(array $command): array
    {
        CashierV3TransactionGuard::assertInTransaction('importedCardRefund.refund');
        $operator = $command['operator_scope'] ?? null;
        $scope = $command['data_scope'] ?? null;
        $recorder = $command['event_recorder'] ?? null;
        $execution = $command['event_execution'] ?? null;
        if (!$operator instanceof CashierV3OperatorScope || !$scope instanceof CashierV3DataScopeContext
            || !$recorder instanceof CashierV3BusinessEventRecorder || !$execution instanceof CashierV3BusinessEventExecution) {
            throw self::failure('退款上下文无效，请刷新后重试。', 'imported_card_refund_context');
        }
        $this->assertRefundAccess($operator, $scope);
        $payload = (array)($command['payload'] ?? []);
        $holderId = $this->positiveId($payload['holderId'] ?? null);
        $memberId = $this->positiveId($payload['memberId'] ?? null);
        if (!is_string($payload['reason'] ?? null)) {
            throw self::failure('请填写有效退款原因。', 'imported_card_refund_reason_type');
        }
        $reason = trim((string)($payload['reason'] ?? ''));
        $amount = $this->refundCents($payload['refundAmount'] ?? '');
        $key = trim((string)($command['idempotency_key'] ?? ''));
        if ($holderId <= 0 || $memberId <= 0 || $key === '' || $reason === '' || mb_strlen($reason) > 255) {
            throw self::failure('请选择旧卡并填写退款金额、原因。', 'imported_card_refund_input');
        }
        $fingerprint = hash('sha256', json_encode([$holderId, $memberId, $amount, $reason,
            $operator->storeId(), $operator->operatorId()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $existing = (array)Db::name(CashierV3OrderLifecycleServices::OPERATION_TABLE)
            ->where('tenant_id', $scope->tenantId())->where('command_idempotency_key', $key)->lock(true)->find();
        if ($existing) {
            if ((string)$existing['immutable_fingerprint'] !== $fingerprint
                || (string)$existing['source_type'] !== 'imported_card') {
                throw self::failure('退款请求编号已被其他操作使用。', 'imported_card_refund_key_conflict');
            }
            return $this->result($existing, true);
        }
        $snapshot = $this->loadSnapshot($holderId, $scope->tenantId(), true);
        $holder = $snapshot['holder'];
        if (!$holder || (int)$holder['uid'] !== $memberId || (int)$holder['is_del'] !== 0) {
            throw self::failure('旧卡归属或状态已变化，请重新选择。', 'imported_card_refund_holder_changed');
        }
        $order = $snapshot['order'];
        if (!$this->isOpenImportedCard($order) || (int)$order['uid'] !== $memberId) {
            // The original order retains the original debtor after a transfer. Refunding it
            // against the new holder would cancel another member's debt and misdirect cash.
            throw self::failure('该旧卡已退款、已转赠或状态已变化，暂不能办理。', 'imported_card_refund_order_changed');
        }
        $state = $snapshot['state'];
        if ($state && ((int)$state['current_member_id'] !== $memberId
            || (string)$state['card_status'] !== 'enabled'
            || (int)$state['origin_order_id'] !== (int)$order['id'])) {
            throw self::failure('该旧卡的当前状态已变化，请重新选择。', 'imported_card_refund_state_changed');
        }
        $expected = (string)($payload['snapshotFingerprint'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/D', $expected)
            || !hash_equals($expected, $this->snapshotFingerprint($snapshot))) {
            throw CashierV3CommandException::versionConflict('该旧卡权益或欠款已变化，请重新选择后确认退款。');
        }
        if (count($snapshot['holders']) !== 1) {
            throw self::failure('该旧卡来源单存在多张有效卡，请联系管理员核对。', 'imported_card_duplicate_holder');
        }
        $debts = $snapshot['debts'];
        if (count($debts) > 1) throw self::failure('该旧卡关联多笔欠款，退款未提交。', 'imported_card_refund_duplicate_debt');
        $debt = (array)($debts[0] ?? []);
        if ($debt && ((int)$debt['uid'] !== $memberId || (int)$debt['store_id'] !== (int)$order['store_id'])) {
            throw self::failure('旧卡欠款关联异常，退款未提交。', 'imported_card_refund_debt_mismatch');
        }
        $cancelledDebt = 0;
        if ($debt && (int)$debt['status'] === 0) {
            $cancelledDebt = CashierV3DebtCancellationServices::cents($debt['total_debt'])
                - CashierV3DebtCancellationServices::cents($debt['repaid_debt']);
            if ($cancelledDebt < 0) throw self::failure('旧卡欠款余额异常，退款未提交。', 'imported_card_refund_debt_negative');
        }
        $now = time();
        $businessDate = (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d');
        $operationId = 'OLO-' . strtoupper(substr(hash('sha256', $scope->tenantId() . '|' . $key), 0, 40));
        // 单号日期必须与业务日一致，不能受 PHP 进程默认时区影响。
        $operationNo = 'TK' . str_replace('-', '', substr($businessDate, 2))
            . strtoupper(substr(hash('sha256', $operationId), 0, 12));
        $dimensions = $this->dimensions($operator, $scope, $memberId);
        $event = $recorder->recordInTx($execution, (array)($command['event_contract'] ?? []), [
            'event_type' => 'imported_card.refunded', 'aggregate_type' => 'imported_card',
            'aggregate_id' => (string)$holderId, 'aggregate_version' => 1, 'event_version' => 1,
            'source_type' => 'refund-imported-card', 'source_id' => $operationId,
            'member_id' => $memberId, 'business_date' => $businessDate,
            'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now,
            'aggregate_name_snapshot' => (string)$holder['card_name'],
            'store_name_snapshot' => $dimensions['storeName'],
            'payload' => ['holderId' => $holderId, 'originOrderId' => (int)$order['id'],
                'refundAmountCents' => $amount, 'cancelledDebtCents' => $cancelledDebt],
        ]);
        $this->cancelLinkedDebt($debt, $cancelledDebt, $reason, $key, $now, $businessDate,
            $operator, $scope, $dimensions, $recorder, $execution, (array)($command['event_contract'] ?? []));
        // Disabling the holder leaves historical writeoff/service facts intact while stopping
        // all unconsumed rights. The origin order is marked refunded only after debt locks pass.
        if ((int)Db::name('user_card_holder')->where('id', $holderId)->where('is_del', 0)
            ->update(['is_del' => 1]) !== 1
            || (int)Db::name('store_order')->where('id', (int)$order['id'])->where('terminal_action', 0)
                ->update(['terminal_action' => StoreOrderTerminalOperation::ACTION_REFUND,
                    'terminal_action_time' => $now, 'refund_status' => 2]) !== 1) {
            throw self::failure('旧卡状态已变化，退款未提交。', 'imported_card_refund_race');
        }
        if ($state) {
            Db::name('cashier_v3_card_state')->where('id', (int)$state['id'])
                ->update(['card_status' => 'disabled', 'status_reason_snapshot' => '旧卡退款撤销剩余权益',
                    'current_version' => Db::raw('current_version+1'), 'last_operation_id' => $operationId,
                    'updated_at' => $now]);
        }
        $rules = Db::name('cashier_v3_card_rule_state')->where('tenant_id', $scope->tenantId())
            ->where('card_holder_id', $holderId)->where('status', 'active')->column('id');
        if ($rules) {
            Db::name('cashier_v3_card_rule_state')->whereIn('id', $rules)
                ->update(['status' => 'revoked', 'state_version' => Db::raw('state_version+1'), 'update_time' => $now]);
            Db::name('cashier_v3_card_rule_component')->whereIn('rule_state_id', $rules)->where('status', 'active')
                ->update(['status' => 'revoked', 'state_version' => Db::raw('state_version+1'), 'update_time' => $now]);
        }
        $operation = [
            'operation_id' => $operationId, 'operation_no' => $operationNo, 'tenant_id' => $scope->tenantId(),
            'store_id' => $operator->storeId(), 'member_id' => $memberId, 'operator_id' => $operator->operatorId(),
            'source_type' => 'imported_card', 'source_order_id' => (string)$holderId,
            'source_order_no_snapshot' => (string)$order['order_id'], 'operation_type' => 'refund',
            'command_idempotency_key' => $key, 'immutable_fingerprint' => $fingerprint,
            'reason_snapshot' => $reason, 'request_json' => json_encode([
                'holderId' => $holderId, 'originOrderId' => (int)$order['id'],
                'originStoreId' => (int)$order['store_id'], 'memberId' => $memberId,
                'cardName' => (string)$holder['card_name'], 'refundAmountCents' => $amount,
                'cancelledDebtCents' => $cancelledDebt,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'business_event_no' => (string)$event['event_no'], 'amount_cents' => -$amount,
            'cash_refund_cents' => $amount, 'reversed_cash_cents' => $amount,
            'status' => 'succeeded', 'version' => 1, 'business_date' => $businessDate,
            'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ];
        if (Db::name(CashierV3OrderLifecycleServices::OPERATION_TABLE)->insert($operation) !== 1) {
            throw self::failure('退款记录保存失败，操作已回滚。', 'imported_card_refund_operation_insert');
        }
        $this->writeRefundFact($operation, $amount, $key, $event, $dimensions, $operator, $scope, $now);
        return $this->result($operation, false);
    }

    /**
     * 候选和提交共享同一权威快照粒度。退款锁定权益明细及欠款后计算指纹，
     * 防止选择卡之后发生核销、补交或转赠却仍按旧内容确认；历史明细只读取。
     */
    private function loadSnapshot(int $holderId, string $tenantId, bool $lock): array
    {
        $read = static function ($query) use ($lock): array {
            if ($lock) $query->lock(true);
            return (array)$query->find();
        };
        $rows = static function ($query) use ($lock): array {
            if ($lock) $query->lock(true);
            return $query->order('id asc')->select()->toArray();
        };
        $holder = $read(Db::name('user_card_holder')->where('id', $holderId));
        $orderId = (int)($holder['oid'] ?? 0);
        return ['holder' => $holder,
            'order' => $read(Db::name('store_order')->where('id', $orderId)),
            'state' => $read(Db::name('cashier_v3_card_state')->where('tenant_id', $tenantId)->where('card_holder_id', $holderId)),
            'holders' => $rows(Db::name('user_card_holder')->where('oid', $orderId)->where('is_del', 0)->field('id,uid')),
            'details' => $rows(Db::name('store_order_cart_info')->where('oid', $orderId)
                ->field('id,uid,oid,write_times,write_surplus_times,is_writeoff,surplus_num,write_start,write_end')),
            'rules' => $rows(Db::name('cashier_v3_card_rule_state')->where('tenant_id', $tenantId)->where('card_holder_id', $holderId)),
            'components' => $rows(Db::name('cashier_v3_card_rule_component')->where('tenant_id', $tenantId)->where('card_holder_id', $holderId)),
            'debts' => $rows(Db::name('store_debt')->where('order_id', $orderId)),
        ];
    }

    /** The opaque token contains no personal data and never replaces transaction row locks. */
    private function snapshotFingerprint(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** Only a migration carrier, never a normal purchase order, can enter this command. */
    private function isOpenImportedCard(array $order): bool
    {
        return $order && (int)($order['type'] ?? 0) === 11 && (int)($order['product_type'] ?? 0) === 5
            && (string)($order['pay_type'] ?? '') === 'migration'
            && (string)($order['channel_type'] ?? '') === 'migration'
            && (int)($order['paid'] ?? 0) === 1 && (int)($order['is_del'] ?? 0) === 0
            && (int)($order['is_system_del'] ?? 1) === 0 && (int)($order['is_user_del'] ?? 1) === 0
            && (int)($order['card_upgrade_use_oid'] ?? -1) === 0
            && (int)($order['refund_status'] ?? -1) === 0
            && (int)($order['terminal_action'] ?? -1) === 0;
    }

    /** 旧卡实际退款只接受正整数元；统一资金事实仍以分保存，不四舍五入小数输入。 */
    private function refundCents($value): int
    {
        if (!is_int($value) && !is_string($value)) {
            throw self::failure('实际退款金额必须是整数元。', 'imported_card_refund_money_type');
        }
        $raw = trim((string)$value);
        if (!preg_match('/^[1-9][0-9]{0,9}$/D', $raw)) {
            throw self::failure('实际退款金额必须是大于 0 的整数元。', 'imported_card_refund_money');
        }
        return (int)$raw * 100;
    }

    /** 不允许布尔、数组或带小数的 ID 经强制转换误命中另一张卡。 */
    private function positiveId($value): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]{0,17}$/D', (string)$value)) {
            throw self::failure('会员或旧卡标识无效，请重新选择。', 'imported_card_refund_id_type');
        }
        return (int)$value;
    }

    /** Refund receipt is immutable; an idempotent replay never inserts a second negative fact. */
    private function result(array $operation, bool $replayed): array
    {
        return ['data' => ['importedCardRefund' => [
            'operationId' => (string)$operation['operation_id'],
            'operationNo' => (string)$operation['operation_no'],
            'refundAmountCents' => (int)$operation['cash_refund_cents'],
            'replayed' => $replayed,
        ]], 'business_no' => (string)$operation['operation_no'],
            'touched' => [], 'message' => '旧卡退款已登记，剩余权益已作废。'];
    }

    /** Cancel only the unpaid portion attached to the original imported order. */
    private function cancelLinkedDebt(array $debt, int $remaining, string $reason, string $commandKey,
        int $now, string $date, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope,
        array $dimensions, CashierV3BusinessEventRecorder $recorder, CashierV3BusinessEventExecution $execution,
        array $contract): void
    {
        if (!$debt || $remaining === 0) return;
        $debtId = (int)$debt['id'];
        $latest = (array)Db::name(CashierV3DebtCancellationServices::TABLE)
            ->where('debt_id', $debtId)->order('id desc')->lock(true)->find();
        if ($latest && !Db::name(CashierV3DebtRestorationServices::TABLE)
            ->where('cancellation_id', (string)$latest['cancellation_id'])->lock(true)->find()) {
            throw self::failure('该旧卡欠款的取消状态异常，退款未提交。', 'imported_card_refund_debt_cancel_state');
        }
        $cancellationId = 'DC-' . strtoupper(substr(hash('sha256', $scope->tenantId() . '|' . $commandKey . '|debt'), 0, 40));
        $version = max(1, (int)Db::name('cashier_v3_business_event')
            ->where('tenant_id', $scope->tenantId())->where('aggregate_type', 'debt')
            ->where('aggregate_id', (string)$debtId)->max('aggregate_version') + 1);
        $originStoreName = (string)Db::name('system_store')->where('id', (int)$debt['store_id'])->value('name');
        $event = $recorder->recordInTx($execution, $contract, [
            'event_type' => 'debt.cancelled', 'aggregate_type' => 'debt',
            'aggregate_id' => (string)$debtId, 'aggregate_version' => $version, 'event_version' => 1,
            'source_type' => 'refund-imported-card', 'source_id' => $cancellationId,
            'member_id' => (int)$debt['uid'], 'business_date' => $date,
            'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now,
            'aggregate_name_snapshot' => (string)$debt['debt_no'],
            'store_name_snapshot' => $originStoreName,
            'payload' => ['debtId' => $debtId, 'cancelledDebtCents' => $remaining,
                'reason' => '关联旧卡退款：' . $reason, 'cancellationId' => $cancellationId],
        ]);
        $row = [
            'cancellation_id' => $cancellationId, 'debt_id' => $debtId,
            'tenant_id' => $scope->tenantId(),
            // 欠款事实保留原门店归属；退款业绩另按实际办理门店记账。
            'store_id' => (int)$debt['store_id'], 'member_id' => (int)$debt['uid'],
            'debt_no_snapshot' => (string)$debt['debt_no'],
            'source_order_no_snapshot' => (string)$debt['order_sn'],
            'original_debt_cents' => CashierV3DebtCancellationServices::cents($debt['total_debt']),
            'repaid_debt_cents' => CashierV3DebtCancellationServices::cents($debt['repaid_debt']),
            'cancelled_debt_cents' => $remaining,
            'reason_snapshot' => mb_substr('关联旧卡退款：' . $reason, 0, 255),
            'operator_id' => $operator->operatorId(),
            'operator_name_snapshot' => $dimensions['operatorName'],
            'store_name_snapshot' => $originStoreName,
            'command_idempotency_key' => $commandKey, 'business_event_no' => (string)$event['event_no'],
            'business_date' => $date, 'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now,
        ];
        if (Db::name(CashierV3DebtCancellationServices::TABLE)->insert($row) !== 1
            || (int)Db::name('store_debt')->where('id', $debtId)->where('status', 0)
                ->update(['status' => CashierV3DebtCancellationServices::CANCELLED_STATUS,
                    'update_time' => $now]) !== 1) {
            throw self::failure('旧卡欠款已变化，退款未提交。', 'imported_card_refund_debt_race');
        }
    }

    /** A negative cash fact is born on refund completion; migration created no positive cash fact. */
    private function writeRefundFact(array $operation, int $amount, string $commandKey, array $event,
        array $dimensions, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope, int $now): void
    {
        $operationId = (string)$operation['operation_id'];
        $fact = [
            'fact_id' => 'CPF-' . strtoupper(substr(hash('sha256', $scope->tenantId() . '|' . $operationId), 0, 40)),
            'business_event_no' => (string)$event['event_no'],
            'fact_type' => 'payment_refunded', 'fact_direction' => 'adjustment',
            'natural_key' => 'imported_card_refund:' . $operationId,
            'command_idempotency_key' => $commandKey, 'immutable_fingerprint' => '',
            'fact_version' => 1, 'reversal_of' => '', 'status' => 'effective',
            'tenant_id' => $scope->tenantId(), 'tenant_name_snapshot' => '',
            'organization_id' => (string)$operator->organizationId(),
            'organization_name_snapshot' => $dimensions['organizationName'],
            'organization_path_snapshot' => $dimensions['organizationPath'],
            'store_id' => $operator->storeId(), 'store_name_snapshot' => $dimensions['storeName'],
            'member_id' => (int)$operation['member_id'],
            'member_name_snapshot' => $dimensions['memberName'],
            'operator_id' => $operator->operatorId(),
            'operator_name_snapshot' => $dimensions['operatorName'],
            'business_date' => (string)$operation['business_date'],
            'business_timezone' => 'Asia/Shanghai',
            'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now,
            'checkout_request_id' => $operationId, 'order_id' => $operationId,
            'order_no_snapshot' => (string)$operation['operation_no'],
            'source_document_type' => 'imported_card_refund',
            'source_line_id' => (string)$operation['source_order_id'],
            // No original payment-method evidence exists in a migrated rights carrier.
            'payment_method' => 'manual_refund', 'payment_authority_key' => 'manual_refund:' . $operationId,
            'collection_reference' => (string)$operation['source_order_no_snapshot'],
            'amount_cents' => -$amount,
        ];
        $fact['immutable_fingerprint'] = hash('sha256', json_encode($fact, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if (Db::name('cashier_v3_payment_fact')->insert($fact) !== 1) {
            throw self::failure('退款业绩事实保存失败，操作已回滚。', 'imported_card_refund_fact_insert');
        }
    }

    /** Occurrence-time dimensions belong to the processing store, not the historic card store. */
    private function dimensions(CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope, int $memberId): array
    {
        $storeName = trim((string)Db::name('system_store')->where('id', $operator->storeId())->value('name'));
        $member = (array)Db::name('user')->where('uid', $memberId)->field('real_name,nickname,phone')->find();
        $memberName = trim((string)($member['real_name'] ?? ''))
            ?: trim((string)($member['nickname'] ?? '')) ?: trim((string)($member['phone'] ?? ''));
        $profile = $scope->operatorProfile();
        $operatorName = '';
        foreach (['staff_name', 'real_name', 'name', 'account'] as $field) {
            $operatorName = trim((string)($profile[$field] ?? ''));
            if ($operatorName !== '') break;
        }
        $organization = (array)Db::name('organization')->where('id', (int)$operator->organizationId())
            ->where('is_del', 0)->field('id,pid,name')->find();
        if ($storeName === '' || $memberName === '' || $operatorName === '' || !$organization) {
            throw self::failure('退款维度资料不完整，操作未提交。', 'imported_card_refund_dimension_missing');
        }
        $ids = [];
        $seen = [];
        while ($organization) {
            $id = (int)$organization['id'];
            if ($id <= 0 || isset($seen[$id])) throw self::failure('组织归属异常，退款未提交。', 'imported_card_refund_org_path');
            $seen[$id] = true;
            $ids[] = $id;
            $pid = (int)($organization['pid'] ?? 0);
            if ($pid <= 0) break;
            $organization = (array)Db::name('organization')->where('id', $pid)->where('is_del', 0)
                ->field('id,pid,name')->find();
            if (!$organization) throw self::failure('组织归属异常，退款未提交。', 'imported_card_refund_org_path');
        }
        $current = (array)Db::name('organization')->where('id', (int)$operator->organizationId())->field('name')->find();
        return ['storeName' => $storeName, 'memberName' => $memberName,
            'operatorName' => $operatorName, 'organizationName' => (string)$current['name'],
            'organizationPath' => '/' . implode('/', array_reverse($ids)) . '/'];
    }

    /** Refund permission applies independently of the member-selector permission. */
    private function assertRefundAccess(CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): void
    {
        if ($operator->tenantId() !== $scope->tenantId() || $operator->storeId() !== $scope->forcedStoreId()
            || !$scope->allowsStore($operator->storeId()) || $scope->isReadOnlySession()
            || !$scope->hasFeature('cashier.v3.order.refund')) {
            throw self::failure('当前账号无旧卡退款权限。', 'imported_card_refund_permission');
        }
    }

    private static function failure(string $message, string $reason): CashierV3CommandException
    {
        return new CashierV3CommandException(CashierV3ResultCode::INVALID_COMMAND_CONTEXT, $message,
            CashierV3ResultCode::STATUS_FAILED, ['reason' => $reason]);
    }
}
