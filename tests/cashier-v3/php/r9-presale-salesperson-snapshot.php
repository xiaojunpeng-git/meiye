<?php
declare(strict_types=1);

// Run inside the local cashier PHP container; the transaction only reads and
// locks one eligible staff record, then rolls back without changing business data.
$backend = getenv('CASHIER_BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';
(new think\App($backend . '/'))->initialize();

use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\cashier\CashierV3CashierWorkspaceServices;
use app\services\cashier\v3\order\settlement\CashierV3SalesOrderPlanV1;
use think\facade\Db;

$staff = Db::name('system_store_staff')->alias('ss')
    ->join('employee e', 'e.id = ss.employee_id')
    ->where('ss.status', 1)->where('ss.is_del', 0)
    ->where('ss.cashier_salesperson_enabled', 1)
    ->where('e.status', 1)->where('e.is_del', 0)
    ->where('e.employment_type_version', '>', 0)
    ->field('ss.id,ss.store_id')->find();
if (!$staff) {
    throw new RuntimeException('No eligible local salesperson fixture');
}
$operator = new CashierV3OperatorScope((int)$staff['store_id'], 1, '', '0');
$workspace = (new ReflectionClass(CashierV3CashierWorkspaceServices::class))
    ->newInstanceWithoutConstructor();
$line = ['line_role' => 'sale', 'line_id' => 'presale-test-line',
    'salespeople_snapshot_json' => json_encode([
        ['staffId' => (int)$staff['id'], 'allocationWeight' => 100, 'isPreSale' => true],
    ])];

Db::startTrans();
try {
    $people = $workspace->salespeopleFromCheckoutRequestLinesInTx([$line], $operator);
    if (($people['presale-test-line'][0]['isPreSale'] ?? null) !== true) {
        throw new RuntimeException('Presale role lost while revalidating checkout staff');
    }
    $line['salespeople_snapshot_json'] = json_encode([
        ['staffId' => (int)$staff['id'], 'allocationWeight' => 100],
    ]);
    $postsale = $workspace->salespeopleFromCheckoutRequestLinesInTx([$line], $operator);
    if (($postsale['presale-test-line'][0]['isPreSale'] ?? null) !== false) {
        throw new RuntimeException('Missing historical role did not remain postsale');
    }
} finally {
    Db::rollback();
}

// The order canonicalizer is isolated from the DB. It must keep true while
// leaving historical false absent so old order fingerprints remain stable.
$canonical = new ReflectionMethod(CashierV3SalesOrderPlanV1::class, 'salespeopleSnapshot');
$canonical->setAccessible(true);
$input = [['staffId' => (int)$staff['id'], 'allocationWeight' => 100, 'isPreSale' => true]];
$presaleOrder = $canonical->invoke(null, json_encode($input));
if (($presaleOrder[0]['isPreSale'] ?? null) !== true) {
    throw new RuntimeException('Presale role lost while creating sale order line');
}
$input[0]['isPreSale'] = false;
$postsaleOrder = $canonical->invoke(null, json_encode($input));
if (array_key_exists('isPreSale', $postsaleOrder[0])) {
    throw new RuntimeException('Legacy postsale fingerprint changed');
}
echo "R9_PRESALE_SALESPERSON_SNAPSHOT=PASS\n";
