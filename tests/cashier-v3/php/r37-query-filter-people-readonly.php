<?php
/** 只读验证筛选候选：不改任职、不分配业绩、不执行业务命令。 */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 4) . '/后端代码';
require $backend . '/vendor/autoload.php';
$app = new \think\App($backend . '/'); $app->initialize();
$operator = new \app\services\cashier\v3\CashierV3OperatorScope(133, 71, 'test', '0');
$scope = new \app\services\cashier\v3\CashierV3DataScopeContext(71, 701, 133, '0', 'test', [133], \app\services\cashier\v3\CashierV3DataScopeContext::MODE_STORES, [], false, '', 'test', [], []);
$service = new \app\services\cashier\v3\member\CashierV3QueryEntitySelectorServices();
$payload = ['entityType' => 'person', 'selectorContext' => ['scope' => 'query_filter'], 'page' => 1, 'pageSize' => 20];
$all = $service->query($payload, $operator, $scope);
if (!$all['records']) throw new RuntimeException('missing candidates');
foreach ($all['records'] as $row) if ($row['storeId'] !== 133) throw new RuntimeException('store leak');
$one = $service->query($payload + ['keyword' => '汤静静'], $operator, $scope);
if (!$one['records'] || $one['records'][0]['name'] !== '汤静静') throw new RuntimeException('keyword failed');
try {
    $bad = $payload; $bad['selectorContext']['scope'] = 'invalid';
    $service->query($bad, $operator, $scope);
    throw new RuntimeException('invalid scope accepted');
} catch (\app\services\cashier\v3\CashierV3CommandException $expected) {}
echo "PASS: read-only candidates, keyword, forced store, invalid scope rejection\n";
