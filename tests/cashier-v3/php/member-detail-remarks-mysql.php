<?php
declare(strict_types=1);

/** Read-only comparison of member-detail remarks against their source rows. */
$backend = getenv('CASHIER_V3_BACKEND') ?: '/var/www/html';
$phone = trim((string)getenv('MEMBER_DETAIL_TEST_PHONE'));
if ($phone === '') throw new RuntimeException('MEMBER_DETAIL_TEST_PHONE_REQUIRED');
require $backend . '/vendor/autoload.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\member\CashierV3MemberDetailQueryServices;
use think\facade\Db;

$app = new \think\App($backend . '/');
$app->env->load($backend . '/.env');
$app->initialize();
$member = (array)Db::name('user')->where('phone', $phone)->field('uid,belong_store_id')->find();
$memberId = (int)($member['uid'] ?? 0);
$storeId = (int)($member['belong_store_id'] ?? 0);
$organizationId = (string)Db::name('organization_store')->where('store_id', $storeId)->value('org_id');
if ($memberId <= 0 || $storeId <= 0 || $organizationId === '' || $organizationId === '0') {
    throw new RuntimeException('MEMBER_DETAIL_TEST_SCOPE_MISSING');
}
$operator = new CashierV3OperatorScope($storeId, 1, $organizationId, '0');
$scope = new CashierV3DataScopeContext(
    1, 1, $storeId, '0', $organizationId, [$storeId],
    CashierV3DataScopeContext::MODE_STORES, [], false, '', 'member-detail-remarks-test', [],
    ['id' => 1, 'staff_name' => 'integration operator']
);
$reader = new CashierV3MemberDetailQueryServices();
$detail = $reader->read($memberId, $operator, $scope, ['tab' => 'writeoff']);
$checked = 0;
foreach ($detail['serviceRecords'] as $service) {
    $id = (int)str_replace('service:', '', (string)$service['id']);
    $source = (array)Db::name('cashier_v3_entitlement_service_fact')->where('id', $id)
        ->where('member_id', $memberId)->field('detail_remark_snapshot')->find();
    if (!$source || (string)$service['detailRemark'] !== (string)($source['detail_remark_snapshot'] ?? '')) {
        throw new RuntimeException('SERVICE_REMARK_MISMATCH');
    }
    $checked++;
}

$holderId = (int)($detail['cards'][0]['id'] ?? 0);
if ($holderId <= 0) throw new RuntimeException('TEST_MEMBER_HAS_NO_CARD');
$timeline = $reader->cardHistory($memberId, $holderId, 1, $operator, $scope);
$purchaseNote = (string)(Db::name('cashier_v3_card_purchase_receipt')->alias('receipt')
    ->leftJoin('cashier_v3_sales_order_line line',
        'line.tenant_id=receipt.tenant_id AND line.order_line_id=receipt.sales_order_line_id')
    ->where('receipt.tenant_id', $scope->tenantId())->where('receipt.card_holder_id', $holderId)
    ->value('line.detail_remark_snapshot') ?? '');
$purchaseChecked = false;
for ($page = 1; $page <= (int)ceil($timeline['total'] / $timeline['pageSize']); $page++) {
    if ($page > 1) $timeline = $reader->cardHistory($memberId, $holderId, $page, $operator, $scope);
    foreach ($timeline['records'] as $record) {
        if (!array_key_exists('detailRemark', $record)) throw new RuntimeException('TIMELINE_REMARK_MISSING');
        if ($record['key'] === 'card:' . $holderId) {
            if ($record['detailRemark'] !== $purchaseNote) throw new RuntimeException('PURCHASE_REMARK_MISMATCH');
            $purchaseChecked = true;
        }
        if (strncmp($record['key'], 'writeoff:', 9) === 0) {
            $source = (array)Db::name('cashier_v3_entitlement_service_fact')
                ->where('service_fact_id', substr($record['key'], 9))->where('member_id', $memberId)
                ->field('detail_remark_snapshot')->find();
            if (!$source || $record['detailRemark'] !== (string)($source['detail_remark_snapshot'] ?? '')) {
                throw new RuntimeException('CARD_WRITEOFF_REMARK_MISMATCH');
            }
            $checked++;
        }
    }
}
if (!$purchaseChecked) throw new RuntimeException('PURCHASE_ROW_MISSING');

