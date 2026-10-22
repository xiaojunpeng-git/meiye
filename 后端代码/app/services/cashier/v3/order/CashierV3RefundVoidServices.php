<?php
declare(strict_types=1);

namespace app\services\cashier\v3\order;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\cashier\CashierV3CashierReadinessGuard;
use app\services\cashier\v3\cashier\CashierV3EntitlementResourceVersionProvider;
use app\services\cashier\v3\checkout\provider\CashierV3MemberBalanceProvider;
use app\services\cashier\v3\event\CashierV3BusinessEventExecution;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\user\UserBalanceAtomicServices;
use app\services\query\metric\MetricMoneyFormatter;
use think\facade\Db;

/**
 * Complete cancellation of one mistaken V3 manual refund. The original refund
 * and facts stay auditable; all projections and signed facts reverse together.
 */
final class CashierV3RefundVoidServices
{
    /** @return array<string,mixed> */
    public function executeInTx(string $recordId, string $reason, string $key,
        CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope,
        CashierV3BusinessEventRecorder $recorder, CashierV3BusinessEventExecution $execution,
        array $contract): array
    {
        CashierV3TransactionGuard::assertInTransaction('refundVoid.executeInTx');
        if ($operator->tenantId() !== $scope->tenantId() || $operator->storeId() !== $scope->forcedStoreId()
            || !$scope->allowsStore($operator->storeId()) || $scope->isReadOnlySession()
            || !$scope->hasFeature('cashier.v3.order.void')) {
            throw self::failure('当前账号没有该门店退款作废权限。', 'refund_void_permission_denied');
        }
        if (!preg_match('/^refund:(OLO-[A-F0-9]{40})$/D', $recordId, $matches)) {
            throw self::failure('只能作废新版卡项、单据、目录或余额退款；旧退款缺少恢复依据。', 'refund_void_record_invalid');
        }
        $tenant = $scope->tenantId();
        $refundId = $matches[1];
        $refund = (array)Db::name(CashierV3OrderLifecycleServices::OPERATION_TABLE)
            ->where('tenant_id', $tenant)->where('operation_id', $refundId)
            ->where('store_id', $operator->storeId())->where('operation_type', 'refund')->lock(true)->find();
        $adjustment = (array)Db::name(CashierV3UnifiedRefundServices::TABLE)
            ->where('tenant_id', $tenant)->where('operation_id', $refundId)->lock(true)->find();
        if (!$refund || !$adjustment || !in_array((string)$refund['source_type'], ['card', 'document', 'catalog', 'balance'], true)
            || (int)$refund['member_id'] !== (int)$adjustment['member_id']
            || (string)$refund['status'] !== 'succeeded' || (string)$adjustment['status'] !== 'effective') {
            throw self::failure('退款记录不存在、已作废或归属门店已变化，请刷新列表。', 'refund_void_not_active');
        }
        $this->assertRightsRestorable($refund, $adjustment, $operator, $scope);
        $originalPayment = $this->oneOriginalFact('cashier_v3_payment_fact', $tenant, $refundId,
            'manual_refund_adjustment');
        if ((int)$originalPayment['amount_cents'] !== -(int)$refund['cash_refund_cents']) {
            throw self::failure('退款现金事实与退款金额不一致，不能安全作废。', 'refund_void_payment_mismatch');
        }
        $balanceFact = $this->balanceFact($refund, $adjustment, $operator, $scope);
        $now = time();
        $operation = (new CashierV3OrderCenterVoidServices())->operation($scope, $operator, $key,
            $recordId, 'manual_refund', $refundId, $reason, $now);
        // 退款销售人可能已多次改派；聚合版本必须接续最后一条已保存事件。
        $nextAggregateVersion = (int)Db::name('cashier_v3_business_event')
            ->where('tenant_id', $tenant)->where('aggregate_type', 'manual_refund')
            ->where('aggregate_id', $refundId)->max('aggregate_version') + 1;
        $event = $recorder->recordInTx($execution, $contract, [
            'event_type' => 'manual_refund.voided', 'aggregate_type' => 'manual_refund',
            'aggregate_id' => $refundId, 'aggregate_version' => $nextAggregateVersion, 'event_version' => 1,
            'source_type' => 'void-order-center-refund', 'source_id' => $operation['operation_id'],
            'reversal_of' => (string)$refund['business_event_no'],
            'member_id' => (int)$refund['member_id'], 'business_date' => date('Y-m-d', $now),
            'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now,
            'aggregate_name_snapshot' => (string)$adjustment['target_name_snapshot'],
            'store_name_snapshot' => (string)Db::name('system_store')->where('id', $operator->storeId())->value('name'),
            'payload' => ['recordId' => $recordId, 'reason' => $reason,
                'refundAmountCents' => (int)$refund['cash_refund_cents'],
                // 转入赠金的退款允许当前本金补足；实际扣款拆分保存在余额流水和冲销事实中。
                'balanceCreditVoidPolicy' => (int)$adjustment['balance_credit_cents'] > 0
                    ? 'gift_first_then_principal_whole_yuan' : 'not_applicable'],
        ]);
        $this->restoreRights($refund, $adjustment, $operator, $scope, $now);
        $balanceResult = $this->reverseBalance($refund, $adjustment, $balanceFact, $operation,
            $key, $operator, $scope, $now);
        $this->reverseSignedFact('cashier_v3_payment_fact', $originalPayment, $operation, $event,
            $key, $operator, $now);
        $this->reverseActiveSalaryFacts($refundId, $tenant, $operation, $event, $key, $operator, $now);
        if ($balanceFact !== null && $balanceResult !== null) {
            $this->reverseSignedFact('cashier_v3_balance_fact', $balanceFact, $operation, $event,
                $key, $operator, $now, $balanceResult);
        }
        if ((int)Db::name(CashierV3UnifiedRefundServices::TABLE)->where('id', (int)$adjustment['id'])
            ->where('status', 'effective')->update(['status' => 'voided']) !== 1
            || (int)Db::name(CashierV3OrderLifecycleServices::OPERATION_TABLE)
                ->where('id', (int)$refund['id'])->where('status', 'succeeded')
                ->update(['status' => 'voided', 'updated_at' => $now, 'version' => Db::raw('version+1')]) !== 1
            || (int)Db::name(CashierV3OrderCenterVoidServices::OPERATION_TABLE)
                ->where('id', (int)$operation['id'])->update([
                    'business_event_no' => (string)$event['event_no'], 'status' => 'succeeded', 'updated_at' => $now,
                ]) !== 1) {
            throw self::failure('退款状态并发变化，整笔作废已回滚。', 'refund_void_status_race');
        }
        return ['data' => ['void' => ['operationNo' => $operation['operation_no'], 'replayed' => false]],
            'business_no' => $operation['operation_no'], 'touched' => ['order_center'],
            'message' => '退款已作废，余额、权益与退款销售人业绩已按原记录撤销。'];
    }

