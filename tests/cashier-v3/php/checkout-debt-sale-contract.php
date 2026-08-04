<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/后端代码/vendor/autoload.php';

use app\services\cashier\v3\settlement\CashierV3CheckoutDebtAuthorityServices;

$passed = 0;
$failed = 0;
$check = static function (string $name, bool $condition) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    fwrite(STDERR, "FAIL {$name}\n");
};

$lines = [
    ['order_line_id' => 'line-a', 'sale_amount_cents' => 333],
    ['order_line_id' => 'line-b', 'sale_amount_cents' => 667],
];
$allocation = CashierV3CheckoutDebtAuthorityServices::allocate(501, $lines);
$check(
    'debt uses deterministic largest-remainder line allocation',
    $allocation === ['line-a' => 167, 'line-b' => 334]
        && array_sum($allocation) === 501
);
$check(
    'debt policy authority is store-scoped',
    CashierV3CheckoutDebtAuthorityServices::authorityKey(163)
        === 'checkout-debt-policy:store:163'
);

$submission = file_get_contents(
    dirname(__DIR__, 3)
    . '/后端代码/app/services/cashier/v3/settlement/CashierV3SaleOnlyCheckoutSubmissionServices.php'
);
$card = file_get_contents(
    dirname(__DIR__, 3)
    . '/后端代码/app/services/cashier/v3/card/CashierV3CardPurchaseIssuanceServices.php'
);
$mixedPort = file_get_contents(
    dirname(__DIR__, 3)
    . '/后端代码/app/services/cashier/v3/settlement/ThinkPhpCashierV3CheckoutSubmissionExecutionPort.php'
);
$orchestrator = file_get_contents(
    dirname(__DIR__, 3)
    . '/后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutSubmissionOrchestrator.php'
);
$resultReader = file_get_contents(
    dirname(__DIR__, 3)
    . '/后端代码/app/services/cashier/v3/settlement/ThinkPhpCashierV3CheckoutResultReadRepository.php'
);
$frontend = file_get_contents(
    dirname(__DIR__, 3)
    . '/前端代码/cashier-v3/src/views/CashierWorkbenchView.vue'
);
$check(
    'mixed checkout persists debt before events and exposes the authoritative slice',
    strpos($orchestrator, 'persistDebtInTx($authority, $salesOrder)') !== false
        && strpos($mixedPort, '$this->debts->persistInTx(') !== false
        && strpos($mixedPort, "'event_type' => 'debt.recorded'") !== false
        && strpos($orchestrator, "'debt' => \$debt ?: null") !== false
);
$check(
    'checkout replay fails closed when a declared debt authority is missing',
    strpos($resultReader, 'findCheckoutDebt($request, $order)') !== false
        && strpos($resultReader, 'if ($debt === false)') !== false
        && strpos($resultReader, "'debt' => \$debt") !== false
);
$check(
    'debt authority, legacy card projection and event share final transaction',
    strpos($submission, '$this->cardPurchases->issueInTx(') !== false
        && strpos($submission, '$this->debts->persistInTx(') !== false
        && strpos($submission, "'event_type' => 'debt.recorded'") !== false
        && strpos($card, "'debt_amount' => self::money(\$debtCents)") !== false
);
$debtAuthority = file_get_contents(
    dirname(__DIR__, 3)
    . '/后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutDebtAuthorityServices.php'
);
$check(
    'new V3 debt records an immutable source mapping in the checkout transaction',
    strpos($debtAuthority, "Db::name('cashier_v3_debt_authority')") !== false
        && strpos($debtAuthority, "'sales_order_record_id' => \$v3OrderRecordId") !== false
        && strpos($debtAuthority, 'checkout_debt_v3_authority_replay_conflict') !== false
);
$check(
    'cashier debt editor supplies intent without client authority keys',
    strpos($frontend, 'debtAmountCents: checkoutDebtAmountCents.value') !== false
        && strpos($frontend, 'checkout-debt-policy:') === false
        && strpos($frontend, 'cashier-v3:open-member-debt-repayment') === false
);

echo "CHECKOUT_DEBT_SALE_CONTRACT passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
