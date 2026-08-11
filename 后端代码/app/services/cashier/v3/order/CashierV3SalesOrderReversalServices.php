<?php
declare(strict_types=1);

namespace app\services\cashier\v3\order;

use app\model\order\StoreOrderTerminalOperation;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\checkout\provider\CashierV3MemberBalanceProvider;
use app\services\user\UserBalanceAtomicServices;
use think\exception\ValidateException;
use think\facade\Db;

/** Transaction-only restoration of assets created or consumed by a V3 sale. */
final class CashierV3SalesOrderReversalServices
{
    public const DEBT_REVERSAL_TABLE = 'cashier_v3_order_lifecycle_debt_reversal';
    public const BENEFIT_REVERSAL_TABLE = 'cashier_v3_order_lifecycle_benefit_reversal';

    /** @return array<string,mixed> */
    public function prepare(array $source, string $action, array $input, CashierV3DataScopeContext $scope, int $entitlementCreditCents = 0): array
    {
        CashierV3TransactionGuard::assertInTransaction('salesOrderReversal.prepare');
        $balanceFacts = Db::name('cashier_v3_balance_fact')->where('tenant_id', $scope->tenantId())
            ->where('order_id', (string)$source['sourceId'])->where('fact_direction', 'forward')
            ->where('status', 'effective')->lock(true)->order('id', 'asc')->select()->toArray();
        $principalPaid = 0;
        $bonusPaid = 0;
        foreach ($balanceFacts as $fact) {
            $principal = (int)($fact['principal_delta_cents'] ?? 0);
            $bonus = (int)($fact['bonus_delta_cents'] ?? 0);
            if ($principal > 0 || $bonus > 0) throw self::failure('sales_reversal_balance_direction_invalid');
            $principalPaid += abs($principal);
            $bonusPaid += abs($bonus);
        }

        $restorePrincipal = $action === 'void-sales-order' ? $principalPaid : (int)$input['restorePrincipalCents'];
        $restoreBonus = $action === 'void-sales-order' ? $bonusPaid : (int)$input['restoreBonusCents'];
        if ($restorePrincipal > $principalPaid || $restoreBonus > $bonusPaid) {
            throw self::failure('sales_reversal_balance_restore_exceeds_source');
        }

        $debts = Db::name('cashier_v3_debt_authority')->alias('a')->join('store_debt d', 'd.id=a.debt_id')
            ->where('a.tenant_id', $scope->tenantId())->where('a.sales_order_id', (string)$source['sourceId'])
            ->field('a.debt_id,a.debt_no,d.total_debt,d.repaid_debt,d.status,d.update_time')->lock(true)->select()->toArray();
        $cancelledDebtCents = 0;
        foreach ($debts as $debt) {
            $total = $this->moneyToCents($debt['total_debt'] ?? null);
            $repaid = $this->moneyToCents($debt['repaid_debt'] ?? null);
            if ($repaid > 0) throw self::failure('sales_reversal_debt_already_repaid');
            if ((int)$debt['status'] !== 0 || $total <= 0) throw self::failure('sales_reversal_debt_state_invalid');
            $cancelledDebtCents += $total;
        }

        $receipts = Db::name('cashier_v3_card_purchase_receipt')->where('tenant_id', $scope->tenantId())
            ->where('sales_order_id', (string)$source['sourceId'])->where('status', 'completed')
            ->lock(true)->order('id', 'asc')->select()->toArray();
        $expectedCardCount = (int)Db::name('cashier_v3_sales_order_line')->where('tenant_id', $scope->tenantId())
            ->where('order_id', (string)$source['sourceId'])->where('line_direction', 'forward')
            ->where('line_status', 'settled')->where('item_type', 'card')->sum('quantity');
        if (count($receipts) !== $expectedCardCount) throw self::failure('sales_reversal_card_issuance_incomplete');
        $cards = [];
        foreach ($receipts as $receipt) $cards[] = $this->lockUnusedCard((array)$receipt, $source, $scope);

        $paymentFacts = Db::name('cashier_v3_payment_fact')->where('tenant_id', $scope->tenantId())
            ->where('order_id', (string)$source['sourceId'])->where('fact_direction', 'forward')
            ->where('status', 'effective')->lock(true)->order('id', 'asc')->select()->toArray();
        $cashCollected = array_sum(array_map(static function (array $row): int { return (int)($row['amount_cents'] ?? 0); }, $paymentFacts));
        $cashReversal = $action === 'void-sales-order' ? $cashCollected : (int)$input['cashRefundCents'];
        $cashRefund = $action === 'void-sales-order' ? 0 : $cashReversal;
        if ($cashReversal < 0 || $cashReversal > $cashCollected) throw self::failure('sales_reversal_cash_refund_exceeds_source');
        if ($entitlementCreditCents < 0) throw self::failure('sales_reversal_entitlement_credit_invalid');
        $economicReversal = $cashReversal + $restorePrincipal + $restoreBonus + $cancelledDebtCents + $entitlementCreditCents;
        if ($economicReversal <= 0 || $economicReversal > (int)$source['amountCents']) {
            throw self::failure('sales_reversal_economic_amount_invalid');
        }
        if ($action === 'void-sales-order' && $economicReversal !== (int)$source['amountCents']) {
            throw self::failure('sales_void_source_equation_invalid');
        }
        // A coupon is a one-shot sale discount. It becomes reusable only when
        // the order's entire settled economic value is reversed; a partial
        // financial refund must leave the coupon consumed.
        $coupons = $economicReversal === (int)$source['amountCents']
            ? $this->lockConsumedCoupons($source, $scope)
            : [];

        return [
            'balanceFacts' => $balanceFacts, 'principalPaidCents' => $principalPaid, 'bonusPaidCents' => $bonusPaid,
            'restorePrincipalCents' => $restorePrincipal, 'restoreBonusCents' => $restoreBonus,
            'debts' => $debts, 'cancelledDebtCents' => $cancelledDebtCents,
            'cards' => $cards, 'paymentFacts' => $paymentFacts, 'cashCollectedCents' => $cashCollected,
            'cashRefundCents' => $cashRefund, 'cashReversalCents' => $cashReversal,
            'entitlementCreditCents' => $entitlementCreditCents, 'economicReversalCents' => $economicReversal,
            'coupons' => $coupons,
        ];
    }

