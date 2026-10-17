<?php
declare(strict_types=1);

use app\services\report\StoreUnifiedReportPhaseFourServices;
use app\services\report\StoreUnifiedReportPhaseThreeServices;
use think\facade\Db;

// Read-only RH reconciliation: the six-dimension analysis must subtract a
// classified standalone refund once, on its refund business date and store.
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();
$config = config('database.connections.mysql');
if (($config['hostname'] ?? '') !== 'mysql' || ($config['database'] ?? '') !== 'ruihao') {
    throw new RuntimeException('Local ruihao database required');
}
$check = static function (bool $passed, string $label): void {
    if (!$passed) throw new RuntimeException($label);
    echo 'PASS ' . $label . "\n";
};

$service = new StoreUnifiedReportPhaseFourServices();
$reflect = static function (string $method) use ($service): ReflectionMethod {
    $reflection = new ReflectionMethod($service, $method);
    $reflection->setAccessible(true);
    return $reflection;
};
$categoryIds = $reflect('categoryIds')->invoke($service, '六维', []);
$baseCash = $reflect('cashRows');
$completeCash = $reflect('sixDimensionCashRows');
$standalone = $reflect('comparisonStandaloneRefundRows');
$cents = $reflect('cents');
$allocator = $reflect('allocateStandaloneRefundCategoryCents');
$range = ['start' => '2026-10-08', 'end' => '2026-10-08'];
$storeId = 145;

Db::execute('SET TRANSACTION READ ONLY');
Db::startTrans();
try {
    $standaloneRows = $standalone->invoke($service, [$storeId], $range);
    $target = array_values(array_filter($standaloneRows, static fn(array $row): bool =>
        (string)$row['order_no_snapshot'] === 'TK2610100001'));
    $check(count($target) === 1
        && (int)$target[0]['amount_cents'] === -500000
        && str_starts_with((string)$target[0]['category_path'], '六维/自营'),
        'standalone refund resolves once to the verified six-dimension self-run category');

    $linkedRows = $baseCash->invoke($service, [$storeId], $range, $categoryIds);
    $check(count(array_filter($linkedRows, static fn(array $row): bool =>
        (string)($row['order_no_snapshot'] ?? '') === 'TK2610100001')) === 0,
        'refund without an allocation is absent from sale-allocation cash rows');
    $allRows = $completeCash->invoke($service, [$storeId], $range, $categoryIds);
    $included = array_values(array_filter($allRows, static fn(array $row): bool =>
        (string)($row['order_no_snapshot'] ?? '') === 'TK2610100001'));
    $check(count($included) === 1 && (int)$included[0]['amount_cents'] === -500000,
        'complete six-dimension cash scope appends the missing refund exactly once');

    $screenDay = ['start' => '2026-10-18', 'end' => '2026-10-18'];
    $otherCategoryRefunds = $standalone->invoke($service, [23], $screenDay);
    $screenRefund = array_values(array_filter($otherCategoryRefunds, static fn(array $row): bool =>
        (string)$row['order_no_snapshot'] === 'TK2610180001'));
    $screenSixRows = $completeCash->invoke($service, [23], $screenDay, $categoryIds);
    $check(count($screenRefund) === 1
        && (string)$screenRefund[0]['category_path'] === '生美/卡项'
        && count(array_filter($screenSixRows, static fn(array $row): bool =>
            (string)($row['order_no_snapshot'] ?? '') === 'TK2610180001')) === 0,
        'the highlighted October 18 beauty refund stays outside six-dimension data');

    $expectedSelf = $expectedPartner = 0;
    foreach ($allRows as $row) {
        if (str_contains((string)($row['category_path'] ?? ''), '合作')) {
            $expectedPartner += (int)$row['amount_cents'];
        } else {
            $expectedSelf += (int)$row['amount_cents'];
        }
    }
    $report = $service->query('six_dimension_analysis', [$storeId], $range, []);
    $row = current(array_filter($report['records'], static fn(array $record): bool =>
        (int)($record['store_id'] ?? 0) === $storeId));
    $check(is_array($row)
        && $cents->invoke($service, $row['self_received_performance']) === $expectedSelf
        && $cents->invoke($service, $row['partner_received_performance']) === $expectedPartner
        && $cents->invoke($service, $row['all_received_performance']) === $expectedSelf + $expectedPartner,
        'table self, partner and all received performance reconcile to the complete signed cash scope');
    $check((string)$row['company_name'] !== '未配置分公司',
        'standalone refund retains its organization snapshot for company grouping');
    $check((string)$report['metric_version'] === 'six-dimension-analysis-standalone-refund-v6',
        'table declares the standalone-refund metric version');

    $detail = (new StoreUnifiedReportPhaseThreeServices())->query(
        'six_dimension_consumption_refund_detail', [$storeId], $range,
        ['metric_code' => 'phase_four_amount', 'category_path' => '六维 / 自营']
    );
    $refundDetails = array_values(array_filter($detail['records'], static fn(array $record): bool =>
        (string)($record['source_order_id'] ?? '') === (string)$target[0]['order_id']));
    $check(count($refundDetails) === 1
        && $cents->invoke($service, $refundDetails[0]['refund_amount']) === 500000,
        'six-dimension popup shows the same standalone refund once as a positive refund amount');

    $categories = [
        ['id' => 1, 'path' => '六维/自营/A', 'platform_product_id' => 11, 'weight_cents' => 10000],
        ['id' => 2, 'path' => '六维/合作/B', 'platform_product_id' => 22, 'weight_cents' => 20000],
    ];
    $split = $allocator->invokeArgs($service, [-10000, &$categories]);
    $check(array_sum($split) === -10000
        && count(array_filter($split, static fn(int $amount): bool => $amount % 100 !== 0)) === 0,
        'verified whole-card refund split preserves total and whole-yuan amounts');
    $unverified = [
        ['id' => 1, 'path' => '六维/自营/A', 'platform_product_id' => 11, 'weight_cents' => 0],
        ['id' => 2, 'path' => '六维/合作/B', 'platform_product_id' => 22, 'weight_cents' => 20000],
    ];
    $collapsed = $allocator->invokeArgs($service, [-10000, &$unverified]);
    $check($collapsed === [-10000] && count($unverified) === 1 && (int)$unverified[0]['id'] === 0,
        'unverified multi-item refund stays one unclassified amount instead of guessing');
    echo 'LOCAL_STORE145_2026_10_08_CENTS=' . $expectedSelf . '/' . $expectedPartner
        . '/' . ($expectedSelf + $expectedPartner) . "\n";
} finally {
    Db::rollBack();
}
