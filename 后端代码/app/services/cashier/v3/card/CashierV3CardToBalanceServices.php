<?php
declare(strict_types=1);

namespace app\services\cashier\v3\card;

use app\services\cashier\v3\CashierV3BusinessDocumentNumberServices;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\CashierV3CrossStoreEntitlementPolicy;
use app\services\cashier\v3\checkout\provider\CashierV3MemberBalanceProvider;
use app\services\cashier\v3\order\CashierV3UnifiedRefundServices;
use app\services\cashier\v3\service\CashierV3ServiceOrderState;
use app\services\user\UserBalanceAtomicServices;
use think\facade\Db;

/** Whole-card conversion is an asset movement, never a sale, payment or refund. */
final class CashierV3CardToBalanceServices
{
    public const TYPE = 'card_to_balance';
    public const FEATURE = 'cashier.v3.cashier.card.to_balance';

    /** Gateway owns the transaction and the sole card.operation.recorded event execution. */
    public function submitInTx(array $scope): array
    {
        CashierV3TransactionGuard::assertInTransaction('cardToBalance.submit');
        $operator = $scope['operator_scope'] ?? null;
        $access = $scope['data_scope'] ?? null;
        if (!$operator instanceof CashierV3OperatorScope || !$access instanceof CashierV3DataScopeContext
            || $operator->tenantId() !== $access->tenantId() || $operator->operatorId() !== $access->operatorId()
            || $operator->storeId() !== $access->forcedStoreId() || !$access->allowsStore($operator->storeId())
            || $access->isReadOnlySession() || !$access->hasFeature(self::FEATURE)) {
            throw self::fail('当前账号无权办理卡转余额。', 'card_to_balance_scope_denied');
        }
        $payload = (array)($scope['payload'] ?? []);
        $memberId = $payload['memberId'] ?? 0;
        $holderId = $payload['sourceCardHolderId'] ?? 0;
        $amount = $payload['confirmedAmountCents'] ?? 0;
        $reason = trim((string)($payload['reason'] ?? ''));
        $key = trim((string)($scope['idempotency_key'] ?? ''));
        if (($payload['operationType'] ?? '') !== self::TYPE || !is_int($memberId) || $memberId <= 0
            || !is_int($holderId) || $holderId <= 0 || !is_int($amount) || $amount <= 0 || $amount % 100 !== 0
            || $reason === '' || mb_strlen($reason) > 500 || $key === '') {
            throw self::fail('卡转余额内容无效，请重新确认整卡实际剩余金额。', 'card_to_balance_payload_invalid');
        }
        // Identity is business-only: no browser version or mutable card status in the fingerprint.
        $fingerprint = hash('sha256', self::json([self::TYPE, $memberId, $holderId, $amount, $reason,
            $operator->tenantId(), $operator->storeId(), $operator->operatorId()]));
        // Discovery is not qualification: legacy terminal writers lock origin
        // before repayment orders and holders. Acquire that same origin mutex
        // first, then re-read the holder under lock before using any of its data.
        $holderHint = (array)Db::name('user_card_holder')->where('id', $holderId)->field('id,oid')->find();
        $originId = (int)($holderHint['oid'] ?? 0);
        if ($originId <= 0) {
            // A committed audit still supports replay if the historical holder
            // disappeared; do not requalify a completed business operation.
            $originId = (int)Db::name('cashier_v3_card_operation')->where('tenant_id', $access->tenantId())
                ->where('command_idempotency_key', $key)->value('origin_order_id');
        }
        $order = $originId > 0 ? (array)Db::name('store_order')->where('id', $originId)->lock(true)->find() : [];
        $existing = Db::name('cashier_v3_card_operation')->where('tenant_id', $access->tenantId())
            ->where('command_idempotency_key', $key)->lock(true)->find();
        if ($existing) {
            if (!hash_equals((string)$existing['immutable_fingerprint'], $fingerprint)) {
                throw new CashierV3CommandException(CashierV3ResultCode::IDEMPOTENCY_KEY_CONFLICT,
                    '该请求编号已用于其他卡操作。', CashierV3ResultCode::STATUS_CONFLICT);
            }
            // Re-register the same persisted descriptor with a fresh direct-call
            // execution, so the gateway's mandatory one-event guard still holds.
            $savedEvent = (array)Db::name('cashier_v3_business_event')->where('tenant_id', $access->tenantId())
                ->where('command_idempotency_key', $key)->where('aggregate_id', $existing['operation_id'])
                ->where('event_type', 'card.operation.recorded')->lock(true)->find();
            if (!$savedEvent) throw self::fail('原操作事件缺失，请联系管理员核对。', 'card_to_balance_replay_event_missing');
            $execution = $scope['event_execution'];
            if (!in_array((int)$savedEvent['id'], $execution->eventIds(), true)) {
                $savedEvent['event_id'] = (int)$savedEvent['id'];
                $execution->addEvent($savedEvent);
            }
            return $this->result((array)$existing, true);
        }
        // A legacy refund may have claimed the order before its channel call,
        // including a repayment carrying part of this card's paid value. Guard
        // uses a current read after the origin mutex, before any holder lock.
        if ($order && self::legacyRelatedTerminalOrderIds([$originId], true)) {
            throw self::fail('本卡来源订单或关联补交单已有退款或作废流程，请先处理原流程。', 'card_to_balance_legacy_terminal_pending');
        }
        $holder = (array)Db::name('user_card_holder')->where('id', $holderId)->lock(true)->find();
        if (!$holder || (int)$holder['oid'] !== $originId || (int)$holder['uid'] !== $memberId || (int)$holder['is_del'] !== 0) {
            throw self::fail('本卡当前归属或状态已变化，请重新选择。', 'card_to_balance_holder_invalid');
        }
        $historical = (int)($order['store_id'] ?? -1) === 0
            && (string)($order['mark'] ?? '') === 'migration_entitlement';
        if (!$order || (int)$holder['store_id'] !== (int)$order['store_id']
            || ((int)$holder['store_id'] === 0 && !$historical)
            || ($historical && !in_array($holderId, CashierV3HistoricalEntitlementScopePolicy::allowedHolderIds(
                [$holderId], $access->tenantId(), $operator->storeId()), true))
            || (!$historical && !CashierV3CrossStoreEntitlementPolicy::enabled()
                && (int)$holder['store_id'] !== $operator->storeId())) {
            throw self::fail('本卡不在可操作范围内。', 'card_to_balance_card_scope_denied');
        }
        // All rule pools are locked before the shared monetary reader observes them.
        $state = (array)Db::name('cashier_v3_card_state')->where('tenant_id', $access->tenantId())
            ->where('card_holder_id', $holderId)->lock(true)->find();
        if ($state && ((int)$state['origin_order_id'] !== (int)$order['id']
            || (int)$state['current_member_id'] !== $memberId)) {
            throw self::fail('本卡归属资料不一致。', 'card_to_balance_state_mismatch');
        }
        $rule = (array)Db::name('cashier_v3_card_rule_state')->where('tenant_id', $access->tenantId())
            ->where('card_holder_id', $holderId)->lock(true)->find();
        Db::name('cashier_v3_card_rule_component')->where('tenant_id', $access->tenantId())
            ->where('card_holder_id', $holderId)->order('id asc')->lock(true)->select();
        if (($rule['rule_type'] ?? '') === 'combination') {
            Db::name('cashier_v3_card_rule_group_state')->where('tenant_id', $access->tenantId())
                ->where('card_holder_id', $holderId)->order('id asc')->lock(true)->select();
        }
        $details = Db::name('store_order_cart_info')->where('oid', (int)$order['id'])
            ->order('id asc')->lock(true)->select()->toArray();
        $busy = self::occupiedHolders($access->tenantId(), [$holderId], array_fill_keys(array_column($details, 'id'), $holderId), true);
        if (isset($busy[$holderId])) throw self::fail('本卡存在预约或服务占用，请先处理。', 'card_to_balance_occupied');
        $snapshot = (new CashierV3UnifiedRefundServices())->transferSnapshot($holderId, $memberId, $access->tenantId(), true);
        if (!$snapshot['eligible']) throw self::fail($snapshot['unavailableReason'], 'card_to_balance_unavailable');
        if ((int)$snapshot['amountCents'] !== $amount) {
            throw self::fail('本卡实际剩余金额已变化，请重新确认，不能修改转入金额。', 'card_to_balance_amount_changed');
        }
        $provider = new CashierV3MemberBalanceProvider();
        $before = $provider->lockSnapshotInTx($memberId, $operator, $access);
        $now = time();
        $date = date('Y-m-d', $now);
        $operationId = 'CTB-' . substr(hash('sha256', $access->tenantId() . '|' . $key), 0, 40);
        $operationNo = (new CashierV3BusinessDocumentNumberServices())->cardOperationNoForCommandInTx(
            $access->tenantId(), $key, $date, $now);
        $dimensions = $this->dimensions($operator, $access, $memberId);
        $row = array_merge($dimensions, [
            'operation_id' => $operationId, 'operation_no' => $operationNo, 'operation_type' => self::TYPE,
            'operation_status' => 'completed', 'contract_version' => 'card-to-balance-v1',
            'tenant_id' => $access->tenantId(), 'store_id' => $operator->storeId(),
            'source_card_holder_id' => $holderId, 'source_card_holder_version' => max(1, (int)($state['current_version'] ?? 1)),
            'origin_order_id' => (int)$order['id'], 'origin_member_id' => (int)$order['uid'],
            'member_id_before' => $memberId, 'member_id_after' => $memberId,
            'card_name_snapshot' => mb_substr((string)$holder['card_name'], 0, 128),
            'card_no_snapshot' => mb_substr((string)$holder['card_no'], 0, 128),
            'card_status_before' => (string)($state['card_status'] ?? 'enabled'), 'card_status_after' => 'disabled',
            'write_end_before' => (int)$holder['write_end'], 'write_end_after' => (int)$holder['write_end'],
            'source_remaining_value_cents' => $amount, 'settlement_delta_cents' => 0,
            'reason_snapshot' => $reason, 'command_idempotency_key' => $key,
            'natural_key' => self::TYPE . ':' . $holderId, 'immutable_fingerprint' => $fingerprint,
            'source_snapshot_json' => self::json(['amount' => $snapshot, 'holder' => $holder, 'state' => $state,
                'rule' => $rule, 'details' => $details]), 'target_snapshot_json' => self::json(['memberId' => $memberId, 'bonusCents' => $amount]),
            'result_snapshot_json' => '{}', 'operator_id' => $operator->operatorId(),
            'business_date' => $date, 'business_timezone' => 'Asia/Shanghai',
            'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now, 'add_time' => $now, 'update_time' => $now,
        ]);
        $operationRowId = (int)Db::name('cashier_v3_card_operation')->insertGetId($row);
        $event = $scope['event_recorder']->recordInTx($scope['event_execution'], (array)$scope['event_contract'], [
            'event_type' => 'card.operation.recorded', 'aggregate_type' => 'card_operation',
            'aggregate_id' => $operationId, 'aggregate_version' => 1, 'source_type' => 'submit-card-operation',
            'source_id' => $operationId, 'member_id' => $memberId, 'business_date' => $date,
            'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now,
            'aggregate_name_snapshot' => $row['card_name_snapshot'], 'store_name_snapshot' => $row['store_name_snapshot'],
            'payload' => ['operationId' => $operationId, 'operationType' => self::TYPE, 'subtype' => self::TYPE,
                'operationStatus' => 'completed', 'cardHolderId' => $holderId, 'amountCents' => $amount,
                'memberIdBefore' => $memberId, 'memberIdAfter' => $memberId, 'settlementDeltaCents' => 0],
        ]);
        $changed = (new UserBalanceAtomicServices())->creditBenGive($memberId, '0.00', bcdiv((string)$amount, '100', 2),
            self::TYPE, $operationRowId, '卡项剩余金额转赠金', 'cv3-ctb-' . hash('sha256', $access->tenantId() . '|' . $key));
        $after = $provider->lockSnapshotInTx($memberId, $operator, $access);
        if (empty($changed['after']) || !empty($changed['idempotent'])
            || $after['accountVersion'] !== $before['accountVersion'] + 1
            || $after['principalCents'] !== $before['principalCents']
            || $after['giftCents'] !== $before['giftCents'] + $amount) {
            throw self::fail('余额入账未通过核对，本次转换已取消。', 'card_to_balance_credit_failed');
        }
        $this->closeCard($holder, $state, $rule, $access->tenantId(), $operationId, $now);
        $fact = array_merge(array_intersect_key($row, array_flip(['tenant_id', 'organization_id',
            'organization_path_snapshot', 'organization_name_snapshot', 'store_id', 'store_name_snapshot',
            'operator_id', 'operator_name_snapshot', 'business_date', 'business_timezone', 'occurred_at', 'settled_at', 'recorded_at'])), [
            'fact_id' => $operationId, 'business_event_no' => $event['event_no'], 'fact_type' => 'balance_changed',
            'fact_direction' => 'forward', 'natural_key' => self::TYPE . ':' . $operationId,
            'command_idempotency_key' => $key, 'fact_version' => 1, 'status' => 'effective',
            'member_id' => $memberId, 'member_name_snapshot' => $row['member_name_after_snapshot'],
            'checkout_request_id' => $operationId, 'order_id' => $operationId, 'order_no_snapshot' => $operationNo,
            'source_document_type' => 'card_operation', 'source_line_id' => (string)$operationRowId,
            'balance_change_type' => self::TYPE, 'balance_account_id' => $after['authorityKey'],
            'account_version' => $after['accountVersion'], 'principal_delta_cents' => 0, 'bonus_delta_cents' => $amount,
            'principal_after_cents' => $after['principalCents'], 'bonus_after_cents' => $after['giftCents'],
        ]);
        $fact['immutable_fingerprint'] = hash('sha256', self::json($fact));
        Db::name('cashier_v3_balance_fact')->insert($fact);
        $result = ['cardHolderId' => $holderId, 'currentMemberId' => $memberId, 'cardStatus' => 'disabled',
            'effectiveWriteEnd' => (int)$holder['write_end']];
        $row['result_snapshot_json'] = self::json(['state' => $result, 'amountCents' => $amount]);
        Db::name('cashier_v3_card_operation')->where('id', $operationRowId)
            ->update(['result_snapshot_json' => $row['result_snapshot_json']]);
        return $this->result($row, false);
    }

