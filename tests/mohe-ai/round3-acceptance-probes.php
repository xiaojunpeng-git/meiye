<?php
// Reuse only the disposable MySQL fixture; never loads customer configuration.
require __DIR__ . '/query-mysql.php';
$failures = [];
$verify = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) $failures[] = $label;
};
$connection = $db->connect('fixture');
$connection->startTrans();
try {
    $connection->execute("INSERT INTO eb_cashier_v3_entitlement_service_fact (tenant_id,store_id,business_date,checkout_request_id,source_line_id,service_status,quantity,member_id,order_id) VALUES
        ('0',1,'2026-09-09','acceptance-1','acceptance-1','completed',1,900002,'acceptance-order-1'),
        ('0',1,'2026-09-10','acceptance-2','acceptance-2','completed',1,900002,'acceptance-order-2')");
    $probeRange = ['start'=>'2026-09-09','end'=>'2026-09-10'];
    $expected = $registered->summary('customer_active', '0', [1], $probeRange);
    $byStore = $registered->storeTotals('customer_active', '0', [1], $probeRange);
    $byMonth = $registered->periodTotals('customer_active', '0', [1], $probeRange, 'month');
    $verify($expected === 1 && $byStore[0]['metric_value'] === $expected, 'distinct member store period equals 1; actual=' . $byStore[0]['metric_value']);
    $verify($expected === 1 && $byMonth['2026-09'] === $expected, 'distinct member monthly period equals 1; actual=' . $byMonth['2026-09']);
    $bucket = $registered->categoryReportBuckets('consume_amount', '0', [1], $range, [71], $completeFilters)[0];
    $reportClass = new ReflectionClass(app\services\report\StoreUnifiedReportServices::class);
    $report = $reportClass->newInstanceWithoutConstructor();
    $match = $reportClass->getMethod('itemAnalysisConfiguredCategory');
    $match->setAccessible(true);
    $definitions = ['71'=>['key'=>'category_71','label'=>'护理','category_id'=>71,'category_path'=>'护理']];
    // Reader report buckets expose one normalized category pair to every
    // consumer; no page-specific service fact field is allowed here.
    $result = $match->invoke($report, $definitions, (int)($bucket['category_id'] ?? 0), (string)($bucket['category_path'] ?? ''));
    $verify($result !== null && $result['id'] === '71', 'consumption bucket resolves category 71 using normalized reader fields');
} finally {
    $connection->rollback();
}
echo 'ROUND3_ACCEPTANCE_FAILURES=' . count($failures) . PHP_EOL;
exit($failures === [] ? 0 : 1);
