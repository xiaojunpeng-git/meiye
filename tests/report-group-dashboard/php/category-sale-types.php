<?php
declare(strict_types=1);

// Run through scripts/group-dashboard-sale-types-local-verification.mjs. Baseline is loaded from an
// immutable Git blob in memory, never a parallel editable business source tree.
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();
use app\services\query\metric\RegisteredMetricReadServices;
use app\services\query\metric\SaleTypesBaselineMetricReader;
use app\services\report\GroupManagementDashboardServices;
use think\facade\Db;
// BASELINE_INJECTION
if (!is_file('/.dockerenv') || config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao') throw new RuntimeException('Local mirror only');
$checks = 0;
function r91Check(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
$reader = RegisteredMetricReadServices::withCurrentReportProductCategory();
$baseline = SaleTypesBaselineMetricReader::withCurrentReportProductCategory();
$dashboard = new GroupManagementDashboardServices();
$treeMethod = new ReflectionMethod($dashboard,'categories');
$treeMethod->setAccessible(true);
$queryLog = [];
Db::listen(static function ($sql) use (&$queryLog): void { $queryLog[] = $sql; });
Db::execute('SET TRANSACTION READ ONLY');
Db::startTrans();
try {
    $tree = $treeMethod->invoke($dashboard);
    $cases = [];
    $range = ['start'=>'2026-10-01','end'=>'2026-10-17'];
    foreach ([[23],[68],[23,68],[2147483647]] as $stores) {
        $queryLog = [];
        $start = microtime(true);
        $result = $reader->dashboardSaleTypeBreakdown('0',$stores,$range,$tree['roots'],$tree['children']);
        $seconds = round(microtime(true)-$start,3);
        $quantityQueries = array_values(array_filter($queryLog,static fn($sql)=>strpos($sql,'SUM(p.quantity)')!==false));
        r91Check(count($quantityQueries) === 1, 'Exactly one quantity query for all categories');
        r91Check($result['totals'] === $baseline->dashboardSaleTypeTotals('0',$stores,$range), 'Top four types match accepted baseline exactly');
        $display = [];
        foreach ($tree['roots'] as $root) {
            $rootId = (int)$root['id']; $queue = [$rootId]; $ids = [];
            while ($queue) {
                $id = (int)array_shift($queue);
                if (isset($ids[$id])) continue;
                $ids[$id] = true;
                foreach ($tree['children'][$id] ?? [] as $next) $queue[] = $next;
            }
            $expected = $baseline->dashboardSaleTypeTotals('0',$stores,$range,array_keys($ids));
            unset($expected['experience_card']);
            r91Check(array_keys($result['categories'][$rootId]) === ['normal_card','project','product'], 'Three fixed types in stable order, including zeros');
            r91Check($result['categories'][$rootId] === $expected, 'Each category exactly equals the original top-card logic with category scope');
            $display[$rootId] = $expected;
        }
        $money = $reader->categoryDashboardCards('actual_performance','0',$stores,$range,$tree['roots'],$tree['children']);
        $oldMoney = $baseline->categoryDashboardCards('actual_performance','0',$stores,$range,$tree['roots'],$tree['children']);
        $strip = static function(array $cards): array {
            foreach ($cards as &$card) { unset($card['secondary_categories'],$card['source_explanation']); }
            return $cards;
        };
        r91Check($strip($money) === $strip($oldMoney), 'Parent amounts, shares, rankings, and drilldown parameters unchanged');
        $cases[] = ['stores'=>$stores,'seconds'=>$seconds,'totals'=>$result['totals'],'categories'=>$display];
    }
    $empty = $reader->dashboardSaleTypeBreakdown('0',[23],['start'=>'2099-01-01','end'=>'2099-01-01'],$tree['roots'],$tree['children']);
    foreach ($empty['categories'] as $types) foreach ($types as $type) r91Check($type === ['sales_quantity'=>0,'cash_performance_cents'=>0], 'No-data types remain zero');
    $filtered = $reader->dashboardSaleTypeBreakdown('0',[23],$range,$tree['roots'],$tree['children'],[26332]);
    r91Check($filtered['totals'] === $baseline->dashboardSaleTypeTotals('0',[23],$range,[26332]), 'Selected category still limits top totals');
    try {
        $reader->dashboardSaleTypeBreakdown('0',[],$range,$tree['roots'],$tree['children']);
        throw new RuntimeException('Empty permission scope accepted');
    } catch (\app\services\query\metric\MetricQueryContractException $error) { $checks++; }
    // A single-store end-to-end service response validates labels and the shared
    // composition used by platform/embedded dashboards without changing data.
    $page = $dashboard->dashboard(['tenant_id'=>'0','store_ids'=>[23]],['start_date'=>$range['start'],'end_date'=>$range['end']]);
    r91Check(array_column($page['sale_type_cards'],'title') === ['疗程卡','单项','产品'], 'Only experience card display removed');
    foreach ($page['sale_type_cards'] as $type) {
        r91Check(['sales_quantity'=>$type['sales_quantity'],'cash_performance_cents'=>$type['cash_performance_cents']]
            === $baseline->dashboardSaleTypeTotals('0',[23],$range)[$type['code']], 'Visible top values unchanged');
    }
    foreach ($page['categories'] as $card) {
        if ($card['category_id'] === 0) continue;
        r91Check(array_column($card['sale_types'],'title') === ['疗程卡','单项','产品'], 'Every first-level category exposes the same three labels');
        r91Check(!isset($card['secondary_categories']), 'Superseded child-category projection removed');
    }
    echo json_encode(['result'=>'PASS','checks'=>$checks,'cases'=>$cases,'writes'=>0],JSON_UNESCAPED_UNICODE),PHP_EOL;
} finally { Db::rollback(); }
