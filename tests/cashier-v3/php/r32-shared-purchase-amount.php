<?php
declare(strict_types=1);

/** 共享次数卡以整卡成交额计价；纯公式测试不读写任何数据库。 */
require __DIR__ . '/../../../后端代码/vendor/autoload.php';

use app\services\cashier\v3\cashier\CashierV3EntitlementActualAmountAllocator as Allocator;
use app\services\cashier\v3\cashier\CashierV3SharedCardPurchaseAmount as Shared;
use app\services\cashier\v3\checkout\CashierV3EntitlementCompletionKernel as Kernel;

function check32(bool $condition, string $case): void
{
    if (!$condition) throw new RuntimeException($case);
    echo "PASS $case\n";
}

$order = ['pay_price' => '2000.00'];
$rule = ['ruleType' => 'choice_count', 'totalTimes' => 30, 'remainingTimes' => 30];
$first = Shared::project($order, $rule, []);
check32($first !== null && $first['purchaseAmount'] === '2000.00'
    && $first['remainingAmount'] === '2000.00', 'original card amount, not project sale allocation');
check32(Allocator::allocateForSnapshot('2000.00', 30, 0, 1, []) === '66.00', 'first use whole yuan');
check32(Allocator::allocateForSnapshot('2000.00', 30, 28, 1, []) === '66.00', '29th use whole yuan');
check32(Allocator::allocateForSnapshot('2000.00', 30, 29, 1, []) === '86.00', 'final use receives remainder');
check32(Kernel::allocateActualAmountCents(200000, 30, 0, 1, $first['calculationVersion']) === 6600,
    'final kernel uses whole-card amount');
check32(Kernel::allocateActualAmountCents(200000, 30, 29, 1, $first['calculationVersion']) === 8600,
    'final kernel keeps remainder on last use');

$rule['remainingTimes'] = 29;
$afterOne = Shared::project($order, $rule, []);
check32($afterOne !== null && $afterOne['remainingAmount'] === '1934.00'
    && $afterOne['consumedTimesAtSelection'] === 1, 'second project shares the one consumed use');
$rule['remainingTimes'] = 28;
$refunded = Shared::project($order, $rule, ['r26RefundedCents' => 6600, 'r26RefundRemovedTimes' => 1]);
check32($refunded !== null && $refunded['remainingAmount'] === '1868.00'
    && $refunded['consumedTimesAtSelection'] === 1, 'refund removes future use without faking service');
check32(Shared::project($order, $rule, ['sourceType' => 'cashier_v3_project_replacement']) === null,
    'independent replacement amount stays independent');
check32(Shared::project(['pay_price' => '2000.25'], $rule, []) === null,
    'fractional purchase amount cannot silently enter whole-yuan allocation');
echo "R32_SHARED_PURCHASE_AMOUNT_PASS\n";
