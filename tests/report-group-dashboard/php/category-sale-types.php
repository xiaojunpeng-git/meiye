<?php
declare(strict_types=1);

// Run through scripts/group-dashboard-sale-types-local-verification.mjs. Baseline is loaded from an
// immutable Git blob in memory, never a parallel editable business source tree.
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();
use app\services\query\metric\RegisteredMetricReadServices;
use app\services\query\metric\SaleTypesBaselineMetricReader;
use app\services\report\GroupManagementDashboardServices;
use app\services\query\metric\MetricDefinitionRegistry;
use app\services\report\StoreReportCurrentProductCategoryServices;
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
/** Independent delta oracle: preserve the old query population, adding only
 * verified legacy custom-card identities under their current platform setting.
 * No amount, date, void or payment rule is reconstructed by this test. */
function expectedSaleTypes(array $stores, array $range, array $categoryIds = []): array {
    global $baseline;
    $result = $baseline->dashboardSaleTypeTotals('0', $stores, $range, $categoryIds);
    $factory = new ReflectionMethod($baseline, 'factQuery');
    $factory->setAccessible(true);
    $cash = new ReflectionMethod($baseline, 'saleCashQuery');
    $cash->setAccessible(true);
    $queries = [
        [$factory->invoke($baseline, MetricDefinitionRegistry::get('sales_quantity')['source'], '0', $stores, $range), 'p', 'p.quantity', 'sales_quantity'],
        [$cash->invoke($baseline, '0', $stores, $range)->where('p.amount_cents', '>', 0), 's', 'p.amount_cents', 'cash_performance_cents'],
    ];
    foreach ($queries as [$query, $alias, $amount, $field]) {
        $rows = $query->join('store_product custom', "custom.id={$alias}.item_id")
            ->where("{$alias}.source_type", 'card')
            ->where(function ($q): void {
                $q->where('custom.id',8154)->whereOr(function ($q): void {
                    $q->where('custom.type',1)->where('custom.pid',8154);
                });
            })->fieldRaw("{$alias}.fact_id,{$alias}.store_id,{$alias}.item_id,{$amount} metric_value")
            ->select()->toArray();
        if (!$categoryIds) {
            foreach ($rows as $row) $result['normal_card'][$field] += (int)$row['metric_value'];
            continue;
        }
        $sales = array_column($rows, null, 'fact_id');
        if (!$sales) continue;
        if ($field === 'cash_performance_cents') {
            // Independent oracle uses the unchanged public registered projection,
            // not the new narrowed custom-card query or its aggregation helper.
            foreach ($baseline->categoryRows('cash_performance','0',$stores,$range,$categoryIds) as $row) {
                if (isset($sales[$row['sale_fact_id'] ?? ''])) $result['normal_card'][$field] += (int)$row['amount_cents'];
            }
            continue;
        }
        $items = Db::name('cashier_v3_card_sale_item_allocation_fact')->where('tenant_id','0')
            ->where('status','effective')->whereIn('sale_fact_id',array_keys($sales))
            ->field('sale_fact_id,card_receipt_id,component_product_id')->select()->toArray();
        $links = [];
        foreach ($items as $i => $item) $links[$i] = ['store_id'=>(int)$sales[$item['sale_fact_id']]['store_id'],'product_id'=>(int)$item['component_product_id']];
        $categories = (new StoreReportCurrentProductCategoryServices())->resolve($links);
        $receipts = [];
        foreach ($items as $i => $item) {
            if (!$item['card_receipt_id'] || !in_array((int)($categories[$i]['id'] ?? 0),$categoryIds,true)) continue;
            $receipts[$item['sale_fact_id'].':'.$item['card_receipt_id']] = true;
        }
        $result['normal_card'][$field] += count($receipts);
    }
    return $result;
}
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
    // This historical local mirror is not proof of today's production totals.
    $range = ['start'=>'2026-10-01','end'=>'2026-10-19'];
    r91Check((int)Db::name('store_product')->where('id',8154)->value('card_statistics_type') === 0, 'Oracle custom-card configuration is normal');
    foreach ([[23],[68],[1],[49],[23,68],[2147483647]] as $stores) {
        $queryLog = [];
        $start = microtime(true);
        $result = $reader->dashboardSaleTypeBreakdown('0',$stores,$range,$tree['roots'],$tree['children']);
        $seconds = round(microtime(true)-$start,3);
        $quantityQueries = array_values(array_filter($queryLog,static fn($sql)=>strpos($sql,'SUM(p.quantity)')!==false));
        r91Check(count($quantityQueries) === 1, 'Exactly one quantity query for all categories');
        r91Check($result['totals'] === expectedSaleTypes($stores,$range), 'Only custom-card quantity and positive cash enter the normal-card bucket');
        $oldTotals = $baseline->dashboardSaleTypeTotals('0',$stores,$range);
        if ($stores === [68]) r91Check($result['totals']['normal_card']['cash_performance_cents'] > $oldTotals['normal_card']['cash_performance_cents'], 'Real custom-card receipts reproduce the missing cash');
        foreach (['experience_card','project','product'] as $kind) r91Check($result['totals'][$kind] === $oldTotals[$kind], 'Other sale types unchanged');
        foreach (['cash_performance','actual_performance'] as $metric) {
            r91Check($reader->summary($metric,'0',$stores,$range) === $baseline->summary($metric,'0',$stores,$range), 'Overall cash and net performance unchanged');
        }
        $display = [];
        foreach ($tree['roots'] as $root) {
            $rootId = (int)$root['id']; $queue = [$rootId]; $ids = [];
            while ($queue) {
                $id = (int)array_shift($queue);
                if (isset($ids[$id])) continue;
                $ids[$id] = true;
                foreach ($tree['children'][$id] ?? [] as $next) $queue[] = $next;
            }
            $expected = expectedSaleTypes($stores,$range,array_keys($ids));
            unset($expected['experience_card']);
            r91Check(array_keys($result['categories'][$rootId]) === ['normal_card','project','product'], 'Three fixed types in stable order, including zeros');
            r91Check($result['categories'][$rootId] === $expected, 'Each category preserves ordinary sales and adds only custom receipt/component values');
            $display[$rootId] = $expected;
        }
        if ($stores === [49]) {
            $rootCount = array_sum(array_map(static fn($types)=>$types['normal_card']['sales_quantity'],$display));
            $rootCash = array_sum(array_map(static fn($types)=>$types['normal_card']['cash_performance_cents'],$display));
            r91Check($rootCount === $result['totals']['normal_card']['sales_quantity'] + 1, 'Real cross-root receipt counts once in both categories, once at the top');
            r91Check($rootCash === $result['totals']['normal_card']['cash_performance_cents'], 'Cross-root cash sums exactly to top cash');
        }
        $money = $reader->categoryDashboardCards('actual_performance','0',$stores,$range,$tree['roots'],$tree['children']);
        $oldMoney = $baseline->categoryDashboardCards('actual_performance','0',$stores,$range,$tree['roots'],$tree['children']);
        $strip = static function(array $cards): array {
            foreach ($cards as &$card) { unset($card['secondary_categories'],$card['source_explanation']); }
            return $cards;
        };
        r91Check($strip($money) === $strip($oldMoney), 'Parent amounts, shares, rankings, and drilldown parameters unchanged');
        $cases[] = ['stores'=>$stores,'seconds'=>$seconds,'before'=>$oldTotals,'totals'=>$result['totals'],'categories'=>$display];
    }
    $empty = $reader->dashboardSaleTypeBreakdown('0',[23],['start'=>'2099-01-01','end'=>'2099-01-01'],$tree['roots'],$tree['children']);
    foreach ($empty['categories'] as $types) foreach ($types as $type) r91Check($type === ['sales_quantity'=>0,'cash_performance_cents'=>0], 'No-data types remain zero');
    foreach ([[23],[68],[1],[49]] as $stores) {
        foreach ([[26332],[26332,26337],array_values(array_filter(array_map('intval',array_keys($tree['children'])),static fn($id)=>$id>0))] as $selected) {
            $filtered = $reader->dashboardSaleTypeBreakdown('0',$stores,$range,$tree['roots'],$tree['children'],$selected);
            r91Check($filtered['totals'] === expectedSaleTypes($stores,$range,$selected), 'Selected categories use component membership, not the custom shell');
        }
    }
    try {
        $reader->dashboardSaleTypeBreakdown('0',[],$range,$tree['roots'],$tree['children']);
        throw new RuntimeException('Empty permission scope accepted');
    } catch (\app\services\query\metric\MetricQueryContractException $error) { $checks++; }
    // A single-store end-to-end service response validates labels and the shared
    // composition used by platform/embedded dashboards without changing data.
    $page = $dashboard->dashboard(['tenant_id'=>'0','store_ids'=>[68]],['start_date'=>$range['start'],'end_date'=>$range['end']]);
    r91Check(array_column($page['sale_type_cards'],'title') === ['疗程卡','单项','产品'], 'Only experience card display removed');
    foreach ($page['sale_type_cards'] as $type) {
        r91Check(['sales_quantity'=>$type['sales_quantity'],'cash_performance_cents'=>$type['cash_performance_cents']]
            === expectedSaleTypes([68],$range)[$type['code']], 'Visible top values include precisely the custom-card delta');
    }
    foreach ($page['categories'] as $card) {
        if ($card['category_id'] === 0) continue;
        r91Check(array_column($card['sale_types'],'title') === ['疗程卡','单项','产品'], 'Every first-level category exposes the same three labels');
        r91Check(!isset($card['secondary_categories']), 'Superseded child-category projection removed');
    }
    echo json_encode(['result'=>'PASS','checks'=>$checks,'cases'=>$cases,'writes'=>0],JSON_UNESCAPED_UNICODE),PHP_EOL;
} finally { Db::rollback(); }
