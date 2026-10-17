<?php

declare(strict_types=1);

/** Read-only local regression for a cashier-snapshot sale paid again through debt repayment. */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require rtrim($backend, '/\\') . '/vendor/autoload.php';

use app\services\report\StoreDealPeopleQualificationServices;
use app\services\report\StoreUnifiedReportServices;
use app\services\report\GroupManagementDashboardServices;
use think\facade\Db;

(new \think\App(rtrim($backend, '/\\') . '/'))->initialize();
if (getenv('R42_DEAL_PEOPLE_LOCAL_TEST') !== '1'
    || config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('Explicit local Ruihao development database required');
}

$orderNo = 'XS26100400111';
$repaymentNo = 'BJ26101300015';
$sale = Db::name('cashier_v3_sales_order')->where('order_no', $orderNo)->find();
if (!$sale || (int)$sale['member_id'] !== 1015607 || (int)$sale['store_id'] !== 108
    || (string)$sale['business_source_primary_name_snapshot'] !== 'A老客首护') {
    throw new RuntimeException('The local original-sale regression sample is unavailable');
}
$originalCash = Db::name('cashier_v3_payment_fact')->where('order_id', $sale['order_id'])
    ->where('source_document_type', 'cashier_snapshot')->where('fact_type', 'payment_collected')
    ->where('fact_direction', 'forward')->where('status', 'effective')
    ->where('business_date', '2026-10-04')->sum('amount_cents');
$repayment = Db::name('cashier_v3_debt_repayment')->where('repayment_no', $repaymentNo)
    ->where('sales_order_id', $sale['order_id'])->where('status', 'succeeded')->find();
if ((int)$originalCash !== 200000 || !$repayment || (int)$repayment['repayment_amount_cents'] !== 200000) {
    throw new RuntimeException('The local cash-and-repayment regression sample changed');
}

$qualifier = new StoreDealPeopleQualificationServices();
$onSaleDay = $qualifier->qualifiedDays([108], ['start' => '2026-10-04', 'end' => '2026-10-04']);
$onRepaymentDay = $qualifier->qualifiedDays([108], ['start' => '2026-10-13', 'end' => '2026-10-13']);
$memberEvents = static fn(array $days): array => array_values(array_filter($days,
    static fn(array $day): bool => (int)$day['member_id'] === 1015607));
$saleEvents = $memberEvents($onSaleDay);
if (count($saleEvents) !== 1 || (string)$saleEvents[0]['sales_order_id'] !== (string)$sale['order_id']
    || (string)$saleEvents[0]['business_date'] !== '2026-10-04'
    || $memberEvents($onRepaymentDay) !== []) {
    throw new RuntimeException('Snapshot sale must qualify on its first cash day, not again on repayment day');
}

$reports = new StoreUnifiedReportServices();
$detail = static function (string $date) use ($reports): array {
    $result = $reports->query([108], [
        'report' => 'market_detail', 'start_date' => $date, 'end_date' => $date,
        '_internal_all' => true,
    ]);
    return array_values(array_filter((array)$result['records'],
        static fn(array $row): bool => (int)($row['member_id'] ?? 0) === 1015607
            && (int)($row['business_source_primary_id'] ?? 0) === 17));
};
$saleRows = $detail('2026-10-04');
$repaymentRows = $detail('2026-10-13');
if (count($saleRows) !== 1 || (int)$saleRows[0]['effective_people'] !== 1
    || count($repaymentRows) !== 1 || (int)$repaymentRows[0]['effective_people'] !== 0
    || (int)$repaymentRows[0]['amount_cents'] !== 200000) {
    throw new RuntimeException('Market detail must move only the person, retaining repayment cash on its business day');
}

// Market totals and the platform dashboard must project the same shared qualification events.
foreach (['2026-10-04' => $onSaleDay, '2026-10-13' => $onRepaymentDay] as $date => $events) {
    $expected = count(array_filter($events,
        static fn(array $event): bool => (int)$event['business_source_primary_id'] === 17));
    $market = $reports->query([108], [
        'report' => 'market_performance', 'start_date' => $date, 'end_date' => $date,
        '_internal_all' => true,
    ]);
    $storeRows = array_values(array_filter((array)$market['records'],
        static fn(array $row): bool => (int)($row['store_id'] ?? 0) === 108));
    if (count($storeRows) !== 1 || (int)$storeRows[0]['channel_17_effective'] !== $expected) {
        throw new RuntimeException('Market summary differs from shared source-A qualification events');
    }

    $dashboard = new GroupManagementDashboardServices();
    $sources = new ReflectionMethod($dashboard, 'sources');
    $sources->setAccessible(true);
    $sourceRows = $sources->invoke($dashboard, [108], ['start' => $date, 'end' => $date], [], [], []);
    $sourceA = array_values(array_filter($sourceRows['records'],
        static fn(array $row): bool => (int)$row['source_id'] === 17));
    if (count($sourceA) !== 1 || (int)$sourceA[0]['deal_count'] !== $expected) {
        throw new RuntimeException('Platform dashboard differs from shared source-A qualification events');
    }
}

echo "PASS R42 cashier-snapshot original payment and later repayment qualification\n";