    /**
     * Return blocked origin IDs for either the source or any legacy repayment
     * terminal. The mutation caller already holds origin orders: current reads
     * then follow repayment/debt relations before holders, matching terminal
     * writers. The list caller uses the same relation once for its whole batch.
     */
    public static function legacyRelatedTerminalOrderIds(array $originIds, bool $lock = false): array
    {
        if ($lock) CashierV3TransactionGuard::assertInTransaction('cardToBalance.relatedLegacyTerminal');
        $originIds = array_values(array_filter(array_unique(array_map('intval', $originIds)), static fn(int $id): bool => $id > 0));
        if (!$originIds) return [];
        sort($originIds, SORT_NUMERIC);
        $orderOrigins = [];
        foreach ($originIds as $originId) $orderOrigins[$originId][$originId] = true;
        $repayments = Db::name('store_order')->where('is_debt_repay', 1)
            ->whereIn('debt_repay_origin_order_id', $originIds)->field('id,debt_repay_origin_order_id')
            ->order('id asc')->lock($lock)->select()->toArray();
        foreach ($repayments as $repayment) {
            $orderOrigins[(int)$repayment['id']][(int)$repayment['debt_repay_origin_order_id']] = true;
        }
        // Older successful repayments can be linked only through the debt
        // ledger. Never infer origin identity from a document number or name.
        // Split the join: optimizer-selected repayment-first scans can acquire
        // locks outside these origins. Both current reads now begin from an
        // indexed equality set: origin -> debt PKs -> those debts' repayments.
        $debts = Db::name('store_debt')->whereIn('order_id', $originIds)->field('id,order_id')
            ->order('id asc')->lock($lock)->select()->toArray();
        if ($debts) {
            $debtOrigins = array_column($debts, 'order_id', 'id');
            $bridges = Db::name('store_debt_repay')->whereIn('debt_id', array_keys($debtOrigins))
                ->where('repay_order_id', '>', 0)->field('id,debt_id,repay_order_id')
                ->order('id asc')->lock($lock)->select()->toArray();
            foreach ($bridges as $bridge) {
                $orderOrigins[(int)$bridge['repay_order_id']][(int)$debtOrigins[(int)$bridge['debt_id']]] = true;
            }
        }
        $blocked = [];
        foreach (CashierV3CardConversionGuard::legacyTerminalOrderIds(array_keys($orderOrigins), $lock) as $terminalOrderId) {
            foreach (array_keys($orderOrigins[$terminalOrderId]) as $originId) $blocked[$originId] = true;
        }
        return array_keys($blocked);
    }

