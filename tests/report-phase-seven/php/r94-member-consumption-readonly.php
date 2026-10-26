<?php
declare(strict_types=1);

/** Local-only real-data comparison. Baseline is an immutable Git blob loaded
 * in memory; every database statement runs inside a READ ONLY transaction. */
require '/var/www/html/vendor/autoload.php';
use app\services\report\MemberManagementDashboardServices;
use app\services\report\StoreReportCurrentProductCategoryServices;
use app\services\report\StoreReportNormalDataScopeServices;
use think\facade\Db;
(new think\App('/var/www/html/'))->initialize();
if (config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao') throw new RuntimeException('Local database only');
$baseline = base64_decode((string)getenv('R94_BASELINE_CLASS'), true);
if (!$baseline || strpos($baseline, 'final class MemberManagementDashboardServices') === false) throw new RuntimeException('Fixed baseline required');
eval(preg_replace('/^<\?php\s*/', '', str_replace('final class MemberManagementDashboardServices', 'final class R94BaselineMemberDashboard', $baseline)));
$old = new app\services\report\R94BaselineMemberDashboard();
$new = new MemberManagementDashboardServices();
$checks = 0;
$assert = static function ($condition, $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message);
    $checks++;
};
$preserved = static function (array $dashboard): array {
    unset($dashboard['data_as_of'], $dashboard['metric_version']);
    foreach (['amount','people','average'] as $key) unset($dashboard['field_explanations']['ranking.consumption_amount.' . $key]);
    unset($dashboard['consumption']['rankings']['consumption_projects'], $dashboard['consumption']['rankings']['consumption_products']);
    foreach ($dashboard['consumption']['categories'] as &$category) {
        unset($category['rankings']['consumption_projects'], $category['rankings']['consumption_products']);
    }
    unset($category);
    return $dashboard;
};
// Independent aggregation of authoritative project facts, including signed/zero
// amounts. Do not call the new ranking method to construct its own expected rows.
$rank = static function (array $rows): array {
    $groups = [];
    foreach ($rows as $row) {
        $name = trim((string)$row['item_name']);
        if ($name === '') continue;
        $groups[$name]['amount'] = ($groups[$name]['amount'] ?? 0) + (int)$row['amount_cents'];
        if ((int)$row['member_id'] > 0) $groups[$name]['members'][(int)$row['member_id']] = true;
    }
    $out = [];
    foreach ($groups as $name => $group) {
        $people = count($group['members'] ?? []);
        $out[] = ['name' => $name, 'consumption_cents' => $group['amount'], 'people' => $people,
            'average_cents' => $people ? (int)round($group['amount'] / $people) : null];
    }
    usort($out, static fn($a, $b) => ($b['consumption_cents'] <=> $a['consumption_cents']) ?: strcmp($a['name'], $b['name']));
    return array_slice($out, 0, 5);
};
Db::execute('SET TRANSACTION READ ONLY');
Db::startTrans();
try {
    $allStores = array_map('intval', Db::name('system_store')->where('is_del', 0)->where('name', '<>', '总部')->column('id'));
    $results = [];
    foreach ([['stores'=>[23], 'start'=>'2026-10-01','end'=>'2026-10-18'],
              ['stores'=>[49], 'start'=>'2026-10-01','end'=>'2026-10-18'],
              ['stores'=>$allStores, 'start'=>'2026-10-17','end'=>'2026-10-17'],
              ['stores'=>[23], 'start'=>'2000-01-01','end'=>'2000-01-01']] as $sample) {
        $start = microtime(true);
        $context = ['tenant_id'=>'0','store_ids'=>$sample['stores']];
        $input = ['start_date'=>$sample['start'],'end_date'=>$sample['end']];
        $before = $old->dashboard($context, $input);
        $baselineMs = round((microtime(true) - $start) * 1000);
        $start = microtime(true);
        $actual = $new->dashboard($context, $input);
        $candidateMs = round((microtime(true) - $start) * 1000);
        $assert($preserved($before) === $preserved($actual), 'Existing overview, sales ranks, tiers or visits changed');
        $query = Db::name('cashier_v3_performance_fact')->alias('p')
            ->join('cashier_v3_entitlement_service_fact s', "s.tenant_id=p.tenant_id AND s.checkout_request_id=p.checkout_request_id AND s.source_line_id=p.source_line_id AND s.service_status='completed'")
            ->where('p.tenant_id','0')->whereIn('p.store_id',$sample['stores'])
            ->where('p.status','effective')->where('p.performance_type','consumption_performance_recorded')
            ->whereBetween('p.business_date',[$sample['start'],$sample['end']]);
        (new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderServices($query, 's');
        $rows = $query->fieldRaw('p.amount_cents,p.member_id,p.store_id,s.project_id product_id,s.project_name_snapshot item_name')->select()->toArray();
        $resolved = (new StoreReportCurrentProductCategoryServices())->resolveVerifiedFactItems($rows);
        foreach ($rows as $index => &$row) $row['root_id'] = (int)($resolved[$index]['root_id'] ?? 0);
        unset($row);
        $assert($actual['consumption']['rankings']['consumption_projects'] === $rank($rows), 'Global service rank differs from facts');
        $assert(!isset($actual['consumption']['rankings']['consumption_products']), 'Obsolete product rank remains');
        foreach ($actual['consumption']['categories'] as $category) {
            $id = (int)$category['id'];
            $expected = $rank(array_values(array_filter($rows, static fn($row) => $row['root_id'] === $id)));
            $assert($category['rankings']['consumption_projects'] === $expected, "Category {$id} service ranking mismatch");
            $assert(!isset($category['rankings']['consumption_products']), 'Category product consumption remains');
        }
        $assert($new->dashboard($context, $input)['consumption'] === $actual['consumption'], 'Refresh changed result');
        $results[] = ['stores'=>count($sample['stores']), 'range'=>$input, 'fact_rows'=>count($rows),
            'baseline_ms'=>$baselineMs,'candidate_ms'=>$candidateMs,
            'service_top5'=>$actual['consumption']['rankings']['consumption_projects']];
    }
    try {
        $new->dashboard(['tenant_id'=>'0','store_ids'=>[23]], ['store_ids'=>49]);
        throw new RuntimeException('Unauthorized scope accepted');
    } catch (InvalidArgumentException $expected) { $checks++; }
    $context = ['tenant_id'=>'0','store_ids'=>[49]];
    $input = ['start_date'=>'2026-10-01','end_date'=>'2026-10-18'];
    $unfiltered = $new->dashboard($context,$input);
    foreach (array_slice($unfiltered['consumption']['categories'],0,3) as $category) {
        $filtered = $new->dashboard($context,$input+['category_id'=>$category['id']]);
        $assert(count($filtered['consumption']['categories']) === 1, 'Category filter expanded');
        $assert($filtered['consumption']['rankings']['consumption_projects'] === $category['rankings']['consumption_projects'], 'Category-filtered global rank mismatch');
    }
    echo json_encode(['result'=>'PASS','checks'=>$checks,'samples'=>$results], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
} finally {
    Db::rollback();
}
