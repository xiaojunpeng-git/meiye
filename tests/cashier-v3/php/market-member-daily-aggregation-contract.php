<?php

declare(strict_types=1);

/** 按门店、日期、会员 ID、来源聚合；同名会员和不同来源不能串行。 */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require rtrim($backend, '/\\') . '/vendor/autoload.php';

$service = new \app\services\report\StoreUnifiedReportPhaseTwoServices();
$method = new ReflectionMethod($service, 'marketMemberDailyRows');
$make = static function (string $date, int $member, int $source, string $order, int $cents, int $visits, int $walkIn): array {
    return [
        'store_id' => 133, 'business_date' => $date, 'member_id' => $member,
        'business_source_primary_id' => $source, 'order_id' => $order,
        'order_no_snapshot' => $order, 'amount_cents' => $cents,
        'creator_name' => $order, 'recorded_at' => 1790000000,
        'visits' => $visits, 'walk_in' => $walkIn, 'walk_in_version' => 1,
        'effective_people' => 0,
    ];
};
$rows = $method->invoke($service, [
    $make('2026-09-22', 42, 3, 'A', 10000, 1, 2),
    $make('2026-09-22', 42, 3, 'B', 2500, 1, 1),
    $make('2026-09-22', 42, 4, 'C', 5000, 1, 0),
    $make('2026-09-23', 42, 3, 'D', 8000, 0, 0),
    $make('2026-09-22', 43, 3, 'E', 7000, 0, 0),
    $make('2026-09-22', 0, 3, 'GUEST-A', 3000, 1, 0),
    $make('2026-09-22', 0, 3, 'GUEST-B', 4000, 1, 0),
]);
if (count($rows) !== 6) throw new RuntimeException('Different dates, members, sources or guest orders were merged');
$daily = $rows[0];
if ((int)$daily['amount_cents'] !== 12500 || (string)$daily['amount'] !== '125'
    || (int)$daily['visits'] !== 1 || (int)$daily['walk_in'] !== 3
    || (string)$daily['annotation_subject_key'] !== 'market-day-v1:133:2026-09-22:42:3'
    || (string)$daily['annotation_subject_type'] !== 'market_member_day'
    || (string)$daily['creator_name'] !== 'A、B'
    || array_column($daily['_market_orders'], 'order_id') !== ['A', 'B']) {
    throw new RuntimeException('Same-day member/source aggregation is incorrect');
}
$guests = array_values(array_filter($rows, static function (array $row): bool {
    return (int)$row['member_id'] === 0;
}));
if (count($guests) !== 2
    || (string)$guests[0]['annotation_subject_type'] !== 'market_guest_order'
    || (string)$guests[0]['source_order_id'] !== 'GUEST-A'
    || (string)$guests[0]['annotation_subject_key'] !== 'market-guest-v1:133:2026-09-22:3:' . hash('sha256', 'GUEST-A')
    || (string)$guests[1]['annotation_subject_key'] !== 'market-guest-v1:133:2026-09-22:3:' . hash('sha256', 'GUEST-B')) {
    throw new RuntimeException('Guest orders do not have independent stable annotation subjects');
}
echo "PASS market member-day-source amount, visits and legacy walk-in aggregation\n";
echo "PASS market guest orders retain independent stable annotation subjects\n";