    /** Batch advisory/live occupancy lookup; the locked path shares the real detail rows with service writers. */
    public static function occupiedHolders(string $tenantId, array $holderIds, array $detailHolders, bool $lock = false): array
    {
        if ($lock) CashierV3TransactionGuard::assertInTransaction('cardToBalance.occupancy');
        if (!$holderIds) return [];
        $busy = [];
        foreach (Db::name('cashier_v3_reservation_entitlement_occupation')->where('tenant_id', $tenantId)
            ->whereIn('card_holder_id', $holderIds)->where('status', 'ACTIVE')->lock($lock)->select()->toArray() as $row) {
            $busy[(int)$row['card_holder_id']] = true;
        }
        if (!$detailHolders) return $busy;
        foreach ([['cashier_v3_reservation_line', 'cashier_v3_reservation', 'reservation_id',
            ['PENDING_CONFIRMATION', 'UNSTARTED', 'IN_SERVICE']],
            ['cashier_v3_service_order_line', 'cashier_v3_service_order', 'service_order_id',
                CashierV3ServiceOrderState::activeOrderStatuses()]] as [$lineTable, $headerTable, $foreignKey, $statuses]) {
            $query = Db::name($lineTable)->alias('l')->join($headerTable . ' h', 'h.id=l.' . $foreignKey . ' AND h.tenant_id=l.tenant_id')
                ->where('l.tenant_id', $tenantId)->whereIn('l.entitlement_source_detail_id', array_keys($detailHolders))
                ->whereIn('h.status', $statuses);
            if ($lineTable === 'cashier_v3_service_order_line') $query->where('l.status', 'ACTIVE');
            foreach ($query->field('l.entitlement_source_detail_id')->lock($lock)->select()->toArray() as $row) {
                $busy[(int)$detailHolders[(int)$row['entitlement_source_detail_id']]] = true;
            }
        }
        return $busy;
    }

