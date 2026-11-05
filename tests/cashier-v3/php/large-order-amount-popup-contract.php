<?php
declare(strict_types=1);

/** 金额弹窗只解释同一授权行：成交、退款、分期三类各自守恒，伪造行键被拒绝。 */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();

use app\services\report\StoreUnifiedReportPhaseTwoServices;
use app\services\report\StoreLargeOrderInstallmentServices;
use app\controller\cashier\v3\Report as CashierReport;
use app\controller\admin\v1\report\UnifiedReport as AdminReport;

// 两端入口的显式参数白名单必须保留行键和金额列，否则点击会误读成整张汇总表。
foreach ([CashierReport::class, AdminReport::class] as $controller) {
    $method = new ReflectionMethod($controller, 'inputRules');
    $method->setAccessible(true);
    $rules = $method->invoke((new ReflectionClass($controller))->newInstanceWithoutConstructor());
    $keys = array_column($rules, 0);
    if (!in_array('detail_row_key', $keys, true) || !in_array('detail_metric', $keys, true)) {
        throw new RuntimeException($controller . ' dropped large-order detail parameters');
    }
}

$service = new StoreUnifiedReportPhaseTwoServices();
$method = new ReflectionMethod($service, 'largeOrderDetail');
$method->setAccessible(true);
$identity = ['tenant_id' => '0', 'role_snapshot' => 'salesperson', 'employee_id' => 10,
    'store_id' => 5, 'rule_type' => 'beauty', 'award_category_id' => 30];
$key = str_repeat('a', 64);
$row = $identity + ['member_id' => null, 'row_key' => $key, 'award_month' => '2026-10', 'award_amount_cents' => 10000,
    'refund_amount_cents' => 2000, 'due_amount_cents' => 4000,
    '_drilldown' => array_fill_keys(['award_amount', 'refund_amount', 'monthly_due', 'installment_2'], ['report' => 'salesperson_large_order_statistics'])];
$facts = [
    $identity + ['member_id' => 20, 'business_date' => '2026-10-01', 'amount_cents' => 7000, 'order_id' => 'SO-1', 'source_line_id' => 'L1'],
    $identity + ['member_id' => 21, 'business_date' => '2026-10-03', 'amount_cents' => 3000, 'order_id' => 'SO-2', 'source_line_id' => 'L2'],
    $identity + ['member_id' => 20, 'business_date' => '2026-10-04', 'amount_cents' => -2000, 'operation_id' => 'RF-1'],
    ['tenant_id' => '0', 'role_snapshot' => 'salesperson', 'employee_id' => 11, 'store_id' => 5, 'member_id' => 20,
        'rule_type' => 'beauty', 'award_category_id' => 30, 'business_date' => '2026-10-05',
        'amount_cents' => 9000, 'order_id' => 'OTHER'],
];
$run = static function (string $metric, array $input = []) use ($method, $service, $row, $facts, $key): array {
    return $method->invoke($service, ['records' => [$row]], $facts, $key, $metric, $input, '2026-11');
};
foreach (['award_amount' => ['100', 2], 'refund_amount' => ['20', 1],
    'monthly_due' => ['40', 2], 'installment_2' => ['40', 2]] as $metric => [$sum, $count]) {
    $result = $run($metric);
    if ((string)$result['summary_row']['receipt_amount'] !== $sum || $result['total'] !== $count) {
        throw new RuntimeException($metric . ' detail total mismatch');
    }
}
$filtered = $run('award_amount', ['detail_order_no' => 'SO-1']);
if ($filtered['total'] !== 1 || $filtered['records'][0]['order_no'] !== 'SO-1') throw new RuntimeException('Detail order filter mismatch');
try {
    $method->invoke($service, ['records' => [$row]], $facts, str_repeat('b', 64), 'award_amount', [], '2026-11');
    throw new RuntimeException('Forged row key accepted');
} catch (InvalidArgumentException $expected) {}
$refundOnly = $row;
$refundOnly['row_key'] = str_repeat('c', 64);
$refundOnly['refund_only'] = true;
$refundOnly['award_month'] = '-';
$refundOnly['refund_amount_cents'] = 2000;
$refundOnly['_drilldown'] = ['refund_amount' => ['report' => 'salesperson_large_order_statistics']];
$novemberRefund = $identity + ['member_id' => 22, 'business_date' => '2026-11-04',
    'amount_cents' => -2000, 'operation_id' => 'RF-2'];
$only = $method->invoke($service, ['records' => [$refundOnly]], [$novemberRefund],
    $refundOnly['row_key'], 'refund_amount', [], '2026-11');
if ($only['total'] !== 1 || $only['records'][0]['order_no'] !== 'RF-2') throw new RuntimeException('Refund-only detail mismatch');
// 同一员工、会员、分类跨门店的应计行必须各有独立行键，避免弹窗串店。
$base = ['tenant_id' => 0, 'role_snapshot' => 'salesperson', 'employee_id' => 10,
    'member_id' => 20, 'business_date' => '2026-10-01', 'rule_type' => 'beauty',
    'award_category_id' => 30, 'beauty_category' => '项目'];
$separate = StoreLargeOrderInstallmentServices::dueRows(
    [$base + ['store_id' => 5], $base + ['store_id' => 6]],
    [0 => ['amount_cents' => 5000000, 'threshold_cents' => 5000000],
     1 => ['amount_cents' => 5000000, 'threshold_cents' => 5000000]], '2026-10');
if (count($separate) !== 2 || $separate[0]['row_key'] === $separate[1]['row_key']) {
    throw new RuntimeException('Cross-store detail row key collision');
}
echo "LARGE_ORDER_AMOUNT_POPUP_OK\n";
