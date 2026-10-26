<?php
declare(strict_types=1);
namespace app\services\cashier\v3\reservation;

use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use think\facade\Db;

/**
 * Maintenance-only correction for an unambiguous single-use shared card.
 * The caller supplies an independently backed-up, fingerprinted plan and owns
 * the transaction. No HTTP route is registered. Other histories fail closed.
 */
final class CashierV3ReservationSharedPoolRepairServices
{
    // Existing readers recognize this historical amount-correction contract.
    // Keep its code while giving this repair its own event, IDs and rule version.
    public const AMOUNT_CONTRACT = 'SHARED-CARD-PURCHASE-REPAIR-R32';

    /** Lock and fingerprint every authority/fact relevant to this exact correction. */
    public function planInTx(int $holderId): array
    {
        CashierV3TransactionGuard::assertInTransaction('reservationSharedRepair.plan');
        $holder = Db::name('user_card_holder')->where('id', $holderId)->lock(true)->find();
        if (!$holder) throw new \LogicException('repair_holder_missing');
        $state = Db::name('cashier_v3_card_rule_state')->where('card_holder_id', $holderId)->lock(true)->find();
        $tenant = (string)($state['tenant_id'] ?? '');
        $order = Db::name('store_order')->where('id', $holder['oid'])->lock(true)->find();
        $writeoffs = Db::name('cashier_v3_entitlement_writeoff_fact')->where('holder_id', $holderId)
            ->where('tenant_id', $tenant)->order('id asc')->lock(true)->select()->toArray();
        if (!$state || !$order || $state['rule_type'] !== 'choice_count'
            || (int)$state['shared_total_times'] !== 1 || (int)$state['shared_remaining_times'] !== 1
            || $state['status'] !== 'active' || (int)$holder['write_surplus_times'] !== 0
            || (int)$holder['is_del'] !== 0 || count($writeoffs) !== 1) {
            throw new \LogicException('repair_history_requires_individual_reconciliation');
        }
        $wf = $writeoffs[0];
        if ($wf['source_document_type'] !== 'reservation' || $wf['status'] !== 'effective'
            || (int)$wf['quantity'] !== 1 || (int)$wf['member_id'] !== (int)$holder['uid']
            || (int)$wf['origin_order_id'] !== (int)$order['id']
            || !preg_match('/^(0|[1-9][0-9]*)\.00$/D', (string)$order['pay_price'])
            || (int)$order['refund_status'] !== 0 || (int)$order['terminal_action'] !== 0
            || bccomp((string)$order['debt_amount'], (string)$order['repaid_debt_amount'], 2) !== 0) {
            throw new \LogicException('repair_source_not_eligible');
        }
        $services = Db::name('cashier_v3_entitlement_service_fact')->where('tenant_id', $tenant)
            ->where('checkout_request_id', $wf['checkout_request_id'])->where('source_line_id', $wf['source_line_id'])
            ->lock(true)->select()->toArray();
        if (count($services) !== 1 || $services[0]['service_status'] !== 'completed') throw new \LogicException('repair_service_invalid');
        foreach ([['cashier_v3_entitlement_reversal_fact', 'holder_id'], ['cashier_v3_refund_adjustment', 'card_holder_id'],
            ['cashier_v3_card_operation', 'source_card_holder_id']] as [$table, $field]) {
            if (Db::name($table)->where('tenant_id', $tenant)->where($field, $holderId)->lock(true)->find()) {
                throw new \LogicException('repair_followup_card_operation_present');
            }
        }
        if (Db::name('cashier_v3_service_record_void_operation')->where('tenant_id', $tenant)
            ->where('service_fact_id', $services[0]['id'])->lock(true)->find()) throw new \LogicException('repair_service_voided');
        $details = Db::name('store_order_cart_info')->where('oid', $order['id'])->where('cart_type', 2)
            ->order('id asc')->lock(true)->select()->toArray();
        $usedDetail = array_values(array_filter($details, static function (array $d) use ($wf): bool { return (int)$d['id'] === (int)$wf['source_detail_id']; }));
        if (count($usedDetail) !== 1 || (int)$usedDetail[0]['write_surplus_times'] !== 0) throw new \LogicException('repair_detail_invalid');
        $performance = Db::name('cashier_v3_performance_fact')->where('tenant_id', $tenant)
            ->where('checkout_request_id', $wf['checkout_request_id'])->where('source_line_id', $wf['source_line_id'])
            ->order('id asc')->lock(true)->select()->toArray();
        $consumption = array_values(array_filter($performance, static function (array $p): bool {
            return $p['performance_type'] === 'consumption_performance_recorded' && $p['status'] === 'effective';
        }));
        if (count($consumption) !== 1 || $consumption[0]['fact_direction'] !== 'forward'
            || (int)$consumption[0]['amount_cents'] !== (int)$wf['actual_entitlement_amount_cents']
            || $consumption[0]['rule_code_snapshot'] !== 'reservation_consumption_actual_entitlement_amount') {
            throw new \LogicException('repair_consumption_policy_changed');
        }
        $adjustments = Db::name('cashier_v3_service_record_adjustment_operation')->where('tenant_id', $tenant)
            ->where('service_fact_id', $services[0]['id'])->order('id asc')->lock(true)->select()->toArray();
        // 本清单只纠正消耗金额。无人调整且存在非零劳动业绩的其他历史，
        // 需要单独判定劳动计价策略，不能被本次单卡纠正自动覆盖或遗漏。
        $laborNet = 0;
        foreach ($performance as $fact) {
            if ($fact['status'] === 'effective' && $fact['performance_type'] === 'labor_performance_allocated') $laborNet += (int)$fact['amount_cents'];
        }
        if (!$adjustments && $laborNet !== 0) throw new \LogicException('repair_labor_requires_individual_reconciliation');
        $targetCents = (int)bcmul((string)$order['pay_price'], '100', 0);
        if ($targetCents <= (int)$wf['actual_entitlement_amount_cents'] || $targetCents % 100 !== 0) throw new \LogicException('repair_amount_not_understated');
        $before = compact('holder', 'state', 'order', 'writeoffs', 'services', 'details', 'performance', 'adjustments');
        return ['holder_id' => $holderId, 'tenant_id' => $tenant, 'target_cents' => $targetCents,
            'source_consumption' => $consumption[0], 'before' => $before,
            'fingerprint' => hash('sha256', json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))];
    }

    /** Append a reversal and corrected forward fact, preserving all later personnel facts. */
    public function applyInTx(int $holderId, string $expectedFingerprint, string $repairKey, int $now): array
    {
        $plan = $this->planInTx($holderId);
        if (!hash_equals($expectedFingerprint, $plan['fingerprint'])) throw new \LogicException('repair_plan_changed');
        $source = $plan['source_consumption'];
        $before = $plan['before'];
        $store = (int)$source['store_id'];
        $org = (string)Db::name('organization_store')->where('store_id', $store)->value('org_id');
        $operator = new CashierV3OperatorScope($store, (int)$source['operator_id'], $org, $plan['tenant_id']);
        $scope = new CashierV3DataScopeContext($operator->operatorId(), 0, $store, $plan['tenant_id'], $org,
            null, CashierV3DataScopeContext::MODE_ALL, [], true, 'authorized-maintenance', 'r24', [],
            ['id' => $operator->operatorId(), 'name' => '产品经理授权数据纠正']);
        $recorder = new CashierV3BusinessEventRecorder();
        $eventType = 'entitlement.shared_pool_corrected';
        $event = $recorder->recordInTx($recorder->newExecution('repair-reservation-shared-pool', $repairKey, $operator, $scope, 'r24-maintenance'), [
            'required_event_types' => [$eventType], 'allowed_event_types' => [$eventType],
            'aggregate_type' => 'card_holder', 'source_type' => 'reservation_shared_repair',
        ], [
            'event_type' => $eventType, 'aggregate_type' => 'card_holder', 'aggregate_id' => (string)$holderId,
            'aggregate_version' => (int)$before['state']['state_version'] + 1,
            'member_id' => (int)$before['holder']['uid'], 'source_type' => 'reservation_shared_repair', 'source_id' => $repairKey,
            'business_date' => (string)$source['business_date'], 'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now,
            'payload' => ['beforeFingerprint' => $expectedFingerprint, 'writeoffId' => $before['writeoffs'][0]['writeoff_id'],
                'oldAmountCents' => (int)$source['amount_cents'], 'correctedAmountCents' => $plan['target_cents'],
                'oldRemainingTimes' => 1, 'newRemainingTimes' => 0, 'personnelAdjustmentsKept' => count($before['adjustments'])],
        ]);
        foreach (['reversal', 'forward'] as $direction) {
            $copy = $source; unset($copy['id']);
            $identity = $repairKey . ':' . $direction;
            $copy['fact_id'] = 'R24-' . substr(hash('sha256', $identity), 0, 40);
            $copy['natural_key'] = 'reservation_shared_repair:' . hash('sha256', $identity);
            $copy['business_event_no'] = $event['event_no']; $copy['command_idempotency_key'] = $repairKey;
            $copy['fact_direction'] = $direction; $copy['fact_version'] = 1;
            $copy['reversal_of'] = $direction === 'reversal' ? $source['fact_id'] : '';
            $copy['amount_cents'] = $direction === 'reversal' ? -(int)$source['amount_cents'] : $plan['target_cents'];
            $copy['allocation_base_amount_cents'] = $copy['amount_cents'];
            $copy['rule_code_snapshot'] = self::AMOUNT_CONTRACT;
            $copy['rule_name_snapshot'] = '预约共享次数卡历史金额纠正'; $copy['rule_version_snapshot'] = 'r24-20261021';
            $copy['occurred_at'] = $now; $copy['settled_at'] = $now; $copy['recorded_at'] = $now;
            $copy['immutable_fingerprint'] = hash('sha256', json_encode($copy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if ((int)Db::name('cashier_v3_performance_fact')->insert($copy) !== 1) throw new \LogicException('repair_fact_insert_failed');
        }
        $changed = Db::name('cashier_v3_card_rule_state')->where('id', $before['state']['id'])
            ->where('state_version', $before['state']['state_version'])->where('shared_remaining_times', 1)
            ->update(['shared_remaining_times' => 0, 'status' => 'exhausted',
                'state_version' => (int)$before['state']['state_version'] + 1, 'update_time' => $now]);
        if ((int)$changed !== 1) throw new \LogicException('repair_pool_version_conflict');
        $net = (int)Db::name('cashier_v3_performance_fact')->where('tenant_id', $plan['tenant_id'])
            ->where('checkout_request_id', $source['checkout_request_id'])->where('source_line_id', $source['source_line_id'])
            ->where('performance_type', 'consumption_performance_recorded')->where('status', 'effective')->sum('amount_cents');
        if ($net !== $plan['target_cents']) throw new \LogicException('repair_net_amount_mismatch');
        return ['holderId' => $holderId, 'remainingTimes' => 0, 'consumptionNetCents' => $net,
            'eventNo' => $event['event_no'], 'deltaCents' => $net - (int)$source['amount_cents'],
            'beforeFingerprint' => $expectedFingerprint, 'keptPersonnelAdjustments' => count($before['adjustments'])];
    }
}
