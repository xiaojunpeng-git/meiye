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

$upgradeSnapshot = $snapshot;
$upgradeSnapshot['header']['sale_amount_cents'] = 10000;
$upgradeSnapshot['batch']['receivable_amount_cents'] = 2000;
$upgradeSnapshot['batch']['collected_amount_cents'] = 2000;
$upgradeSnapshot['batch']['cash_performance_amount_cents'] = 2000;
$upgradeSnapshot['request'] = ['debt_amount_cents' => 0, 'balance_deduction_amount_cents' => 0];
$upgradeSnapshot['lines'][0]['sale_amount_cents'] = 10000;
$upgradeSnapshot['upgradeSettlement'] = [
    'operation_id' => 'COP-integrity-upgrade-1',
    'operation_type' => 'card_upgrade',
    'settlement_status' => 'settled',
    'entitlement_credit_cents' => 8000,
    'cash_delta_cents' => 2000,
];
$upgradeRecord = $method->invoke($service, $upgradeSnapshot, false);
$upgradeDetail = $method->invoke($service, $upgradeSnapshot, true);
$upgradeOk = ($upgradeRecord['orderStatus'] ?? '') === '正常'
    && ($upgradeRecord['receivableAmount'] ?? null) === 100.0
    && ($upgradeRecord['actualReceivedAmount'] ?? null) === 20.0
    && ($upgradeRecord['dataIntegrityStatus'] ?? '') === 'valid'
    && ($upgradeDetail['amountSummary']['entitlementCreditAmount'] ?? null) === 80.0
    && ($upgradeDetail['amountSummary']['payableAmount'] ?? null) === 100.0;

$zeroCreditDebtUpgrade = $snapshot;
$zeroCreditDebtUpgrade['header']['sale_amount_cents'] = 18000;
$zeroCreditDebtUpgrade['header']['original_amount_cents'] = 18000;
$zeroCreditDebtUpgrade['batch']['receivable_amount_cents'] = 10000;
$zeroCreditDebtUpgrade['batch']['collected_amount_cents'] = 10000;
$zeroCreditDebtUpgrade['batch']['cash_performance_amount_cents'] = 10000;
$zeroCreditDebtUpgrade['request'] = ['debt_amount_cents' => 8000, 'balance_deduction_amount_cents' => 0];
$zeroCreditDebtUpgrade['lines'][0]['original_amount_cents'] = 18000;
$zeroCreditDebtUpgrade['lines'][0]['sale_amount_cents'] = 18000;
$zeroCreditDebtUpgrade['upgradeSettlement'] = [
    'operation_id' => 'COP-integrity-upgrade-zero-credit',
    'operation_type' => 'card_upgrade',
    'settlement_status' => 'settled',
    'entitlement_credit_cents' => 0,
    'cash_delta_cents' => 18000,
];
$zeroCreditDebtRecord = $method->invoke($service, $zeroCreditDebtUpgrade, false);
$zeroCreditDebtDetail = $method->invoke($service, $zeroCreditDebtUpgrade, true);
$zeroCreditDebtOk = ($zeroCreditDebtRecord['orderStatus'] ?? '') === '正常'
    && ($zeroCreditDebtRecord['paymentStatus'] ?? '') === '部分支付（含欠款）'
    && ($zeroCreditDebtRecord['receivableAmount'] ?? null) === 180.0
    && ($zeroCreditDebtRecord['debtAmount'] ?? null) === 80.0
    && ($zeroCreditDebtRecord['actualReceivedAmount'] ?? null) === 100.0
    && ($zeroCreditDebtDetail['amountSummary']['payableAmount'] ?? null) === 180.0;

$couponUpgradeSnapshot = $snapshot;
$couponUpgradeSnapshot['header']['original_amount_cents'] = 1280000;
$couponUpgradeSnapshot['header']['discount_amount_cents'] = 10000;
$couponUpgradeSnapshot['header']['sale_amount_cents'] = 1270000;
$couponUpgradeSnapshot['batch']['receivable_amount_cents'] = 1096800;
$couponUpgradeSnapshot['batch']['collected_amount_cents'] = 1096800;
$couponUpgradeSnapshot['batch']['cash_performance_amount_cents'] = 1096800;
$couponUpgradeSnapshot['request'] = ['debt_amount_cents' => 10000, 'balance_deduction_amount_cents' => 0];
$couponUpgradeSnapshot['lines'][0]['original_amount_cents'] = 1280000;
$couponUpgradeSnapshot['lines'][0]['discount_amount_cents'] = 10000;
$couponUpgradeSnapshot['lines'][0]['coupon_discount_cents'] = 10000;
$couponUpgradeSnapshot['lines'][0]['coupon_name_snapshot'] = '100元现金券';
$couponUpgradeSnapshot['lines'][0]['debt_amount_cents'] = 10000;
$couponUpgradeSnapshot['lines'][0]['sale_amount_cents'] = 1270000;
$couponUpgradeSnapshot['upgradeSettlement'] = [
    'operation_id' => 'COP-integrity-coupon-upgrade',
    'operation_type' => 'card_upgrade',
    'settlement_status' => 'settled',
    'entitlement_credit_cents' => 163200,
    // Immutable upgrade delta is before the 100 yuan order coupon.
    'cash_delta_cents' => 1116800,
];
$couponUpgradeSnapshot['debtAuthorities'] = [[
    'debt_id' => 47, 'debt_no' => 'QK2608110008',
    'sales_order_id' => 'CSO-integrity-coupon-upgrade',
    'sales_order_no_snapshot' => 'XS-INT-COUPON-UPGRADE',
]];
$couponUpgradeRecord = $method->invoke($service, $couponUpgradeSnapshot, false);
$couponUpgradeDetail = $method->invoke($service, $couponUpgradeSnapshot, true);
$couponUpgradeOk = ($couponUpgradeRecord['orderStatus'] ?? '') === '正常'
    && ($couponUpgradeRecord['paymentStatus'] ?? '') === '部分支付（含欠款）'
    && ($couponUpgradeRecord['receivableAmount'] ?? null) === 12700.0
    && ($couponUpgradeRecord['actualReceivedAmount'] ?? null) === 10968.0
    && ($couponUpgradeRecord['dataIntegrityStatus'] ?? '') === 'valid'
    && ($couponUpgradeDetail['amountSummary']['couponDiscountAmount'] ?? null) === 100.0
    && ($couponUpgradeDetail['amountSummary']['entitlementCreditAmount'] ?? null) === 1632.0
    && ($couponUpgradeDetail['amountSummary']['debtAmount'] ?? null) === 100.0
    && count($couponUpgradeDetail['items'] ?? []) === 1
    && ($couponUpgradeDetail['items'][0]['couponName'] ?? '') === '100元现金券'
    && count($couponUpgradeDetail['related']['upgrades'] ?? []) === 1
    && count($couponUpgradeDetail['related']['debtSettlements'] ?? []) === 1;

echo ($ok && $upgradeOk && $zeroCreditDebtOk && $couponUpgradeOk) ? "ORDER_CENTER_SETTLEMENT_INTEGRITY=PASS\n" : "ORDER_CENTER_SETTLEMENT_INTEGRITY=FAIL\n";
exit($ok && $upgradeOk && $zeroCreditDebtOk && $couponUpgradeOk ? 0 : 1);