    /**
     * 退款不是全量作废：它只冲销本次人工确认的资金事实，不能撤回已经
     * 发放或已经使用的卡项、服务、库存、欠款等后续业务。全量回滚仍由
     * prepare() 仅供 void-sales-order 使用。
     *
     * @return array<string,mixed>
     */
    public function prepareFinancialRefund(array $source, array $input, CashierV3DataScopeContext $scope, int $entitlementCreditCents = 0): array
    {
        CashierV3TransactionGuard::assertInTransaction('salesOrderReversal.prepareFinancialRefund');
        $balanceFacts = Db::name('cashier_v3_balance_fact')->where('tenant_id', $scope->tenantId())
            ->where('order_id', (string)$source['sourceId'])->where('fact_direction', 'forward')
            ->where('status', 'effective')->lock(true)->order('id', 'asc')->select()->toArray();
        $principalPaid = 0;
        $bonusPaid = 0;
        foreach ($balanceFacts as $fact) {
            $principal = (int)($fact['principal_delta_cents'] ?? 0);
            $bonus = (int)($fact['bonus_delta_cents'] ?? 0);
            if ($principal > 0 || $bonus > 0) throw self::failure('sales_refund_balance_direction_invalid');
            $principalPaid += abs($principal);
            $bonusPaid += abs($bonus);
        }
        $restorePrincipal = (int)$input['restorePrincipalCents'];
        $restoreBonus = (int)$input['restoreBonusCents'];
        if ($restorePrincipal > $principalPaid || $restoreBonus > $bonusPaid) {
            throw self::failure('sales_refund_balance_restore_exceeds_source');
        }

        $paymentFacts = Db::name('cashier_v3_payment_fact')->where('tenant_id', $scope->tenantId())
            ->where('order_id', (string)$source['sourceId'])->where('fact_direction', 'forward')
            ->where('status', 'effective')->lock(true)->order('id', 'asc')->select()->toArray();
        $cashCollected = array_sum(array_map(static function (array $row): int {
            return (int)($row['amount_cents'] ?? 0);
        }, $paymentFacts));
        $cashRefund = (int)$input['cashRefundCents'];
        if ($cashRefund < 0 || $cashRefund > $cashCollected) {
            throw self::failure('sales_refund_cash_refund_exceeds_collected');
        }
        if ($entitlementCreditCents < 0) throw self::failure('sales_refund_entitlement_credit_invalid');
        $economicReversal = $cashRefund + $restorePrincipal + $restoreBonus + $entitlementCreditCents;
        if ($economicReversal <= 0 || $economicReversal > (int)$source['amountCents']) {
            throw self::failure('sales_refund_economic_amount_invalid');
        }
        $coupons = $economicReversal === (int)$source['amountCents']
            ? $this->lockConsumedCoupons($source, $scope)
            : [];

        return [
            'balanceFacts' => $balanceFacts,
            'principalPaidCents' => $principalPaid,
            'bonusPaidCents' => $bonusPaid,
            'restorePrincipalCents' => $restorePrincipal,
            'restoreBonusCents' => $restoreBonus,
            'debts' => [],
            'cancelledDebtCents' => 0,
            'cards' => [],
            'paymentFacts' => $paymentFacts,
            'cashCollectedCents' => $cashCollected,
            'cashRefundCents' => $cashRefund,
            'cashReversalCents' => $cashRefund,
            'entitlementCreditCents' => $entitlementCreditCents,
            'economicReversalCents' => $economicReversal,
            'coupons' => $coupons,
        ];
    }

