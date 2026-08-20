<?php

namespace app\services\cashier\v3\settlement;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\checkout\provider\CashierV3MemberBalanceContractException;
use app\services\cashier\v3\checkout\provider\CashierV3MemberBalanceProvider;

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

    private const ACTION_FINAL_SNAPSHOT = 'finalize-checkout-snapshot';
    private const ACTION_SUBMIT = 'submit-checkout';

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
            if (!in_array($action, [self::ACTION_FINAL_SNAPSHOT, self::ACTION_SUBMIT], true)) {
                throw self::failure('checkout_balance_discovery_action_invalid');
            }
            $operator = $scope['operator_scope'] ?? null;
            $dataScope = $scope['data_scope'] ?? null;
            if (!($operator instanceof CashierV3OperatorScope)
                || !($dataScope instanceof CashierV3DataScopeContext)) {
                throw self::failure('checkout_balance_discovery_scope_missing');
            }
            $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
            if (!is_array($payload['checkoutSnapshot'] ?? null)) {
                throw self::failure('checkout_balance_discovery_snapshot_required');
            }
            if ($action === self::ACTION_SUBMIT) {
                $snapshotPayload = $payload['checkoutSnapshot'];
                $amount = self::nonNegativeInt(
                    $snapshotPayload['balancePaymentAmount']
                        ?? ($snapshotPayload['payment']['balancePaymentAmount'] ?? 0),
                    'checkout_snapshot_balance_amount_invalid'
                );
                if ($amount === 0) {
                    return ['contractVersion' => self::CONTRACT_VERSION, 'resources' => []];
                }
                $memberId = self::positiveInt(
                    $snapshotPayload['memberId'] ?? null,
                    'checkout_snapshot_balance_member_required'
                );
                $account = $this->balances->readSnapshot($memberId, $operator, $dataScope);
                if ($amount > (int)$account['totalCents']) {
                    throw self::failure('checkout_balance_discovery_authority_stale');
                }
                return [
                    'contractVersion' => self::CONTRACT_VERSION,
                    'resources' => [[
                        'kind' => CashierV3MemberBalanceProvider::KIND,
                        'id' => (string)$account['accountId'],
                        'expectedVersion' => (int)$account['accountVersion'],
                        'roles' => ['checkout_member_balance'],
                        'accessMode' => 'read',
                        'providerContractVersion' => CashierV3MemberBalanceProvider::CONTRACT_VERSION,
                        'authorityFingerprint' => self::fingerprint($account, $amount),
                    ]],
                ];
            }
            throw self::failure('checkout_balance_discovery_snapshot_required');
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
