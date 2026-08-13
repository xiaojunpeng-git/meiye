<?php

namespace app\services\cashier\v3\settlement;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\CashierV3CheckoutWorkspaceIdentity;
use app\services\cashier\v3\checkout\provider\CashierV3MemberBalanceContractException;
use app\services\cashier\v3\checkout\provider\CashierV3MemberBalanceProvider;
use think\facade\Db;

/**
 * Adds the real member-balance row to a final checkout resource plan.
 *
 * A balance draft is only an intention. This discoverer runs inside the
 * Gateway transaction immediately before final-preparation locks are checked;
 * it freezes the real account version and rejects any edited or stale draft.
 * No money is changed here.
 */
final class CashierV3CheckoutBalanceAuthorityDiscovery
{
    public const CONTRACT_VERSION = 'cashier-v3-checkout-balance-discovery-v1';

    private const ACTION_PREPARE_SUBMISSION = 'prepare-checkout-submission';
    private const ACTION_APPLY = 'apply-balance-payment';
    private const ACTION_UPDATE = 'update-balance-payment';

    /** @var CashierV3MemberBalanceProvider */
    private $balances;

    public function __construct(?CashierV3MemberBalanceProvider $balances = null)
    {
        $this->balances = $balances ?: new CashierV3MemberBalanceProvider();
    }

    public function discover(array $scope): array
    {
        CashierV3TransactionGuard::assertInTransaction('checkoutBalanceDiscovery');
        try {
            $action = (string)($scope['action'] ?? '');
            if (!in_array($action, [
                self::ACTION_PREPARE_SUBMISSION,
                self::ACTION_APPLY,
                self::ACTION_UPDATE,
            ], true)) {
                throw self::failure('checkout_balance_discovery_action_invalid');
            }
            $operator = $scope['operator_scope'] ?? null;
            $dataScope = $scope['data_scope'] ?? null;
            if (!($operator instanceof CashierV3OperatorScope)
                || !($dataScope instanceof CashierV3DataScopeContext)) {
                throw self::failure('checkout_balance_discovery_scope_missing');
            }
            $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
            $requestId = self::requestId($payload['checkoutRequestId'] ?? null);
            $requestVersion = self::positiveInt(
                $payload['checkoutRequestVersion'] ?? null,
                'checkout_balance_discovery_request_version_invalid'
            );
            $stateContextId = self::stateContextId($scope['state_context_id'] ?? null);
            $workspaceId = CashierV3CheckoutWorkspaceIdentity::id($operator->storeId(), $stateContextId);
            $request = Db::name('cashier_v3_checkout_request')
                ->where('request_id', $requestId)
                ->where('tenant_id', $dataScope->tenantId())
                ->where('store_id', $dataScope->forcedStoreId())
                ->where('workspace_id', $workspaceId)
                ->where('state_context_id', $stateContextId)
                ->whereIn('request_status', ['editing', 'ready_for_submit'])
                ->lock(true)
                ->field(
                    'request_version,member_id,balance_deduction_amount_cents,'
                    . 'balance_authority_key,balance_account_id,balance_account_version'
                )
                ->find();
            if (!$request) {
                throw self::failure('checkout_balance_discovery_request_not_found');
            }
            if ((int)($request['request_version'] ?? 0) !== $requestVersion) {
                throw CashierV3CommandException::versionConflict(
                    '结账资料已变化，请刷新后重试。',
                    ['reason' => 'checkout_balance_discovery_request_version_conflict']
                );
            }
            $amount = self::nonNegativeInt(
                $request['balance_deduction_amount_cents'] ?? null,
                'checkout_balance_discovery_amount_invalid'
            );
            if ($action === self::ACTION_PREPARE_SUBMISSION && $amount === 0) {
                return [
                    'contractVersion' => self::CONTRACT_VERSION,
                    'resources' => [],
                ];
            }
            $memberId = self::positiveInt(
                $request['member_id'] ?? null,
                'checkout_balance_discovery_member_required'
            );
            // Applying/updating a balance draft is read-only. Final
            // submission preparation also only verifies and records the
            // account version; it does not debit money. The immutable plan
            // upgrades this dependency to mutate when submit-checkout locks
            // the plan, so preparation must not report a balance mutation.
            $snapshot = $action === self::ACTION_PREPARE_SUBMISSION
                ? $this->balances->lockSnapshotInTx($memberId, $operator, $dataScope)
                : $this->balances->readSnapshot($memberId, $operator, $dataScope);
            if ($action === self::ACTION_PREPARE_SUBMISSION) {
                $expectedKey = (string)($request['balance_authority_key'] ?? '');
                $expectedAccountId = (string)($request['balance_account_id'] ?? '');
                $expectedVersion = self::positiveInt(
                    $request['balance_account_version'] ?? null,
                    'checkout_balance_discovery_account_version_invalid'
                );
                if (!hash_equals((string)$snapshot['authorityKey'], $expectedKey)
                    || !hash_equals((string)$snapshot['accountId'], $expectedAccountId)
                    || (int)$snapshot['accountVersion'] !== $expectedVersion
                    || $amount > (int)$snapshot['totalCents']) {
                    throw CashierV3CommandException::versionConflict(
                        '会员余额已变化，请返回结账页面重新选择余额支付。',
                        ['reason' => 'checkout_balance_discovery_authority_stale']
                    );
                }
            }

            return [
                'contractVersion' => self::CONTRACT_VERSION,
                'resources' => [[
                    'kind' => CashierV3MemberBalanceProvider::KIND,
                    'id' => (string)$snapshot['accountId'],
                    'expectedVersion' => (int)$snapshot['accountVersion'],
                    'roles' => ['checkout_member_balance'],
                    'accessMode' => 'read',
                    'providerContractVersion' => CashierV3MemberBalanceProvider::CONTRACT_VERSION,
                    'authorityFingerprint' => self::fingerprint(
                        $snapshot,
                        $action === self::ACTION_APPLY ? 0 : $amount
                    ),
                ]],
            ];
        } catch (CashierV3MemberBalanceContractException $exception) {
            throw self::failure($exception->reason());
        }
    }

