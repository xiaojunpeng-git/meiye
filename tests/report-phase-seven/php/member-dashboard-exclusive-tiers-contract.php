<?php

declare(strict_types=1);

/** Each signed fact enters one member or guest-order net, then exactly one tier. */
$root = dirname(__DIR__, 3);
require_once $root . '/后端代码/app/services/report/MemberManagementDashboardServices.php';

$service = new \app\services\report\MemberManagementDashboardServices();
$subjectsMethod = new ReflectionMethod($service, 'tierSubjects');
$build = new ReflectionMethod($service, 'buildExclusiveTierRows');
$totalMethod = new ReflectionMethod($service, 'tierTotal');
if (PHP_VERSION_ID < 80100) {
    $subjectsMethod->setAccessible(true);
    $build->setAccessible(true);
    $totalMethod->setAccessible(true);
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
    ['member_id' => 0, 'store_id' => 1, 'order_id' => 'guest-a', 'amount_cents' => 80000],
    ['member_id' => 0, 'store_id' => 1, 'order_id' => 'guest-a', 'amount_cents' => 20000],
    ['member_id' => 0, 'store_id' => 1, 'order_id' => 'guest-a', 'amount_cents' => -30000],
    ['member_id' => 0, 'store_id' => 2, 'order_id' => 'guest-b', 'amount_cents' => -15000, 'business_source_primary_id' => 9],
    ['member_id' => 0, 'store_id' => 1, 'fact_id' => 'standalone-1', 'amount_cents' => 5000],
];
$subjects = $subjectsMethod->invoke($service, $cash, [9]);
$all = $build->invoke($service, $tiers, $subjects, false);
$new = $build->invoke($service, $tiers, $subjects, true);
$allTotal = $totalMethod->invoke($service, $all);
$newTotal = $totalMethod->invoke($service, $new);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$assert(count($subjects) === 11, 'All eight members and three guest documents must have distinct subjects');
$assert($subjects['member:5']['amount'] === -20000 && $subjects['member:6']['amount'] === -40000,
    'Negative-net and refund-only members keep their signed amount');
$assert($subjects['guest:1:order:guest-a']['amount'] === 70000,
    'Guest sale lines and refund on the same document become one net');
$assert(array_column($all, 'people') === [2, 2, 7]
    && array_column($all, 'amount_cents') === [3000000, 1480000, 133000],
    'Member and guest nets enter one interval each, including exact boundaries and negative nets');
$assert(array_column($new, 'people') === [1, 2, 6]
    && array_column($new, 'amount_cents') === [1000000, 1480000, 148000],
    'New-customer column excludes source A member and guest once');
$assert($allTotal['people'] === 11 && $allTotal['amount_cents'] === 4613000
    && $allTotal['amount_cents'] === array_sum(array_column($cash, 'amount_cents')),
    'Main tier totals reconcile exactly to all overview facts in cents');
$assert($newTotal['people'] === 9 && $newTotal['amount_cents'] === 2628000,
    'New-customer total reconciles to its own non-A subjects');
$assert(abs(array_sum(array_column($all, 'member_share')) - 100.0) < 0.0001
    && abs(array_sum(array_column($new, 'amount_share')) - 100.0) < 0.0001,
    'Shares reconcile within their own columns');
$assert($all[2]['average_cents'] === 19000 && $new[2]['average_cents'] === 24667,
    'Averages divide signed tier net by the tier subject count');
$overlap = $build->invoke($service, [
    ['name' => 'first', 'lower' => 0, 'upper' => null],
    ['name' => 'second', 'lower' => 0, 'upper' => null],
], $subjects, false);
$assert(array_column($overlap, 'people') === [11, 0]
    && array_sum(array_column($overlap, 'amount_cents')) === 4613000,
    'Overlapping configuration cannot double count subjects or money');
$uncoveredRejected = false;
try {
    $build->invoke($service, [['name' => 'gap', 'lower' => 1, 'upper' => null]], $subjects, false);
} catch (RuntimeException $e) {
    $uncoveredRejected = true;
}
$assert($uncoveredRejected, 'A configuration gap must fail instead of dropping people or money');
$missingGuestIdentityRejected = false;
try {
    $subjectsMethod->invoke($service, [['member_id' => 0, 'amount_cents' => 100]], []);
} catch (RuntimeException $e) {
    $missingGuestIdentityRejected = true;
}
$assert($missingGuestIdentityRejected, 'A guest fact without a traceable identity must fail');

echo "PASS 12 member/guest exclusive tier checks\n";