    /** @return array<string,mixed> */
    public function apply(
        array $source,
        string $action,
        array $prepared,
        string $operationId,
        string $commandKey,
        CashierV3OperatorScope $operator,
        CashierV3DataScopeContext $scope,
        int $now
    ): array {
        CashierV3TransactionGuard::assertInTransaction('salesOrderReversal.apply');
        $balance = $this->restoreBalance($source, $action, $prepared, $operationId, $commandKey, $operator, $scope, $now);
        foreach ((array)$prepared['debts'] as $debt) {
            $this->cancelDebt((array)$debt, $source, $action, $operationId, $commandKey, $operator, $scope, $now);
        }
        foreach ((array)$prepared['cards'] as $card) {
            $this->revokeCard((array)$card, $source, $action, $operationId, $commandKey, $operator, $scope, $now);
        }
        $this->restoreCoupons((array)($prepared['coupons'] ?? []), $source, $operationId, $now);
        return ['balance' => $balance, 'restoredPrincipalCents' => (int)$prepared['restorePrincipalCents'],
            'restoredBonusCents' => (int)$prepared['restoreBonusCents'], 'cancelledDebtCents' => (int)$prepared['cancelledDebtCents'],
            'revokedCardCount' => count((array)$prepared['cards'])];
    }

    /** @return array<string,mixed> */
    private function restoreBalance(array $source, string $action, array $prepared, string $operationId, string $commandKey, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope, int $now): array
    {
        $principal = (int)$prepared['restorePrincipalCents'];
        $bonus = (int)$prepared['restoreBonusCents'];
        if ($principal === 0 && $bonus === 0) return [];
        $provider = new CashierV3MemberBalanceProvider();
        $before = $provider->lockSnapshotInTx((int)$source['memberId'], $operator, $scope);
        try {
            $changed = (new UserBalanceAtomicServices())->creditBenGive(
                (int)$source['memberId'], $this->centsToMoney($principal), $this->centsToMoney($bonus),
                $action === 'void-sales-order' ? 'order_void' : 'pay_product_refund', (int)$source['sourceRecordId'],
                $action === 'void-sales-order' ? '收银V3销售订单作废退回余额' : '收银V3销售订单退款退回余额',
                'cv3-sales-lifecycle-' . hash('sha256', $scope->tenantId() . '|' . $commandKey)
            );
        } catch (ValidateException $exception) {
            throw self::failure('sales_reversal_balance_credit_failed');
        }
        $after = $provider->lockSnapshotInTx((int)$source['memberId'], $operator, $scope);
        $idempotent = !empty($changed['idempotent']);
        if ((int)$after['accountVersion'] !== (int)$before['accountVersion'] + ($idempotent ? 0 : 1)) {
            throw self::failure('sales_reversal_balance_version_invalid');
        }
        $facts = (array)$prepared['balanceFacts'];
        if (!$facts) throw self::failure('sales_reversal_balance_fact_missing');
        $principalAllocations = $this->allocateBalanceComponent($facts, $principal, 'principal_delta_cents');
        $bonusAllocations = $this->allocateBalanceComponent($facts, $bonus, 'bonus_delta_cents');
        foreach ($facts as $index => $template) {
            $principalPart = (int)($principalAllocations[$index] ?? 0);
            $bonusPart = (int)($bonusAllocations[$index] ?? 0);
            if ($principalPart === 0 && $bonusPart === 0) continue;
            $row = $template;
            unset($row['id']);
            $row['fact_id'] = 'OLB-' . strtoupper(substr(hash_hmac('sha256', $operationId . '|balance|' . $template['fact_id'], $this->secret()), 0, 40));
            $row['fact_type'] = 'balance_restored'; $row['fact_direction'] = 'reversal';
            $row['natural_key'] = 'order_lifecycle:balance:' . hash('sha256', $operationId . '|' . $template['fact_id']);
            $row['command_idempotency_key'] = $commandKey; $row['fact_version'] = 1;
            $row['reversal_of'] = (string)$template['fact_id']; $row['operator_id'] = $operator->operatorId();
            $row['business_date'] = date('Y-m-d', $now); $row['occurred_at'] = $now; $row['settled_at'] = $now; $row['recorded_at'] = $now;
            $row['balance_change_type'] = $action === 'void-sales-order' ? 'order_void' : 'order_refund';
            $row['account_version'] = (int)$after['accountVersion'];
            $row['principal_delta_cents'] = $principalPart; $row['bonus_delta_cents'] = $bonusPart;
            $row['principal_after_cents'] = (int)$after['principalCents']; $row['bonus_after_cents'] = (int)$after['giftCents'];
            $row['immutable_fingerprint'] = hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if ((int)Db::name('cashier_v3_balance_fact')->insert($row) !== 1) throw self::failure('sales_reversal_balance_fact_insert_failed');
        }
        return ['accountVersionBefore' => (int)$before['accountVersion'], 'accountVersionAfter' => (int)$after['accountVersion']];
    }

