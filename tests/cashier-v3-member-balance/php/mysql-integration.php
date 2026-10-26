<?php
declare(strict_types=1);

require_once __DIR__ . '/mysql-bootstrap.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3ResourceScope;
use app\services\cashier\v3\checkout\provider\CashierV3MemberBalanceContractException;
use app\services\cashier\v3\checkout\provider\CashierV3MemberBalanceProvider;
use app\services\cashier\v3\checkout\provider\CashierV3MemberBalanceWriterAdapter;
use think\facade\Db;

mbaMysqlBoot();
$passed = 0;
$failed = 0;
function mbaIntegrationAssert(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    echo "FAIL {$name}" . ($detail === '' ? '' : ': ' . $detail) . "\n";
}
function mbaReason(callable $callback): string
{
    try {
        $callback();
    } catch (CashierV3MemberBalanceContractException $exception) {
        return $exception->reason();
    } catch (\Throwable $exception) {
        return 'UNEXPECTED:' . get_class($exception) . ':' . $exception->getMessage();
    }
    return '';
}
function mbaRequest(
    int $memberId,
    int $version,
    int $amountCents,
    string $key,
    string $mode = CashierV3MemberBalanceWriterAdapter::PAYMENT_MODE_COMBINED
): array {
    return [
        'memberId' => $memberId,
        'expectedVersion' => $version,
        'amountCents' => $amountCents,
        'sourceOrderId' => 9000 + $memberId,
        'commandIdempotencyKey' => $key,
        'paymentMode' => $mode,
    ];
}

Db::name('balance_test_barrier')->delete(true);
Db::name('user_money')->delete(true);
Db::name('user')->delete(true);
$provider = new CashierV3MemberBalanceProvider();
$adapter = new CashierV3MemberBalanceWriterAdapter($provider);
$operator = mbaOperator();
$scope = mbaScope();

$ready = $adapter->readinessStatus();
mbaIntegrationAssert(
    'provider and atomic ledger schema are ready',
    !empty($ready['ready'])
        && $ready['provider']['versionSource'] === 'eb_user.balance_version'
        && $ready['ledgerSchema']['uniqueIdempotencyKey']
);

mbaSeedMember(101, '60.00', '50.00');
$resolved = $provider->resolveScopeWithDataScope('member_balance', '101', $operator, $scope);
mbaIntegrationAssert(
    'active member resolves only to server tenant scope',
    $resolved !== null && $resolved->type() === 'tenant' && $resolved->id() === 'TENANT-1'
);

$deduction = Db::transaction(function () use ($adapter, $operator, $scope): array {
    $write = $adapter->deductCheckoutInTx(
        mbaRequest(101, 1, 8000, 'CHECKOUT-BALANCE-101'),
        $operator,
        $scope
    );
    $provider = new CashierV3MemberBalanceProvider();
    $write['observedTouchedVersion'] = $provider->bumpVersionWithDataScope(
        CashierV3ResourceScope::of('tenant', 'TENANT-1'),
        'member_balance',
        '101',
        'submit-checkout',
        $scope
    );
    return $write;
});
$member101 = mbaMember(101);
$ledger101 = Db::name('user_money')->where('uid', 101)->find();
mbaIntegrationAssert(
    'deduction is principal-first and advances the real account exactly once',
    $deduction['before'] === ['principalCents' => 6000, 'giftCents' => 5000, 'totalCents' => 11000]
        && $deduction['change'] === ['principalCents' => -6000, 'giftCents' => -2000, 'totalCents' => -8000]
        && $deduction['after'] === ['principalCents' => 0, 'giftCents' => 3000, 'totalCents' => 3000]
        && $deduction['accountVersionBefore'] === 1
        && $deduction['accountVersionAfter'] === 2
        && $deduction['observedTouchedVersion'] === 2
        && (string)$member101['now_money'] === '30.00'
        && (string)$member101['ben_money'] === '0.00'
        && (string)$member101['give_money'] === '30.00'
        && (int)$member101['balance_version'] === 2
        && (string)$ledger101['ben_change_amount'] === '-60.00'
        && (string)$ledger101['give_change_amount'] === '-20.00'
);

$replay = Db::transaction(function () use ($adapter, $operator, $scope): array {
    return $adapter->deductCheckoutInTx(
        mbaRequest(101, 2, 8000, 'CHECKOUT-BALANCE-101'),
        $operator,
        $scope
    );
});
mbaIntegrationAssert(
    'same stable key replays without a second deduction or version bump',
    $replay['idempotentReplay']
        && $replay['accountVersionBefore'] === 2
        && $replay['accountVersionAfter'] === 2
        && (int)Db::name('user_money')->where('uid', 101)->count() === 1
        && (int)mbaMember(101)['balance_version'] === 2
);

