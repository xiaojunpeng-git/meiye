<?php
declare(strict_types=1);

// 在本地 PHP 容器运行；只读核对欠款浮层与原销售单的来源快照，不提交收款。
require '/var/www/html/vendor/autoload.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\cashier\CashierV3MemberDebtProjectionServices;
use think\facade\Db;

$app = new \think\App('/var/www/html/');
$app->env->load('/var/www/html/.env');
$app->initialize();

$authority = (array)Db::name('cashier_v3_debt_authority')->alias('a')
    ->join('cashier_v3_sales_order s', 's.id=a.sales_order_record_id AND s.order_id=a.sales_order_id')
    ->join('store_debt d', 'd.id=a.debt_id')
    ->where('s.order_no', 'XS26100300005')->where('d.status', 0)
    ->field('a.tenant_id,a.store_id,a.member_id,a.debt_id,s.business_source_primary_id,s.business_source_primary_name_snapshot,s.business_source_secondary_id,s.business_source_secondary_name_snapshot,s.business_source_label_snapshot')
    ->find();
if (!$authority) {
    echo "SKIP local fixture XS26100300005 has no outstanding sales debt\n";
    exit(0);
}
$storeId = (int)$authority['store_id'];
$scope = new CashierV3DataScopeContext(1, 0, $storeId, (string)$authority['tenant_id'], '', [$storeId], CashierV3DataScopeContext::MODE_STORES, [], false, '', 'test', [], []);
$operator = new CashierV3OperatorScope($storeId, 1, '', (string)$authority['tenant_id']);
$snapshot = (new CashierV3MemberDebtProjectionServices())->read((int)$authority['member_id'], $operator, $scope);
$records = array_values(array_filter($snapshot['records'], static fn (array $row): bool => (int)$row['debtId'] === (int)$authority['debt_id']));
if (count($records) !== 1) throw new RuntimeException('sales debt missing from member repayment overlay');
$source = (array)($records[0]['originalBusinessSource'] ?? []);
foreach (['primarySourceId' => 'business_source_primary_id', 'primarySourceNameSnapshot' => 'business_source_primary_name_snapshot',
    'secondarySourceId' => 'business_source_secondary_id', 'secondarySourceNameSnapshot' => 'business_source_secondary_name_snapshot',
    'displayNameSnapshot' => 'business_source_label_snapshot'] as $projectionKey => $orderKey) {
    if ((string)($source[$projectionKey] ?? '') !== (string)$authority[$orderKey]) {
        throw new RuntimeException('repayment overlay source differs from original sales order: ' . $projectionKey);
    }
}
echo 'PASS repayment overlay original source: ' . $source['displayNameSnapshot'] . "\n";

// 充值欠款使用同一个“去还款”入口；抽一笔仍未结清且有原单来源的记录核对。
$recharge = (array)Db::name('cashier_v3_recharge_debt_authority')->alias('a')
    ->join('user_recharge r', 'r.id=a.recharge_id')
    ->join('store_debt d', 'd.id=a.debt_id')
    ->where('d.status', 0)->where('r.business_source_primary_id', '>', 0)
    ->field('a.tenant_id,a.store_id,a.member_id,a.debt_id,r.business_source_primary_id,r.business_source_primary_name_snapshot,r.business_source_secondary_id,r.business_source_secondary_name_snapshot,r.business_source_label_snapshot')
    ->find();
if (!$recharge) {
    echo "SKIP no outstanding recharge debt with original source\n";
    exit(0);
}
$rechargeStoreId = (int)$recharge['store_id'];
$rechargeScope = new CashierV3DataScopeContext(1, 0, $rechargeStoreId, (string)$recharge['tenant_id'], '', [$rechargeStoreId], CashierV3DataScopeContext::MODE_STORES, [], false, '', 'test', [], []);
$rechargeOperator = new CashierV3OperatorScope($rechargeStoreId, 1, '', (string)$recharge['tenant_id']);
$rechargeSnapshot = (new CashierV3MemberDebtProjectionServices())->read((int)$recharge['member_id'], $rechargeOperator, $rechargeScope);
$rechargeRecords = array_values(array_filter($rechargeSnapshot['records'], static fn (array $row): bool => (int)$row['debtId'] === (int)$recharge['debt_id']));
if (count($rechargeRecords) !== 1) throw new RuntimeException('recharge debt missing from member repayment overlay');
$rechargeSource = (array)($rechargeRecords[0]['originalBusinessSource'] ?? []);
foreach (['primarySourceId' => 'business_source_primary_id', 'primarySourceNameSnapshot' => 'business_source_primary_name_snapshot',
    'secondarySourceId' => 'business_source_secondary_id', 'secondarySourceNameSnapshot' => 'business_source_secondary_name_snapshot',
    'displayNameSnapshot' => 'business_source_label_snapshot'] as $projectionKey => $orderKey) {
    if ((string)($rechargeSource[$projectionKey] ?? '') !== (string)$recharge[$orderKey]) {
        throw new RuntimeException('repayment overlay source differs from original recharge order: ' . $projectionKey);
    }
}
echo 'PASS recharge repayment overlay original source: ' . $rechargeSource['displayNameSnapshot'] . "\n";

// 历史原单若没有可靠来源快照，新规则不能用当前工具栏来源冒充；列出本地受影响数。
$missingSales = (int)Db::name('cashier_v3_debt_authority')->alias('a')
    ->join('store_debt d', 'd.id=a.debt_id')
    ->leftJoin('cashier_v3_sales_order s', 's.id=a.sales_order_record_id AND s.order_id=a.sales_order_id')
    ->where('d.status', 0)->whereRaw('(s.id IS NULL OR s.business_source_primary_id=0 OR s.business_source_label_snapshot=\'\')')
    ->count('a.id');
$missingRecharge = (int)Db::name('cashier_v3_recharge_debt_authority')->alias('a')
    ->join('store_debt d', 'd.id=a.debt_id')
    ->leftJoin('user_recharge r', 'r.id=a.recharge_id')
    ->where('d.status', 0)->whereRaw('(r.id IS NULL OR r.business_source_primary_id=0 OR r.business_source_label_snapshot=\'\')')
    ->count('a.id');
echo "LOCAL outstanding debts without original source: sales={$missingSales}, recharge={$missingRecharge}\n";
