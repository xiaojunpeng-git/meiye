<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../后端代码/app/services/report/StoreReportGuestDisplayServices.php';
require_once __DIR__ . '/../../../后端代码/app/services/report/StoreUnifiedReportDetailFilter.php';

use app\services\report\StoreReportGuestDisplayServices;
use app\services\report\StoreUnifiedReportDetailFilter;

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', actual ' . var_export($actual, true));
    }
}

// 真实游客 ID 才能触发展示投影；页面与完整导出对同一行使用同一规则。
$rows = [
    ['member_id' => 0, 'member_name_snapshot' => '', 'member_name' => '', 'customer' => '-', 'amount' => 10],
    ['member_id' => '0', 'member_name_snapshot' => '历史散客姓名', 'member_name' => '历史散客姓名', 'customer' => '历史散客姓名', 'amount' => 20],
    ['member_id' => 42, 'member_name_snapshot' => '张女士', 'member_name' => '张女士', 'customer' => '张女士', 'amount' => 30],
    ['member_id' => null, 'member_name_snapshot' => '', 'member_name' => '', 'customer' => '', 'amount' => 40],
    ['member_name_snapshot' => '', 'member_name' => '', 'customer' => '', 'amount' => 50],
];
$result = [
    'columns' => [['key' => 'member_name_snapshot'], ['key' => 'member_name'], ['key' => 'customer'], ['key' => 'amount']],
    'records' => $rows,
    'summary_row' => ['member_name_snapshot' => '-', 'member_name' => '-', 'customer' => '-', 'amount' => 150],
    'total' => 5,
];
$page = StoreReportGuestDisplayServices::project($result);
$export = StoreReportGuestDisplayServices::project($result);
assertSameValue($page, $export, '页面和导出投影不一致');
foreach ([0, 1] as $index) {
    foreach (['member_name_snapshot', 'member_name', 'customer'] as $key) {
        assertSameValue('游客', $page['records'][$index][$key], '游客姓名未统一');
    }
}
assertSameValue('张女士', $page['records'][2]['member_name'], '真实会员快照被覆盖');
assertSameValue('', $page['records'][3]['member_name'], 'NULL 身份被误判为游客');
assertSameValue('', $page['records'][4]['member_name'], '缺失身份被误判为游客');
assertSameValue($result['summary_row'], $page['summary_row'], '汇总行被修改');
assertSameValue(5, $page['total'], '人数或总行数被修改');
assertSameValue(10, $page['records'][0]['amount'], '金额被修改');
assertSameValue($page, StoreReportGuestDisplayServices::project($page), '重复投影不稳定');

$filtered = StoreUnifiedReportDetailFilter::apply($rows, ['detail_member' => '游客']);
assertSameValue(2, count($filtered), '游客姓名筛选未使用相同展示规则');
assertSameValue(0, count(StoreUnifiedReportDetailFilter::apply($rows, ['detail_member' => '游客', 'detail_order_no' => '不存在'])), '单号筛选被放宽');

echo "report guest display contract ok\n";
