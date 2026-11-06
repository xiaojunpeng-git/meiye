<?php
declare(strict_types=1);

/** Local-only read-only regression: compare optimized facts to the original
 * signed reader, then exercise name grouping and XLSX export consistency. */
require '/var/www/html/vendor/autoload.php';
(new \think\App('/var/www/html/'))->initialize();
ini_set('memory_limit', '512M');
$db = config('database.connections.mysql');
if (($db['hostname'] ?? '') !== 'mysql' || ($db['database'] ?? '') !== 'ruihao') {
    throw new RuntimeException('Local ruihao database required');
}
function check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
}
function invoke(object $service, string $method, ...$args) {
    $reflection = new ReflectionMethod($service, $method);
    $reflection->setAccessible(true);
    return $reflection->invoke($service, ...$args);
}
function fingerprint(array $rows, array $keys): array {
    $rows = array_map(static function ($row) use ($keys) {
        $values = [];
        foreach ($keys as $key) $values[] = (string)($row[$key] ?? '');
        return json_encode($values, JSON_UNESCAPED_UNICODE);
    }, $rows);
    sort($rows, SORT_STRING);
    return [count($rows), hash('sha256', implode("\n", $rows))];
}
$service = new \app\services\report\CustomerAnalyticsServices();
$cash = [
    ['item_id'=>'1','item_name'=>' 美肤 ','member_id'=>1,'amount_cents'=>10000,'category_path'=>'生美/面部'],
    ['item_id'=>'2','item_name'=>'美肤','member_id'=>1,'amount_cents'=>20000,'category_path'=>'生美/面部'],
    ['item_id'=>'3','item_name'=>'美肤','member_id'=>2,'amount_cents'=>-5000,'category_path'=>'生美/面部'],
    ['item_id'=>'4','item_name'=>'肩颈','member_id'=>1,'amount_cents'=>5000,'category_path'=>'生美/身体'],
];
$services = [
    ['project_id'=>'1','project_name_snapshot'=>'美肤','member_id'=>1,'consumption_cents'=>1000,'category_path'=>'生美/面部'],
    ['project_id'=>'2','project_name_snapshot'=>'美肤','member_id'=>1,'consumption_cents'=>2000,'category_path'=>'生美/面部'],
    ['project_id'=>'5','project_name_snapshot'=>'消耗专用','member_id'=>2,'consumption_cents'=>500,'category_path'=>'生美/身体'],
];
$grouped = invoke($service, 'groupBeautyItems', $cash, $services, ['_internal_all'=>true]);
check($grouped['total'] === 3, 'same trimmed name merges different item IDs; consumption-only item retained');
$byName = array_column($grouped['records'], null, 'item_name');
check($byName['美肤']['cash_amount'] === '250' && $byName['美肤']['deal_people'] === 2, 'signed cash sums and merged member dedup');
check($byName['美肤']['consumption_amount'] === '30' && $byName['美肤']['consumption_people'] === 1, 'consumption dedup after name grouping');
check($grouped['summary_row']['cash_amount'] === '300' && $grouped['summary_row']['deal_people'] === '2', 'report total people is union rather than sum');
check($grouped['summary_row']['consumption_people'] === '2', 'consumption total people union');
check(!in_array('item_id', array_column($grouped['columns'], 'key'), true), 'display and export columns do not mislabel grouped name as product ID');
$filtered = invoke($service, 'groupBeautyItems', $cash, $services, ['category_path'=>'生美/面部','_internal_all'=>true]);
check($filtered['total'] === 1 && $filtered['records'][0]['people_share'] === '100%', 'category filter and share use same population');
check(invoke($service, 'groupBeautyItems', [], [], [])['total'] === 0, 'empty facts remain empty');

\think\facade\Db::execute('SET TRANSACTION READ ONLY');
\think\facade\Db::startTrans();
try {
    $stores = array_map('intval', \think\facade\Db::name('system_store')->where('is_del', 0)->column('id'));
    $range = ['start'=>'2026-10-01', 'end'=>'2026-10-31'];
    $reader = new \app\services\report\StoreUnifiedReportPhaseFourServices();
    $ids = invoke($reader, 'categoryIds', '生美', []);
    check($ids !== [], 'local 生美 category subtree exists');
    $originalCash = invoke($reader, 'cashRows', $stores, $range, $ids);
    $cashKeys = ['id','store_id','member_id','item_id','item_name','category_id','category_path','amount_cents'];
    $cashFingerprint = fingerprint($originalCash, $cashKeys);
    unset($originalCash);
    $originalServices = invoke($reader, 'serviceRows', $stores, $range, $ids);
    $serviceKeys = ['service_fact_id','store_id','member_id','project_id','project_name_snapshot','category_id','category_path','consumption_cents'];
    $serviceFingerprint = fingerprint($originalServices, $serviceKeys);
    unset($originalServices);
    $optimized = (new \app\services\report\StoreUnifiedReportPhaseFourServices())->customerBeautyItemRows($stores, $range);
    check($cashFingerprint === fingerprint($optimized['cash'], $cashKeys), 'full-month signed cash fact multiset identical to original reader');
    check($serviceFingerprint === fingerprint($optimized['services'], $serviceKeys), 'full-month service and consumption fact multiset identical to original reader');
    foreach (array_merge($optimized['cash'], $optimized['services']) as $row) {
        if (!in_array((int)$row['category_id'], $ids, true)) throw new RuntimeException('non-beauty fact leaked');
    }
    echo 'PASS: all returned facts belong to 生美 subtree; cash=' . $cashFingerprint[0] . ', services=' . $serviceFingerprint[0] . "\n";
    unset($optimized);
    $store = [$stores[0]];
    $read = $service->read('customer_item_analysis', $store, $range, [], true);
    $export = $service->export('customer_item_analysis', $store, $range, []);
    try {
        check($read['records'] === $export['records'] && $read['summary_row'] === $export['summary_row'], 'single-store query/export rows and totals identical');
        check($read['columns'] === $export['columns'] && is_file($export['path']), 'XLSX uses identical grouped columns');
        $zip = new ZipArchive();
        check($zip->open($export['path']) === true, 'export is valid XLSX archive');
        $zip->close();
    } finally { @unlink($export['path']); }
    try {
        (new \app\services\report\StoreUnifiedReportPhaseFourServices())->customerBeautyItemRows($stores, $range, ['mode'=>'self_participant']);
        throw new RuntimeException('Missing employee was accepted');
    } catch (InvalidArgumentException $e) { echo "PASS: personal scope without employee fails closed\n"; }
} finally {
    \think\facade\Db::rollback();
}
