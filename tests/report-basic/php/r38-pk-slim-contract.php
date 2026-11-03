<?php
declare(strict_types=1);

/** PK 精简投影只读当前月现金与合作方分成；移除列对应的慢来源不得再执行。 */
require dirname(__DIR__, 3) . '/后端代码/vendor/autoload.php';

use app\services\report\Sidebar007PkEfficiencyV3ReadServices;
use app\services\report\Sidebar007ReportServices;

$fail = static function (): void { throw new RuntimeException('Removed PK source was queried'); };
$stores = [['id' => 7, 'name' => '万象城店']];
$partner = static fn(): array => [['store_id' => 7, 'partner_amount_cents' => '10000',
    'outsourced_amount_cents' => '0', 'refund_deduction_cents' => '0',
    'unclassified_rows' => '0', 'unsigned_reversal_rows' => '0']];
$payments = static fn(): array => [['fact_id' => 'p1', 'store_id' => 7, 'order_id' => 'RCH:7',
    'source_line_id' => 'pay-1', 'source_document_type' => 'recharge',
    'amount_cents' => 110000, 'eligible' => true]];
$result = (new Sidebar007PkEfficiencyV3ReadServices())->readPk([7], $stores, '2026-10', [],
    $fail, $partner, $fail, $payments, static fn(): array => [], $fail, '2026-10-31', $fail,
    ['store_name', 'month_cash_cents', 'sharing_cents', 'actual_cents']);
$expected = ['store_name', 'month_cash_cents', 'sharing_cents', 'actual_cents'];
if (array_column($result['columns'], 'key') !== $expected
    || array_keys($result['rows'][0]['cells']) !== $expected
    || $result['rows'][0]['cells']['month_cash_cents'] !== 110000
    || $result['rows'][0]['cells']['sharing_cents'] !== 10000
    || $result['rows'][0]['cells']['actual_cents'] !== 100000
    || !$result['complete']) {
    throw new RuntimeException('PK compact columns or cash minus partner amount changed');
}
$fields = (new Sidebar007ReportServices())->definitions('basic_sales_pk');
if (array_column($fields, 'key') !== $expected || array_filter($fields, static fn($f): bool => !$f['money'] && $f['key'] !== 'store_name')) {
    throw new RuntimeException('PK visible and export field contracts differ');
}
$context = ['visible_store_ids' => [7], 'basic_scope_mode' => 'stores',
    'query_cutoff_date' => '2026-10-31', 'permissions' => [], 'data_as_of' => time()];
$source = static fn(): array => $result;
$storeReader = static fn(): array => $stores;
$report = new Sidebar007ReportServices($source, $storeReader);
$range = ['start_date' => '2026-10-01', 'end_date' => '2026-10-31'];
$page = $report->query('basic_sales_pk', $context, $range);
$export = $report->query('basic_sales_pk', $context, $range + [
    'export' => ['scope' => 'query', 'fields' => $expected],
]);
if (array_column($page['queryFields'], 'key') !== $expected
    || array_keys(array_intersect_key($page['rows'][0], array_fill_keys($expected, true))) !== $expected
    || $page['rows'][0]['actual_cents'] !== '1000.00'
    || $export['exportRows'][0]['actual_cents'] !== '1000.00') {
    throw new RuntimeException('PK page and export do not share the compact projection');
}
try {
    $report->query('basic_sales_pk', $context, $range + [
        'export' => ['scope' => 'query', 'fields' => ['goal_cents']],
    ]);
    throw new RuntimeException('Removed PK goal was still exportable');
} catch (\InvalidArgumentException|\app\services\query\UnifiedQueryException $expectedError) {
    // 删除的列不能通过导出接口绕过后端字段白名单。
}
echo "PASS PK four columns, cash amount, partner allocation, and unused source bypass\n";