    /** Terminal holder, detail, rule and legacy carrier gates prevent later enable/restore entry points. */
    private function closeCard(array $holder, array $state, array $rule, string $tenantId, string $operationId, int $now): void
    {
        $holderId = (int)$holder['id'];
        Db::name('user_card_holder')->where('id', $holderId)->update(['is_del' => 1, 'write_surplus_times' => 0]);
        Db::name('store_order_cart_info')->where('oid', (int)$holder['oid'])
            ->where('cart_type', 2)->where('product_type', 6)
            ->update(['write_surplus_times' => 0, 'is_writeoff' => 1]);
        // Holder deletion is the irreversible entry gate. Neither the historical
        // legacy sale nor its V3 sale/payment/refund facts change terminal status.
        $closed = ['card_status' => 'disabled', 'status_reason_snapshot' => '卡转余额已终止，不可恢复启用',
            'last_operation_id' => $operationId, 'updated_at' => $now];
        if ($state) {
            $closed['current_version'] = (int)$state['current_version'] + 1;
            Db::name('cashier_v3_card_state')->where('tenant_id', $tenantId)->where('card_holder_id', $holderId)->update($closed);
        } else {
            Db::name('cashier_v3_card_state')->insert(array_merge($closed, ['tenant_id' => $tenantId,
                'card_holder_id' => $holderId, 'origin_order_id' => (int)$holder['oid'],
                'origin_member_id' => (int)Db::name('store_order')->where('id', (int)$holder['oid'])->value('uid'),
                'current_member_id' => (int)$holder['uid'], 'effective_write_start' => (int)$holder['write_start'],
                'effective_write_end' => (int)$holder['write_end'], 'current_version' => 2, 'created_at' => $now]));
        }
        Db::name('cashier_v3_card_rule_state')->where('tenant_id', $tenantId)->where('card_holder_id', $holderId)
            ->update(['status' => 'revoked', 'shared_remaining_times' => 0,
                'state_version' => Db::raw('state_version+1'), 'update_time' => $now]);
        Db::name('cashier_v3_card_rule_component')->where('tenant_id', $tenantId)->where('card_holder_id', $holderId)
            ->update(['status' => 'revoked', 'remaining_times' => 0,
                'state_version' => Db::raw('state_version+1'), 'update_time' => $now]);
        if (($rule['rule_type'] ?? '') === 'combination') {
            Db::name('cashier_v3_card_rule_group_state')->where('tenant_id', $tenantId)->where('card_holder_id', $holderId)
                ->update(['status' => 'revoked', 'shared_remaining_times' => 0,
                    'state_version' => Db::raw('state_version+1'), 'update_time' => $now]);
        }
    }

