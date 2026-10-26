<?php
declare(strict_types=1);

// Read-only integration: the debt list and member repayment overlay must show
// the same issuance-time card name without mutating historical debt rows.
require '/var/www/html/vendor/autoload.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\cashier\CashierV3MemberDebtProjectionServices;
use app\services\cashier\v3\order\CashierV3OrderCenterRecordQueryServices;
use app\services\cashier\v3\order\CashierV3OrderCenterUnifiedQueryContract;
use think\facade\Db;

$app = new \think\App('/var/www/html/');
$app->env->load('/var/www/html/.env');
$app->initialize();

$fields = CashierV3OrderCenterUnifiedQueryContract::definition('order_center_debt')['fields'];
$keys = array_column($fields, 'key');
$orderIndex = array_search('source_order_no', $keys, true);
$nameIndex = array_search('product_name', $keys, true);
if ($orderIndex === false || $nameIndex !== $orderIndex + 1
    || ($fields[$nameIndex]['label'] ?? '') !== '商品名称') {
    throw new RuntimeException('debt list/export field is not beside the source order');
}

$fixture = (array)Db::name('store_debt')->alias('d')
    ->join('store_debt_item i', 'i.debt_id=d.id')
    ->join('user_card_holder h', 'h.oid=i.order_id AND h.product_id=i.product_id')
    ->join('user u', 'u.uid=d.uid')
    ->whereIn('i.product_name', ['历史卡项欠款', 'SWXT历史卡项欠款'])
    ->where('d.status', 0)->where('u.status', 1)->where('u.is_del', 0)
    ->whereRaw('d.total_debt>d.repaid_debt')
    ->where('h.card_name', '<>', '')
    ->field('d.id,d.debt_no,d.uid,d.store_id,d.add_time,h.card_name')
    ->find();
if (!$fixture) {
    echo "SKIP no active historical card debt with an issuance name\n";
    exit(0);
}

$storeId = (int)$fixture['store_id'];
$scope = new CashierV3DataScopeContext(1, 0, $storeId, '0', '', [$storeId], CashierV3DataScopeContext::MODE_STORES, [], false, '', 'test', ['cashier.v3.order_center'], []);
$operator = new CashierV3OperatorScope($storeId, 1, '', '0');
$date = date('Y-m-d', (int)$fixture['add_time']);
$page = (new CashierV3OrderCenterRecordQueryServices())->queryRecords([
    'recordType' => 'debt', 'keyword' => (string)$fixture['debt_no'],
    'businessDateFrom' => $date, 'businessDateTo' => $date,
    'page' => 1, 'pageSize' => 20,
], $operator, $scope);
$matches = array_values(array_filter($page['records'], static function (array $record) use ($fixture): bool {
    return (int)($record['debtId'] ?? 0) === (int)$fixture['id'];
}));
if (count($matches) !== 1 || (string)$matches[0]['productName'] !== trim((string)$fixture['card_name'])) {
    throw new RuntimeException('debt list product name differs from issuance snapshot');
}

$overlay = (new CashierV3MemberDebtProjectionServices())->read((int)$fixture['uid'], $operator, $scope);
$details = array_values(array_filter($overlay['records'], static function (array $record) use ($fixture): bool {
    return (int)$record['debtId'] === (int)$fixture['id'];
}));
if (count($details) !== 1 || $details[0]['summary'] !== $matches[0]['productName']) {
    throw new RuntimeException('debt list and member debt overlay show different product names');
}
echo 'PASS debt list and overlay product name: ' . $matches[0]['productName'] . "\n";
