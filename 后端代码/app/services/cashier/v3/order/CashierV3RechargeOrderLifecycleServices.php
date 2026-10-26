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
use app\services\cashier\v3\checkout\provider\CashierV3MemberBalanceProvider;
use app\services\user\UserBalanceAtomicServices;
use think\exception\ValidateException;
use think\facade\Db;

/**
 * New V3 recharge refund and void authority.
 *
 * This service never changes the recharge master row. It appends an order
 * lifecycle operation, reverses only the exact V3 facts and uses the balance
 * atomic service for a specified principal/bonus debit. Recharge debt and
 * package gifts are handled by dedicated reversal authorities in the same
 * transaction.
 */
final class CashierV3RechargeOrderLifecycleServices
{
    private const OPERATION_TABLE = CashierV3OrderLifecycleServices::OPERATION_TABLE;
    private const ACTIONS = ['refund-recharge-order', 'void-recharge-order'];

    public function discover(array $scope): array
    {
        $operator = $scope['operator_scope'] ?? null;
        $dataScope = $scope['data_scope'] ?? null;
        if (!$operator instanceof CashierV3OperatorScope || !$dataScope instanceof CashierV3DataScopeContext) {
            throw self::failure('recharge_lifecycle_scope_incomplete');
        }
        $source = $this->source((array)($scope['payload'] ?? []), $operator, $dataScope, false);
        $balance = (new CashierV3MemberBalanceProvider())->readSnapshot($source['memberId'], $operator, $dataScope);
        $payload = (array)($scope['payload'] ?? []);
        $action = (string)($scope['action'] ?? '');
        $touchesBalance = $action === 'void-recharge-order';
        if (!$touchesBalance) {
            foreach (['principalRefundAmount', 'bonusRefundAmount'] as $field) {
                $amount = trim((string)($payload[$field] ?? '0'));
                if (preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/D', $amount) === 1 && bccomp($amount, '0', 2) === 1) {
                    $touchesBalance = true;
                    break;
                }
            }
        }
        $version = 1 + (int)Db::name(self::OPERATION_TABLE)->where('tenant_id', $dataScope->tenantId())
            ->where('source_type', 'recharge')->where('source_order_id', (string)$source['rechargeId'])->count();
        return ['resources' => [[
            'kind' => CashierV3RechargeOrderLifecycleVersionProvider::KIND,
            'id' => (string)$source['rechargeId'], 'expectedVersion' => $version,
            'roles' => ['recharge_order'], 'accessMode' => 'mutate',
            'providerContractVersion' => CashierV3RechargeOrderLifecycleVersionProvider::CONTRACT_VERSION,
            'authorityFingerprint' => hash('sha256', implode('|', [$dataScope->tenantId(), $source['rechargeId'], $version])),
        ], [
            'kind' => CashierV3MemberBalanceProvider::KIND,
            'id' => (string)$source['memberId'], 'expectedVersion' => (int)$balance['accountVersion'],
            'roles' => ['member_balance'], 'accessMode' => $touchesBalance ? 'mutate' : 'read',
            'providerContractVersion' => CashierV3MemberBalanceProvider::CONTRACT_VERSION,
            'authorityFingerprint' => hash('sha256', implode('|', [$dataScope->tenantId(), $source['memberId'], $balance['accountVersion']])),
        ]]];
    }

