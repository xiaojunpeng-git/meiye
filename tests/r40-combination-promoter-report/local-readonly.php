<?php
declare(strict_types=1);

/** 本地只读冒烟：真实 MySQL 验证汇总、分页明细、精确下钻和导出契约，不创建或修改业务事实。 */
require '/var/www/html/vendor/autoload.php';
$app = new think\App('/var/www/html/');
$app->env->load('/var/www/html/.env');
$app->initialize();
echo "initialized\n";
echo 'snapshots ' . think\facade\Db::name('combination_promotion_order')->count() . "\n";
echo 'paid group seats ' . think\facade\Db::name('store_pink')->where('is_virtual', 0)->count() . "\n";
if (config('database.connections.mysql.hostname') !== 'mysql' || config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('Only the local mysql/ruihao test database is allowed');
}
$admin = ['id' => 1, 'level' => 0, 'admin_type' => 1, 'employee_id' => 0, 'roles' => []];
$service = new app\services\activity\combination\CombinationPromotionServices();
$filters = ['start_date' => '2026-11-01', 'end_date' => '2026-11-30'];
try { $service->promoterSummary($filters, ['id'=>2,'level'=>1,'admin_type'=>1,'roles'=>[]]); throw new RuntimeException('Missing permission was accepted'); }
catch (think\exception\ValidateException $error) { if ($error->getMessage() !== '无推广人统计表权限') throw $error; }
echo "options\n";
$options = $service->promoterOptions($admin);
if (!isset($options['activities'], $options['scope']['tree'])) throw new RuntimeException('Options contract missing');
if (getenv('PROMOTER_TEST_FULL') !== '1') $filters['store_ids'] = [$options['scope']['allowed_store_ids'][0] ?? 0];
$summary = $service->promoterSummary($filters, $admin);
echo 'summary ' . $summary['count'] . "\n";
if (($summary['limit'] ?? 0) !== 20 || count($summary['columns'] ?? []) !== 9) throw new RuntimeException('Summary contract missing');
foreach ($summary['columns'] as $column) {
    if (!isset($column['value_source'], $column['summable'], $column['fixed_left'])) throw new RuntimeException('Summary column metadata missing');
    if ($column['summable'] && !isset($column['drill'])) throw new RuntimeException('Summary drill metadata missing');
}
$details = $service->details($filters, $admin, null, false, true);
echo "details\n";
if (($details['limit'] ?? 0) !== 20 || count($details['columns'] ?? []) !== 13) throw new RuntimeException('Detail contract missing');
foreach ($details['columns'] as $column) if (!isset($column['value_source'], $column['summable'], $column['fixed_left'])) throw new RuntimeException('Detail column metadata missing');
$rows = $summary['list'] ?? [];
if ($rows) {
    echo "drill\n";
    $drill = $service->details($filters + ['dimension' => 'member', 'dimension_id' => $rows[0]['promoter_id'], 'status' => 'formed', 'people_only' => 1], $admin, null, false, true);
    echo "drill-done\n";
    if (!array_key_exists('count', $drill)) { echo 'drill keys: ' . implode(',', array_keys($drill)) . "\n"; throw new RuntimeException('Drill contract missing'); }
}
echo "export-start\n";
try { $export = $service->promoterSummary($filters, $admin, true); }
catch (Throwable $error) { echo get_class($error) . ': ' . $error->getMessage() . "\n"; throw $error; }
echo "export\n";
if (($export['count'] ?? -1) !== $summary['count'] || !is_file($export['path'])) throw new RuntimeException('Export count mismatch');
$head = file_get_contents($export['path'], false, null, 0, 512);
if (strpos($head, '已成团推广业绩') === false) throw new RuntimeException('Export header missing');
unlink($export['path']);
echo 'PASS real local read-only promoter report contracts; summary ' . $summary['count'] . ', detail ' . $details['count'] . "\n";
