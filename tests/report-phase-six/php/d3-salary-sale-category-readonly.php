<?php

declare(strict_types=1);

use app\services\report\StoreUnifiedReportPhaseSixServices;
use think\facade\Db;

// Read-only RH fixture regression: frozen sale categories must reach salary buckets,
// exact employee/store drilldowns and the complete export projection without rewriting facts.
require '/var/www/html/vendor/autoload.php';
$app = new \think\App('/var/www/html/');
$app->initialize();
set_exception_handler(static function (Throwable $error): void {
    file_put_contents('php://stderr', $error->getMessage() . "\n");
    exit(1);
});

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message);
    $checks++;
    echo "PASS: $message\n";
};
$service = new StoreUnifiedReportPhaseSixServices();
$range = ['start' => '2026-10-08', 'end' => '2026-10-08'];
$input = ['salary_employee_id' => 663, 'salary_store_id' => 126];
$key = 'salary_category_cash_26321';
$snapshotQuery = static function (): array {
    return Db::name('cashier_v3_report_sale_dimension_fact')->alias('d')
        ->join('cashier_v3_sale_fact s', 's.tenant_id=d.tenant_id AND s.fact_id=d.sale_fact_id')
        ->where('s.store_id', 126)->where('s.business_date', '2026-10-08')
        ->where('d.item_name_snapshot', 'like', '%花园SPA%')
        ->field('d.sale_fact_id,d.category_id_snapshot,d.category_path_snapshot')
        ->order('d.sale_fact_id')->select()->toArray();
};
$before = $snapshotQuery();
$assert(count($before) === 4, 'fixture contains four matching sale dimensions');
foreach ($before as $snapshot) {
    $assert((int)$snapshot['category_id_snapshot'] === 26321
        && $snapshot['category_path_snapshot'] === '花园 / 花园产品', 'sale already has its frozen category');
}

$summary = $service->query('phase_six_salary_summary', [126], $range,
    ['employee_name' => '彭菲', '_internal_all' => true]);
$assert(count($summary['records']) === 1 && (int)$summary['records'][0]['employee_id'] === 663,
    'summary selects the exact employee');
$row = $summary['records'][0];
$assert($row[$key] === '1596' && $summary['summary_row'][$key] === '1596',
    'summary and total put 1596 yuan in the frozen category');
$assert(($row['salary_category_cash_unclassified'] ?? '0') === '0', 'known category is not unclassified');
$assert(array_sum(array_map('intval', array_filter($row,
    static fn(string $field): bool => strpos($field, 'salary_category_cash_') === 0, ARRAY_FILTER_USE_KEY))) === 1596,
    'classification preserves total employee cash');
$assert($row['project_count'] === '0' && $row['consumption_amount'] === '0' && $row['labor_fee'] === '0',
    'sales do not create service or labor amounts');

$detail = $service->query('phase_six_salary_detail', [126], $range, $input + ['salary_category_key' => $key]);
$export = $service->query('phase_six_salary_detail', [126], $range,
    $input + ['salary_category_key' => $key, '_internal_all' => true]);
$assert($detail['records'] === $export['records'] && $detail['summary_row'] === $export['summary_row'],
    'screen and complete export projection reconcile');
$assert(count($detail['records']) === 2 && $detail['summary_row']['cash_amount'] === '1596',
    'category drilldown explains the exact summary cell');
$amounts = [];
foreach ($detail['records'] as $record) {
    $assert((int)$record['store_id'] === 126 && (int)$record['employee_id'] === 663
        && $record['performance_category'] === '花园/花园产品', 'detail keeps store, employee and category');
    $amounts[$record['document_no']] = $record['cash_amount_cents'];
}
ksort($amounts);
$assert($amounts === ['XS26100800169' => 149000, 'XS26100800196' => 10600],
    'both original sales retain their cent amounts');
$empty = $service->query('phase_six_salary_detail', [126], $range,
    $input + ['salary_category_key' => 'salary_category_cash_unclassified']);
$assert($empty['records'] === [] && $empty['summary_row']['cash_amount'] === '0',
    'old unclassified link no longer exposes reclassified sales');
try {
    $service->query('phase_six_salary_detail', [15], $range, $input + ['salary_category_key' => $key]);
    throw new RuntimeException('unauthorized store was accepted');
} catch (InvalidArgumentException $error) {
    $assert(strpos($error->getMessage(), '无权限') !== false, 'drilldown cannot expand authorized stores');
}

// Unit boundary checks keep genuinely missing snapshots unclassified and preserve
// card-component allocation with integer-cent remainder, rather than using the card category.
$allocate = new ReflectionMethod($service, 'salarySaleCategoryAmounts');
$allocate->setAccessible(true);
$assert($allocate->invoke($service, ['source_type' => 'product', 'amount_cents' => 101,
    'category_id' => 26321, 'category_path' => '花园 / 花园产品'], [])
    === [26321 => ['cents' => 101, 'path' => '花园 / 花园产品']], 'non-card sales use the frozen ID');
$missing = $allocate->invoke($service, ['source_type' => 'product', 'amount_cents' => 101,
    'category_path' => '花园 / 花园产品'], []);
$assert(array_keys($missing) === [0], 'missing ID is not guessed from a category name');
$card = $allocate->invoke($service, ['source_type' => 'card', 'amount_cents' => 101,
    'category_id' => 999], [
        ['category_id_snapshot' => 2, 'category_path_snapshot' => 'A', 'cash_performance_amount_cents' => 2],
        ['category_id_snapshot' => 3, 'category_path_snapshot' => 'B', 'cash_performance_amount_cents' => 1],
    ]);
$assert(array_keys($card) === [2, 3] && $card[2]['cents'] === 67 && $card[3]['cents'] === 34,
    'card categories and last-component remainder are unchanged');

// A projection fix must not revive forward facts that were reversed on a later day.
// Query original dates with the same reader so classification cannot bypass terminal filtering.
$reversed = Db::name('cashier_v3_performance_fact')->alias('f')
    ->join('cashier_v3_performance_fact r', 'r.tenant_id=f.tenant_id AND r.reversal_of=f.fact_id')
    ->where('f.fact_direction', 'forward')->where('f.status', 'effective')
    ->where('r.fact_direction', 'reversal')->where('r.status', 'effective')
    ->whereRaw('r.business_date > f.business_date')
    ->whereIn('f.performance_type', ['sales_performance_allocated', 'labor_performance_allocated'])
    ->field('f.fact_id,f.store_id,f.business_date')->select()->toArray();
$assert($reversed !== [], 'cross-date reversal fixture is present');
$groups = [];
foreach ($reversed as $fact) {
    $groupKey = $fact['store_id'] . '|' . $fact['business_date'];
    $groups[$groupKey]['sample'] = $fact;
    $groups[$groupKey]['ids'][] = $fact['fact_id'];
}
foreach ($groups as $group) {
    $fact = $group['sample'];
    $originalRange = ['start' => $fact['business_date'], 'end' => $fact['business_date']];
    $original = $service->query('phase_six_salary_detail', [(int)$fact['store_id']], $originalRange,
        ['_internal_all' => true]);
    $assert(array_intersect($group['ids'], array_column($original['records'], 'row_key')) === [],
        'later-reversed facts stay excluded from original-date detail');
}
$assert($snapshotQuery() === $before, 'read-only verification leaves original dimensions unchanged');
echo "PASS: $checks salary sale-category checks\n";
