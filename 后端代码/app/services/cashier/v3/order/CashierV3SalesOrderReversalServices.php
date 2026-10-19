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
            // 独立取消欠款（status=3）保留了单独的免除事实，必须先恢复欠款，
            // 再由整单作废统一冲销；单独识别该状态才能给门店明确的处理路径。
            if ((int)$debt['status'] === CashierV3DebtCancellationServices::CANCELLED_STATUS) {
                throw self::failure('sales_reversal_debt_cancelled');
            }
            if ((int)$debt['status'] !== 0 || $total <= 0) throw self::failure('sales_reversal_debt_state_invalid');
            // 补交作废后状态应变为 voided、欠款投影应回到 0；两者都须成立，
            // 避免投影异常时把仍有效的补交单留在已作废销售单下面。
            if (Db::name('cashier_v3_debt_repayment')->where('tenant_id', $scope->tenantId())
                ->where('debt_id', (int)$debt['debt_id'])->where('status', 'succeeded')->count() > 0) {
                throw self::failure('sales_reversal_debt_repayment_active');
            }
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
        // Deferred member projects use the same holder/detail authority as card
        // redemption. A whole-sale void must check occupation and retract these
        // rights too; an ordinary financial refund deliberately keeps them.
        $projectLines = Db::name('cashier_v3_sales_order_line')->where('tenant_id', $scope->tenantId())
            ->where('order_id', (string)$source['sourceId'])->where('line_direction', 'forward')
            ->where('line_status', 'settled')->where('item_type', 'project')->lock(true)->select()->toArray();
        foreach ($projectLines as $projectLine) {
            $technicalNo = 'v3p' . substr(hash('sha256', $projectLine['order_line_id']), 0, 28);
            $carrier = Db::name('store_order')->where('order_id', $technicalNo)->lock(true)->find();
            if (!$carrier) continue; // Store immediate-service sales have no deferred carrier.
            $holders = Db::name('user_card_holder')->where('oid', (int)$carrier['id'])->lock(true)->select()->toArray();
            $detail = Db::name('store_order_cart_info')->where('oid', (int)$carrier['id'])->where('cart_type', 2)->lock(true)->find();
            $snapshot = json_decode((string)($detail['cart_info'] ?? ''), true);
            if (count($holders) !== 1 || ($snapshot['sourceType'] ?? '') !== 'member_v3_project_purchase'
                || ($snapshot['salesOrderLineId'] ?? '') !== $projectLine['order_line_id']
                || ($snapshot['salesOrderId'] ?? '') !== $source['sourceId']) {
                throw self::failure('sales_reversal_member_project_mapping_invalid');
            }
            $cards[] = $this->lockUnusedCard([
                'receipt_id' => 'MPR-' . substr(hash('sha256', $projectLine['order_line_id']), 0, 40),
                'member_id' => (int)$source['memberId'], 'store_id' => (int)$source['storeId'],
                'sales_order_line_id' => $projectLine['order_line_id'],
                'card_holder_id' => (int)$holders[0]['id'], 'legacy_order_id' => (int)$carrier['id'],
            ], $source, $scope);
        }

        $paymentFacts = Db::name('cashier_v3_payment_fact')->where('tenant_id', $scope->tenantId())
            ->where('order_id', (string)$source['sourceId'])->where('fact_direction', 'forward')
            ->where('status', 'effective')->lock(true)->order('id', 'asc')->select()->toArray();
        $cashCollected = array_sum(array_map(static function (array $row): int { return (int)($row['amount_cents'] ?? 0); }, $paymentFacts));
        $cashReversal = $action === 'void-sales-order' ? $cashCollected : (int)$input['cashRefundCents'];
        $cashRefund = $action === 'void-sales-order' ? 0 : $cashReversal;
        if ($cashReversal < 0 || $cashReversal > $cashCollected) throw self::failure('sales_reversal_cash_refund_exceeds_source');
        if ($entitlementCreditCents < 0) throw self::failure('sales_reversal_entitlement_credit_invalid');
        $nonCashSettlement = $action === 'void-sales-order'
            ? $this->lockedNonCashSettlement($source, $scope, $cashCollected, $principalPaid + $bonusPaid)
            : 0;
        $couponTender = $action === 'void-sales-order'
            ? $this->couponTenderCents($source, $scope) : 0;
        // Non-cash value settled the original sale without a payment fact.
        // Count it in a full void, never as refunded or reversed cash.
        $economicReversal = $cashReversal + $restorePrincipal + $restoreBonus + $cancelledDebtCents
            + $entitlementCreditCents + $nonCashSettlement + $couponTender;
        // 免费的人次／项目也是已经结算的销售事实。全量作废仍需留下作废
        // 事件和反向业务事实，只是没有资金、欠款、余额或权益可冲销；退款
        // 则必须始终有正向经济金额，不能借此生成零额退款。
        $isZeroValueVoid = $action === 'void-sales-order'
            && (int)$source['amountCents'] === 0
            && $economicReversal === 0;
        if ((!$isZeroValueVoid && $economicReversal <= 0) || $economicReversal > (int)$source['amountCents']) {
            throw self::failure('sales_reversal_economic_amount_invalid');
        }
        if ($action === 'void-sales-order' && $economicReversal !== (int)$source['amountCents']) {
            throw self::failure('sales_void_source_equation_invalid');
        }
        // Coupon tender restores only with a full void; even a refund of all
        // refundable cash leaves the coupon consumed.
        $coupons = $action === 'void-sales-order'
            ? $this->lockConsumedCoupons($source, $scope)
            : [];

        return [
            'balanceFacts' => $balanceFacts, 'principalPaidCents' => $principalPaid, 'bonusPaidCents' => $bonusPaid,
            'restorePrincipalCents' => $restorePrincipal, 'restoreBonusCents' => $restoreBonus,
            'debts' => $debts, 'cancelledDebtCents' => $cancelledDebtCents,
            'cards' => $cards, 'paymentFacts' => $paymentFacts, 'cashCollectedCents' => $cashCollected,
            'cashRefundCents' => $cashRefund, 'cashReversalCents' => $cashReversal,
            'entitlementCreditCents' => $entitlementCreditCents, 'nonCashSettlementCents' => $nonCashSettlement,
            'economicReversalCents' => $economicReversal,
            'coupons' => $coupons,
        ];
    }

    /** Reverse committed non-cash value only when no collection was recorded for that draft. */
    private function lockedNonCashSettlement(array $source, CashierV3DataScopeContext $scope, int $cashCollected, int $balancePaid): int
    {
        $drafts = Db::name('cashier_v3_checkout_payment_draft')
            ->where('tenant_id', $scope->tenantId())
            ->where('request_id', (string)$source['checkoutRequestId'])
            ->where('draft_status', 'committed')->lock(true)->select()->toArray();
        $collectedDraftIds = array_fill_keys(Db::name('cashier_v3_payment_collection')
            ->where('tenant_id', $scope->tenantId())
            ->where('sales_order_id', (string)$source['sourceId'])
            ->where('collection_direction', 'forward')
            ->where('collection_status', 'settled')
            ->column('checkout_payment_draft_id'), true);
        $legacy = array_values(array_filter($drafts, static function (array $draft): bool {
            return (string)$draft['payment_method'] === 'old_card_entry';
        }));
        if ($legacy !== [] && (count($drafts) !== 1 || count($legacy) !== 1
            || $cashCollected !== 0 || $balancePaid !== 0
            || isset($collectedDraftIds[(string)$legacy[0]['payment_draft_id']])
            || (int)$legacy[0]['store_id'] !== (int)$source['storeId']
            || (int)$legacy[0]['member_id'] !== (int)$source['memberId']
            || (int)$legacy[0]['amount_cents'] <= 0)) {
            throw self::failure('sales_void_source_equation_invalid');
        }
        $total = 0;
        foreach ($drafts as $draft) {
            if ((string)$draft['payment_method'] === 'old_card_entry'
                || ((string)$draft['payment_method'] === 'other_collection'
                    && !isset($collectedDraftIds[(string)$draft['payment_draft_id']]))) {
                $total += (int)$draft['amount_cents'];
            }
        }
        return $total;
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
        // A financial refund never reissues the original coupon, including
        // when all cash paid by the customer is refunded.
        $coupons = [];

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
            || (int)$holder['is_del'] !== 0 || (int)$order['paid'] !== 1
            || (int)$order['refund_status'] !== 0 || (int)$order['terminal_action'] !== 0) {
            throw self::failure('sales_reversal_card_already_changed');
        }
        if ((int)$holder['write_surplus_times'] !== (int)$holder['write_times']) {
            // 已使用的新购卡不能跟原销售订单一起撤销；将卡名和次数口径直接告知门店，
            // 避免通用的“权益状态不满足”掩盖真实原因，但不放宽作废的账权保护。
            throw self::cardUsageFailure($holder);
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
            // 只看未了结的后续权益，而非“曾有记录”：服务作废会追加权益返还事实，
            // 原核销事实永远保留。次数已恢复仍须核对每一笔核销确实完成作废。
            $writeoffs = Db::name('cashier_v3_entitlement_writeoff_fact')->where('tenant_id', $scope->tenantId())
                ->whereIn('source_detail_id', $detailIds)->where('status', 'effective')
                ->field('writeoff_id,source_kind,source_detail_id,holder_id,quantity')->order('id', 'asc')->lock(true)->select()->toArray();
            if ($writeoffs && !$this->allCardWriteoffsVoided($writeoffs, $scope)) {
                throw self::failure('sales_reversal_card_downstream_use_exists');
            }
            if (Db::name('cashier_v3_service_order_line')->where('tenant_id', $scope->tenantId())
                ->whereIn('entitlement_source_detail_id', $detailIds)->where('status', 'ACTIVE')->count() > 0
                || Db::name('store_reservation_order')->whereIn('cart_info_id', $detailIds)
                    ->whereIn('status', [0, 1, 3])->where('is_del', 0)->where('is_system_del', 0)->count() > 0) {
                throw self::failure('sales_reversal_card_active_occupation_exists');
            }
        }
        if ($this->hasUnreversedCardOperation((int)$receipt['card_holder_id'], $scope)) {
            throw self::failure('sales_reversal_card_operation_exists');
        }
        $rule = (array)Db::name('cashier_v3_card_rule_state')->where('tenant_id', $scope->tenantId())
            ->where('card_holder_id', (int)$receipt['card_holder_id'])->lock(true)->find();
        $components = [];
        if ($rule) {
            $groups = Db::name('cashier_v3_card_rule_group_state')->where('tenant_id', $scope->tenantId())
                ->where('rule_state_id', (int)$rule['id'])->lock(true)->order('id', 'asc')->select()->toArray();
            $components = Db::name('cashier_v3_card_rule_component')->where('tenant_id', $scope->tenantId())
                ->where('rule_state_id', (int)$rule['id'])->lock(true)->order('id', 'asc')->select()->toArray();
            if (!self::cardRuleCountersRestored($rule, $groups, $components)) {
                throw self::failure('sales_reversal_card_rule_used');
            }
        }
        return ['receipt' => $receipt, 'holder' => $holder, 'order' => $order, 'details' => $details, 'rule' => $rule, 'components' => $components];
    }

    /**
     * 已核销权益须先由上游逐笔证明成功作废。这里检查可用次数已全数返还；
     * 任选项目的选中时间和种数是不可抹掉的使用历史，不代表仍有有效核销。
     */
    private static function cardRuleCountersRestored(array $rule, array $groups, array $components): bool
    {
        if (!$components || ((string)$rule['rule_type'] === 'combination' && !$groups)
            || (int)$rule['shared_remaining_times'] !== (int)$rule['shared_total_times']
            || (string)$rule['status'] !== 'active') return false;
        foreach ($groups as $group) {
            if ((int)$group['shared_remaining_times'] !== (int)$group['shared_total_times']
                || (string)$group['status'] !== 'active') return false;
        }
        foreach ($components as $component) {
            if ((int)$component['remaining_times'] !== (int)$component['total_times']
                || (string)$component['status'] !== 'active') return false;
        }
        return true;
    }

    /** @param array<int,array<string,mixed>> $writeoffs 逐笔核销必须有等量返还及成功服务作废，不能只凭次数投影相等放行。 */
    private function allCardWriteoffsVoided(array $writeoffs, CashierV3DataScopeContext $scope): bool
    {
        $writeoffIds = array_values(array_unique(array_column($writeoffs, 'writeoff_id')));
        if (count($writeoffIds) !== count($writeoffs)) return false;
        $reversals = Db::name('cashier_v3_entitlement_reversal_fact')->where('tenant_id', $scope->tenantId())
            ->whereIn('original_writeoff_id', $writeoffIds)
            ->field('original_writeoff_id,service_fact_id,source_detail_id,holder_id,quantity,operation_id')
            ->order('id', 'asc')->lock(true)->select()->toArray();
        $operationIds = array_values(array_unique(array_column($reversals, 'operation_id')));
        $operations = $operationIds
            ? Db::name('cashier_v3_service_record_void_operation')->where('tenant_id', $scope->tenantId())
                ->whereIn('operation_id', $operationIds)
                ->field('operation_id,service_fact_id,status')->lock(true)->select()->toArray()
            : [];
        return self::writeoffsFullyReversed($writeoffs, $reversals, $operations);
    }

    /** @param array<int,array<string,mixed>> $writeoffs @param array<int,array<string,mixed>> $reversals @param array<int,array<string,mixed>> $operations */
    private static function writeoffsFullyReversed(array $writeoffs, array $reversals, array $operations): bool
    {
        $byWriteoff = [];
        foreach ($reversals as $reversal) {
            $id = (string)$reversal['original_writeoff_id'];
            if (isset($byWriteoff[$id])) return false;
            $byWriteoff[$id] = $reversal;
        }
        if (count($byWriteoff) !== count($writeoffs)) return false;
        $voided = [];
        foreach ($operations as $operation) {
            if ((string)$operation['status'] === 'succeeded') {
                $voided[(string)$operation['operation_id']] = (int)$operation['service_fact_id'];
            }
        }
        foreach ($writeoffs as $writeoff) {
            $reversal = $byWriteoff[(string)$writeoff['writeoff_id']] ?? null;
            // 时间卡核销不扣次数，其成功作废的返还数量为 0；其他卡须逐笔等量返还。
            $expectedQuantity = (string)$writeoff['source_kind'] === 'time_card' ? 0 : (int)$writeoff['quantity'];
            if (!$reversal || (int)$reversal['quantity'] !== $expectedQuantity
                || (int)$reversal['source_detail_id'] !== (int)$writeoff['source_detail_id']
                || (int)$reversal['holder_id'] !== (int)$writeoff['holder_id']
                || (int)$reversal['service_fact_id'] <= 0
                || ($voided[(string)$reversal['operation_id']] ?? 0) !== (int)$reversal['service_fact_id']) return false;
        }
        return true;
    }

    /** 已取消或已反向的卡项操作只保留审计历史，不继续占用原卡的作废资格。 */
    private function hasUnreversedCardOperation(int $holderId, CashierV3DataScopeContext $scope): bool
    {
        $operations = Db::name('cashier_v3_card_operation')->where('tenant_id', $scope->tenantId())
            ->where('source_card_holder_id', $holderId)->where('operation_status', '<>', 'cancelled')
            ->field('operation_id')->lock(true)->select()->toArray();
        if (!$operations) return false;
        $settlements = Db::name('cashier_v3_card_operation_settlement')->where('tenant_id', $scope->tenantId())
            ->whereIn('operation_id', array_column($operations, 'operation_id'))
            ->field('operation_id,settlement_status')->lock(true)->select()->toArray();
        $reversed = [];
        foreach ($settlements as $settlement) {
            if ((string)$settlement['settlement_status'] === 'reversed') {
                $reversed[(string)$settlement['operation_id']] = true;
            }
        }
        foreach ($operations as $operation) {
            if (!isset($reversed[(string)$operation['operation_id']])) return true;
        }
        return false;
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
            Db::name('cashier_v3_card_rule_group_state')->where('rule_state_id', (int)$card['rule']['id'])->where('status', 'active')
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

    /** Coupon tender is non-cash settlement; a full void must reverse it to balance the gross sale. */
    private function couponTenderCents(array $source, CashierV3DataScopeContext $scope): int
    {
        return (int)Db::name('cashier_v3_sales_order_line')
            ->where('tenant_id', $scope->tenantId())
            ->where('order_id', (string)$source['sourceId'])
            ->where('line_direction', 'forward')->where('line_status', 'settled')
            ->sum('coupon_payment_cents');
    }

    /** @return array<int,array<string,mixed>> */
    private function lockConsumedCoupons(array $source, CashierV3DataScopeContext $scope): array
    {
        // Multi-coupon redemptions have one row per actual coupon instance.
        // The first ID is mirrored on the sale line for legacy fact readers;
        // do not restore that ID twice during a whole-order void.
        $orderCoupons = Db::name('cashier_v3_checkout_order_coupon')
            ->where('tenant_id', $scope->tenantId())->where('order_id', (string)$source['sourceId'])
            ->where('status', 'consumed')->order('id', 'asc')->lock(true)->select()->toArray();
        $orderCouponIds = array_fill_keys(array_map('intval', array_column($orderCoupons, 'coupon_user_id')), true);
        $lines = Db::name('cashier_v3_sales_order_line')->where('tenant_id', $scope->tenantId())
            ->where('order_id', (string)$source['sourceId'])->where('line_direction', 'forward')
            ->where('line_status', 'settled')->where('coupon_user_id', '>', 0)
            ->field('order_line_id,coupon_user_id,coupon_name_snapshot,coupon_discount_cents,coupon_payment_cents')
            ->order('line_no', 'asc')->lock(true)->select()->toArray();
        $seen = [];
        foreach ($lines as &$line) {
            $couponId = (int)$line['coupon_user_id'];
            if (isset($orderCouponIds[$couponId])) continue;
            // A void restores either historical discount coupons or the new
            // non-cash tender; partial refunds intentionally restore neither.
            if (isset($seen[$couponId])
                || (int)$line['coupon_discount_cents'] + (int)($line['coupon_payment_cents'] ?? 0) <= 0) {
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
        $lines = array_values(array_filter($lines, static fn(array $line): bool =>
            !isset($orderCouponIds[(int)$line['coupon_user_id']])));
        foreach ($orderCoupons as $row) {
            $couponId = (int)$row['coupon_user_id'];
            if (isset($seen[$couponId]) || (int)$row['amount_cents'] <= 0) {
                throw self::failure('sales_reversal_coupon_trace_invalid');
            }
            $coupon = (array)Db::name('store_coupon_user')->where('id', $couponId)->lock(true)->find();
            if (!$coupon || (int)($coupon['uid'] ?? 0) !== (int)$source['memberId']
                || (int)($coupon['status'] ?? -1) !== 1 || (int)($coupon['use_time'] ?? 0) <= 0
                || (int)($coupon['is_fail'] ?? -1) !== 0) {
                throw self::failure('sales_reversal_coupon_state_changed');
            }
            $row['coupon'] = $coupon;
            $lines[] = ['coupon_user_id' => $couponId, 'coupon' => $coupon, 'order_coupon_row_id' => (int)$row['id']];
            $seen[$couponId] = true;
        }
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
            if ((int)($line['order_coupon_row_id'] ?? 0) > 0) {
                $changed = Db::name('cashier_v3_checkout_order_coupon')
                    ->where('id', (int)$line['order_coupon_row_id'])->where('status', 'consumed')
                    ->update(['status' => 'voided', 'voided_at' => $now]);
                if ((int)$changed !== 1) throw self::failure('sales_reversal_order_coupon_restore_race');
            }
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
    /** 生成门店可直接理解的卡项使用拒绝原因，同时保留日志和客户端可用的结构化次数。 */
    private static function cardUsageFailure(array $holder): CashierV3CommandException
    {
        $cardName = trim((string)($holder['card_name'] ?? '')) ?: '未命名卡项';
        $totalTimes = max(0, (int)($holder['write_times'] ?? 0));
        $remainingTimes = max(0, (int)($holder['write_surplus_times'] ?? 0));
        $usedTimes = max(0, $totalTimes - $remainingTimes);
        $message = sprintf(
            '卡项「%s」已使用过，不能直接作废原销售订单，使用过的卡项只能停用。',
            $cardName
        );
        return new CashierV3CommandException(
            CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
            $message,
            CashierV3ResultCode::STATUS_FAILED,
            [
                'reason' => 'sales_reversal_card_already_changed',
                'cardNameSnapshot' => $cardName,
                'totalTimes' => $totalTimes,
                'usedTimes' => $usedTimes,
                'remainingTimes' => $remainingTimes,
            ]
        );
    }
    /** 按真实拒绝原因给门店短提示；历史核销仍有记录，不能误称为尚未作废。 */
    private static function failure(string $reason): CashierV3CommandException
    {
        $messages = [
            'sales_reversal_balance_direction_invalid' => '订单余额支付记录异常，操作未完成。',
            'sales_refund_balance_direction_invalid' => '订单余额支付记录异常，操作未完成。',
            'sales_reversal_balance_restore_exceeds_source' => '退回余额超过原支付余额，操作未完成。',
            'sales_refund_balance_restore_exceeds_source' => '退回余额超过原支付余额，操作未完成。',
            'sales_reversal_debt_already_repaid' => '具体原因：订单欠款已有补交金额。需要怎么做：先作废对应补交单，确认欠款恢复为待还后，再作废销售订单。',
            'sales_reversal_debt_repayment_active' => '具体原因：订单欠款仍有未作废的补交单。需要怎么做：先作废对应补交单，确认欠款恢复为待还后，再作废销售订单。',
            'sales_reversal_debt_cancelled' => '具体原因：订单关联欠款已单独取消。需要怎么做：先到订单中心的欠款管理恢复欠款，再返回销售订单作废。',
            'sales_reversal_debt_state_invalid' => '具体原因：订单关联欠款已结清或状态异常。需要怎么做：先到订单中心的欠款管理核对欠款和补交记录，恢复为待还后，再作废销售订单。',
            'sales_reversal_card_issuance_incomplete' => '订单卡项发放记录不完整，销售订单未作废。',
            'sales_reversal_cash_refund_exceeds_source' => '退款金额超过原收款金额，操作未完成。',
            'sales_refund_cash_refund_exceeds_collected' => '退款金额超过原收款金额，操作未完成。',
            'sales_reversal_entitlement_credit_invalid' => '订单权益返还金额异常，操作未完成。',
            'sales_refund_entitlement_credit_invalid' => '订单权益返还金额异常，操作未完成。',
            'sales_reversal_economic_amount_invalid' => '订单退款金额与原订单金额不符，操作未完成。',
            'sales_refund_economic_amount_invalid' => '订单退款金额与原订单金额不符，操作未完成。',
            'sales_void_source_equation_invalid' => '订单作废金额与原订单金额不符，销售订单未作废。',
            'sales_reversal_card_scope_mismatch' => '卡项归属与订单不一致，销售订单未作废。',
            'sales_reversal_card_already_changed' => '卡项状态已变化，销售订单未作废。',
            'sales_reversal_card_benefit_used' => '卡项权益未恢复到开单状态，销售订单未作废。',
            'sales_reversal_card_downstream_use_exists' => '卡项有未作废的核销记录，销售订单未作废。',
            'sales_reversal_card_active_occupation_exists' => '卡项有未结束的预约或服务单，销售订单未作废。',
            'sales_reversal_card_operation_exists' => '卡项有未作废的后续操作单，销售订单未作废。',
            'sales_reversal_card_rule_used' => '卡项次数或项目选择未恢复，销售订单未作废。',
            'sales_reversal_coupon_trace_invalid' => '订单优惠券记录异常，操作未完成。',
            'sales_reversal_coupon_state_changed' => '订单优惠券状态已变化，操作未完成。',
            'sales_reversal_balance_credit_failed' => '会员余额退回失败，操作未完成。',
            'sales_reversal_balance_version_invalid' => '会员余额已变化，操作未完成。',
            'sales_reversal_balance_fact_missing' => '订单余额记录缺失，操作未完成。',
            'sales_reversal_balance_fact_insert_failed' => '订单余额退回记录写入失败，操作未完成。',
            'sales_reversal_balance_component_allocation_invalid' => '订单余额拆分金额异常，操作未完成。',
            'sales_reversal_debt_cancel_race' => '订单欠款状态已变化，操作未完成。',
            'sales_reversal_debt_audit_insert_failed' => '欠款作废记录写入失败，操作未完成。',
            'sales_reversal_card_holder_revoke_race' => '卡项状态已变化，操作未完成。',
            'sales_reversal_card_order_revoke_race' => '卡项订单状态已变化，操作未完成。',
            'sales_reversal_card_audit_insert_failed' => '卡项作废记录写入失败，操作未完成。',
            'sales_reversal_coupon_restore_race' => '优惠券状态已变化，操作未完成。',
            'sales_reversal_money_invalid' => '订单金额资料异常，操作未完成。',
            'sales_reversal_secret_missing' => '系统暂无法校验此操作，请联系管理员。',
        ];
        $message = $messages[$reason] ?? '订单相关记录异常，操作未完成。';
        return new CashierV3CommandException(
            CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
            $message,
            CashierV3ResultCode::STATUS_FAILED,
            ['reason' => $reason]
        );
    }
}