    /** @return array<int,int> */
    private function allocateBalanceComponent(array $facts, int $target, string $column): array
    {
        $out = [];
        $remaining = $target;
        foreach ($facts as $index => $fact) {
            $available = abs((int)($fact[$column] ?? 0));
            $part = min($available, $remaining);
            $out[$index] = $part;
            $remaining -= $part;
        }
        if ($remaining !== 0) throw self::failure('sales_reversal_balance_component_allocation_invalid');
        return $out;
    }

    private function cancelDebt(array $debt, array $source, string $action, string $operationId, string $commandKey, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope, int $now): void
    {
        $amount = $this->moneyToCents($debt['total_debt'] ?? null);
        $affected = Db::name('store_debt')->where('id', (int)$debt['debt_id'])->where('status', 0)
            ->where('repaid_debt', '0.00')->update(['status' => 2, 'update_time' => $now]);
        if ((int)$affected !== 1) throw self::failure('sales_reversal_debt_cancel_race');
        $row = [
            'reversal_id' => 'OLD-' . strtoupper(substr(hash_hmac('sha256', $operationId . '|' . $debt['debt_id'], $this->secret()), 0, 40)),
            'operation_id' => $operationId, 'tenant_id' => $scope->tenantId(), 'store_id' => $operator->storeId(),
            'member_id' => (int)$source['memberId'], 'source_order_id' => (string)$source['sourceId'],
            'debt_id' => (int)$debt['debt_id'], 'debt_no_snapshot' => (string)$debt['debt_no'],
            'cancelled_debt_cents' => $amount, 'reversal_type' => $action === 'void-sales-order' ? 'void' : 'refund',
            'command_idempotency_key' => $commandKey, 'operator_id' => $operator->operatorId(), 'status' => 'cancelled',
            'occurred_at' => $now, 'created_at' => $now,
        ];
        if ((int)Db::name(self::DEBT_REVERSAL_TABLE)->insert($row) !== 1) throw self::failure('sales_reversal_debt_audit_insert_failed');
    }

