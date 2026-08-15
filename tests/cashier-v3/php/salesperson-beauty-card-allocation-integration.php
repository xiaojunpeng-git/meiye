<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/_lib.php';
require dirname(__DIR__, 3) . '/后端代码/vendor/autoload.php';

use app\services\report\StoreUnifiedReportServices;
use think\facade\Db;

c1aBootThinkApp(dirname(__DIR__, 3) . '/后端代码/');
date_default_timezone_set('Asia/Shanghai');

$candidate = Db::name('cashier_v3_performance_fact')->alias('p')
    ->join('cashier_v3_sale_fact s', 's.tenant_id=p.tenant_id AND s.store_id=p.store_id AND s.source_line_id=p.source_line_id')
    ->where('p.status', 'effective')->where('p.performance_type', 'sales_performance_allocated')
    ->where('p.amount_cents', '>', 0)->where('s.status', 'effective')->where('s.source_type', 'card')
    ->where('s.sale_amount_cents', '>', 0)
    ->field('p.tenant_id,p.store_id,p.employee_id,p.amount_cents p_amount,s.fact_id,s.sale_amount_cents,s.business_date,s.member_id')
    ->order('p.id', 'asc')->find();
if (!is_array($candidate)) {
    fwrite(STDERR, "BLOCKED no effective card salesperson fixture\n");
    exit(2);
}

$tenant = (string)$candidate['tenant_id'];
$store = (int)$candidate['store_id'];
$saleFact = (string)$candidate['fact_id'];
$saleAmount = (int)$candidate['sale_amount_cents'];
$performanceAmount = (int)$candidate['p_amount'];
$beautyCash = intdiv($performanceAmount, 2);
$otherCash = $performanceAmount - $beautyCash;
$beautySale = intdiv($saleAmount, 2);
$otherSale = $saleAmount - $beautySale;
$date = (string)$candidate['business_date'];
$prefix = 'CODEx-A-' . substr(hash('sha256', $saleFact . microtime(true)), 0, 24);

$rows = [
    [
        'allocation_fact_id' => $prefix . '-1', 'natural_key' => $prefix . '-N1', 'contract_version' => 'test', 'fact_version' => 1,
        'status' => 'effective', 'reversal_of' => '', 'tenant_id' => $tenant, 'organization_id' => 'test-org', 'store_id' => $store,
        'member_id' => (int)$candidate['member_id'], 'order_id' => 'test-order', 'sale_fact_id' => $saleFact, 'source_line_id' => 'test-line',
        'card_receipt_id' => $prefix . '-R', 'card_issue_no' => 1, 'component_product_id' => 900001, 'category_id_snapshot' => 990001,
        'category_name_snapshot' => '生美', 'category_path_snapshot' => '生美 / 测试项目', 'partner_name_snapshot' => '', 'product_type_snapshot' => 'project',
        'component_count' => 2, 'configured_amount_cents' => $beautySale, 'sale_amount_cents' => $beautySale,
        'cash_performance_amount_cents' => $beautyCash, 'business_date' => $date, 'occurred_at' => time(), 'settled_at' => time(), 'recorded_at' => time(),
        'business_event_no' => 'test-event', 'command_idempotency_key' => $prefix, 'immutable_fingerprint' => hash('sha256', $prefix . '-1'), 'add_time' => time(), 'update_time' => time(),
    ],
    [
        'allocation_fact_id' => $prefix . '-2', 'natural_key' => $prefix . '-N2', 'contract_version' => 'test', 'fact_version' => 1,
        'status' => 'effective', 'reversal_of' => '', 'tenant_id' => $tenant, 'organization_id' => 'test-org', 'store_id' => $store,
        'member_id' => (int)$candidate['member_id'], 'order_id' => 'test-order', 'sale_fact_id' => $saleFact, 'source_line_id' => 'test-line',
        'card_receipt_id' => $prefix . '-R', 'card_issue_no' => 1, 'component_product_id' => 900002, 'category_id_snapshot' => 990002,
        'category_name_snapshot' => '家居', 'category_path_snapshot' => '家居 / 测试项目', 'partner_name_snapshot' => '', 'product_type_snapshot' => 'project',
        'component_count' => 2, 'configured_amount_cents' => $otherSale, 'sale_amount_cents' => $otherSale,
        'cash_performance_amount_cents' => $otherCash, 'business_date' => $date, 'occurred_at' => time(), 'settled_at' => time(), 'recorded_at' => time(),
        'business_event_no' => 'test-event', 'command_idempotency_key' => $prefix, 'immutable_fingerprint' => hash('sha256', $prefix . '-2'), 'add_time' => time(), 'update_time' => time(),
    ],
];

Db::startTrans();
try {
    foreach ($rows as $row) Db::name('cashier_v3_card_sale_category_allocation_fact')->insert($row);
    $result = (new StoreUnifiedReportServices())->query([$store], [
        'report' => 'salesperson_large_order_statistics',
        'start_date' => $date, 'end_date' => $date, 'page' => 1, 'limit' => 100,
    ]);
    $expectedCents = (int)round($performanceAmount * $beautyCash / max(1, $performanceAmount));
    $matched = false;
    foreach ((array)($result['records'] ?? []) as $record) {
        if ((int)($record['employee_id'] ?? 0) === (int)$candidate['employee_id']
            && (int)($record['member_id'] ?? 0) === (int)$candidate['member_id']) {
            $matched = ((int)round(((float)($record['daily_cash'] ?? '0')) * 100) === $expectedCents);
            break;
        }
    }
    if (!$matched) throw new RuntimeException('mixed_card_beauty_cash_allocation_mismatch');
    echo "PASS mixed card uses category cash-performance allocation\n";
    Db::rollback();
} catch (Throwable $e) {
    Db::rollback();
    fwrite(STDERR, 'FAIL ' . $e->getMessage() . "\n");
    exit(1);
}
