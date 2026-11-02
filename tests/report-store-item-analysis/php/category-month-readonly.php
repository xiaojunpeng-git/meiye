<?php

declare(strict_types=1);

/**
 * 本地瑞昊快照只读回归：分类的当日、月累计须分别对齐固定指标，
 * 月内有业绩而所选日没有业绩的分类也必须出现在列表、下钻和导出中。
 */
$backend = getenv('CASHIER_V3_BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';

use app\services\report\StoreUnifiedReportServices;
use app\services\report\StoreItemAnalysisXlsxExporter;
use think\facade\Db;

(new \think\App($backend . '/'))->initialize();
$config = config('database.connections.mysql');
if (($config['database'] ?? '') !== 'ruihao') {
    throw new RuntimeException('本测试只允许在本地瑞昊快照执行');
}
Db::execute('SET TRANSACTION READ ONLY');
Db::startTrans();
try {
    $service = new StoreUnifiedReportServices();
    $filters = ['report' => 'store_item_analysis', 'start_date' => '2026-10-31', 'end_date' => '2026-10-31'];
    $result = $service->query([5], $filters);
    $row = $result['records'][0] ?? null;
    if (!is_array($row)) throw new RuntimeException('本地快照缺少政和朝阳店样本');
    $cents = $row['_item_analysis_metric_cents'] ?? [];
    $visible = array_fill_keys(array_column($result['columns'], 'key'), true);
    $assert = static function (bool $condition, string $message): void {
        if (!$condition) throw new RuntimeException($message);
    };

    foreach (['' => 'today', '_cumulative' => 'cumulative'] as $suffix => $period) {
        foreach (['cash' => 'cash', 'after_split' => 'actual', 'consume' => 'consume'] as $categoryMetric => $summaryMetric) {
            $total = 0;
            foreach ($visible as $key => $_) {
                if (preg_match('/^item_analysis_category_\d+_' . $categoryMetric . preg_quote($suffix, '/') . '$/', $key)) {
                    $total += (int)($cents[$key] ?? 0);
                }
            }
            $summaryKey = 'item_analysis_' . $summaryMetric . '_' . $period;
            $assert($total === (int)($cents[$summaryKey] ?? 0), "{$period} {$categoryMetric} 分类合计未对齐");
        }
    }

    $monthOnlyKey = '';
    foreach ($visible as $key => $_) {
        if (!preg_match('/^(item_analysis_category_\d+)_cash_cumulative$/', $key, $match)) continue;
        if ((int)($cents[$key] ?? 0) !== 0 && (int)($cents[$match[1] . '_cash'] ?? 0) === 0) {
            $monthOnlyKey = $key;
            break;
        }
    }
    $assert($monthOnlyKey !== '', '月内有业绩而所选日无业绩的分类没有显示');
    $detail = $service->query([5], ['report' => 'store_item_analysis_detail',
        'start_date' => '2026-10-31', 'end_date' => '2026-10-31',
        'metric_code' => $monthOnlyKey, 'detail_store_key' => (string)$row['item_analysis_store_key']]);
    $assert(($detail['scope']['date_range'] ?? []) === ['start' => '2026-10-01', 'end' => '2026-10-31'],
        '月累计分类下钻未使用月初至所选日');
    $assert((string)$detail['summary_row']['receipt_amount'] === (string)$row[$monthOnlyKey],
        '月累计分类下钻与表格金额不一致');

    $export = $service->export([5], $filters);
    $assert(array_column($export['columns'], 'key') === array_column($result['columns'], 'key')
        && $export['column_groups'] === $result['column_groups']
        && (string)$export['records'][0][$monthOnlyKey] === (string)$row[$monthOnlyKey],
        '导出列、分组或月累计金额与列表不一致');
    $assert(substr((new StoreItemAnalysisXlsxExporter())->render($export), 0, 2) === 'PK',
        '包含月累计分类列的 XLSX 无法生成');
    echo "store item analysis category month readonly: PASS {$monthOnlyKey}\n";
} finally {
    Db::rollback();
}
