<?php
declare(strict_types=1);

/** Local-only fact probe: lifecycle and adjustment rows use different source-type keys. */
require '/var/www/html/vendor/autoload.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\order\CashierV3RefundSalespersonServices;
use think\facade\Db;

(new think\App('/var/www/html/'))->initialize();
if ((string)config('database.connections.mysql.hostname') !== 'mysql'
    || (string)config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('This probe is restricted to the local ruihao database.');
}
$staff = (array)Db::name('system_store_staff')->alias('s')
    ->join('organization_store os', 'os.store_id=s.store_id')
    ->join('employee e', 'e.id=s.employee_id')
    ->where('s.store_id', 102)->where('s.status', 1)->where('s.is_del', 0)
    ->field('s.id,s.store_id,s.employee_id,os.org_id,e.name,e.employment_type_code,e.employment_type_version')
    ->find();
$memberId = (int)Db::name('user')->where('uid', 978855)->where('is_del', 0)->value('uid');
if (!$staff || $memberId <= 0) throw new RuntimeException('Local refund fact fixture is unavailable.');
$operator = new CashierV3OperatorScope((int)$staff['store_id'], (int)$staff['id'], (string)$staff['org_id'], '0');
$scope = new CashierV3DataScopeContext((int)$staff['id'], (int)$staff['id'], (int)$staff['store_id'], '0',
    (string)$staff['org_id'], [(int)$staff['store_id']], CashierV3DataScopeContext::MODE_STORES, [], false,
    '', 'r42-refund-source-type', ['cashier.v3.order_center'], $staff);
$person = ['employeeId' => (int)$staff['employee_id'], 'name' => (string)$staff['name'],
    'employeeTypeCode' => (string)$staff['employment_type_code'],
    'employeeTypeAuthorityVersion' => (int)$staff['employment_type_version'],
    'allocationWeight' => 100, 'performanceAmountCents' => 8000, 'isPreSale' => false];
$method = new ReflectionMethod(CashierV3RefundSalespersonServices::class, 'writeFacts');
$method->setAccessible(true);
$service = new CashierV3RefundSalespersonServices();
$cases = [
    [['source_type' => 'card'], 'manual_refund'],
    [['target_type' => 'card'], 'manual_refund'],
    [['target_type' => 'imported_card'], 'imported_card_refund'],
];
$ids = [];
Db::startTrans();
try {
    foreach ($cases as $index => [$type, $expected]) {
        $id = 'OLO-' . strtoupper(sha1('r42-refund-source-type-' . $index . '-' . bin2hex(random_bytes(8))));
        $ids[] = $id;
        $operation = $type + ['operation_id' => $id, 'member_id' => $memberId,
            'business_date' => date('Y-m-d'), 'operation_no' => 'R42-SOURCE-' . $index,
            'cash_refund_cents' => 8000];
        $method->invoke($service, $operation, [$person], 'R42-SOURCE-TEST-' . $index,
            'R42-SOURCE-KEY-' . $index, $operator, $scope, 'create');
        $fact = (array)Db::name('cashier_v3_performance_fact')->where('order_id', $id)->find();
        if ((string)($fact['source_document_type'] ?? '') !== $expected
            || (int)($fact['amount_cents'] ?? 0) !== -8000) {
            throw new RuntimeException('Refund personnel source or amount mismatch: ' . $index);
        }
    }
} finally {
    Db::rollback();
}
if ((int)Db::name('cashier_v3_performance_fact')->whereIn('order_id', $ids)->count() !== 0) {
    throw new RuntimeException('Refund personnel test facts survived rollback.');
}
echo "R42 refund personnel source type rollback PASS\n";
