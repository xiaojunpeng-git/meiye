<?php

declare(strict_types=1);

/** R87 后以当前开启分类限定样本；只读验证既有原单达标算法与人数汇总/下钻/导出。 */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require rtrim($backend, '/\\') . '/vendor/autoload.php';

use app\services\report\StoreDealPeopleQualificationServices;
use app\services\report\StoreUnifiedReportServices;
use think\facade\Db;

(new \think\App(rtrim($backend, '/\\') . '/'))->initialize();
if (getenv('R41_DEAL_PEOPLE_LOCAL_TEST') !== '1'
    || config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('需要显式指定本机瑞昊开发库');
}
$range = ['start' => '2026-10-01', 'end' => '2026-10-17'];
$report = new StoreUnifiedReportServices();
$scopeMethod = new ReflectionMethod($report, 'partnerReportCategoryIds');
$scopeMethod->setAccessible(true);
$allowed = $scopeMethod->invoke($report, [41, 108]);
$events = (new StoreDealPeopleQualificationServices())->qualifiedDays([41, 108], $range);
$sample = null;
foreach ($events as $event) {
    if ((int)($event['item_id'] ?? 0) > 0 && isset($allowed[(int)($event['category_id'] ?? 0)])) {
        $sample = $event;
        break;
    }
}
if ($sample === null) throw new RuntimeException('本机样本缺少可核实商品的达标原单');
Db::execute('SET TRANSACTION READ ONLY');
Db::startTrans();
try {
    $date = (string)$sample['business_date'];
    $expected = array_values(array_filter((new StoreDealPeopleQualificationServices())->qualifiedDays(
        [(int)$sample['store_id']], ['start'=>$date, 'end'=>$date]),
        static fn(array $event): bool => (string)$event['category_path'] === (string)$sample['category_path']));
    if ($expected === []) throw new RuntimeException('本机样本缺少可核实的开启分类达标原单');
    $input = ['report' => 'partner_item_summary', 'start_date' => $date, 'end_date' => $date];
    $report = new StoreUnifiedReportServices();
    $summary = $report->query([(int)$sample['store_id']], $input);
    $export = $report->export([(int)$sample['store_id']], $input);
    $count = 0;
    foreach ($summary['records'] as $row) {
        if ((string)$row['performance_type'] === (string)$sample['category_path']) {
            $count += (int)$row['member_count'];
        }
    }
    if ($count !== count($expected) || $export['summary_row']['member_count'] !== $summary['summary_row']['member_count']) {
        throw new RuntimeException('合作方分类汇总和导出未按唯一达标原单计数');
    }
    $detail = $report->query([(int)$sample['store_id']], [
        'report' => 'partner_item_detail', 'metric_code' => 'member_count',
        'category_path_exact' => (string)$sample['category_path'],
        'start_date' => $date, 'end_date' => $date,
    ]);
    $actualOrders = array_column($detail['records'], 'order_no'); sort($actualOrders);
    $expectedOrders = array_column($expected, 'order_no_snapshot'); sort($expectedOrders);
    if ((int)$detail['total'] !== count($expected) || (int)$detail['summary_row']['member_count'] !== count($expected)
        || $actualOrders !== $expectedOrders) {
        throw new RuntimeException('合作方人数下钻与汇总未对齐原销售单');
    }
    echo "PASS R41 partner summary, detail and export\n";
} finally {
    Db::rollback();
}