    private static function fingerprint(array $snapshot, int $amount): string
    {
        $payload = [
            'contractVersion' => self::CONTRACT_VERSION,
            'authorityKey' => (string)$snapshot['authorityKey'],
            'accountId' => (string)$snapshot['accountId'],
            'accountVersion' => (int)$snapshot['accountVersion'],
            'principalCents' => (int)$snapshot['principalCents'],
            'giftCents' => (int)$snapshot['giftCents'],
            'totalCents' => (int)$snapshot['totalCents'],
            'deductionAmountCents' => $amount,
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw self::failure('checkout_balance_discovery_fingerprint_encode_failed');
        }
        return hash('sha256', $json);
    }

    private static function requestId($value): string
    {
        if (!is_string($value)
            || preg_match('/^CKR-[0-9a-f]{40}$/D', $value) !== 1) {
            throw self::failure('checkout_balance_discovery_request_id_invalid');
        }
        return $value;
    }

    private static function stateContextId($value): string
    {
        if (!is_string($value)
            || $value === ''
            || strlen($value) > 64
            || preg_match('/^[A-Za-z0-9_.:-]+$/D', $value) !== 1) {
            throw self::failure('checkout_balance_discovery_state_context_invalid');
        }
        return $value;
    }

    private static function positiveInt($value, string $reason): int
    {
        if (!is_int($value) || $value <= 0) {
            throw self::failure($reason);
        }
        return $value;
    }

    private static function nonNegativeInt($value, string $reason): int
    {
        if (!is_int($value) || $value < 0) {
            throw self::failure($reason);
        }
        return $value;
    }

    private static function failure(string $reason): CashierV3CommandException
    {
        return new CashierV3CommandException(
            CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
            '会员余额结账资料暂不可用，请刷新后重试。',
            CashierV3ResultCode::STATUS_FAILED,
            ['reason' => $reason]
        );
    }
}
