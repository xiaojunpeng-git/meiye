<?php

declare(strict_types=1);

/**
 * 本地瑞昊镜像回滚测试：三级配置只影响新冻结的分成事实；旧二级事实
 * 保留原列与金额，三级事实在列表、合计和导出中使用独立列。
 */
$root = getenv('CASHIER_V3_BACKEND_ROOT') ?: '/var/www/html';
require $root . '/vendor/autoload.php';
(new think\App($root . '/'))->initialize();

use app\services\report\StoreReportPartnerCategorySnapshotServices;
use app\services\report\StoreUnifiedReportServices;
use think\facade\Db;

if (getenv('R4_LOCAL_TEST') !== '1'
    || config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('三级分成回滚测试只允许本地瑞昊镜像');
}
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$saleFactId = 'CFS-5415ccb40f15fb7592c28a8c2e2e04e32903afe5';
$filters = ['report' => 'member_consumption_detail', 'start_date' => '2026-10-22',
    'end_date' => '2026-10-22', '_internal_all' => true];
$findRow = static function (array $report) use ($saleFactId): array {
    foreach ($report['records'] as $row) {
        if ((string)($row['fact_id'] ?? '') === $saleFactId) return $row;
    }
    throw new RuntimeException('本地镜像缺少三级卡项销售样本');
};