    /** Card rights need an exact before/after snapshot; a missing legacy snapshot is not recoverable by guesswork. */
    private function assertRightsRestorable(array $refund, array $adjustment,
        CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): void
    {
        $type = (string)$adjustment['target_type'];
        // Wallet refunds do not mutate sale rights. Their exact debit split is reversed separately.
        if (in_array($type, ['catalog', 'balance'], true)) return;
        if ($type === 'document') {
            $line = (array)Db::name('cashier_v3_sales_order_line')->alias('l')
                ->join('cashier_v3_sales_order so', 'so.order_id=l.order_id AND so.tenant_id=l.tenant_id')
                ->where('l.tenant_id', $scope->tenantId())->where('l.order_line_id', (string)$adjustment['source_line_id'])
                ->where('l.member_id', (int)$refund['member_id'])->lock(true)
                ->field('l.line_status,l.line_direction,so.order_status,so.order_direction')->find();
            if (!$line || (string)$line['line_status'] !== 'settled' || (string)$line['line_direction'] !== 'forward'
                || (string)$line['order_status'] !== 'settled' || (string)$line['order_direction'] !== 'forward') {
                throw self::failure('原销售明细或订单已作废/变更，不能恢复单据退款。', 'refund_void_line_changed');
            }
            $later = Db::name(CashierV3UnifiedRefundServices::TABLE)->where('tenant_id', $scope->tenantId())
                ->where('source_line_id', (string)$adjustment['source_line_id'])->where('status', 'effective')
                ->where('id', '>', (int)$adjustment['id'])->count();
            if ($later > 0) throw self::failure('原销售明细在本次退款后又发生退款，请先处理后续退款。', 'refund_void_line_later_refund');
            return;
        }
        if ($type !== 'card') throw self::failure('退款对象类型无法识别，不能安全作废。', 'refund_void_target_unknown');
        $request = json_decode((string)$refund['request_json'], true);
        $before = $request['_reversal']['rightsBefore'] ?? null;
        $after = $request['_reversal']['rightsAfter'] ?? null;
        if (!is_array($before) || !is_array($after)) {
            throw self::failure('这笔旧卡项退款缺少退款前后的完整权益快照，无法确认原有项目状态，不能安全作废。', 'refund_void_rights_snapshot_missing');
        }
        $holderId = (int)$adjustment['card_holder_id'];
        $origin = (array)Db::name('store_order')->where('id', (int)$adjustment['source_order_id'])
            ->where('uid', (int)$refund['member_id'])->lock(true)
            ->field('id,paid,is_del,is_system_del,is_user_del,refund_status,terminal_action')->find();
        if (!$origin || (int)$origin['paid'] !== 1 || (int)$origin['is_del'] !== 0
            || (int)$origin['is_system_del'] !== 0 || (int)$origin['is_user_del'] !== 0
            || (int)$origin['refund_status'] !== 0 || (int)$origin['terminal_action'] !== 0) {
            throw self::failure('原卡销售单已取消或终止，不能重新开放退款前权益。', 'refund_void_origin_changed');
        }
        $current = (new CashierV3UnifiedRefundServices())->refundReversalRightsSnapshotInTx($scope->tenantId(), $holderId);
        if ((int)($current['holder']['uid'] ?? 0) !== (int)$refund['member_id']) {
            throw self::failure('这张卡的持有人已变化，不能恢复退款前权益。', 'refund_void_holder_changed');
        }
        foreach (['holder' => '卡项剩余次数或有效期', 'state' => '持卡状态',
            'rule' => '卡规则或共享次数', 'components' => '卡内项目次数或状态',
            'carts' => '卡内项目明细'] as $part => $label) {
            if (json_encode($current[$part] ?? null, JSON_UNESCAPED_UNICODE)
                !== json_encode($after[$part] ?? null, JSON_UNESCAPED_UNICODE)) {
                throw self::failure($label . '在退款后又发生变化，不能安全恢复原权益。', 'refund_void_rights_' . $part . '_changed');
            }
        }
        if (array_key_exists('groups', $after)
            && json_encode($current['groups'] ?? null, JSON_UNESCAPED_UNICODE)
                !== json_encode($after['groups'], JSON_UNESCAPED_UNICODE)) {
            throw self::failure('组合卡分组在退款后又发生变化，不能安全恢复。', 'refund_void_rights_groups_changed');
        }
        $later = Db::name(CashierV3UnifiedRefundServices::TABLE)->where('tenant_id', $scope->tenantId())
            ->where('card_holder_id', $holderId)->where('status', 'effective')
            ->where('id', '>', (int)$adjustment['id'])->count();
        if ($later > 0) throw self::failure('这张卡在本次退款后又发生退款，请先处理后续退款。', 'refund_void_card_later_refund');
    }