    /** @return array<string,mixed> */
    private function lockUnusedCard(array $receipt, array $source, CashierV3DataScopeContext $scope): array
    {
        if ((int)$receipt['member_id'] !== (int)$source['memberId'] || (int)$receipt['store_id'] !== (int)$source['storeId']) {
            throw self::failure('sales_reversal_card_scope_mismatch');
        }
        $holder = (array)Db::name('user_card_holder')->where('id', (int)$receipt['card_holder_id'])->lock(true)->find();
        $order = (array)Db::name('store_order')->where('id', (int)$receipt['legacy_order_id'])->lock(true)->find();
        if (!$holder || !$order || (int)$holder['uid'] !== (int)$source['memberId'] || (int)$holder['oid'] !== (int)$receipt['legacy_order_id']
            || (int)$holder['is_del'] !== 0 || (int)$holder['write_surplus_times'] !== (int)$holder['write_times']
            || (int)$order['paid'] !== 1 || (int)$order['refund_status'] !== 0 || (int)$order['terminal_action'] !== 0) {
            throw self::failure('sales_reversal_card_already_changed');
        }
        $details = Db::name('store_order_cart_info')->where('oid', (int)$receipt['legacy_order_id'])->where('cart_type', 2)
            ->lock(true)->order('id', 'asc')->select()->toArray();
        foreach ($details as $detail) {
            if ((int)$detail['write_surplus_times'] !== (int)$detail['write_times']
                || (int)$detail['surplus_num'] !== (int)$detail['cart_num']
                || (int)$detail['split_surplus_num'] !== (int)$detail['cart_num']
                || (int)$detail['is_writeoff'] !== 0 || (int)$detail['writeoff_time'] !== 0 || (int)$detail['refund_num'] !== 0) {
                throw self::failure('sales_reversal_card_benefit_used');
            }
        }
        $detailIds = array_values(array_map('intval', array_column($details, 'id')));
        if ($detailIds) {
            if (Db::name('cashier_v3_entitlement_writeoff_fact')->where('tenant_id', $scope->tenantId())->whereIn('source_detail_id', $detailIds)->count() > 0
                || Db::name('cashier_v3_service_order_line')->where('tenant_id', $scope->tenantId())->whereIn('entitlement_source_detail_id', $detailIds)->count() > 0
                || Db::name('store_reservation_order')->whereIn('cart_info_id', $detailIds)->where('is_del', 0)->where('is_system_del', 0)->count() > 0) {
                throw self::failure('sales_reversal_card_downstream_use_exists');
            }
        }
        if (Db::name('cashier_v3_card_operation')->where('tenant_id', $scope->tenantId())
            ->where('source_card_holder_id', (int)$receipt['card_holder_id'])->count() > 0) {
            throw self::failure('sales_reversal_card_operation_exists');
        }
        $rule = (array)Db::name('cashier_v3_card_rule_state')->where('tenant_id', $scope->tenantId())
            ->where('card_holder_id', (int)$receipt['card_holder_id'])->lock(true)->find();
        $components = [];
        if ($rule) {
            if ((int)$rule['selected_kind_count'] !== 0 || (int)$rule['shared_remaining_times'] !== (int)$rule['shared_total_times']
                || (string)$rule['status'] !== 'active') throw self::failure('sales_reversal_card_rule_used');
            $components = Db::name('cashier_v3_card_rule_component')->where('tenant_id', $scope->tenantId())
                ->where('rule_state_id', (int)$rule['id'])->lock(true)->order('id', 'asc')->select()->toArray();
            foreach ($components as $component) {
                if ((int)$component['remaining_times'] !== (int)$component['total_times'] || (int)$component['selected_at'] !== 0
                    || (string)$component['status'] !== 'active') throw self::failure('sales_reversal_card_rule_component_used');
            }
        }
        return ['receipt' => $receipt, 'holder' => $holder, 'order' => $order, 'details' => $details, 'rule' => $rule, 'components' => $components];
    }

