<?php
/** R51 目录合同：26张已点名报表，包含四张经营分析与门店对账单，历史导入范围仅刷单。 */
require dirname(__DIR__, 3) . '/后端代码/app/services/report/BasicReportCatalogServices.php';
use app\services\report\BasicReportCatalogServices as Catalog;
$checks = 0;
$assert = static function ($value, string $message) use (&$checks): void {
    ++$checks;
    if (!$value) throw new RuntimeException($message);
};
$entries = Catalog::entries();
$assert(count($entries) === 26, 'All twenty-six explicitly requested reports');
$assert(count(array_unique(array_column($entries, 'code'))) === 26, 'Stable codes must be unique');
$groups = array_count_values(array_column($entries, 'group'));
$assert($groups === ['经营分析' => 4, '门店统计' => 8, '员工统计' => 6, '其他报表' => 8], 'Preserve groups and add real-time payroll under employee statistics');
$assert(count(array_filter($entries, static function ($r) { return $r['historical_import']; })) === 1, 'Only brush imports history');
foreach ($entries as $entry) {
    $assert(Catalog::definition($entry['code']) === $entry, 'Exact code resolves one definition');
    $assert($entry['scope'] === 'platform_and_store', 'Both terminals use the same business definition');
}
$assert(Catalog::definition('basic_brush_registration')['historical_import'], 'Brush history explicitly included');
$assert(Catalog::definition('basic_unconsumed_summary')['time_mode'] === 'current', 'Current balances are not historical');
$assert(Catalog::definition('basic_unconsumed_as_of_detail')['time_mode'] === 'current', 'Approved live report has no historical selector');
$assert(Catalog::definition('basic_customer_entitlements')['time_mode'] === 'current', 'Customer entitlements use current card project facts');
$assert(Catalog::definition('basic_salary_realtime')['time_mode'] === 'realtime_month', 'Payroll is real-time monthly, not old stored payroll results');
try { Catalog::definition('salary_report'); throw new RuntimeException('Unknown code unexpectedly allowed'); }
catch (InvalidArgumentException $expected) { $assert(true, 'Unknown report fails closed'); }
echo json_encode(['checks' => $checks, 'pass' => true], JSON_UNESCAPED_UNICODE), PHP_EOL;