    /** Restore only columns changed by refund; keep authority versions monotonic. */
    private function restoreRights(array $refund, array $adjustment, CashierV3OperatorScope $operator,
        CashierV3DataScopeContext $scope, int $now): void
    {
        if ((string)$adjustment['target_type'] !== 'card') return;
        $request = json_decode((string)$refund['request_json'], true);
        $before = $request['_reversal']['rightsBefore'];
        $after = $request['_reversal']['rightsAfter'];
        $holderId = (int)$adjustment['card_holder_id'];
        Db::name('user_card_holder')->where('id', $holderId)->update([
            'is_del' => (int)$before['holder']['is_del'],
            'write_surplus_times' => (int)$before['holder']['write_surplus_times'],
            'write_end' => (int)$before['holder']['write_end'],
        ]);
        if ($before['state']) Db::name('cashier_v3_card_state')->where('id', (int)$before['state']['id'])
            ->where('tenant_id', $scope->tenantId())->where('current_version', (int)$after['state']['current_version'])
            ->update(['card_status' => (string)$before['state']['card_status'],
                'status_reason_snapshot' => (string)$before['state']['status_reason_snapshot'],
                'current_version' => Db::raw('current_version+1'), 'updated_at' => $now]);
        if ($before['rule']) Db::name('cashier_v3_card_rule_state')->where('id', (int)$before['rule']['id'])
            ->where('tenant_id', $scope->tenantId())->where('state_version', (int)$after['rule']['state_version'])
            ->update(['status' => (string)$before['rule']['status'],
                'shared_remaining_times' => (int)$before['rule']['shared_remaining_times'],
                'valid_through' => (int)$before['rule']['valid_through'],
                'state_version' => Db::raw('state_version+1'), 'update_time' => $now]);
        foreach ($before['components'] as $index => $component) {
            $afterComponent = $after['components'][$index];
            Db::name('cashier_v3_card_rule_component')->where('id', (int)$component['id'])
                ->where('tenant_id', $scope->tenantId())
                ->where('state_version', (int)$afterComponent['state_version'])
                ->update(['status' => (string)$component['status'],
                    'remaining_times' => (int)$component['remaining_times'],
                    'state_version' => Db::raw('state_version+1'), 'update_time' => $now]);
        }
        foreach ((array)($before['groups'] ?? []) as $index => $group) {
            $afterGroup = $after['groups'][$index] ?? null;
            if (!$afterGroup || (int)$afterGroup['id'] !== (int)$group['id']) {
                throw self::failure('组合卡分组恢复快照不一致。', 'refund_void_group_snapshot_invalid');
            }
            Db::name('cashier_v3_card_rule_group_state')->where('id', (int)$group['id'])
                ->where('tenant_id', $scope->tenantId())
                ->where('state_version', (int)$afterGroup['state_version'])
                ->update(['status' => (string)$group['status'],
                    'shared_remaining_times' => (int)$group['shared_remaining_times'],
                    'selected_kind_count' => (int)$group['selected_kind_count'],
                    'state_version' => Db::raw('state_version+1'), 'update_time' => $now]);
        }
        foreach ($before['carts'] as $cart) Db::name('store_order_cart_info')->where('id', (int)$cart['id'])
            ->update(['cart_info' => (string)$cart['cart_info'],
                'write_surplus_times' => (int)$cart['write_surplus_times'],
                'write_end' => (int)$cart['write_end']]);
        $versions = new CashierV3EntitlementResourceVersionProvider(new CashierV3CashierReadinessGuard());
        $versions->synchronizeProjectionVersion('card_holder', (string)$holderId, $operator, $scope);
        foreach ($before['carts'] as $cart) $versions->synchronizeProjectionVersion(
            'member_benefit_pool', (string)$cart['id'], $operator, $scope);
    }