    /** @return array<string,mixed> */
    public function executeInTx(string $action, array $scope): array
    {
        CashierV3TransactionGuard::assertInTransaction('rechargeOrderLifecycle.executeInTx');
        if (!in_array($action, self::ACTIONS, true)) {
            throw self::failure('recharge_lifecycle_action_invalid');
        }
        $operator = $scope['operator_scope'] ?? null;
        $dataScope = $scope['data_scope'] ?? null;
        $recorder = $scope['event_recorder'] ?? null;
        $execution = $scope['event_execution'] ?? null;
        if (!$operator instanceof CashierV3OperatorScope || !$dataScope instanceof CashierV3DataScopeContext
            || !$recorder instanceof CashierV3BusinessEventRecorder || !$execution instanceof CashierV3BusinessEventExecution) {
            throw self::failure('recharge_lifecycle_scope_incomplete');
        }
        $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
        $source = $this->source($payload, $operator, $dataScope, true);
        $input = $this->input($action, $payload, $source);
        $commandKey = trim((string)($scope['idempotency_key'] ?? ''));
        if ($commandKey === '') throw self::failure('recharge_lifecycle_idempotency_missing');
        $fingerprint = hash('sha256', json_encode([$action, $source['rechargeId'], $input], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $existing = Db::name(self::OPERATION_TABLE)->where('tenant_id', $dataScope->tenantId())
            ->where('command_idempotency_key', $commandKey)->lock(true)->find();
        if ($existing) {
            if ((string)$existing['immutable_fingerprint'] !== $fingerprint) throw self::failure('recharge_lifecycle_idempotency_conflict');
            return $this->result((array)$existing, true);
        }

        $this->assertEligible($source, $dataScope);
        // 退款只调整人工确认的资金：已发生的欠款、赠品和权益保持原状。
        // 严格作废才会执行全量回滚。
        $debtReversal = new CashierV3RechargeDebtReversalServices();
        $giftReversal = new CashierV3RechargeGiftReversalServices();
        $preparedDebt = $action === 'void-recharge-order' ? $debtReversal->prepare($source, $action, $dataScope) : [];
        $preparedGifts = $action === 'void-recharge-order' ? $giftReversal->prepare($source, $action, $dataScope) : [];
        $now = time();
        $operationType = $action === 'refund-recharge-order' ? 'refund' : 'void';
        $operationId = 'RLO-' . strtoupper(substr(hash_hmac('sha256', implode('|', [$dataScope->tenantId(), $action, $source['rechargeId'], $commandKey]), $this->secret()), 0, 40));
        $operationNo = ($operationType === 'refund' ? 'TK' : 'ZF') . date('ymd', $now) . strtoupper(substr(hash('sha256', $operationId), 0, 5));
        $event = $recorder->recordInTx($execution, (array)($scope['event_contract'] ?? []), [
            'event_type' => $operationType === 'refund' ? 'recharge.refunded' : 'recharge.voided',
            'aggregate_type' => 'recharge_order', 'aggregate_id' => 'RCH:' . $source['rechargeId'],
            // recharge.completed is aggregate version 1; each append-only
            // lifecycle operation advances the same aggregate timeline.
            'aggregate_version' => 2 + (int)Db::name(self::OPERATION_TABLE)->where('tenant_id', $dataScope->tenantId())->where('source_type', 'recharge')->where('source_order_id', (string)$source['rechargeId'])->count(),
            'event_version' => 1, 'source_type' => $action, 'source_id' => $operationId,
            'member_id' => $source['memberId'], 'business_date' => date('Y-m-d', $now),
            'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now,
            'aggregate_name_snapshot' => $source['orderNo'], 'store_name_snapshot' => $source['storeName'],
            'payload' => ['rechargeId' => $source['rechargeId'], 'rechargeOrderNo' => $source['orderNo'], 'operationId' => $operationId, 'operationNo' => $operationNo, 'cashRefundCents' => $input['cashRefundCents'], 'principalDebitCents' => $input['principalDebitCents'], 'bonusDebitCents' => $input['bonusDebitCents']],
        ]);

        $balance = $this->deductBalanceInTx($source, $input, $operationType, $commandKey, $operator, $dataScope);
        $this->writeReversals($source, $input, $operationType, $operationId, $commandKey, (array)$event, $operator, $dataScope, $now, $balance);
        if ($action === 'void-recharge-order') {
            $debtReversal->apply($source, $action, $preparedDebt, $operationId, $commandKey, $operator, $dataScope, $now);
            $giftReversal->apply($source, $action, $preparedGifts, $operationId, $commandKey,
                $recorder, $execution, (array)($scope['event_contract'] ?? []), $operator, $dataScope, $now, $input['reason']);
        }
        $row = [
            'operation_id' => $operationId, 'operation_no' => $operationNo, 'tenant_id' => $dataScope->tenantId(),
            'store_id' => $operator->storeId(), 'member_id' => $source['memberId'], 'operator_id' => $operator->operatorId(),
            'source_type' => 'recharge', 'source_order_id' => (string)$source['rechargeId'], 'source_order_no_snapshot' => $source['orderNo'],
            'operation_type' => $operationType, 'command_idempotency_key' => $commandKey, 'immutable_fingerprint' => $fingerprint,
            'reason_snapshot' => $input['reason'], 'request_json' => json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            // Refund statistics read this amount only. A void reverses facts but is not a cash refund statistic.
            'business_event_no' => (string)$event['event_no'], 'amount_cents' => $input['cashRefundCents'], 'cash_refund_cents' => $input['cashRefundCents'],
            'deducted_principal_cents' => $input['principalDebitCents'], 'deducted_bonus_cents' => $input['bonusDebitCents'],
            'status' => 'succeeded', 'version' => 1,
            'business_date' => date('Y-m-d', $now), 'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ];
        if ((int)Db::name(self::OPERATION_TABLE)->insert($row) !== 1) throw self::failure('recharge_lifecycle_operation_insert_failed');
        return $this->result($row, false);
    }

    /** @return array<string,mixed> */
    private function source(array $payload, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope, bool $lock): array
    {
        $id = (int)($payload['rechargeId'] ?? $payload['sourceRechargeId'] ?? 0);
        if ($id <= 0) throw self::failure('recharge_lifecycle_identity_missing');
        $query = Db::name('user_recharge')->where('id', $id)->where('store_id', $operator->storeId())->where('paid', 1);
        if ($lock) $query->lock(true);
        $recharge = (array)$query->find();
        if (!$recharge || (int)($recharge['uid'] ?? 0) <= 0) throw self::failure('recharge_lifecycle_source_unavailable');
        $orderId = 'RCH:' . $id;
        $balanceQuery = Db::name('cashier_v3_balance_fact')->where('tenant_id', $scope->tenantId())->where('order_id', $orderId)
            ->where('source_document_type', 'recharge')->where('fact_direction', 'forward')->where('status', 'effective');
        if ($lock) $balanceQuery->lock(true);
        $balanceFacts = $balanceQuery->order('id', 'asc')->select()->toArray();
        if ($balanceFacts === []) throw self::failure('recharge_lifecycle_not_v3_authority');
        $principal = 0; $bonus = 0;
        foreach ($balanceFacts as $fact) { $principal += (int)$fact['principal_delta_cents']; $bonus += (int)$fact['bonus_delta_cents']; }
        // A recharge has one authoritative account credit.  Reversing multiple
        // rows without a component allocation would over-deduct the member.
        if (count($balanceFacts) !== 1) throw self::failure('recharge_lifecycle_balance_allocation_required');
        if ($principal < 0 || $bonus < 0 || $principal + $bonus <= 0) throw self::failure('recharge_lifecycle_balance_authority_invalid');
        return ['rechargeId' => $id, 'orderId' => $orderId, 'orderNo' => (string)$recharge['order_id'], 'memberId' => (int)$recharge['uid'], 'storeId' => (int)$recharge['store_id'], 'storeName' => (string)Db::name('system_store')->where('id', $operator->storeId())->value('name'), 'principalCents' => $principal, 'bonusCents' => $bonus, 'balanceFacts' => $balanceFacts];
    }

    /** @return array{reason:string,cashRefundCents:int,principalDebitCents:int,bonusDebitCents:int} */
    private function input(string $action, array $payload, array $source): array
    {
        $reason = trim((string)($payload['reason'] ?? ''));
        if ($reason === '' || mb_strlen($reason) > 255) throw self::failure('recharge_lifecycle_reason_invalid');
        if ($action === 'void-recharge-order') {
            return ['reason' => $reason, 'cashRefundCents' => 0, 'principalDebitCents' => $source['principalCents'], 'bonusDebitCents' => $source['bonusCents']];
        }
        $cash = $this->cents($payload['cashRefundAmount'] ?? $payload['refundAmount'] ?? '');
        $principal = $this->cents($payload['principalRefundAmount'] ?? '0');
        $bonus = $this->cents($payload['bonusRefundAmount'] ?? '0');
        if ($cash < 0 || $cash > $source['principalCents'] || $principal < 0 || $bonus < 0
            || $cash + $principal + $bonus <= 0 || $principal > $source['principalCents'] || $bonus > $source['bonusCents']) throw self::failure('recharge_lifecycle_refund_amount_invalid');
        return ['reason' => $reason, 'cashRefundCents' => $cash, 'principalDebitCents' => $principal, 'bonusDebitCents' => $bonus];
    }

    private function assertEligible(array $source, CashierV3DataScopeContext $scope): void
    {
        if (Db::name(self::OPERATION_TABLE)->where('tenant_id', $scope->tenantId())->where('source_type', 'recharge')->where('source_order_id', (string)$source['rechargeId'])->whereIn('operation_type', ['refund', 'void'])->where('status', 'succeeded')->count() > 0) throw self::failure('recharge_lifecycle_already_reversed');
    }

    private function writeReversals(array $source, array $input, string $operationType, string $operationId, string $commandKey, array $event, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope, int $now, array $balance): void
    {
        $paymentRows = Db::name('cashier_v3_payment_fact')->where('tenant_id', $scope->tenantId())->where('order_id', $source['orderId'])->where('source_document_type', 'recharge')->where('fact_direction', 'forward')->where('status', 'effective')->lock(true)->select()->toArray();
        $totalCash = 0;
        foreach ($paymentRows as $row) $totalCash += (int)$row['amount_cents'];
        if ($input['cashRefundCents'] > $totalCash) {
            throw self::failure('recharge_lifecycle_cash_refund_exceeds_collected');
        }
        $paymentReversals = $operationType === 'refund'
            ? $this->allocateBySource($paymentRows, (int)$input['cashRefundCents'])
            : array_map(static function (array $row): int { return (int)$row['amount_cents']; }, $paymentRows);
        foreach ($paymentRows as $index => $row) {
            $amount = -(int)$paymentReversals[$index];
            if ($amount !== 0) $this->reverseFact('cashier_v3_payment_fact', $row, $operationId, $commandKey, $event, $operator, $now, $amount);
        }
        if ((int)$input['principalDebitCents'] + (int)$input['bonusDebitCents'] > 0) {
            foreach ($source['balanceFacts'] as $row) {
                $this->reverseBalanceFact($row, $operationId, $commandKey, $event, $operator, $now, $input, $balance);
            }
        }
        $performanceRows = Db::name('cashier_v3_performance_fact')->where('tenant_id', $scope->tenantId())->where('order_id', $source['orderId'])->where('source_document_type', 'recharge')->where('fact_direction', 'forward')->where('status', 'effective')->lock(true)->select()->toArray();
        $performanceGroups = [];
        foreach ($performanceRows as $index => $row) {
            $performanceGroups[(string)($row['performance_type'] ?? '')][$index] = $row;
        }
        foreach ($performanceGroups as $group) {
            $performanceReversals = $operationType === 'refund'
                ? $this->allocateBySource($group, (int)$input['cashRefundCents'], $totalCash)
                : array_map(static function (array $row): int { return (int)$row['amount_cents']; }, $group);
            foreach ($group as $index => $row) {
                $amount = -(int)$performanceReversals[$index];
                if ($amount !== 0) $this->reverseFact('cashier_v3_performance_fact', $row, $operationId, $commandKey, $event, $operator, $now, $amount);
            }
        }
    }

    private function reverseFact(string $table, array $source, string $operationId, string $commandKey, array $event, CashierV3OperatorScope $operator, int $now, int $amount): void
    {
        $row = $source; unset($row['id']);
        $row['fact_id'] = 'RLR-' . strtoupper(substr(hash_hmac('sha256', $table . '|' . $source['fact_id'] . '|' . $operationId, $this->secret()), 0, 40));
        $row['business_event_no'] = (string)$event['event_no']; $row['fact_direction'] = 'reversal'; $row['natural_key'] = 'recharge_lifecycle:' . hash('sha256', $table . '|' . $source['fact_id'] . '|' . $operationId); $row['command_idempotency_key'] = $commandKey; $row['fact_version'] = 1; $row['reversal_of'] = (string)$source['fact_id']; $row['operator_id'] = $operator->operatorId(); $row['business_date'] = date('Y-m-d', $now); $row['occurred_at'] = $now; $row['settled_at'] = $now; $row['recorded_at'] = $now;
        $row['amount_cents'] = $amount; $row['immutable_fingerprint'] = hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ((int)Db::name($table)->insert($row) !== 1) throw self::failure('recharge_lifecycle_fact_reversal_insert_failed');
    }

    private function reverseBalanceFact(array $source, string $operationId, string $commandKey, array $event, CashierV3OperatorScope $operator, int $now, array $input, array $balance): void
    {
        $row = $source; unset($row['id']);
        $row['fact_id'] = 'RLB-' . strtoupper(substr(hash_hmac('sha256', $source['fact_id'] . '|' . $operationId, $this->secret()), 0, 40));
        $row['business_event_no'] = (string)$event['event_no']; $row['fact_direction'] = 'reversal'; $row['natural_key'] = 'recharge_lifecycle:' . hash('sha256', 'balance|' . $source['fact_id'] . '|' . $operationId); $row['command_idempotency_key'] = $commandKey; $row['fact_version'] = 1; $row['reversal_of'] = (string)$source['fact_id']; $row['operator_id'] = $operator->operatorId(); $row['business_date'] = date('Y-m-d', $now); $row['occurred_at'] = $now; $row['settled_at'] = $now; $row['recorded_at'] = $now;
        // Keep the original metric dimension and represent the correction via
        // its reversal link and signed balance deltas.  That lets recharge
        // statistics net the forward credit with this adjustment.
        $row['balance_change_type'] = (string)$source['balance_change_type'];
        $row['principal_delta_cents'] = -(int)$input['principalDebitCents']; $row['bonus_delta_cents'] = -(int)$input['bonusDebitCents'];
        $row['balance_account_id'] = (string)$balance['accountId'];
        $row['account_version'] = (int)$balance['accountVersionAfter'];
        $row['principal_after_cents'] = (int)$balance['after']['principalCents'];
        $row['bonus_after_cents'] = (int)$balance['after']['giftCents'];
        $row['immutable_fingerprint'] = hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ((int)Db::name('cashier_v3_balance_fact')->insert($row) !== 1) throw self::failure('recharge_lifecycle_balance_reversal_insert_failed');
    }

    /**
     * Splits a refund across original fact rows without losing cents.  Ties are
     * resolved by source order, so a replay always has the identical mapping.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,int>
     */
    private function allocateBySource(array $rows, int $amount, int $total = 0): array
    {
        if ($amount <= 0 || $rows === []) return array_fill(0, count($rows), 0);
        if ($total <= 0) foreach ($rows as $row) $total += (int)($row['amount_cents'] ?? 0);
        if ($total <= 0 || $amount > $total) throw self::failure('recharge_lifecycle_allocation_invalid');
        $allocations = [];
        $allocated = 0;
        $sourceTotal = 0;
        foreach ($rows as $index => $row) {
            $source = (int)($row['amount_cents'] ?? 0);
            if ($source < 0) throw self::failure('recharge_lifecycle_allocation_invalid');
            $numerator = bcmul((string)$source, (string)$amount, 0);
            $base = (int)bcdiv($numerator, (string)$total, 0);
            $allocations[$index] = ['amount' => $base, 'remainder' => (int)bcmod($numerator, (string)$total), 'factId' => (string)($row['fact_id'] ?? '')];
            $allocated += $base;
            $sourceTotal += $source;
        }
        if ($sourceTotal > $total) throw self::failure('recharge_lifecycle_allocation_invalid');
        uasort($allocations, static function (array $left, array $right): int {
            $remainder = $right['remainder'] <=> $left['remainder'];
            return $remainder !== 0 ? $remainder : strcmp($left['factId'], $right['factId']);
        });
        $target = (int)bcdiv(
            bcadd(bcmul((string)$sourceTotal, (string)$amount, 0), bcdiv((string)$total, '2', 0), 0),
            (string)$total,
            0
        );
        $remaining = $target - $allocated;
        foreach ($allocations as &$allocation) {
            if ($remaining <= 0) break;
            $allocation['amount']++;
            $remaining--;
        }
        unset($allocation);
        ksort($allocations);
        return array_map(static function (array $allocation): int { return (int)$allocation['amount']; }, $allocations);
    }

    /**
     * Makes the legacy atomic ledger change visible to the V3 account-version
     * authority. The command policy will later supply its expected version;
     * this local guard remains mandatory so fact rows can never claim the
     * original recharge version after a real balance mutation.
     */
    private function deductBalanceInTx(array $source, array $input, string $operationType, string $commandKey, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): array
    {
        $provider = new CashierV3MemberBalanceProvider();
        $before = $provider->lockSnapshotInTx((int)$source['memberId'], $operator, $scope);
        if ((int)$input['principalDebitCents'] === 0 && (int)$input['bonusDebitCents'] === 0) {
            return [
                'accountId' => (string)$before['accountId'],
                'accountVersionBefore' => (int)$before['accountVersion'],
                'accountVersionAfter' => (int)$before['accountVersion'],
                'after' => [
                    'principalCents' => (int)$before['principalCents'],
                    'giftCents' => (int)$before['giftCents'],
                    'totalCents' => (int)$before['totalCents'],
                ],
            ];
        }
        try {
            $result = (new UserBalanceAtomicServices())->deductBenGive(
                (int)$source['memberId'], $this->money((int)$input['principalDebitCents']), $this->money((int)$input['bonusDebitCents']),
                $operationType === 'refund' ? 'user_recharge_refund' : 'user_recharge_void', (int)$source['rechargeId'],
                $operationType === 'refund' ? '收银V3充值退款扣回余额' : '收银V3充值作废扣回余额',
                'cv3-recharge-lifecycle-' . hash('sha256', $scope->tenantId() . '|' . $commandKey)
            );
        } catch (ValidateException $exception) {
            throw self::failure('recharge_lifecycle_balance_deduction_failed');
        }
        $after = $provider->lockSnapshotInTx((int)$source['memberId'], $operator, $scope);
        $idempotent = !empty($result['idempotent']);
        $expectedVersion = $idempotent ? (int)$before['accountVersion'] : (int)$before['accountVersion'] + 1;
        if ((int)$after['accountVersion'] !== $expectedVersion
            || $this->cents($result['after']['ben'] ?? '') !== (int)$after['principalCents']
            || $this->cents($result['after']['give'] ?? '') !== (int)$after['giftCents']) {
            throw self::failure('recharge_lifecycle_balance_version_invalid');
        }
        return [
            'accountId' => (string)$after['accountId'],
            'accountVersionBefore' => (int)$before['accountVersion'],
            'accountVersionAfter' => (int)$after['accountVersion'],
            'after' => [
                'principalCents' => (int)$after['principalCents'],
                'giftCents' => (int)$after['giftCents'],
                'totalCents' => (int)$after['totalCents'],
            ],
        ];
    }

    private function cents($value): int { $raw = trim((string)$value); if (preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/D', $raw) !== 1) throw self::failure('recharge_lifecycle_money_invalid'); [$whole, $decimal] = array_pad(explode('.', $raw, 2), 2, ''); return (int)$whole * 100 + (int)str_pad($decimal, 2, '0'); }
    private function money(int $cents): string { return bcdiv((string)$cents, '100', 2); }
    private function secret(): string { $secret = trim((string)config('cashier_v3.checkout_namespace_secret')); if (strlen($secret) < 32) throw self::failure('recharge_lifecycle_secret_missing'); return $secret; }
    private function result(array $row, bool $replayed): array { $touched = ['recharge_order']; if ((int)($row['deducted_principal_cents'] ?? 0) + (int)($row['deducted_bonus_cents'] ?? 0) > 0) $touched[] = 'member_balance'; return ['operationId' => (string)$row['operation_id'], 'operationNo' => (string)$row['operation_no'], 'operationType' => (string)$row['operation_type'], 'sourceOrderNo' => (string)$row['source_order_no_snapshot'], 'cashRefundCents' => (int)($row['cash_refund_cents'] ?? $row['amount_cents']), 'status' => (string)$row['status'], 'replayed' => $replayed, 'touchedRoles' => $touched, 'message' => '充值订单操作已完成。']; }
    private static function failure(string $reason): CashierV3CommandException { return new CashierV3CommandException(CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE, '充值订单资料、余额或后续权益状态不满足本次操作条件，未提交任何变更。', CashierV3ResultCode::STATUS_FAILED, ['reason' => $reason]); }
}