    /** Audit dimensions come only from authenticated scope and locked business master records. */
    private function dimensions(CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope, int $memberId): array
    {
        $storeName = (string)Db::name('system_store')->where('id', $operator->storeId())->lock(true)->value('name');
        $member = (array)Db::name('user')->where('uid', $memberId)->lock(true)->find();
        $memberName = trim((string)$member['real_name']) ?: (string)$member['nickname'];
        $operatorName = (string)Db::name('system_store_staff')->where('id', $operator->operatorId())
            ->where('store_id', $operator->storeId())->lock(true)->value('staff_name');
        if ($operatorName === '') {
            $profile = $scope->operatorProfile();
            foreach (['staff_name', 'real_name', 'name', 'account'] as $field) {
                $operatorName = trim((string)($profile[$field] ?? ''));
                if ($operatorName !== '') break;
            }
        }
        $orgId = (int)$operator->organizationId();
        if ($orgId <= 0) $orgId = (int)Db::name('organization_store')->where('store_id', $operator->storeId())->value('org_id');
        $leafId = $orgId;
        $path = []; $name = ''; $seen = [];
        while ($orgId > 0) {
            if (isset($seen[$orgId]) || count($seen) >= 64) throw self::fail('组织资料异常。', 'card_to_balance_organization_invalid');
            $seen[$orgId] = true;
            $org = Db::name('organization')->where('id', $orgId)->where('is_del', 0)->lock(true)->find();
            if (!$org) throw self::fail('组织资料缺失。', 'card_to_balance_organization_missing');
            if ($name === '') $name = (string)$org['name'];
            array_unshift($path, (string)$orgId); $orgId = (int)$org['pid'];
        }
        if ($storeName === '' || $memberName === '' || $operatorName === '' || !$path) {
            throw self::fail('业务审计资料缺失。', 'card_to_balance_audit_missing');
        }
        return ['organization_id' => (string)$leafId, 'organization_path_snapshot' => implode('/', $path),
            'organization_name_snapshot' => mb_substr($name, 0, 128), 'store_name_snapshot' => mb_substr($storeName, 0, 128),
            'member_name_before_snapshot' => mb_substr($memberName, 0, 128),
            'member_name_after_snapshot' => mb_substr($memberName, 0, 128), 'operator_name_snapshot' => mb_substr($operatorName, 0, 128)];
    }

    /** Replays expose the immutable operation result; no event, credit or resource bump is repeated. */
    private function result(array $row, bool $replayed): array
    {
        $saved = json_decode((string)$row['result_snapshot_json'], true) ?: [];
        return ['operation' => ['operationId' => $row['operation_id'], 'operationNo' => $row['operation_no'],
            'operationType' => self::TYPE, 'operationStatus' => 'completed', 'requiresCheckout' => false,
            'settlementDeltaCents' => 0, 'sourceCardHolderId' => (int)$row['source_card_holder_id'],
            'memberIdAfter' => (int)$row['member_id_after'], 'businessDate' => $row['business_date'],
            'amountCents' => (int)$row['source_remaining_value_cents']], 'state' => $saved['state'] ?? [],
            'touched' => ['member_balance', 'member', 'source_card'], 'replayed' => $replayed];
    }

    private static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function fail(string $message, string $reason): CashierV3CommandException
    {
        return new CashierV3CommandException(CashierV3ResultCode::INVALID_COMMAND_CONTEXT, $message,
            CashierV3ResultCode::STATUS_FAILED, ['reason' => $reason]);
    }
}
