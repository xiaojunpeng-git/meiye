<?php

declare(strict_types=1);

/** 只在本机开发库临时赋予样本单合作方归属，验证人数汇总/明细/导出后回滚。 */
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
$events = (new StoreDealPeopleQualificationServices())->qualifiedDays([41, 108], $range);
$sample = null;
foreach ($events as $event) {
    if ((int)($event['item_id'] ?? 0) > 0 && (string)($event['category_path'] ?? '') !== '') {
        $sample = $event;
        break;
    }
}
if ($sample === null) throw new RuntimeException('本机样本缺少可核实商品的达标原单');
$saleFactIds = Db::name('cashier_v3_sale_fact')->alias('s')
    ->join('cashier_v3_card_sale_item_allocation_fact c',
        "c.tenant_id=s.tenant_id AND c.store_id=s.store_id AND c.sale_fact_id=s.fact_id AND c.status='effective'")
    ->where('s.order_id', (string)$sample['sales_order_id'])
    ->where('s.store_id', (int)$sample['store_id'])
    ->where('c.component_product_id', (int)$sample['item_id'])
    ->column('s.fact_id');
if ($saleFactIds === []) throw new RuntimeException('本机样本缺少可核实的卡项明细');

Db::startTrans();
try {
    $changed = Db::name('cashier_v3_card_sale_category_allocation_fact')
        ->whereIn('sale_fact_id', array_values(array_unique($saleFactIds)))
        ->where('status', 'effective')->update(['partner_name_snapshot' => 'R41本机事务测试']);
    if ($changed < 1) throw new RuntimeException('本机样本缺少可切换的合作方分类事实');
    $date = (string)$sample['business_date'];
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
    if ($count !== 1 || $export['summary_row']['member_count'] !== $summary['summary_row']['member_count']) {
        throw new RuntimeException('合作方分类汇总和导出未按唯一达标原单计数');
    }
    $detail = $report->query([(int)$sample['store_id']], [
        'report' => 'partner_item_detail', 'metric_code' => 'member_count',
        'category_path_exact' => (string)$sample['category_path'],
        'start_date' => $date, 'end_date' => $date,
    ]);
    if ((int)$detail['total'] !== 1 || (int)$detail['summary_row']['member_count'] !== 1
        || (string)$detail['records'][0]['order_no'] !== (string)$sample['order_no_snapshot']) {
        throw new RuntimeException('合作方人数下钻与汇总未对齐原销售单');
    }
    echo "PASS R41 partner summary, detail and export\n";
} finally {
    Db::rollback();
}