    /** 原入账事实确定扣回总额；后续收支不阻止作废，以当前锁定的赠金及本金合计为准。 */
    private function balanceFact(array $refund, array $adjustment, CashierV3OperatorScope $operator,
        CashierV3DataScopeContext $scope): ?array
    {
        if ((string)$adjustment['target_type'] === 'balance') {
            $debit = (int)$adjustment['balance_debit_cents'];
            $principal = (int)$adjustment['principal_debit_cents'];
            $bonus = (int)$adjustment['bonus_debit_cents'];
            $rows = Db::name('cashier_v3_balance_fact')->where('tenant_id', $scope->tenantId())
                ->where('order_id', (string)$refund['operation_id'])
                ->where('balance_change_type', 'manual_refund_debit')
                ->where('fact_direction', 'forward')->where('status', 'effective')->lock(true)->select()->toArray();
            if ($debit <= 0 || $principal + $bonus !== $debit || count($rows) !== 1
                || (int)$rows[0]['member_id'] !== (int)$refund['member_id']
                || (int)$rows[0]['principal_delta_cents'] !== -$principal
                || (int)$rows[0]['bonus_delta_cents'] !== -$bonus
                || $this->alreadyReversed('cashier_v3_balance_fact', $scope->tenantId(), (string)$rows[0]['fact_id'])) {
                throw self::failure('余额退款的原始扣减流水缺失或拆分不一致，不能安全作废。', 'refund_void_balance_debit_mismatch');
            }
            return $rows[0];
        }
        $credit = (int)$adjustment['balance_credit_cents'];
        if ($credit === 0) return null;
        $rows = Db::name('cashier_v3_balance_fact')->where('tenant_id', $scope->tenantId())
            ->where('order_id', (string)$refund['operation_id'])->where('balance_change_type', 'manual_refund_credit')
            ->where('fact_direction', 'forward')->where('status', 'effective')->lock(true)->select()->toArray();
        if (count($rows) !== 1 || (int)$rows[0]['member_id'] !== (int)$refund['member_id']
            || (int)$rows[0]['bonus_delta_cents'] !== $credit
            || (int)$rows[0]['principal_delta_cents'] !== 0
            || $this->alreadyReversed('cashier_v3_balance_fact', $scope->tenantId(), (string)$rows[0]['fact_id'])) {
            throw self::failure('退款转入赠金的原始流水缺失或金额不一致，不能安全扣回。', 'refund_void_balance_fact_mismatch');
        }
        $balance = (new CashierV3MemberBalanceProvider())->lockSnapshotInTx((int)$refund['member_id'], $operator, $scope);
        $this->creditVoidDebitSplit($balance, $credit);
        return $rows[0];
    }

