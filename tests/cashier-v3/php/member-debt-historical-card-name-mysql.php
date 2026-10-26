<?php
declare(strict_types=1);

// Read-only check against an imported card debt; no repayment or source data is changed.
require '/var/www/html/vendor/autoload.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\cashier\CashierV3MemberDebtProjectionServices;
use think\facade\Db;

$app = new \think\App('/var/www/html/');
$app->env->load('/var/www/html/.env');
$app->initialize();

$fixture = (array)Db::name('store_debt')->alias('d')
    ->join('store_debt_item i', 'i.debt_id=d.id')
    ->join('user_card_holder h', 'h.oid=i.order_id AND h.product_id=i.product_id')
    ->join('user u', 'u.uid=d.uid')
    ->whereIn('i.product_name', ['历史卡项欠款', 'SWXT历史卡项欠款'])
    ->where('d.status', 0)
    ->where('u.status', 1)
    ->where('u.is_del', 0)
    ->where('d.total_debt', '>', 0)
    ->whereRaw('d.total_debt>d.repaid_debt')
    ->where('h.card_name', '<>', '')
    ->field('d.id,d.uid,d.store_id,h.card_name')
    ->find();
if (!$fixture) {
    echo "SKIP no active imported-card debt with an issuance name in local DB\n";
    exit(0);
}

$storeId = (int)$fixture['store_id'];
$scope = new CashierV3DataScopeContext(1, 0, $storeId, '0', '', [$storeId], CashierV3DataScopeContext::MODE_STORES, [], false, '', 'test', [], []);
$operator = new CashierV3OperatorScope($storeId, 1, '', '0');
$projection = (new CashierV3MemberDebtProjectionServices())->read((int)$fixture['uid'], $operator, $scope);
$matches = array_values(array_filter($projection['records'], static function (array $record) use ($fixture): bool {
    return (int)$record['debtId'] === (int)$fixture['id'];
}));
if (count($matches) !== 1 || (string)$matches[0]['summary'] !== trim((string)$fixture['card_name'])) {
    throw new RuntimeException('historical card debt summary did not use the issuance snapshot');
}
echo 'PASS historical card debt summary: ' . $matches[0]['summary'] . "\n";
