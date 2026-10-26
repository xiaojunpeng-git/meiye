<?php
declare(strict_types=1);

namespace app\services\cashier\v3\order;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use think\facade\Db;

/**
 * Locks and reverses only the debt created by a new V3 recharge.
 *
 * Refund leaves debt untouched. Void closes an entirely unpaid debt and appends
 * an audit row; the original recharge and debt amounts remain historical facts.
 */
final class CashierV3RechargeDebtReversalServices
{
    private const AUDIT_TABLE = 'cashier_v3_order_lifecycle_debt_reversal';

    /** @return array<string,mixed> */
    public function prepare(array $source, string $action, CashierV3DataScopeContext $scope): array
    {
        CashierV3TransactionGuard::assertInTransaction('rechargeDebtReversal.prepare');
        if (!in_array($action, ['refund-recharge-order', 'void-recharge-order'], true)) {
            throw self::failure('recharge_debt_reversal_action_invalid');
        }

        // Refund and recharge debt are independent obligations. Do not even
        // lock the debt projection, so a refund cannot change or block it.
        if ($action === 'refund-recharge-order') {
            return ['debt' => [], 'cancelledDebtCents' => 0];
        }

        $authorityHint = Db::name('cashier_v3_recharge_debt_authority')
            ->where('tenant_id', $scope->tenantId())
            ->where('recharge_id', (int)$source['rechargeId'])
            ->find();
        if (!$authorityHint) {
            return ['debt' => [], 'cancelledDebtCents' => 0];
        }
        // Repayment locks store_debt before its authority map. Keep the same
        // order here to prevent a repayment/void deadlock.
        $debt = (array)Db::name('store_debt')->where('id', (int)$authorityHint['debt_id'])->lock(true)->find();
        $authority = (array)Db::name('cashier_v3_recharge_debt_authority')
            ->where('tenant_id', $scope->tenantId())
            ->where('recharge_id', (int)$source['rechargeId'])
            ->lock(true)->find();
        if (!$authority || (int)($authorityHint['debt_id'] ?? 0) !== (int)($authority['debt_id'] ?? 0)) {
            throw self::failure('recharge_debt_reversal_authority_changed');
        }
        $items = Db::name('store_debt_item')->where('debt_id', (int)$authority['debt_id'])
            ->lock(true)->order('id', 'asc')->select()->toArray();
        if (!$debt || count($items) !== 1
            || (int)($authority['store_id'] ?? 0) !== (int)$source['storeId']
            || (int)($authority['member_id'] ?? 0) !== (int)$source['memberId']
            || (string)($authority['recharge_order_no_snapshot'] ?? '') !== (string)$source['orderNo']
            || (int)($debt['id'] ?? 0) !== (int)$authority['debt_id']
            || (int)($debt['order_id'] ?? -1) !== 0
            || (int)($debt['uid'] ?? 0) !== (int)$source['memberId']
            || (int)($debt['store_id'] ?? 0) !== (int)$source['storeId']
            || (string)($debt['debt_no'] ?? '') !== (string)($authority['debt_no'] ?? '')
            || (int)($items[0]['debt_id'] ?? 0) !== (int)$authority['debt_id']) {
            throw self::failure('recharge_debt_reversal_authority_mismatch');
        }

        $total = $this->moneyToCents($debt['total_debt'] ?? null);
        $repaid = $this->moneyToCents($debt['repaid_debt'] ?? null);
        if ($total <= 0 || $this->moneyToCents($items[0]['debt_amount'] ?? null) !== $total
            || $this->moneyToCents($items[0]['repaid_debt'] ?? null) !== $repaid) {
            throw self::failure('recharge_debt_reversal_projection_mismatch');
        }

        $repayments = Db::name('cashier_v3_recharge_debt_repayment')
            ->where('tenant_id', $scope->tenantId())
            ->where('debt_id', (int)$authority['debt_id'])
            ->lock(true)->field('id')->select()->toArray();
        if ($repayments !== [] || $repaid > 0) {
            throw self::failure('recharge_void_debt_repayment_exists');
        }
        if ((int)($debt['status'] ?? -1) !== 0) {
            throw self::failure('recharge_void_debt_state_invalid');
        }

        return [
            'debt' => ['debtId' => (int)$authority['debt_id'], 'debtNo' => (string)$debt['debt_no']],
            'cancelledDebtCents' => $total,
        ];
    }

    public function apply(
        array $source,
        string $action,
        array $prepared,
        string $operationId,
        string $commandKey,
        CashierV3OperatorScope $operator,
        CashierV3DataScopeContext $scope,
        int $now
    ): void {
        CashierV3TransactionGuard::assertInTransaction('rechargeDebtReversal.apply');
        if ($action !== 'void-recharge-order' || empty($prepared['debt'])) {
            return;
        }
        $debt = (array)$prepared['debt'];
        $amount = (int)($prepared['cancelledDebtCents'] ?? 0);
        if ($amount <= 0) throw self::failure('recharge_void_debt_amount_invalid');

        $affected = Db::name('store_debt')->where('id', (int)$debt['debtId'])
            ->where('status', 0)->where('repaid_debt', '0.00')
            ->update(['status' => 2, 'update_time' => $now]);
        if ((int)$affected !== 1) throw self::failure('recharge_void_debt_close_race');

        $row = [
            'reversal_id' => 'RDR-' . strtoupper(substr(hash_hmac('sha256', $operationId . '|' . $debt['debtId'], $this->secret()), 0, 40)),
            'operation_id' => $operationId,
            'tenant_id' => $scope->tenantId(),
            'store_id' => $operator->storeId(),
            'member_id' => (int)$source['memberId'],
            'source_order_id' => (string)$source['rechargeId'],
            'debt_id' => (int)$debt['debtId'],
            'debt_no_snapshot' => (string)$debt['debtNo'],
            'cancelled_debt_cents' => $amount,
            'reversal_type' => 'void',
            'command_idempotency_key' => $commandKey,
            'operator_id' => $operator->operatorId(),
            'status' => 'cancelled',
            'occurred_at' => $now,
            'created_at' => $now,
        ];
        if ((int)Db::name(self::AUDIT_TABLE)->insert($row) !== 1) {
            throw self::failure('recharge_void_debt_audit_insert_failed');
        }
    }

    private function moneyToCents($value): int
    {
        $raw = trim((string)$value);
        if (preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/D', $raw) !== 1) {
            throw self::failure('recharge_debt_reversal_money_invalid');
        }
        [$whole, $decimal] = array_pad(explode('.', $raw, 2), 2, '');
        return (int)$whole * 100 + (int)str_pad($decimal, 2, '0');
    }

    private function secret(): string
    {
        $secret = trim((string)config('cashier_v3.checkout_namespace_secret'));
        if (strlen($secret) < 32) throw self::failure('recharge_debt_reversal_secret_missing');
        return $secret;
    }

    private static function failure(string $reason): CashierV3CommandException
    {
        return new CashierV3CommandException(
            CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
            '该充值单的欠款状态不满足本次作废条件，未提交任何变更。',
            CashierV3ResultCode::STATUS_FAILED,
            ['reason' => $reason]
        );
    }
}
