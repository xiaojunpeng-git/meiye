<?php

declare(strict_types=1);

/** Both tier columns assign each eligible member and signed net to one interval. */
$root = dirname(__DIR__, 3);
require_once $root . '/后端代码/app/services/report/MemberManagementDashboardServices.php';

$service = new \app\services\report\MemberManagementDashboardServices();
$membersMethod = new ReflectionMethod($service, 'tierMembers');
$build = new ReflectionMethod($service, 'buildExclusiveTierRows');
if (PHP_VERSION_ID < 80100) {
    $membersMethod->setAccessible(true);
    $build->setAccessible(true);
}
$tiers = [
    ['name' => '10000以上', 'lower' => 1000000, 'upper' => null],
    ['name' => '5000-10000', 'lower' => 500000, 'upper' => 1000000],
    ['name' => '0-5000', 'lower' => 0, 'upper' => 500000],
];
$cash = [
    ['member_id' => 1, 'amount_cents' => 980000],
    ['member_id' => 2, 'amount_cents' => 133000],
    ['member_id' => 3, 'amount_cents' => 2000000, 'business_source_primary_id' => 9],
    ['member_id' => 4, 'amount_cents' => 500000],
    ['member_id' => 5, 'amount_cents' => 10000],
    ['member_id' => 5, 'amount_cents' => -30000],
    ['member_id' => 6, 'amount_cents' => -40000],
    ['member_id' => 7, 'amount_cents' => 0],
    ['member_id' => 8, 'amount_cents' => 1000000],
];
$members = $membersMethod->invoke($service, $cash, [9]);

$all = $build->invoke($service, $tiers, $members, false);
$new = $build->invoke($service, $tiers, $members, true);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$assert(array_keys($members) === [1, 2, 3, 4, 5, 8], 'Refund-only and zero-only members must not enter either tier column');
$assert($members[5]['amount'] === -20000, 'A purchaser with negative net keeps the signed refund adjustment');
$assert(array_column($all, 'people') === [2, 2, 2], 'Every member belongs to one interval, including exact boundaries');
$assert(array_column($all, 'amount_cents') === [3000000, 1480000, 113000], 'Each member net appears in exactly one tier');
$assert(array_column($new, 'people') === [1, 2, 2], 'Source A members are excluded only from the new-customer column');
$assert(array_column($new, 'amount_cents') === [1000000, 1480000, 113000], 'New-customer amounts have no cross-tier duplication');
$assert(array_sum(array_column($all, 'people')) === count($members)
    && array_sum(array_column($all, 'amount_cents')) === array_sum(array_column($members, 'amount')),
    'Member tier counts and signed amounts must reconcile to the eligible cohort');
$assert(array_sum(array_column($new, 'people')) === 5
    && array_sum(array_column($new, 'amount_cents')) === 2593000,
    'New-customer counts and signed amounts must reconcile after source A exclusion');
$assert(abs(array_sum(array_column($all, 'member_share')) - 100.0) < 0.0001
    && abs(array_sum(array_column($new, 'amount_share')) - 100.0) < 0.0001,
    'Each column share must add up to 100 percent when its denominator is nonzero');
$assert($all[0]['average_cents'] === 1500000
    && $new[1]['average_cents'] === 740000
    && $all[2]['average_cents'] === 56500, 'Averages use only members in that interval');
$overlap = $build->invoke($service, [
    ['name' => 'first', 'lower' => 0, 'upper' => null],
    ['name' => 'second', 'lower' => 0, 'upper' => null],
], $members, false);
$assert(array_column($overlap, 'people') === [6, 0]
    && array_sum(array_column($overlap, 'amount_cents')) === 4593000,
    'Even overlapping configuration cannot count a member or amount twice');
$uncoveredRejected = false;
try {
    $build->invoke($service, [['name' => 'gap', 'lower' => 1, 'upper' => null]], $members, false);
} catch (RuntimeException $e) {
    $uncoveredRejected = true;
}
$assert($uncoveredRejected, 'A configuration gap must fail instead of dropping people or money');

echo "PASS 12 member/new-customer exclusive tier checks\n";
