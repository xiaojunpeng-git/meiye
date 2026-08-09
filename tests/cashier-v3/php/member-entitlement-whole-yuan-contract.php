<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
require_once $root . '/后端代码/app/services/cashier/v3/cashier/CashierV3EntitlementActualAmountAllocator.php';
require_once $root . '/后端代码/app/services/cashier/v3/checkout/CashierV3EntitlementCompletionKernel.php';

use app\services\cashier\v3\cashier\CashierV3EntitlementActualAmountAllocator;
use app\services\cashier\v3\checkout\CashierV3EntitlementCompletionKernel;

$passed = 0;
$failed = 0;

function wholeYuanOk(string $name, bool $condition): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    echo "FAIL {$name}\n";
}

wholeYuanOk('2180 over 9 uses assigns the integer tail to the ninth use',
    CashierV3EntitlementActualAmountAllocator::allocate('2180.00', 9, 0, 1) === '242.00'
    && CashierV3EntitlementActualAmountAllocator::allocate('2180.00', 9, 8, 1) === '244.00'
    && CashierV3EntitlementActualAmountAllocator::remaining('2180.00', 9, 3) === '1454.00');

wholeYuanOk('2180 over 22 keeps the same final-tail invariant',
    CashierV3EntitlementActualAmountAllocator::allocate('2180.00', 22, 0, 21) === '2079.00'
    && CashierV3EntitlementActualAmountAllocator::allocate('2180.00', 22, 21, 1) === '101.00'
    && CashierV3EntitlementActualAmountAllocator::remaining('2180.00', 22, 8) === '1388.00');

$fractionRejected = false;
try {
    CashierV3EntitlementActualAmountAllocator::allocate('2180.60', 9, 0, 1);
} catch (InvalidArgumentException $exception) {
    $fractionRejected = str_contains($exception->getMessage(), 'whole yuan');
}
wholeYuanOk('fractional-yuan source is rejected without rounding or truncation', $fractionRejected);
wholeYuanOk('selector allocation and completion facts share one calculation version',
    CashierV3EntitlementActualAmountAllocator::CALCULATION_VERSION
    === CashierV3EntitlementCompletionKernel::AMOUNT_CALCULATION_VERSION);

$summary = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/cashier/CashierV3CashierMemberSummaryServices.php');
$detail = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/member/CashierV3MemberDetailQueryServices.php');
wholeYuanOk('disabled card state is authoritative for summary and detail',
    strpos($summary, "Db::name('cashier_v3_card_state')") !== false
    && strpos($summary, "->where('card_status', 'disabled')") !== false
    && strpos($detail, "'statusLabel' => \$statusCode === 'disabled' ? '已停用' : '有效'") !== false
    && strpos($detail, "if ((\$card['statusCode'] ?? '') !== 'enabled') continue;") !== false);
wholeYuanOk('time-card count exclusion uses the persisted rule type',
    strpos($summary, "Db::name('cashier_v3_card_rule_state')") !== false
    && strpos($summary, "->where('rule_type', 'time')") !== false
    && strpos($detail, "(\$card['cardRuleType'] ?? '') !== 'time'") !== false);
wholeYuanOk('service records expose frozen card name and number',
    strpos($detail, "'cardName' => (string)(\$row['source_name_snapshot'] ?? '')") !== false
    && strpos($detail, "'cardNo' => (string)(\$row['source_code_snapshot'] ?? '')") !== false);

echo "ASSERT_PASSED={$passed}\n";
echo "ASSERT_FAILED={$failed}\n";
exit($failed === 0 ? 0 : 1);
