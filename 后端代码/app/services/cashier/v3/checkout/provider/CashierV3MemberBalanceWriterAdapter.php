<?php
declare(strict_types=1);

namespace app\services\cashier\v3\checkout\provider;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\user\UserBalanceAtomicServices;
use think\exception\ValidateException;
use think\facade\Db;

/**
 * Final-checkout balance deduction adapter. The caller owns the outer business
 * transaction; this adapter never commits independently.
 */
final class CashierV3MemberBalanceWriterAdapter
{
    public const CONTRACT_VERSION = 'cashier-v3-member-balance-writer-v1';
    public const PAYMENT_MODE_BALANCE_ONLY = 'balance_only';
    public const PAYMENT_MODE_COMBINED = 'combined';
    private const MAX_AMOUNT_CENTS = 9999999999;

    /** @var CashierV3MemberBalanceProvider */
    private $provider;

    /** @var UserBalanceAtomicServices|null */
    private $atomic;

    public function __construct(
        CashierV3MemberBalanceProvider $provider = null,
        UserBalanceAtomicServices $atomic = null
    ) {
        $this->provider = $provider ?: new CashierV3MemberBalanceProvider();
        $this->atomic = $atomic;
    }

    public function contractVersion(): string
    {
        return self::CONTRACT_VERSION;
    }

    public function readinessStatus(): array
    {
        $provider = $this->provider->readinessStatus();
        $ledger = $this->ledgerSchemaStatus();
        $atomicReady = class_exists(UserBalanceAtomicServices::class)
            && method_exists(UserBalanceAtomicServices::class, 'deductPreferBen');
        $ready = !empty($provider['ready']) && $ledger['ready'] && $atomicReady;
        $reasons = [];
        if (empty($provider['ready'])) {
            $reasons[] = 'member_balance_provider_not_ready';
        }
        if (!$ledger['ready']) {
            $reasons[] = 'member_balance_ledger_not_ready';
        }
        if (!$atomicReady) {
            $reasons[] = 'member_balance_atomic_service_not_ready';
        }
        return [
            'dependency' => 'member_balance_writer',
            'contractVersion' => self::CONTRACT_VERSION,
            'ready' => $ready,
            'reasons' => $reasons,
            'provider' => $provider,
            'ledgerSchema' => $ledger,
            'outerTransactionRequired' => true,
            'gatewayActivationPerformed' => false,
        ];
    }

