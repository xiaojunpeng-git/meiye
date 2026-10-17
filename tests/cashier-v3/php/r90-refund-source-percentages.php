<?php
declare(strict_types=1);

// Pure production-method regression. No application boot, SQL, refund command or historical writes.
$backend = getenv('CASHIER_BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';

use app\services\cashier\v3\order\CashierV3RefundSalespersonServices;

$service = new CashierV3RefundSalespersonServices();
$convert = new ReflectionMethod($service, 'sourcePercentages');
$convert->setAccessible(true);
$allocate = new ReflectionMethod($service, 'allocate');
$allocate->setAccessible(true);
$checks = [];
$run = static function (string $name, array $ratios, array $expected, array $groups = [], bool $adjusted = false) use ($service, $convert, &$checks): array {
    $people = []; $facts = [];
    foreach ($ratios as $i => $ratio) {
        $id = $i + 1;
        $group = $groups[$i] ?? 'normal';
        $people[$id] = ['staffId' => $id, 'employeeId' => $id, 'name' => 'fixture-' . $id,
            'allocationGroupKey' => $group, 'performanceIndependent' => $group !== 'normal',
            'isPreSale' => $i === 0, 'performanceAmountManual' => false];
        $facts[$id] = ['allocation_weight_numerator' => $ratio[0], 'allocation_weight_denominator' => $ratio[1],
            'amount_cents' => $ratio[2] ?? $ratio[0],
            'rule_code_snapshot' => $adjusted ? 'ORDER-PERSONNEL-ADJUST-MANUAL-AMOUNT-V1' : 'SALES-CASH-COLLECTED-V1'];
    }
    $before = serialize([$people, $facts]);
    $result = $convert->invoke($service, $people, $facts);
    if (array_column($result, 'allocationWeight') !== $expected || serialize([$people, $facts]) !== $before) {
        throw new RuntimeException($name . ': wrong proportions or source mutated');
    }
    foreach ($result as $id => $person) {
        unset($person['allocationWeight']);
        if ($person !== $people[$id]) throw new RuntimeException($name . ': personnel/group metadata changed');
    }
    $checks[] = $name;
    return array_values($result);
};
$run('original 30/70 retained despite rounded amounts', [[30,100,0],[70,100,100]], [30,70]);
$run('large equal weights', [[580000,1160000],[580000,1160000]], [50,50]);
$run('unequal money-denominated weights', [[200000,580000],[380000,580000]], [34,66]);
$run('thirds stable final remainder', [[1,3],[1,3],[1,3]], [33,33,34]);
$run('different denominators', [[1,2],[1,3],[1,6]], [50,33,17]);
$run('no floating precision loss', [[1,10],[2,10],[7,10]], [10,20,70]);
$run('independent groups never halved', [[580000,580000],[580000,580000]], [100,100], ['independent:10','normal']);
$people = $run('adjusted original screenshot', [[580000,580000],[580000,580000]], [100,100], ['independent:10','normal'], true);
if ($allocate->invoke($service, $people, 580000) !== [580000,580000]) throw new RuntimeException('Independent allocation changed');
$checks[] = 'existing independent deduction calculation unchanged';
$run('adjusted same group uses saved amounts', [[200000,200000],[380000,380000]], [34,66], [], true);
$run('two independent groups with multiple people', [[1,3],[2,3],[1,4],[3,4]], [33,67,25,75], ['normal','normal','independent:10','independent:10']);
$run('zero remains zero', [[0,100],[100,100],[0,100]], [0,100,0]);
$run('all zero does not invent allocation', [[0,100],[0,100]], [0,0]);
$run('adjusted all zero remains zero', [[0,1,0],[0,1,0]], [0,0], [], true);
$run('tiny weight not silently boosted', [[1,1000],[999,1000]], [0,100]);
$run('large integers no overflow', [[PHP_INT_MAX-1,PHP_INT_MAX],[1,PHP_INT_MAX]], [99,1]);
$run('empty selection retained', [], []);
$people = $run('normal integer amount tail', [[33,100],[67,100]], [33,67]);
if ($allocate->invoke($service, $people, 10100) !== [3300,6800]) throw new RuntimeException('Integer tail changed');
$checks[] = 'existing whole-yuan final remainder unchanged';
if ($allocate->invoke($service, $people, 0) !== [0,0]) throw new RuntimeException('Zero cash allocation changed');
$checks[] = 'zero cash does not create deduction';
foreach ([[1,0],[-1,100],[101,100]] as $ratio) {
    try { $run('invalid', [$ratio], [100]); throw new RuntimeException('Invalid ratio accepted'); }
    catch (\app\services\cashier\v3\CashierV3CommandException $error) { $checks[] = 'invalid source ratio rejected'; }
}
echo json_encode(['status'=>'PASS','count'=>count($checks),'checks'=>$checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