$keyConflict = Db::transaction(function () use ($adapter, $operator, $scope): string {
    return mbaReason(function () use ($adapter, $operator, $scope): void {
        $adapter->deductCheckoutInTx(
            mbaRequest(101, 2, 1000, 'CHECKOUT-BALANCE-101'),
            $operator,
            $scope
        );
    });
});
mbaIntegrationAssert(
    'same key with different money fails closed',
    $keyConflict === 'member_balance_idempotency_conflict'
        && (string)mbaMember(101)['now_money'] === '30.00'
        && (int)Db::name('user_money')->where('uid', 101)->count() === 1,
    $keyConflict
);

mbaSeedMember(102, '20.00', '10.00');
$insufficient = Db::transaction(function () use ($adapter, $operator, $scope): string {
    return mbaReason(function () use ($adapter, $operator, $scope): void {
        $adapter->deductCheckoutInTx(
            mbaRequest(102, 1, 4000, 'CHECKOUT-BALANCE-102'),
            $operator,
            $scope
        );
    });
});
mbaIntegrationAssert(
    'insufficient balance writes no row or ledger',
    $insufficient === 'member_balance_insufficient'
        && (string)mbaMember(102)['now_money'] === '30.00'
        && (int)mbaMember(102)['balance_version'] === 1
        && (int)Db::name('user_money')->where('uid', 102)->count() === 0,
    $insufficient
);

mbaSeedMember(103, '10.00', '10.00', '25.00');
$invalid = Db::transaction(function () use ($provider, $operator, $scope): string {
    return mbaReason(function () use ($provider, $operator, $scope): void {
        $provider->lockSnapshotInTx(103, $operator, $scope);
    });
});
mbaIntegrationAssert('inconsistent legacy balance is rejected', $invalid === 'member_balance_invariant_invalid', $invalid);

mbaSeedMember(104, '50.00', '20.00');
try {
    Db::transaction(function () use ($adapter, $operator, $scope): void {
        $adapter->deductCheckoutInTx(
            mbaRequest(104, 1, 3000, 'CHECKOUT-BALANCE-ROLLBACK'),
            $operator,
            $scope
        );
        throw new RuntimeException('force outer rollback');
    });
} catch (RuntimeException $exception) {
}
$rollbackMember = mbaMember(104);
mbaIntegrationAssert(
    'outer transaction rollback restores balance, version and ledger together',
    (string)$rollbackMember['now_money'] === '70.00'
        && (string)$rollbackMember['ben_money'] === '50.00'
        && (string)$rollbackMember['give_money'] === '20.00'
        && (int)$rollbackMember['balance_version'] === 1
        && (int)Db::name('user_money')->where('uid', 104)->count() === 0
);

mbaSeedMember(105, '40.00', '10.00');
Db::name('user')->where('uid', 105)->update([
    'now_money' => '45.00', 'ben_money' => '35.00', 'give_money' => '10.00',
]);
$legacyChanged = mbaMember(105);
Db::name('user')->where('uid', 105)->update(['balance_version' => 99]);
$tamperBlocked = mbaMember(105);
mbaIntegrationAssert(
    'legacy direct balance changes advance version and version-only tampering is ignored',
    (int)$legacyChanged['balance_version'] === 2
        && (int)$tamperBlocked['balance_version'] === 2
        && (string)$tamperBlocked['now_money'] === '45.00'
);

$noneScope = mbaScope(7, 21, 'TENANT-1', CashierV3DataScopeContext::MODE_NONE);
$selfScope = mbaScope(7, 21, 'TENANT-1', CashierV3DataScopeContext::MODE_SELF_PARTICIPANT);
mbaIntegrationAssert(
    'none, self participant, store and tenant mismatches are hidden',
    $provider->resolveScopeWithDataScope('member_balance', '105', $operator, $noneScope) === null
        && $provider->resolveScopeWithDataScope('member_balance', '105', $operator, $selfScope) === null
        && $provider->resolveScopeWithDataScope('member_balance', '105', mbaOperator(8), $scope) === null
        && $provider->resolveScopeWithDataScope('member_balance', '105', mbaOperator(7, 21, 'TENANT-2'), $scope) === null
);

echo "CHECKOUT_BALANCE_MYSQL_INTEGRATION passed={$passed} failed={$failed}\n";
if ($failed > 0) {
    exit(1);
}
