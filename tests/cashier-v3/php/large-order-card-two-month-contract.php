<?php

declare(strict_types=1);

/** Verify only card-sourced beauty awards from 30,000 to below 50,000 accrue in two equal months. */
require dirname(__DIR__, 3) . '/后端代码/vendor/autoload.php';

use app\services\report\StoreLargeOrderInstallmentServices as Installments;
use app\services\report\StoreUnifiedReportPhaseTwoServices as Report;

$checks = 0;
$check = static function (bool $valid, string $label) use (&$checks): void {
    if (!$valid) throw new RuntimeException('FAIL ' . $label);
    $checks++;
};

$check(Installments::schedule(2999999, 'beauty', 'card') === [], 'card below 30,000 does not qualify');
$check(Installments::schedule(3000000, 'beauty', 'card') === [1500000, 1500000], 'card starts at 30,000 with equal months');
$check(Installments::schedule(3840000, 'beauty', 'card') === [1920000, 1920000], '38,400 card has two 19,200 accruals');
$check(Installments::schedule(4999999, 'beauty', 'card') === [2499999, 2500000], 'last card month absorbs the cent remainder');
$check(Installments::schedule(5000000, 'beauty', 'card') === [2000000, 600000, 600000, 600000, 600000, 600000], '50,000 remains six months');
$check(Installments::schedule(3840000, 'beauty', 'project') === [1536000, 1152000, 1152000], 'direct project remains three months');
$check(Installments::schedule(6000000, 'partner', 'card') === [2400000, 1800000, 1800000], 'partner remains three months');

$awards = new ReflectionMethod(Report::class, 'beautyLargeOrderMonthlyAwards');
if (PHP_VERSION_ID < 80100) $awards->setAccessible(true);
$base = [
    'tenant_id' => '0', 'store_id' => 133, 'role_snapshot' => 'salesperson',
    'employee_id' => 1115, 'member_id' => 1137384, 'member_name_snapshot' => '测试会员',
    'business_date' => '2026-11-06', 'rule_type' => 'beauty',
    'beauty_category' => '卡项', 'award_category_id' => 26332,
    'large_order_product_type' => 'card', 'amount_cents' => 3840000,
];
$cardAwards = $awards->invoke(null, [$base]);
$november = Installments::dueRows([$base], $cardAwards, '2026-11');
$december = Installments::dueRows([$base], $cardAwards, '2026-12');
$january = Installments::dueRows([$base], $cardAwards, '2027-01');
$check(count($november) === 1 && $november[0]['installment_count'] === 2
    && $november[0]['due_amount_cents'] === 1920000, 'November shows 1/2 and 19,200');
$check(count($december) === 1 && $december[0]['installment_number'] === 2
    && $december[0]['due_amount_cents'] === 1920000, 'December shows 2/2 and 19,200');
$check($january === [], 'third month has no card accrual');
$check($november[0]['row_key'] !== $december[0]['row_key'], 'monthly drilldown identities stay distinct');

$refund = array_replace($base, ['amount_cents' => -10000]);
$withRefund = $awards->invoke(null, [$base, $refund]);
$refundDue = Installments::dueRows([$base, $refund], $withRefund, '2026-11');
$check(count($refundDue) === 1 && $refundDue[0]['due_amount_cents'] === 1920000
    && $refundDue[0]['refund_amount_cents'] === 10000, 'refund displays without lowering accrual');

// The category name may be “卡项”, but a direct project is still a project by sale fact identity.
$project = array_replace($base, ['amount_cents' => 1840000, 'large_order_product_type' => 'project']);
$cardPart = array_replace($base, ['amount_cents' => 2000000]);
$mixed = $awards->invoke(null, [$cardPart, $project]);
$mixedDue = Installments::dueRows([$cardPart, $project], $mixed, '2026-11');
$check(count($mixedDue) === 1 && $mixedDue[0]['installment_count'] === 3
    && $mixedDue[0]['due_amount_cents'] === 1536000, 'mixed source keeps existing three-month rule');
$projectOnly = array_replace($base, ['large_order_product_type' => 'project']);
$projectAwards = $awards->invoke(null, [$projectOnly]);
$projectDue = Installments::dueRows([$projectOnly], $projectAwards, '2026-11');
$check(count($projectDue) === 1 && $projectDue[0]['installment_count'] === 3,
    'category called card does not change a direct project');

echo 'PASS card two-month accrual checks=', $checks, PHP_EOL;