    private function revokeCard(array $card, array $source, string $action, string $operationId, string $commandKey, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope, int $now): void
    {
        $receipt = (array)$card['receipt'];
        $terminal = $action === 'void-sales-order' ? StoreOrderTerminalOperation::ACTION_VOID : StoreOrderTerminalOperation::ACTION_REFUND;
        if ((int)Db::name('user_card_holder')->where('id', (int)$receipt['card_holder_id'])->where('is_del', 0)->update(['is_del' => 1]) !== 1) {
            throw self::failure('sales_reversal_card_holder_revoke_race');
        }
        $orderUpdate = ['terminal_action' => $terminal, 'terminal_action_time' => $now];
        if ($terminal === StoreOrderTerminalOperation::ACTION_REFUND) $orderUpdate['refund_status'] = 2;
        if ((int)Db::name('store_order')->where('id', (int)$receipt['legacy_order_id'])->where('terminal_action', 0)->update($orderUpdate) !== 1) {
            throw self::failure('sales_reversal_card_order_revoke_race');
        }
        Db::name('cashier_v3_card_state')->where('tenant_id', $scope->tenantId())->where('card_holder_id', (int)$receipt['card_holder_id'])
            ->update(['card_status' => 'disabled', 'status_reason_snapshot' => '销售订单退款/作废撤销', 'current_version' => Db::raw('current_version+1'), 'last_operation_id' => $operationId, 'updated_at' => $now]);
        if (!empty($card['rule'])) {
            Db::name('cashier_v3_card_rule_state')->where('id', (int)$card['rule']['id'])->where('status', 'active')
                ->update(['status' => 'revoked', 'state_version' => Db::raw('state_version+1'), 'update_time' => $now]);
            Db::name('cashier_v3_card_rule_component')->where('rule_state_id', (int)$card['rule']['id'])->where('status', 'active')
                ->update(['status' => 'revoked', 'state_version' => Db::raw('state_version+1'), 'update_time' => $now]);
        }
        $row = [
            'reversal_id' => 'OLC-' . strtoupper(substr(hash_hmac('sha256', $operationId . '|' . $receipt['receipt_id'], $this->secret()), 0, 40)),
            'operation_id' => $operationId, 'tenant_id' => $scope->tenantId(), 'store_id' => $operator->storeId(),
            'member_id' => (int)$source['memberId'], 'source_order_id' => (string)$source['sourceId'],
            'sales_order_line_id' => (string)$receipt['sales_order_line_id'], 'issuance_receipt_id' => (string)$receipt['receipt_id'],
            'card_holder_id' => (int)$receipt['card_holder_id'], 'legacy_order_id' => (int)$receipt['legacy_order_id'],
            'benefit_detail_ids_json' => json_encode(array_values(array_map('intval', array_column((array)$card['details'], 'id')))),
            'reversal_type' => $action === 'void-sales-order' ? 'void' : 'refund', 'command_idempotency_key' => $commandKey,
            'operator_id' => $operator->operatorId(), 'status' => 'revoked', 'occurred_at' => $now, 'created_at' => $now,
        ];
        if ((int)Db::name(self::BENEFIT_REVERSAL_TABLE)->insert($row) !== 1) throw self::failure('sales_reversal_card_audit_insert_failed');
    }

