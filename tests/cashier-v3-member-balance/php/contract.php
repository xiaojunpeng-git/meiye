<?php
declare(strict_types=1);

$backend = getenv('CHECKOUT_BALANCE_BACKEND_ROOT') ?: '/workspace/后端代码';
require_once $backend . '/vendor/autoload.php';

use app\services\cashier\v3\CashierV3DataScopedVersionProvider;
use app\services\cashier\v3\CashierV3ResourceKindCatalog;
use app\services\cashier\v3\checkout\provider\CashierV3MemberBalanceProvider;
use app\services\cashier\v3\checkout\provider\CashierV3MemberBalanceWriterAdapter;

$passed = 0;
$failed = 0;
function mbaContractAssert(string $name, bool $ok): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    echo "FAIL {$name}\n";
}

mbaContractAssert(
    'provider contract and kind are frozen',
    CashierV3MemberBalanceProvider::CONTRACT_VERSION === 'cashier-v3-member-balance-authority-v1'
        && CashierV3MemberBalanceProvider::KIND === 'member_balance'
        && is_subclass_of(CashierV3MemberBalanceProvider::class, CashierV3DataScopedVersionProvider::class)
);
mbaContractAssert(
    'writer contract and payment modes are frozen',
    CashierV3MemberBalanceWriterAdapter::CONTRACT_VERSION === 'cashier-v3-member-balance-writer-v1'
        && CashierV3MemberBalanceWriterAdapter::PAYMENT_MODE_BALANCE_ONLY === 'balance_only'
        && CashierV3MemberBalanceWriterAdapter::PAYMENT_MODE_COMBINED === 'combined'
);
mbaContractAssert(
    'member balance keeps tenant scope and lock order 20',
    CashierV3ResourceKindCatalog::scopeTypeOf('member_balance') === 'tenant'
        && CashierV3ResourceKindCatalog::lockOrderOf('member_balance') === 20
);
mbaContractAssert(
    'provider exposes locked authority snapshot without a shadow identity',
    method_exists(CashierV3MemberBalanceProvider::class, 'lockSnapshotInTx')
        && CashierV3MemberBalanceProvider::identityForMember(91) === '91'
);

$adapter = new CashierV3MemberBalanceWriterAdapter();
$key1 = $adapter->ledgerIdempotencyKey('TENANT-1', 'CHECKOUT-CMD-1');
$key2 = $adapter->ledgerIdempotencyKey('TENANT-1', 'CHECKOUT-CMD-1');
$key3 = $adapter->ledgerIdempotencyKey('TENANT-2', 'CHECKOUT-CMD-1');
mbaContractAssert(
    'ledger idempotency key is stable, bounded and tenant separated',
    $key1 === $key2 && $key1 !== $key3 && strlen($key1) === 76
);
mbaContractAssert(
    'writer exposes only explicit transaction adapter entry',
    method_exists(CashierV3MemberBalanceWriterAdapter::class, 'deductCheckoutInTx')
        && !method_exists(CashierV3MemberBalanceWriterAdapter::class, 'activate')
        && !method_exists(CashierV3MemberBalanceWriterAdapter::class, 'register')
);

echo "CHECKOUT_BALANCE_CONTRACT passed={$passed} failed={$failed}\n";
if ($failed > 0) {
    exit(1);
}
