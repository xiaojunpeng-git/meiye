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
    ['order_line_id' => 'line-a', 'sale_amount_cents' => 333, 'debt_amount_cents' => 100],
    ['order_line_id' => 'line-b', 'sale_amount_cents' => 667, 'debt_amount_cents' => 401],
];
$allocation = CashierV3CheckoutDebtAuthorityServices::exactAllocations(501, $lines);
$check(
    'debt remains on the exact sales line where it was entered',
    $allocation === ['line-a' => 100, 'line-b' => 401]
        && array_sum($allocation) === 501
);
$mismatchRejected = false;
try {
    CashierV3CheckoutDebtAuthorityServices::exactAllocations(500, $lines);
} catch (Throwable $exception) {
    $mismatchRejected = true;
}
$check('header debt must equal the exact line debt sum', $mismatchRejected);
$check(
    'debt policy authority is store-scoped',
    CashierV3CheckoutDebtAuthorityServices::authorityKey(163)
        === 'checkout-debt-policy:store:163'
);

$submission = file_get_contents(
    dirname(__DIR__, 3)
    . '/后端代码/app/services/cashier/v3/settlement/CashierV3SaleOnlyCheckoutSubmissionServices.php'
);
$executionPort = file_get_contents(
    dirname(__DIR__, 3)
    . '/后端代码/app/services/cashier/v3/settlement/ThinkPhpCashierV3CheckoutSubmissionExecutionPort.php'
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
    'debt authority and event share the final transaction without legacy card projection',
    strpos($submission, '$this->cardPurchases->issueInTx(') !== false
        && strpos($submission, '$this->debts->persistInTx(') !== false
        && strpos($submission, "'event_type' => 'debt.recorded'") !== false
        && strpos($submission, 'debtAllocations') === false
        && strpos($card, 'debtAllocations') === false
        && strpos($card, "'debt_amount' => '0.00'") !== false
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
    'new debt numbers use the QK business sequence while historical debt numbers remain stable',
    strpos($debtAuthority, "CashierV3BusinessDocumentNumberServices::DEBT") !== false
        && strpos($debtAuthority, "'checkout_debt'") !== false
        && strpos($debtAuthority, "->where('checkout_request_id', (string)\$header['checkout_request_id'])") !== false
);
$personnelMigration = file_get_contents(
    dirname(__DIR__, 3)
    . '/后端代码/database/upgrades/2026-08-05-收银V3销售欠款行人员快照/02-正式升级.sql'
);
$check(
    'new V3 debt freezes exact line salesperson authority without historical backfill',
    strpos($debtAuthority, "Db::name('cashier_v3_debt_item_personnel_authority')") !== false
        && strpos($debtAuthority, "'order_line_id' => \$lineId") !== false
        && strpos($debtAuthority, "'salespeople_snapshot_json' => self::encodeJson(\$salespeople)") !== false
        && strpos($submission, '$salespeopleByCheckoutLine,') !== false
        && strpos($executionPort, '$salespeopleByCheckoutLine = $this->workspace->lockedSalespeopleByCheckoutLineInTx(') !== false
        && strpos($executionPort, '$salespeopleByCheckoutLine,') !== false
        && strpos($personnelMigration, 'eb_cashier_v3_debt_item_personnel_authority') !== false
        && stripos($personnelMigration, 'UPDATE eb_') === false
);
$check(
    'cashier debt editor persists the selected cart line and header debt is derived',
    strpos($frontend, "mutateCashierDraft('update-cashier-line-debt', line") !== false
        && strpos($frontend, 'debtAmountCents: checkoutDebtAmountCents.value') === false
        && strpos($frontend, "checkoutDebtSummary(line)") !== false
        && strpos($frontend, 'checkout-debt-policy:') === false
        && strpos($frontend, 'cashier-v3:open-member-debt-repayment') === false
);
$migration = file_get_contents(
    dirname(__DIR__, 3)
    . '/后端代码/database/upgrades/2026-08-05-收银V3更多操作权威/02-正式升级.sql'
);
$check(
    'migration adds default-only line debt columns without rewriting historical rows',
    substr_count($migration, "'debt_amount_cents'") >= 4
        && stripos($migration, 'UPDATE eb_') === false
);

echo "CHECKOUT_DEBT_SALE_CONTRACT passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