    /** 赠金优先，本金补足；只分配整元，不把历史小数拆成新的小数收银扣款。 */
    private function creditVoidDebitSplit(array $balance, int $credit): array
    {
        if ($credit <= 0 || $credit % 100 !== 0) {
            throw self::failure('原退款转入金额不是整数元，请先核对历史退款记录后再作废。', 'refund_void_credit_whole_yuan_required');
        }
        if ((int)$balance['totalCents'] < $credit) {
            throw self::failure('会员当前本金为' . MetricMoneyFormatter::integerYuan((int)$balance['principalCents'])
                . '元、赠金为' . MetricMoneyFormatter::integerYuan((int)$balance['giftCents'])
                . '元，合计' . MetricMoneyFormatter::integerYuan((int)$balance['totalCents'])
                . '元，本次作废需扣回' . MetricMoneyFormatter::integerYuan($credit)
                . '元；请在余额充足后重试。', 'refund_void_balance_insufficient');
        }
        $bonus = min(intdiv((int)$balance['giftCents'], 100) * 100, $credit);
        $principal = $credit - $bonus;
        if ($principal > (int)$balance['principalCents']) {
            throw self::failure('本金和赠金含历史小数，无法按整元扣回；请先核对余额后重试。', 'refund_void_balance_whole_yuan_required');
        }
        return ['principal' => $principal, 'bonus' => $bonus];
    }

