<?php

declare(strict_types=1);

/** Cumulative tier rows use thresholds, while new-customer rows exclude source A. */
$root = dirname(__DIR__, 3);
require_once $root . '/后端代码/app/services/report/MemberManagementDashboardServices.php';

$service = new \app\services\report\MemberManagementDashboardServices();
$build = new ReflectionMethod($service, 'buildCumulativeTierRows');
if (PHP_VERSION_ID < 80100) $build->setAccessible(true);
$tiers = [
    ['name' => '10000以上', 'lower' => 1000000, 'upper' => null],
    ['name' => '5000-10000', 'lower' => 500000, 'upper' => 1000000],
    ['name' => '0-5000', 'lower' => 0, 'upper' => 500000],
];
$members = [
    1 => ['amount' => 980000, 'source_a' => false],
    2 => ['amount' => 133000, 'source_a' => false],
    3 => ['amount' => 2000000, 'source_a' => true],
    4 => ['amount' => 500000, 'source_a' => false],
];

$all = $build->invoke($service, $tiers, $members, false);
$new = $build->invoke($service, $tiers, $members, true);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$assert(array_column($all, 'people') === [1, 3, 4], 'Member thresholds must accumulate, including the exact boundary');
$assert(array_column($all, 'amount_cents') === [2000000, 3480000, 3613000], 'Member rows must retain full net amounts');
$assert(array_column($new, 'people') === [0, 2, 3], 'Source A must not enter new-customer tiers');
$assert(array_column($new, 'amount_cents') === [0, 1480000, 1613000], 'New-customer amounts must accumulate');
$assert(abs($all[2]['member_share'] - 100.0) < 0.0001
    && abs($new[2]['amount_share'] - 100.0) < 0.0001, 'Lowest threshold must cover the eligible cohort');
$assert($all[1]['average_cents'] === 1160000
    && $new[1]['average_cents'] === 740000, 'Averages must use each cumulative row');

echo "PASS 6 member/new-customer cumulative tier checks\n";