    /** @return array<int,array<string,mixed>> */
    private function lockConsumedCoupons(array $source, CashierV3DataScopeContext $scope): array
    {
        $lines = Db::name('cashier_v3_sales_order_line')->where('tenant_id', $scope->tenantId())
            ->where('order_id', (string)$source['sourceId'])->where('line_direction', 'forward')
            ->where('line_status', 'settled')->where('coupon_user_id', '>', 0)
            ->field('order_line_id,coupon_user_id,coupon_name_snapshot,coupon_discount_cents')
            ->order('line_no', 'asc')->lock(true)->select()->toArray();
        $seen = [];
        foreach ($lines as &$line) {
            $couponId = (int)$line['coupon_user_id'];
            if (isset($seen[$couponId]) || (int)$line['coupon_discount_cents'] <= 0) {
                throw self::failure('sales_reversal_coupon_trace_invalid');
            }
            $coupon = (array)Db::name('store_coupon_user')->where('id', $couponId)->lock(true)->find();
            if (!$coupon || (int)($coupon['uid'] ?? 0) !== (int)$source['memberId']
                || (int)($coupon['status'] ?? -1) !== 1 || (int)($coupon['use_time'] ?? 0) <= 0
                || (int)($coupon['is_fail'] ?? -1) !== 0) {
                throw self::failure('sales_reversal_coupon_state_changed');
            }
            $line['coupon'] = $coupon;
            $seen[$couponId] = true;
        }
        unset($line);
        return $lines;
    }

    private function restoreCoupons(array $coupons, array $source, string $operationId, int $now): void
    {
        foreach ($coupons as $line) {
            $coupon = (array)($line['coupon'] ?? []);
            $updated = Db::name('store_coupon_user')->where('id', (int)$line['coupon_user_id'])
                ->where('uid', (int)$source['memberId'])->where('status', 1)
                ->where('is_fail', 0)->where('use_time', (int)($coupon['use_time'] ?? 0))
                ->update(['status' => 0, 'use_time' => 0]);
            if ((int)$updated !== 1) throw self::failure('sales_reversal_coupon_restore_race');
        }
    }

    private function moneyToCents($value): int
    {
        $raw = trim((string)$value);
        if (preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/D', $raw) !== 1) throw self::failure('sales_reversal_money_invalid');
        [$whole, $decimal] = array_pad(explode('.', $raw, 2), 2, '');
        return (int)$whole * 100 + (int)str_pad($decimal, 2, '0');
    }

    private function centsToMoney(int $cents): string { return bcdiv((string)$cents, '100', 2); }
    private function secret(): string { $secret = trim((string)config('cashier_v3.checkout_namespace_secret')); if (strlen($secret) < 32) throw self::failure('sales_reversal_secret_missing'); return $secret; }
    private static function failure(string $reason): CashierV3CommandException { return new CashierV3CommandException(CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE, '该订单的资金、欠款或权益状态不满足本次退款/作废条件，未提交任何变更。', CashierV3ResultCode::STATUS_FAILED, ['reason' => $reason]); }
}