    private function reverseBalance(array $refund, array $adjustment, ?array $balanceFact,
        array $operation, string $key, CashierV3OperatorScope $operator,
        CashierV3DataScopeContext $scope, int $now): ?array
    {
        if ($balanceFact === null) return null;
        if ((string)$adjustment['target_type'] === 'balance') {
            $principal = (int)$adjustment['principal_debit_cents'];
            $bonus = (int)$adjustment['bonus_debit_cents'];
            $provider = new CashierV3MemberBalanceProvider();
            $before = $provider->lockSnapshotInTx((int)$refund['member_id'], $operator, $scope);
            try {
                $change = (new UserBalanceAtomicServices())->creditBenGive((int)$refund['member_id'],
                    number_format($principal / 100, 2, '.', ''), number_format($bonus / 100, 2, '.', ''),
                    'manual_refund_debit_void', (int)$operation['id'], '余额退款作废返还',
                    'cv3-balance-refund-void-' . hash('sha256', $scope->tenantId() . '|' . $key));
            } catch (\Throwable $error) {
                throw self::failure('余额退款作废返还失败，整笔作废已回滚。', 'refund_void_balance_restore_failed');
            }
            $after = $provider->lockSnapshotInTx((int)$refund['member_id'], $operator, $scope);
            if (empty($change['money_id']) || !empty($change['idempotent'])
                || (int)$after['accountVersion'] !== (int)$before['accountVersion'] + 1
                || (int)$after['principalCents'] !== (int)$before['principalCents'] + $principal
                || (int)$after['giftCents'] !== (int)$before['giftCents'] + $bonus) {
                throw self::failure('余额退款作废拆分与流水不一致，整笔作废已回滚。', 'refund_void_balance_restore_mismatch');
            }
            return $after;
        }
        $credit = (int)$adjustment['balance_credit_cents'];
        // 以本次扣款前的权威快照核对增量，不能用退款当时的余额覆盖或否定后续收支。
        // 会员行锁贯穿整笔作废事务；赠金优先，本金补足，流水必须反映真实扣款来源。
        $provider = new CashierV3MemberBalanceProvider();
        $before = $provider->lockSnapshotInTx((int)$refund['member_id'], $operator, $scope);
        $debit = $this->creditVoidDebitSplit($before, $credit);
        try {
            $change = (new UserBalanceAtomicServices())->deductBenGive((int)$refund['member_id'],
                number_format($debit['principal'] / 100, 2, '.', ''), number_format($debit['bonus'] / 100, 2, '.', ''),
                'manual_refund_void', (int)$operation['id'], '误录退款作废扣回余额',
                'cv3-manual-refund-void-' . hash('sha256', $scope->tenantId() . '|' . $key));
        } catch (\Throwable $error) {
            throw self::failure('当前余额扣回失败，请刷新并检查可用余额后重试；整笔作废已回滚。', 'refund_void_balance_debit_failed');
        }
        $after = $provider->lockSnapshotInTx((int)$refund['member_id'], $operator, $scope);
        if ((int)$after['accountVersion'] !== (int)$before['accountVersion'] + 1
            || (int)$after['giftCents'] !== (int)$before['giftCents'] - $debit['bonus']
            || (int)$after['principalCents'] !== (int)$before['principalCents'] - $debit['principal']
            || (int)$after['totalCents'] !== (int)$before['totalCents'] - $credit
            || empty($change['money_id']) || !empty($change['idempotent'])) {
            throw self::failure('余额扣回流水与会员余额无法核对，整笔作废已回滚。', 'refund_void_balance_debit_mismatch');
        }
        $after['refundCreditVoidDebit'] = $debit;
        return $after;
    }

    private function oneOriginalFact(string $table, string $tenant, string $refundId, string $factType): array
    {
        $rows = Db::name($table)->where('tenant_id', $tenant)->where('order_id', $refundId)
            ->where('fact_type', $factType)->where('status', 'effective')->lock(true)->select()->toArray();
        if (count($rows) !== 1 || $this->alreadyReversed($table, $tenant, (string)$rows[0]['fact_id'])) {
            throw self::failure('原退款现金事实缺失、重复或已冲销，不能再次作废。', 'refund_void_payment_fact_invalid');
        }
        return $rows[0];
    }