// A non-empty source note must survive the actual authorized detail projection.
$positive = (array)Db::name('cashier_v3_entitlement_service_fact')->alias('sf')
    ->join('user u', 'u.uid=sf.member_id')
    ->join('organization_store os', 'os.store_id=u.belong_store_id')
    ->where('sf.tenant_id', $scope->tenantId())->where('sf.service_status', 'completed')
    ->where('sf.detail_remark_snapshot', '<>', '')
    ->field('sf.id,sf.member_id,sf.detail_remark_snapshot,u.belong_store_id,os.org_id')
    ->order('sf.settled_at desc')->find();
if (!$positive) throw new RuntimeException('POSITIVE_SERVICE_REMARK_SAMPLE_MISSING');
$positiveStoreId = (int)$positive['belong_store_id'];
$positiveOrganizationId = (string)$positive['org_id'];
$positiveOperator = new CashierV3OperatorScope($positiveStoreId, 1, $positiveOrganizationId, '0');
$positiveScope = new CashierV3DataScopeContext(
    1, 1, $positiveStoreId, '0', $positiveOrganizationId, [$positiveStoreId],
    CashierV3DataScopeContext::MODE_STORES, [], false, '', 'member-detail-positive-remark-test', [],
    ['id' => 1, 'staff_name' => 'integration operator']
);
$positiveDetail = $reader->read((int)$positive['member_id'], $positiveOperator, $positiveScope,
    ['tab' => 'writeoff']);
$positiveMatch = false;
foreach ($positiveDetail['serviceRecords'] as $service) {
    if ($service['id'] !== 'service:' . (int)$positive['id']) continue;
    $positiveMatch = $service['detailRemark'] === (string)$positive['detail_remark_snapshot'];
}
if (!$positiveMatch) throw new RuntimeException('POSITIVE_SERVICE_REMARK_MISMATCH');
$positivePurchase = (array)Db::name('cashier_v3_card_purchase_receipt')->alias('receipt')
    ->join('cashier_v3_sales_order_line line',
        'line.tenant_id=receipt.tenant_id AND line.order_line_id=receipt.sales_order_line_id')
    ->join('user_card_holder holder', 'holder.id=receipt.card_holder_id')
    ->join('user u', 'u.uid=holder.uid')
    ->join('organization_store os', 'os.store_id=u.belong_store_id')
    ->where('receipt.tenant_id', $scope->tenantId())
    ->where('line.detail_remark_snapshot', '<>', '')
    ->field('receipt.card_holder_id,holder.uid,u.belong_store_id,os.org_id,line.detail_remark_snapshot')
    ->order('receipt.settled_at desc')->find();
if (!$positivePurchase) throw new RuntimeException('POSITIVE_PURCHASE_REMARK_SAMPLE_MISSING');
$purchaseStoreId = (int)$positivePurchase['belong_store_id'];
$purchaseOrganizationId = (string)$positivePurchase['org_id'];
$purchaseOperator = new CashierV3OperatorScope($purchaseStoreId, 1, $purchaseOrganizationId, '0');
$purchaseScope = new CashierV3DataScopeContext(
    1, 1, $purchaseStoreId, '0', $purchaseOrganizationId, [$purchaseStoreId],
    CashierV3DataScopeContext::MODE_STORES, [], false, '', 'member-detail-positive-purchase-test', [],
    ['id' => 1, 'staff_name' => 'integration operator']
);
$purchaseHolderId = (int)$positivePurchase['card_holder_id'];
$positiveTimeline = $reader->cardHistory((int)$positivePurchase['uid'], $purchaseHolderId, 1,
    $purchaseOperator, $purchaseScope);
$purchaseMatch = false;
for ($page = 1; $page <= (int)ceil($positiveTimeline['total'] / $positiveTimeline['pageSize']); $page++) {
    if ($page > 1) $positiveTimeline = $reader->cardHistory((int)$positivePurchase['uid'],
        $purchaseHolderId, $page, $purchaseOperator, $purchaseScope);
    foreach ($positiveTimeline['records'] as $record) {
        if ($record['key'] !== 'card:' . $purchaseHolderId) continue;
        $purchaseMatch = $record['detailRemark'] === (string)$positivePurchase['detail_remark_snapshot'];
    }
}
if (!$purchaseMatch) throw new RuntimeException('POSITIVE_PURCHASE_REMARK_MISMATCH');
echo 'PASS service remarks=' . count($detail['serviceRecords'])
    . ' card-history rows=' . count($timeline['records'])
    . ' source comparisons=' . $checked . ' positive service=1 positive purchase=1' . PHP_EOL;
