<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../后端代码/app/services/report/Sidebar007ParityPlanServices.php';

use app\services\report\Sidebar007ParityPlanServices as Plan;

function ensure(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function rejects(callable $action, string $message): void
{
    try { $action(); } catch (InvalidArgumentException $error) { return; }
    throw new RuntimeException($message);
}

// 字段来自 007 真人页面；未经确认的销售数据与 PK 只锁定布局，不能伪造事实。
ensure(count(Plan::layout('sales_data')['rows']) === 20, '销售数据须保持 20 指标');
ensure(Plan::layout('sales_pk')['columns'] === [
    '门店', '本月目标', '本月底标', '本月业绩', '业绩分成款', '本月实际分成', '本月增长业绩', '完成率',
], 'PK 字段顺序必须与 007 页面一致');

// 007 当期“日均”除数是首末日期差，缺失输入不能被计算成零。
$stores = [['id' => 8, 'name' => '测试门店']];
$analysis = Plan::projectMatrix('sales_analysis', [8], $stores, [8 => [
    'new_customer_count' => 12, 'casual_customer_count' => 49,
    'designated_customer_count' => 120, 'service_customer_count' => 481,
]], '2026-10-01', '2026-10-30');
ensure(array_column($analysis['rows'], 'metric') === Plan::layout('sales_analysis')['rows'], '销售分析行序错误');
ensure(array_map(static function ($row) { return $row['cells']['store_8']; }, $analysis['rows'])
    === [12, 61, 19, 49, 120, 481, 24], '销售分析派生公式错误');
ensure($analysis['complete'] && !$analysis['gaps'] && $analysis['days'] === 29, '完整数据不应标缺口');

$efficiency = Plan::projectMatrix('store_efficiency', [8], $stores, [8 => [
    'consumption_amount_cents' => 10637200, 'service_customer_count' => 481,
    'bed_count' => 7, 'staff_count' => 9,
]], '2026-10-01', '2026-10-30');
ensure(array_map(static function ($row) { return $row['cells']['store_8']; }, $efficiency['rows'])
    === [10637200, 481, 22100, 16, 7, 2, 28, 9, 1, 0], '门店效能派生公式错误');
ensure($efficiency['complete'] && count($efficiency['gaps']) === 0, '已核实运行精度不应保留技术缺口');
$fractionalTicket = Plan::projectMatrix('store_efficiency', [8], $stores, [8 => [
    'consumption_amount_cents' => 199, 'service_customer_count' => 1,
]], '2026-10-01', '2026-10-30');
ensure($fractionalTicket['rows'][2]['cells']['store_8'] === 100, '007 客单价须截断整元，不能四舍五入');
$missing = Plan::projectMatrix('sales_analysis', [8], $stores, [8 => []], '2026-10-01', '2026-10-30');
ensure(count($missing['gaps']) === 7 && $missing['rows'][0]['cells']['store_8'] === null, '缺失事实不能变成零');

// 门店横列必须严格来自后端授权；即使读取器传来其他门店，也不能泄漏其值。
rejects(static function () use ($stores) {
    Plan::projectMatrix('sales_analysis', [9], $stores, [], '2026-10-01', '2026-10-30');
}, '越权门店列应拒绝');
rejects(static function () use ($stores) {
    Plan::projectMatrix('sales_analysis', [8], $stores, [9 => ['new_customer_count' => 1]], '2026-10-01', '2026-10-30');
}, '越权指标应拒绝');

echo "sidebar-007-parity-plan-contract: PASS\n";