Db::startTrans();
try {
    $allocation = Db::name('cashier_v3_card_sale_category_allocation_fact')
        ->where('sale_fact_id', $saleFactId)->where('status', 'effective')->find();
    $assert(is_array($allocation) && (int)$allocation['category_id_snapshot'] === 26339
        && (int)$allocation['partner_category_id_snapshot'] === 26317,
        '样本须为三级项目、二级分成的已成交卡项');
    $originalShare = (int)$allocation['partner_share_amount_cents'];
    $cash = (int)$allocation['cash_performance_amount_cents'];
    $assert($cash > 0 && $originalShare > 0, '样本现金和原分成须大于零');

    // 只在本地事务中启用三级配置；finally 无条件回滚配置和事实夹具。
    $config = Db::name('cashier_v3_report_category_config')->where('tenant_id', '0')
        ->where('category_id', 26339)->find();
    if (is_array($config)) {
        Db::name('cashier_v3_report_category_config')->where('id', (int)$config['id'])
            ->update(['enabled' => 1, 'partner_default_ratio' => 35]);
        $configId = (int)$config['id'];
    } else {
        $parent = Db::name('cashier_v3_report_category_config')->where('tenant_id', '0')
            ->where('category_id', 26317)->find();
        $assert(is_array($parent), '本地镜像缺少二级分类配置');
        unset($parent['id']);
        $parent['category_id'] = 26339;
        $parent['category_name_snapshot'] = '馥玉';
        $parent['category_parent_id_snapshot'] = 26317;
        $parent['category_parent_name_snapshot'] = '合作';
        $parent['category_path_snapshot'] = '六维 / 合作 / 馥玉';
        $parent['partner_name'] = '六维 / 合作 / 馥玉';
        $parent['enabled'] = 1;
        $parent['partner_default_ratio'] = 35;
        $parent['version'] = 1;
        $configId = (int)Db::name('cashier_v3_report_category_config')->insertGetId($parent);
    }

    $newSnapshot = (new StoreReportPartnerCategorySnapshotServices())->resolveInTx('0', 26339, $cash);
    $expectedShare = min(intdiv($cash * 35 + 5000, 10000), intdiv($cash, 100)) * 100;
    $assert((int)$newSnapshot['partner_category_id_snapshot'] === 26339
        && (int)$newSnapshot['partner_default_ratio_snapshot'] === 35
        && (int)$newSnapshot['partner_share_amount_cents'] === $expectedShare,
        '新结账未使用三级 35% 配置');
    $oddPrice = (new StoreReportPartnerCategorySnapshotServices())->resolveInTx('0', 26339, 12100);
    $assert((int)$oddPrice['partner_share_amount_cents'] === 4200,
        '三级 35% 分成未按整元归整');

    $report = new StoreUnifiedReportServices();
    $old = $report->query([27], $filters);
    $oldRow = $findRow($old);
    $assert((int)$oldRow['partner_category_26317'] === intdiv($originalShare, 100)
        && (int)$oldRow['partner_category_26339'] === 0,
        '启用三级配置后旧二级分成被追溯移动');

    // 当前商品分类改变时只移动展示归类；旧分成比例和金额仍取冻结事实。
    $item = Db::name('cashier_v3_card_sale_item_allocation_fact')->where('sale_fact_id', $saleFactId)
        ->where('status', 'effective')->find();
    $storeProduct = Db::name('store_product')->where('id', (int)($item['component_product_id'] ?? 0))->find();
    $platformProductId = (int)($storeProduct['pid'] ?? 0);
    $platformProduct = Db::name('store_product')->where('id', $platformProductId)->find();
    $assert($platformProductId > 0 && is_array($platformProduct), '样本须有可核实的平台项目');
    Db::name('store_product')->where('id', $platformProductId)->update(['cate_id' => '26321']);
    $reclassified = $findRow($report->query([27], $filters));
    $assert((string)$reclassified['category'] === '花园/花园产品'
        && (int)$reclassified['partner_category_26321'] === intdiv($originalShare, 100)
        && (int)$reclassified['partner_performance'] === (int)$oldRow['partner_performance'],
        '当前分类调整改变了原冻结分成金额');
    Db::name('store_product')->where('id', $platformProductId)
        ->update(['cate_id' => (string)$platformProduct['cate_id']]);

    // 用同一旧事实作本地模拟的新快照，验证读取层；事务回滚后旧事实不变。
    Db::name('cashier_v3_card_sale_category_allocation_fact')->where('id', (int)$allocation['id'])
        ->update(['partner_category_id_snapshot' => 26339,
            'partner_category_name_snapshot' => '馥玉',
            'partner_category_path_snapshot' => '六维 / 合作 / 馥玉',
            'partner_default_ratio_snapshot' => 35,
            'partner_share_amount_cents' => $expectedShare]);
    $new = $report->query([27], $filters);
    $newRow = $findRow($new);
    $assert((int)$newRow['partner_category_26317'] === 0
        && (int)$newRow['partner_category_26339'] === intdiv($expectedShare, 100)
        && (int)$newRow['partner_performance'] === intdiv($expectedShare, 100)
        && (int)$newRow['actual_cash_performance']
            + (int)$newRow['partner_performance'] === (int)$newRow['receipt_total'],
        '三级事实未进入专属列或合作方合计');
    $export = $report->export([27], $filters);
    $exportRow = $findRow($export);
    $assert(array_column($new['columns'], 'key') === array_column($export['columns'], 'key')
        && $new['summary_row'] === $export['summary_row']
        && $newRow['partner_category_26339'] === $exportRow['partner_category_26339'],
        '三级列的列表、合计和导出不一致');

    Db::name('cashier_v3_report_category_config')->where('id', $configId)->update(['enabled' => 0]);
    $fallback = (new StoreReportPartnerCategorySnapshotServices())->resolveInTx('0', 26339, $cash);
    $assert((int)$fallback['partner_category_id_snapshot'] === 26317,
        '三级关闭后新结账没有沿用原二级规则');
    $historic = $report->query([27], $filters);
    $assert((int)$findRow($historic)['partner_category_26339'] === intdiv($expectedShare, 100),
        '三级配置关闭后已冻结的三级分成消失');

    // 补交也是独立冻结事实：关闭开关后，仅有补交的查询期仍须有三级列。
    $repaymentId = 'DRP-453c1dd187af0fbed30b4a1cb2ce4051fe18b1ed';
    $settlement = Db::name('cashier_v3_debt_repayment_item_settlement')
        ->where('repayment_id', $repaymentId)->find();
    $assert(is_array($settlement) && (int)$settlement['cash_performance_amount_cents'] === 10000,
        '本地镜像缺少单项补交样本');
    $repaymentFilters = ['report' => 'member_consumption_detail',
        'start_date' => '2026-10-29', 'end_date' => '2026-10-29', '_internal_all' => true];
    $beforeRepayment = $report->query([23], $repaymentFilters);
    Db::name('cashier_v3_debt_repayment_item_settlement')->where('id', (int)$settlement['id'])
        ->update(['partner_category_id_snapshot' => 26339,
            'partner_category_name_snapshot' => '馥玉',
            'partner_category_path_snapshot' => '六维 / 合作 / 馥玉',
            'partner_default_ratio_snapshot' => 35,
            'partner_share_amount_cents' => 3500]);
    $afterRepayment = $report->query([23], $repaymentFilters);
    $repaymentRow = null;
    foreach ($afterRepayment['records'] as $row) {
        if ((string)($row['repayment_id'] ?? '') === $repaymentId) $repaymentRow = $row;
    }
    $assert(is_array($repaymentRow) && (int)$repaymentRow['partner_category_26339'] === 35
        && (int)$repaymentRow['partner_performance'] === 35
        && (int)($afterRepayment['summary_row']['partner_category_26339'] ?? 0)
            - (int)($beforeRepayment['summary_row']['partner_category_26339'] ?? 0) === 35,
        '已冻结的三级补交在开关关闭后未进入列表和合计');
    $repaymentExport = $report->export([23], $repaymentFilters);
    $assert(array_column($afterRepayment['columns'], 'key') === array_column($repaymentExport['columns'], 'key')
        && $afterRepayment['summary_row'] === $repaymentExport['summary_row'],
        '三级补交的列表与导出不一致');
    echo "third-level partner share local rollback: PASS\n";
} finally {
    Db::rollback();
}
