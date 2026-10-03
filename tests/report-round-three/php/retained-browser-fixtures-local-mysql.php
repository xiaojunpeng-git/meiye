<?php

declare(strict_types=1);

// Read-only assertions for the records created through the local Vue3 UI on
// 2026-10-03. Never recreate, void, delete or roll back these retained examples.
require __DIR__ . '/normal-void-scope-local-mysql.php';

use think\facade\Db;

function retainedAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo 'PASS retained browser fixture: ' . $message . PHP_EOL;
}

$memberId = 1136104;
retainedAssert((string)Db::name('user')->where('uid', $memberId)->value('phone') === '13000031003', 'dedicated local member identity');
$normal = new \app\services\report\StoreReportNormalDataScopeServices();
$supplements = [
    ['cashier_v3_debt_repayment', 'BJ26100200006', 'voided', 2000],
    ['cashier_v3_debt_repayment', 'BJ26100300007', 'succeeded', 1000],
    ['cashier_v3_recharge_debt_repayment', 'BJ26100200005', 'voided', 3000],
    ['cashier_v3_recharge_debt_repayment', 'BJ26100300006', 'succeeded', 1000],
];
foreach ($supplements as [$table, $number, $status, $cents]) {
    $record = Db::name($table)->where('repayment_no', $number)->where('member_id', $memberId)->find();
    retainedAssert($record && $record['status'] === $status, $number . ' retained status=' . $status);
    $raw = Db::name('cashier_v3_payment_fact')->alias('p')->where('p.tenant_id', '0')
        ->where('p.order_id', $record['repayment_id'])->where('p.status', 'effective');
    retainedAssert((int)(clone $raw)->where('p.fact_direction', 'forward')->sum('p.amount_cents') === $cents, $number . ' original receipt retained');
    $expected = $status === 'voided' ? 0 : $cents;
    retainedAssert((int)(clone $raw)->sum('p.amount_cents') === $expected, $number . ' signed receipt net');
    foreach (['2026-10-02', '2026-10-03'] as $day) {
        $query = (clone $raw)->where('p.business_date', $day);
        $normal->excludeVoidedSupplementPaymentFacts($query, 'p.tenant_id', 'p.store_id', 'p.order_id');
        $dayExpected = $status === 'succeeded' && $record['business_date'] === $day ? $cents : 0;
        retainedAssert((int)$query->sum('p.amount_cents') === $dayExpected, $number . ' normal receipt on ' . $day);
    }
    $performance = Db::name('cashier_v3_performance_fact')->where('tenant_id', '0')
        ->where('order_id', $record['repayment_id'])->where('status', 'effective')
        // Cash performance and staff allocation are separate metrics; adding
        // both would double-count the same receipt in this assertion.
        ->where('performance_type', 'sales_performance_allocated');
    retainedAssert((int)$performance->sum('amount_cents') === $expected, $number . ' personnel performance net');
    if ($table === 'cashier_v3_debt_repayment') {
        $allocations = Db::name('cashier_v3_payment_sale_allocation_fact')->alias('a')
            ->join('cashier_v3_payment_fact p', 'p.tenant_id=a.tenant_id AND p.fact_id=a.payment_fact_id')
            ->where('p.order_id', $record['repayment_id'])->where('a.status', 'effective');
        retainedAssert((int)(clone $allocations)->sum('a.amount_cents') === $expected, $number . ' allocation net');
        $normal->excludeVoidedSupplementAllocations($allocations, 'a');
        retainedAssert((int)$allocations->sum('a.amount_cents') === $expected, $number . ' normal allocation');
    } else {
        $balance = Db::name('cashier_v3_balance_fact')->where('tenant_id', '0')->where('order_id', $record['repayment_id']);
        retainedAssert((int)$balance->sum('principal_delta_cents') === $expected, $number . ' recharge balance net');
    }
}

// Current debt and rights remain authoritative business projections, not
// historical report sums. Voiding one supplement must preserve its sibling.
foreach (['QK2610020004' => 50, 'QK2610030006' => 70] as $number => $remaining) {
    $debt = Db::name('store_debt')->where('uid', $memberId)->where('debt_no', $number)->find();
    retainedAssert($debt && (float)$debt['repaid_debt'] === 10.0 && (float)$debt['total_debt'] - (float)$debt['repaid_debt'] === (float)$remaining, $number . ' current debt restored');
}
$card = Db::name('user_card_holder')->where('uid', $memberId)->where('card_no', '1610539')->find();
retainedAssert($card && (int)$card['is_del'] === 0 && (int)$card['write_surplus_times'] === 1, 'service card retained with its one use restored');

foreach (['FW100200009', 'FW100200010'] as $number) {
    $service = Db::name('cashier_v3_entitlement_service_fact')->where('member_id', $memberId)->where('service_record_no', $number)->find();
    retainedAssert($service && $service['service_status'] === 'completed', $number . ' immutable service retained');
    $visible = Db::name('cashier_v3_entitlement_service_fact')->alias('s')->where('s.id', $service['id']);
    $normal->excludeVoidedSalesOrderServices($visible, 's');
    retainedAssert((int)$visible->count() === 0, $number . ' excluded from normal services');
    $personnel = Db::name('cashier_v3_performance_fact')->alias('p')
        ->where('p.member_id', $memberId)->where('p.checkout_request_id', $service['checkout_request_id'])
        ->where('p.source_line_id', $service['source_line_id']);
    $normal->excludeVoidedSalesOrderServices($personnel, 'p');
    retainedAssert((int)$personnel->count() === 0, $number . ' excluded from normal personnel facts');
}

foreach (['2026-10-02' => 25800, '2026-10-03' => 2000] as $day => $expected) {
    $payment = Db::name('cashier_v3_payment_fact')->alias('p')->where('p.tenant_id', '0')
        ->where('p.member_id', $memberId)->where('p.status', 'effective')->where('p.business_date', $day);
    $normal->excludeVoidedSalesOrderFacts($payment, 'p.tenant_id', 'p.order_id');
    $normal->excludeVoidedRechargeOrderFacts($payment, 'p.tenant_id', 'p.order_id');
    $normal->excludeVoidedSupplementPaymentFacts($payment, 'p.tenant_id', 'p.store_id', 'p.order_id');
    retainedAssert((int)$payment->sum('p.amount_cents') === $expected, 'member normal receipts on ' . $day);
}

echo "All retained fixture assertions passed; no data was modified.\n";
