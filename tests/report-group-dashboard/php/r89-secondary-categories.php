<?php
declare(strict_types=1);

/** Local read-only reconciliation; synthetic edge cases never write business facts. */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();
use app\services\query\metric\RegisteredMetricReadServices;
use app\services\report\GroupManagementDashboardServices;
use think\facade\Db;

if (config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao') throw new RuntimeException('Local mirror only');
$checks = 0;
function r89Check(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
$reader = RegisteredMetricReadServices::withCurrentReportProductCategory();
$method = new ReflectionMethod($reader, 'categorySecondaryCards');
$method->setAccessible(true);
$children = [1 => [4, 2, 3, 5], 2 => [20]];
$labels = [4 => ['pid' => 1, 'name' => 'zero'], 2 => ['pid' => 1, 'name' => 'second'],
    3 => ['pid' => 1, 'name' => 'refund'], 5 => ['pid' => 9, 'name' => 'wrong parent']];
$rows = [['category_id' => 1, 'amount_cents' => 900], ['category_id' => 2, 'amount_cents' => 100],
    ['category_id' => 20, 'amount_cents' => 50], ['category_id' => 4, 'amount_cents' => 100],
    ['category_id' => 4, 'amount_cents' => -100], ['category_id' => 3, 'amount_cents' => -75],
    ['category_id' => 5, 'amount_cents' => 22]];
$result = $method->invoke($reader, 1, $rows, $children, $labels, 'actual_performance');
r89Check(array_column($result, 'category_id') === [2, 3], 'zero hidden, wrong parent excluded, configured order preserved');
r89Check(array_column($result, 'amount_cents') === [150, -75], 'descendants and refunds retained, parent-only amounts excluded');
r89Check($result[1]['drilldown'] === ['metric_code' => 'actual_performance', 'category_id' => 3], 'exact child drilldown');
r89Check($method->invoke($reader, 9, [], [], [], 'actual_performance') === [], 'no children creates no strip');

Db::execute('SET TRANSACTION READ ONLY');
Db::startTrans();
try {
    $dashboard = new GroupManagementDashboardServices();
    $treeMethod = new ReflectionMethod($dashboard, 'categories');
    $treeMethod->setAccessible(true);
    $tree = $treeMethod->invoke($dashboard);
    $range = ['start' => '2026-10-01', 'end' => '2026-10-17'];
    $evidence = [];
    foreach ([[23], [68], [2147483647]] as $stores) {
        $start = microtime(true);
        $cards = $reader->categoryDashboardCards('actual_performance', '0', $stores, $range,
            $tree['roots'], $tree['children'], [], $tree['labels']);
        $baseline = $reader->categoryDashboardCards('actual_performance', '0', $stores, $range,
            $tree['roots'], $tree['children']);
        $normalize = static function (array $values): array {
            foreach ($values as &$row) unset($row['secondary_categories']);
            return $values;
        };
        r89Check($normalize($cards) === $normalize($baseline), 'parent totals, shares and rankings unchanged');
        $count = 0;
        foreach ($cards as $card) foreach (($card['secondary_categories'] ?? []) as $child) {
            $id = (int)$child['category_id'];
            r89Check((int)$tree['labels'][$id]['pid'] === $card['category_id'], 'child belongs to displayed root');
            $ids = [$id]; $queue = [$id];
            while ($queue) foreach (($tree['children'][array_shift($queue)] ?? []) as $next) {
                if (!in_array($next, $ids, true)) { $ids[] = $next; $queue[] = $next; }
            }
            $expected = $reader->categorySummary('actual_performance', '0', $stores, $range, $ids);
            r89Check($expected === $child['amount_cents'] && $expected !== 0, 'registered metric agrees with child');
            $drill = $dashboard->drilldown(['tenant_id' => '0', 'store_ids' => $stores],
                ['start_date' => $range['start'], 'end_date' => $range['end']] + $child['drilldown']);
            r89Check((int)array_sum(array_column($drill['records'], 'amount_cents')) === $expected, 'drilldown reconciles');
            $count++;
        }
        if ($stores === [2147483647]) r89Check($count === 0, 'empty data scope leaks no children');
        $evidence[] = ['stores' => $stores, 'children' => $count, 'seconds' => round(microtime(true) - $start, 3), 'categories' => $cards];
    }
    try {
        $reader->categoryDashboardCards('actual_performance', '0', [], $range, $tree['roots'], $tree['children'], [], $tree['labels']);
        throw new RuntimeException('Empty authorization scope accepted');
    } catch (\app\services\query\metric\MetricQueryContractException $e) { $checks++; }
    echo json_encode(['checks' => $checks, 'result' => 'PASS', 'cases' => $evidence], JSON_UNESCAPED_UNICODE), PHP_EOL;
} finally { Db::rollback(); }