    private function reverseActiveSalaryFacts(string $refundId, string $tenant, array $operation,
        array $event, string $key, CashierV3OperatorScope $operator, int $now): void
    {
        $rows = Db::name('cashier_v3_performance_fact')->where('tenant_id', $tenant)
            ->where('order_id', $refundId)->where('performance_type', 'refund_sales_deduction')
            ->where('fact_direction', 'forward')->where('status', 'effective')->lock(true)->select()->toArray();
        foreach ($rows as $row) {
            if ($this->alreadyReversed('cashier_v3_performance_fact', $tenant, (string)$row['fact_id'])) continue;
            $this->reverseSignedFact('cashier_v3_performance_fact', $row, $operation, $event,
                $key, $operator, $now);
        }
    }

    private function alreadyReversed(string $table, string $tenant, string $factId): bool
    {
        return Db::name($table)->where('tenant_id', $tenant)->where('reversal_of', $factId)
            ->where('fact_direction', 'reversal')->where('status', 'effective')->count() > 0;
    }

    /** 保留原业务维度并冲销总额；转赠金退款允许本金补足时，余额分量按实际扣回来源记录。 */
    private function reverseSignedFact(string $table, array $source, array $operation, array $event,
        string $key, CashierV3OperatorScope $operator, int $now, ?array $balanceAfter = null): void
    {
        if ($this->alreadyReversed($table, (string)$source['tenant_id'], (string)$source['fact_id'])) {
            throw self::failure('退款事实已存在冲销，不能重复作废。', 'refund_void_fact_already_reversed');
        }
        $fact = $source;
        unset($fact['id'], $fact['immutable_fingerprint']);
        $fact['fact_id'] = 'RVD-' . strtoupper(substr(hash('sha256', $table . '|' . $source['fact_id'] . '|' . $operation['operation_id']), 0, 40));
        $fact['natural_key'] = 'manual_refund_void:' . hash('sha256', $table . '|' . $source['fact_id'] . '|' . $operation['operation_id']);
        $fact['fact_direction'] = 'reversal';
        $fact['reversal_of'] = (string)$source['fact_id'];
        $fact['business_event_no'] = (string)$event['event_no'];
        $fact['command_idempotency_key'] = $key;
        $fact['operator_id'] = $operator->operatorId();
        $fact['fact_version'] = 1;
        $fact['business_date'] = date('Y-m-d', $now);
        $fact['occurred_at'] = $now;
        $fact['settled_at'] = $now;
        $fact['recorded_at'] = $now;
        foreach (['amount_cents', 'allocation_base_amount_cents', 'labor_fee_amount_cents',
            'principal_delta_cents', 'bonus_delta_cents'] as $column) {
            if (array_key_exists($column, $fact)) $fact[$column] = -(int)$source[$column];
        }
        if ($balanceAfter !== null) {
            // 本金补足是用户明确允许的扣回方式，不能仍写成全扣赠金而导致事实与钱包分量不符。
            if (isset($balanceAfter['refundCreditVoidDebit'])) {
                $debit = $balanceAfter['refundCreditVoidDebit'];
                $fact['principal_delta_cents'] = -(int)$debit['principal'];
                $fact['bonus_delta_cents'] = -(int)$debit['bonus'];
            }
            $fact['account_version'] = (int)$balanceAfter['accountVersion'];
            $fact['principal_after_cents'] = (int)$balanceAfter['principalCents'];
            $fact['bonus_after_cents'] = (int)$balanceAfter['giftCents'];
        }
        $fact['immutable_fingerprint'] = hash('sha256', json_encode($fact, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ((int)Db::name($table)->insert($fact) !== 1) {
            throw self::failure('退款冲销事实保存失败，整笔作废已回滚。', 'refund_void_fact_insert_failed');
        }
    }

    private static function failure(string $message, string $reason): CashierV3CommandException
    {
        return new CashierV3CommandException(CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
            $message, CashierV3ResultCode::STATUS_FAILED, ['reason' => $reason]);
    }
}
