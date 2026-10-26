<?php

declare(strict_types=1);

/** 本地只读样本核对：成交人数资格共用市场会员日，分类报表只投影自己的正向净收款。 */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require rtrim($backend, '/\\') . '/vendor/autoload.php';

use app\services\report\StoreReportImportedRepaymentCountScope;
use app\services\report\StoreUnifiedReportPhaseFourServices;
use app\services\report\StoreUnifiedReportPhaseTwoServices;
use app\services\report\StoreUnifiedReportServices;

(new \think\App(rtrim($backend, '/\\') . '/'))->initialize();
if (getenv('MARKET_DEAL_CROSS_REPORT_LOCAL_TEST') !== '1'
    || config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('Explicit local Ruihao test database required');
}
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, 'FAIL ' . $error->getMessage() . PHP_EOL);
    exit(1);
});
$assert = static function (bool $pass, string $name): void {
    if (!$pass) throw new RuntimeException($name);
    echo 'PASS ' . $name . PHP_EOL;
};

$market = new StoreUnifiedReportPhaseTwoServices();
$partner = new StoreUnifiedReportServices();
$day = ['start' => '2026-10-08', 'end' => '2026-10-08'];
$input = ['report' => 'partner_item_summary', 'start_date' => $day['start'], 'end_date' => $day['end']];
$partnerResult = $partner->query([126], $input);
$partnerExport = $partner->export([126], $input);
$assert($partnerResult['records'] !== [] && $partnerExport['records'] === $partnerResult['records']
    && $partnerExport['summary_row'] === $partnerResult['summary_row'], 'partner query and export share values');
$partnerColumns = array_column($partnerResult['columns'], null, 'key');
$assert(($partnerColumns['member_count']['label'] ?? '') === '成交人数'
    && str_contains((string)$partnerColumns['member_count']['source_explanation'], 'A 来源满1,000元')
    && str_contains((string)$partnerColumns['member_count']['source_explanation'], '其他来源满500元')
    && ($partnerResult['summary_row']['member_count'] ?? null) === '-', 'partner column declares daily threshold and non-additive total');

// Read the exact count-eligible category cash population; keep refunds in its
// signed net even when the presentation row uses a separate refund operation.
$personCash = [];
$facts = new ReflectionMethod(StoreUnifiedReportServices::class, 'partnerItemFacts');
$facts->setAccessible(true);
$facts->invokeArgs($partner, [126, $day, $input, &$personCash]);
$qualified = $market->marketQualifiedMemberDays([126], $day, $input);
$netByPath = [];
foreach ($personCash as $fact) {
    if ((int)$fact['member_id'] <= 0) continue;
    $path = (string)$fact['category_path'];
    $key = $market->marketEffectiveMemberDayKey($fact);
    $netByPath[$path][$key] = (int)($netByPath[$path][$key] ?? 0) + (int)$fact['amount_cents'];
}
foreach ($partnerResult['records'] as $row) {
    $expected = 0;
    foreach ($netByPath[(string)$row['performance_type']] ?? [] as $key => $cents) {
        if ($cents > 0 && isset($qualified[$key])) $expected++;
    }
    $assert((int)$row['member_count'] === $expected, 'partner category uses qualified member-day net');
}

$six = new StoreUnifiedReportPhaseFourServices();
$sixDay = ['start' => '2026-10-11', 'end' => '2026-10-11'];
$sixResult = $six->query('six_dimension_analysis', [5], $sixDay, []);
$sixColumns = array_column($sixResult['columns'], null, 'key');
$assert($sixResult['records'] !== []
    && str_contains((string)$sixColumns['new_deal_people']['source_explanation'], '其他来源满500元')
    && str_contains((string)$sixColumns['old_deal_people']['source_explanation'], '不跨天凑门槛'), 'six-dimension column declares shared daily threshold');
$categoryIds = new ReflectionMethod(StoreUnifiedReportPhaseFourServices::class, 'categoryIds');
$categoryIds->setAccessible(true);
$ids = $categoryIds->invoke($six, '六维', []);
$cashRows = new ReflectionMethod(StoreUnifiedReportPhaseFourServices::class, 'cashRows');
$cashRows->setAccessible(true);
$sixCash = StoreReportImportedRepaymentCountScope::exclude(
    $cashRows->invoke($six, [5], $sixDay, $ids), [5]);
$sixQualified = $market->marketQualifiedMemberDays([5], $sixDay);
$sixNet = [];
foreach ($sixCash as $fact) {
    if ((int)$fact['member_id'] <= 0) continue;
    $key = $market->marketEffectiveMemberDayKey($fact);
    $sixNet[$key] = (int)($sixNet[$key] ?? 0) + (int)$fact['amount_cents'];
}
$expectedSix = 0;
foreach ($sixNet as $key => $cents) if ($cents > 0 && isset($sixQualified[$key])) $expectedSix++;
$actualSix = array_sum(array_column($sixResult['records'], 'total_deal_people'));
$assert($actualSix === $expectedSix && $actualSix > 0, 'six-dimension people match qualifying category member-days');
$assert(array_sum(array_column($sixResult['records'], 'new_deal_people'))
    + array_sum(array_column($sixResult['records'], 'old_deal_people')) === $actualSix,
    'six-dimension new and old people reconcile');
// The same report must add qualifying days, never pool sub-threshold receipts
// across dates or deduplicate a repeat customer over the whole query range.
$period = $six->query('six_dimension_analysis', [9],
    ['start' => '2026-10-08', 'end' => '2026-10-11'], []);
$dailyCount = 0;
foreach (['2026-10-08', '2026-10-09', '2026-10-10', '2026-10-11'] as $date) {
    $daily = $six->query('six_dimension_analysis', [9], ['start' => $date, 'end' => $date], []);
    $dailyCount += array_sum(array_column($daily['records'], 'total_deal_people'));
}
$assert($dailyCount > 0 && $dailyCount === array_sum(array_column($period['records'], 'total_deal_people')),
    'six-dimension period count equals independent qualifying days');

$marketInput = ['report' => 'market_detail', 'start_date' => $sixDay['start'], 'end_date' => $sixDay['end']];
$marketQuery = $partner->query([5], $marketInput);
$marketExport = $partner->export([5], $marketInput);
$assert($marketQuery['summary_row'] === $marketExport['summary_row']
    && $marketQuery['total'] === count($marketExport['records']), 'market detail query total and export reconcile');
echo "cross-report deal people local read-only checks passed\n";