    /**
     * @param array{
     *   memberId:int,expectedVersion:int,amountCents:int,sourceOrderId:int,
     *   commandIdempotencyKey:string,paymentMode:string
     * } $request
     */
    public function deductCheckoutInTx(
        array $request,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        CashierV3TransactionGuard::assertInTransaction('memberBalanceCheckoutDeduct');
        $request = $this->normalizeRequest($request);
        if (empty($this->readinessStatus()['ready'])) {
            throw self::failure('member_balance_writer_not_ready');
        }

        $before = $this->provider->lockSnapshotInTx(
            $request['memberId'],
            $operatorScope,
            $dataScope
        );
        if ($before['accountVersion'] !== $request['expectedVersion']) {
            throw self::failure('member_balance_version_conflict', [
                'expectedVersion' => $request['expectedVersion'],
                'currentVersion' => $before['accountVersion'],
            ]);
        }
        $ledgerKey = $this->ledgerIdempotencyKey(
            $dataScope->tenantId(),
            $request['commandIdempotencyKey']
        );
        $billType = $request['paymentMode'] === self::PAYMENT_MODE_COMBINED
            ? 'pay_combination'
            : 'pay_product';
        try {
            $result = $this->atomicService()->deductPreferBen(
                $request['memberId'],
                $this->centsToMoney($request['amountCents']),
                $billType,
                $request['sourceOrderId'],
                '收银V3余额扣款',
                $ledgerKey
            );
        } catch (ValidateException $exception) {
            throw $this->mapAtomicFailure($exception);
        } catch (\Throwable $exception) {
            throw self::failure('member_balance_atomic_write_failed', [
                'exception' => get_class($exception),
            ]);
        }

        $after = $this->provider->lockSnapshotInTx(
            $request['memberId'],
            $operatorScope,
            $dataScope
        );
        $idempotent = !empty($result['idempotent']);
        $expectedAfterVersion = $idempotent
            ? $before['accountVersion']
            : $before['accountVersion'] + 1;
        if ($after['accountVersion'] !== $expectedAfterVersion) {
            throw self::failure('member_balance_version_advance_invalid', [
                'versionBefore' => $before['accountVersion'],
                'versionAfter' => $after['accountVersion'],
                'idempotentReplay' => $idempotent,
            ]);
        }

        $mutation = $this->normalizeAtomicResult($result);
        if ($mutation['after']['principalCents'] !== $after['principalCents']
            || $mutation['after']['giftCents'] !== $after['giftCents']
            || $mutation['after']['totalCents'] !== $after['totalCents']) {
            throw self::failure('member_balance_replay_state_diverged');
        }
        if (!$idempotent
            && ($mutation['before']['principalCents'] !== $before['principalCents']
                || $mutation['before']['giftCents'] !== $before['giftCents']
                || $mutation['before']['totalCents'] !== $before['totalCents'])) {
            throw self::failure('member_balance_locked_snapshot_changed');
        }

        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'authorityContractVersion' => CashierV3MemberBalanceProvider::CONTRACT_VERSION,
            'resourceKind' => CashierV3MemberBalanceProvider::KIND,
            'authorityKey' => $after['authorityKey'],
            'accountId' => $after['accountId'],
            'memberId' => $request['memberId'],
            'tenantId' => $after['tenantId'],
            'storeId' => $after['storeId'],
            'operatorId' => $operatorScope->operatorId(),
            'accountVersionBefore' => $before['accountVersion'],
            'accountVersionAfter' => $after['accountVersion'],
            'before' => $mutation['before'],
            'change' => $mutation['change'],
            'after' => $mutation['after'],
            'deductedPrincipalCents' => -$mutation['change']['principalCents'],
            'deductedGiftCents' => -$mutation['change']['giftCents'],
            'deductedTotalCents' => -$mutation['change']['totalCents'],
            'ledgerId' => $mutation['ledgerId'],
            'ledgerType' => $billType,
            'ledgerIdempotencyKey' => $ledgerKey,
            'idempotentReplay' => $idempotent,
            'versionAdvanceOwnedBy' => 'eb_user_balance_version_bu',
        ];
    }

    /**
     * Credits a confirmed recharge into the authoritative principal/bonus
     * balance split. The recharge order row is created by the caller first,
     * then this method makes the real balance mutation and immutable ledger
     * entry in the same outer transaction.
     *
     * @param array{memberId:int,expectedVersion:int,principalCents:int,bonusCents:int,sourceRechargeId:int,commandIdempotencyKey:string} $request
     */
    public function creditRechargeInTx(
        array $request,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        CashierV3TransactionGuard::assertInTransaction('memberBalanceRechargeCredit');
        $request = $this->normalizeRechargeCreditRequest($request);
        if (empty($this->readinessStatus()['ready'])) {
            throw self::failure('member_balance_writer_not_ready');
        }

        $before = $this->provider->lockSnapshotInTx($request['memberId'], $operatorScope, $dataScope);
        if ($before['accountVersion'] !== $request['expectedVersion']) {
            throw self::failure('member_balance_version_conflict', [
                'expectedVersion' => $request['expectedVersion'],
                'currentVersion' => $before['accountVersion'],
            ]);
        }
        $ledgerKey = $this->ledgerIdempotencyKey($dataScope->tenantId(), $request['commandIdempotencyKey']);
        try {
            $result = $this->atomicService()->creditBenGive(
                $request['memberId'],
                $this->centsToMoney($request['principalCents']),
                $this->centsToMoney($request['bonusCents']),
                'user_recharge',
                $request['sourceRechargeId'],
                '收银V3会员储值',
                $ledgerKey
            );
        } catch (ValidateException $exception) {
            throw $this->mapAtomicFailure($exception);
        } catch (\Throwable $exception) {
            throw self::failure('member_balance_atomic_write_failed', ['exception' => get_class($exception)]);
        }

        $after = $this->provider->lockSnapshotInTx($request['memberId'], $operatorScope, $dataScope);
        $idempotent = !empty($result['idempotent']);
        $expectedAfterVersion = $idempotent ? $before['accountVersion'] : $before['accountVersion'] + 1;
        if ($after['accountVersion'] !== $expectedAfterVersion) {
            throw self::failure('member_balance_version_advance_invalid', [
                'versionBefore' => $before['accountVersion'],
                'versionAfter' => $after['accountVersion'],
                'idempotentReplay' => $idempotent,
            ]);
        }
        $mutation = $this->normalizeRechargeCreditResult($result);
        if ($mutation['after']['principalCents'] !== $after['principalCents']
            || $mutation['after']['giftCents'] !== $after['giftCents']
            || $mutation['after']['totalCents'] !== $after['totalCents']) {
            throw self::failure('member_balance_replay_state_diverged');
        }
        if (!$idempotent
            && ($mutation['before']['principalCents'] !== $before['principalCents']
                || $mutation['before']['giftCents'] !== $before['giftCents']
                || $mutation['before']['totalCents'] !== $before['totalCents'])) {
            throw self::failure('member_balance_locked_snapshot_changed');
        }
        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'authorityContractVersion' => CashierV3MemberBalanceProvider::CONTRACT_VERSION,
            'resourceKind' => CashierV3MemberBalanceProvider::KIND,
            'authorityKey' => $after['authorityKey'],
            'accountId' => $after['accountId'],
            'memberId' => $request['memberId'],
            'tenantId' => $after['tenantId'],
            'storeId' => $after['storeId'],
            'operatorId' => $operatorScope->operatorId(),
            'accountVersionBefore' => $before['accountVersion'],
            'accountVersionAfter' => $after['accountVersion'],
            'before' => $mutation['before'],
            'change' => $mutation['change'],
            'after' => $mutation['after'],
            'ledgerId' => $mutation['ledgerId'],
            'ledgerType' => 'user_recharge',
            'ledgerIdempotencyKey' => $ledgerKey,
            'idempotentReplay' => $idempotent,
            'versionAdvanceOwnedBy' => 'eb_user_balance_version_bu',
        ];
    }

    public function ledgerIdempotencyKey(string $tenantId, string $commandIdempotencyKey): string
    {
        $tenantId = trim($tenantId);
        $commandIdempotencyKey = trim($commandIdempotencyKey);
        if ($tenantId === ''
            || strlen($tenantId) > 32
            || preg_match('/^[A-Za-z0-9_.:-]+$/D', $tenantId) !== 1
            || $commandIdempotencyKey === ''
            || strlen($commandIdempotencyKey) > 128
            || preg_match('/^[A-Za-z0-9_.:-]+$/D', $commandIdempotencyKey) !== 1) {
            throw self::failure('member_balance_idempotency_key_invalid');
        }
        return 'cv3-balance-' . hash('sha256', $tenantId . '|' . $commandIdempotencyKey);
    }

    private function normalizeRequest(array $request): array
    {
        $keys = array_keys($request);
        sort($keys, SORT_STRING);
        $expectedKeys = [
            'amountCents', 'commandIdempotencyKey', 'expectedVersion',
            'memberId', 'paymentMode', 'sourceOrderId',
        ];
        sort($expectedKeys, SORT_STRING);
        if ($keys !== $expectedKeys) {
            throw self::failure('member_balance_request_shape_invalid');
        }
        foreach (['memberId', 'expectedVersion', 'amountCents', 'sourceOrderId'] as $field) {
            if (!is_int($request[$field])) {
                throw self::failure('member_balance_request_field_invalid', ['field' => $field]);
            }
        }
        if ($request['memberId'] <= 0
            || $request['expectedVersion'] <= 0
            || $request['amountCents'] <= 0
            || $request['amountCents'] > self::MAX_AMOUNT_CENTS
            || $request['sourceOrderId'] <= 0
            || !in_array($request['paymentMode'], [
                self::PAYMENT_MODE_BALANCE_ONLY,
                self::PAYMENT_MODE_COMBINED,
            ], true)) {
            throw self::failure('member_balance_request_value_invalid');
        }
        // Validate now; the derived key is calculated again only from these server values.
        $this->ledgerIdempotencyKey('validation', (string)$request['commandIdempotencyKey']);
        return $request;
    }

    private function normalizeRechargeCreditRequest(array $request): array
    {
        $keys = array_keys($request);
        sort($keys, SORT_STRING);
        $expected = ['bonusCents', 'commandIdempotencyKey', 'expectedVersion', 'memberId', 'principalCents', 'sourceRechargeId'];
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw self::failure('member_balance_recharge_request_shape_invalid');
        }
        foreach (['memberId', 'expectedVersion', 'principalCents', 'bonusCents', 'sourceRechargeId'] as $field) {
            if (!is_int($request[$field])) {
                throw self::failure('member_balance_recharge_request_field_invalid', ['field' => $field]);
            }
        }
        if ($request['memberId'] <= 0 || $request['expectedVersion'] <= 0
            || $request['principalCents'] < 0 || $request['bonusCents'] < 0
            || $request['principalCents'] + $request['bonusCents'] <= 0
            || $request['principalCents'] + $request['bonusCents'] > self::MAX_AMOUNT_CENTS
            || $request['sourceRechargeId'] <= 0) {
            throw self::failure('member_balance_recharge_request_value_invalid');
        }
        $this->ledgerIdempotencyKey('validation', (string)$request['commandIdempotencyKey']);
        return $request;
    }

    private function normalizeAtomicResult(array $result): array
    {
        foreach (['before', 'after', 'change_ben', 'change_give', 'total', 'money_id'] as $key) {
            if (!array_key_exists($key, $result)) {
                throw self::failure('member_balance_atomic_result_incomplete');
            }
        }
        if (!is_array($result['before']) || !is_array($result['after'])) {
            throw self::failure('member_balance_atomic_result_incomplete');
        }
        $before = [
            'principalCents' => $this->moneyToCents($result['before']['ben'] ?? null, false),
            'giftCents' => $this->moneyToCents($result['before']['give'] ?? null, false),
            'totalCents' => $this->moneyToCents($result['before']['total'] ?? null, false),
        ];
        $after = [
            'principalCents' => $this->moneyToCents($result['after']['ben'] ?? null, false),
            'giftCents' => $this->moneyToCents($result['after']['give'] ?? null, false),
            'totalCents' => $this->moneyToCents($result['after']['total'] ?? null, false),
        ];
        $change = [
            'principalCents' => $this->moneyToCents($result['change_ben'], true),
            'giftCents' => $this->moneyToCents($result['change_give'], true),
            'totalCents' => -$this->moneyToCents($result['total'], false),
        ];
        if ($before['principalCents'] + $before['giftCents'] !== $before['totalCents']
            || $after['principalCents'] + $after['giftCents'] !== $after['totalCents']
            || $before['principalCents'] + $change['principalCents'] !== $after['principalCents']
            || $before['giftCents'] + $change['giftCents'] !== $after['giftCents']
            || $before['totalCents'] + $change['totalCents'] !== $after['totalCents']
            || $change['principalCents'] > 0
            || $change['giftCents'] > 0
            || $change['totalCents'] >= 0) {
            throw self::failure('member_balance_atomic_result_invalid');
        }
        $ledgerId = (int)$result['money_id'];
        if ($ledgerId <= 0) {
            throw self::failure('member_balance_atomic_result_incomplete');
        }
        return compact('before', 'change', 'after') + ['ledgerId' => $ledgerId];
    }

    private function normalizeRechargeCreditResult(array $result): array
    {
        foreach (['before', 'after', 'change_ben', 'change_give', 'total', 'money_id'] as $key) {
            if (!array_key_exists($key, $result) || !is_array($result['before']) || !is_array($result['after'])) {
                throw self::failure('member_balance_atomic_result_incomplete');
            }
        }
        $before = [
            'principalCents' => $this->moneyToCents($result['before']['ben'] ?? null, false),
            'giftCents' => $this->moneyToCents($result['before']['give'] ?? null, false),
            'totalCents' => $this->moneyToCents($result['before']['total'] ?? null, false),
        ];
        $after = [
            'principalCents' => $this->moneyToCents($result['after']['ben'] ?? null, false),
            'giftCents' => $this->moneyToCents($result['after']['give'] ?? null, false),
            'totalCents' => $this->moneyToCents($result['after']['total'] ?? null, false),
        ];
        $change = [
            'principalCents' => $this->moneyToCents($result['change_ben'], false),
            'giftCents' => $this->moneyToCents($result['change_give'], false),
            'totalCents' => $this->moneyToCents($result['total'], false),
        ];
        if ($before['principalCents'] + $before['giftCents'] !== $before['totalCents']
            || $after['principalCents'] + $after['giftCents'] !== $after['totalCents']
            || $before['principalCents'] + $change['principalCents'] !== $after['principalCents']
            || $before['giftCents'] + $change['giftCents'] !== $after['giftCents']
            || $before['totalCents'] + $change['totalCents'] !== $after['totalCents']
            || $change['principalCents'] < 0 || $change['giftCents'] < 0 || $change['totalCents'] <= 0) {
            throw self::failure('member_balance_atomic_result_invalid');
        }
        $ledgerId = (int)$result['money_id'];
        if ($ledgerId <= 0) {
            throw self::failure('member_balance_atomic_result_incomplete');
        }
        return compact('before', 'change', 'after') + ['ledgerId' => $ledgerId];
    }

    private function ledgerSchemaStatus(): array
    {
        $required = [
            'id', 'uid', 'link_id', 'type', 'title', 'number', 'balance', 'mark',
            'pm', 'status', 'ben_money', 'give_money', 'ben_change_amount',
            'give_change_amount', 'idempotency_key', 'idempotency_fingerprint', 'add_time',
        ];
        try {
            $rows = Db::query(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS'
                . ' WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=\'eb_user_money\''
            );
            $indexes = Db::query(
                'SELECT INDEX_NAME,NON_UNIQUE,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_list,'
                . ' SUM(CASE WHEN SUB_PART IS NULL THEN 0 ELSE 1 END) AS prefix_parts'
                . ' FROM information_schema.STATISTICS'
                . ' WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=\'eb_user_money\''
                . ' GROUP BY INDEX_NAME,NON_UNIQUE'
            );
        } catch (\Throwable $exception) {
            return ['ready' => false, 'missingColumns' => $required, 'uniqueIdempotencyKey' => false];
        }
        $present = [];
        foreach ((array)$rows as $row) {
            $present[(string)($row['COLUMN_NAME'] ?? $row['column_name'] ?? '')] = true;
        }
        $missing = [];
        foreach ($required as $column) {
            if (!isset($present[$column])) {
                $missing[] = $column;
            }
        }
        $unique = false;
        foreach ((array)$indexes as $index) {
            if ((int)($index['NON_UNIQUE'] ?? $index['non_unique'] ?? 1) === 0
                && (string)($index['columns_list'] ?? '') === 'idempotency_key'
                && (int)($index['prefix_parts'] ?? 0) === 0) {
                $unique = true;
            }
        }
        return [
            'ready' => $missing === [] && $unique,
            'missingColumns' => $missing,
            'uniqueIdempotencyKey' => $unique,
        ];
    }

    private function atomicService(): UserBalanceAtomicServices
    {
        if (!$this->atomic) {
            $service = app()->make(UserBalanceAtomicServices::class);
            if (!$service instanceof UserBalanceAtomicServices) {
                throw self::failure('member_balance_atomic_service_not_ready');
            }
            $this->atomic = $service;
        }
        return $this->atomic;
    }

    private function centsToMoney(int $cents): string
    {
        return intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private function moneyToCents($value, bool $signed): int
    {
        if (!is_string($value) && !is_int($value)) {
            throw self::failure('member_balance_atomic_money_invalid');
        }
        $raw = trim((string)$value);
        $pattern = $signed
            ? '/^(-?)(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?$/D'
            : '/^(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?$/D';
        if (preg_match($pattern, $raw, $matches) !== 1) {
            throw self::failure('member_balance_atomic_money_invalid');
        }
        if ($signed) {
            $negative = $matches[1] === '-';
            $whole = (int)$matches[2];
            $fractionRaw = $matches[3] ?? '';
        } else {
            $negative = false;
            $whole = (int)$matches[1];
            $fractionRaw = $matches[2] ?? '';
        }
        if ($whole > 99999999) {
            throw self::failure('member_balance_atomic_money_invalid');
        }
        $cents = ($whole * 100) + (int)str_pad($fractionRaw, 2, '0');
        return $negative ? -$cents : $cents;
    }

    private function mapAtomicFailure(ValidateException $exception): CashierV3MemberBalanceContractException
    {
        $message = $exception->getMessage();
        if (strpos($message, '余额不足') !== false) {
            return self::failure('member_balance_insufficient');
        }
        if (strpos($message, '幂等') !== false || strpos($message, '原业务不一致') !== false) {
            return self::failure('member_balance_idempotency_conflict');
        }
        if (strpos($message, '余额数据异常') !== false || strpos($message, '先核对') !== false) {
            return self::failure('member_balance_invariant_invalid');
        }
        return self::failure('member_balance_atomic_write_failed');
    }

    private static function failure(string $reason, array $detail = []): CashierV3MemberBalanceContractException
    {
        return new CashierV3MemberBalanceContractException($reason, $detail);
    }
}
