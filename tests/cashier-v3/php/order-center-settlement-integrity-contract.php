<?php

/** 单条销售订单金额快照异常不得阻断订单中心读取。 */

$root = dirname(__DIR__, 3);
$backendRoot = trim((string)getenv('CASHIER_V3_BACKEND_ROOT')) ?: $root . '/后端代码';
require $backendRoot . '/app/services/cashier/v3/order/CashierV3SalesOrderQueryServices.php';

use app\services\cashier\v3\order\CashierV3SalesOrderQueryServices;

$service = new CashierV3SalesOrderQueryServices();
$method = new ReflectionMethod(CashierV3SalesOrderQueryServices::class, 'mapAuthorityOrder');
$method->setAccessible(true);
$snapshot = [
    'header' => [
        'order_id' => 'CSO-integrity-1', 'order_no' => 'XS-INT-1', 'member_id' => 9,
        'member_name_snapshot' => '测试会员', 'store_id' => 118, 'store_name_snapshot' => '测试门店',
        'business_date' => '2026-08-08', 'business_timezone' => 'Asia/Shanghai',
        'occurred_at' => 1786169992, 'settled_at' => 1786169992,
        'original_amount_cents' => 10000, 'discount_amount_cents' => 0, 'sale_amount_cents' => 10000,
        'operator_name_snapshot' => '收银员',
    ],
    'batch' => [
        'receivable_amount_cents' => 5000, 'collected_amount_cents' => 5000,
        'cash_performance_amount_cents' => 5000, 'settled_at' => 1786169992,
    ],
    'request' => ['debt_amount_cents' => 0, 'balance_deduction_amount_cents' => 0],
    'lines' => [[
        'order_line_id' => 'LINE-integrity-1', 'item_type' => 'project', 'item_name_snapshot' => '测试项目',
        'quantity' => 1, 'original_amount_cents' => 10000, 'sale_amount_cents' => 5000,
        'service_object' => '', 'is_experience' => 0,
    ]],
    'collections' => [], 'salespeopleByLine' => ['LINE-integrity-1' => []],
    'craftsmenByLine' => ['LINE-integrity-1' => [[
        'fact_id' => 'F-1', 'employee_id' => 1, 'employee_name_snapshot' => '技师',
        'role_snapshot' => '', 'amount_cents' => 0,
    ]],
    ], 'lifecycleOperations' => [],
];

$record = $method->invoke($service, $snapshot, false);
$detail = $method->invoke($service, $snapshot, true);
$ok = ($record['orderStatus'] ?? '') === '数据异常'
    && ($record['paymentStatus'] ?? '') === '数据异常'
    && ($record['economicsDataStatus'] ?? '') === 'integrity_failed'
    && ($record['dataIntegrityStatus'] ?? '') === 'settlement_equation_invalid'
    && array_key_exists('receivableAmount', $record) && $record['receivableAmount'] === null
    && ($record['availableActions'] ?? ['unexpected']) === []
    && ($detail['amountSummary']['dataStatus'] ?? '') === 'integrity_failed'
    && ($detail['paymentDetails'] ?? ['unexpected']) === [];

echo $ok ? "ORDER_CENTER_SETTLEMENT_INTEGRITY=PASS\n" : "ORDER_CENTER_SETTLEMENT_INTEGRITY=FAIL\n";
exit($ok ? 0 : 1);
