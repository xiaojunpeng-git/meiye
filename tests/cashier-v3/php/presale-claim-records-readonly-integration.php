<?php
declare(strict_types=1);

/** Read-only check: claim rows, outbound registry numbers, filters and store scope share one fact set. */
require '/var/www/html/vendor/autoload.php';

use app\services\cashier\v3\presale\CashierV3PresaleClaimServices;
use think\facade\Db;

$app = new \think\App('/var/www/html/');
$app->initialize();

function checkClaimRecord(bool $condition, string $message): void
{
    if (!$condition) throw new \RuntimeException('FAIL ' . $message);
    echo 'PASS ' . $message . PHP_EOL;
}

$sample = Db::query("SELECT c.tenant_id,c.store_id,c.claim_id,c.claim_no,c.source_kind,c.claim_status,c.business_date
    FROM eb_cashier_v3_presale_claim c ORDER BY c.id DESC LIMIT 1");
checkClaimRecord(count($sample) === 1, '本地存在领用出库事实');
$sample = $sample[0];
$scope = ['tenantId' => (string)$sample['tenant_id'], 'storeIds' => [(int)$sample['store_id']]];
$method = new \ReflectionMethod(CashierV3PresaleClaimServices::class, 'listRecords');
$method->setAccessible(true);
$service = new CashierV3PresaleClaimServices();
$criteria = ['page' => 1, 'limit' => 20, 'keyword' => (string)$sample['claim_no']];
$result = $method->invoke($service, $scope, $criteria);
checkClaimRecord((int)$result['count'] === 1 && count($result['list']) === 1, '出库单号精确命中一笔记录');
$row = $result['list'][0];
checkClaimRecord((string)$row['claim_id'] === (string)$sample['claim_id']
    && (string)$row['outbound_document_no'] === (string)$sample['claim_no'], '记录与领用事实同一单号');
$registered = Db::query('SELECT COUNT(*) total FROM eb_inventory_business_document_no WHERE tenant_id=? AND source_id=? AND document_no=?',
    [(string)$sample['tenant_id'], (string)$sample['claim_id'], (string)$sample['claim_no']]);
checkClaimRecord((int)$registered[0]['total'] === 1, '单号已登记为库存业务单据');
// 中文品名必须在服务端匹配历史商品快照，不能与 ASCII 单号列直接比较而使整页查询失败。
$named = Db::query("SELECT c.tenant_id,c.store_id,c.claim_id,l.product_name_snapshot
    FROM eb_cashier_v3_presale_claim c
    INNER JOIN eb_cashier_v3_presale_claimable_line l
      ON l.tenant_id=c.tenant_id AND l.claimable_line_id=c.claimable_line_id
    WHERE CHAR_LENGTH(l.product_name_snapshot) < LENGTH(l.product_name_snapshot)
    ORDER BY c.id DESC LIMIT 1");
checkClaimRecord(count($named) === 1, '本地存在中文品名领用记录');
$nameScope = ['tenantId' => (string)$named[0]['tenant_id'], 'storeIds' => [(int)$named[0]['store_id']]];
$nameResult = $method->invoke($service, $nameScope, ['page' => 1, 'limit' => 100,
    'keyword' => (string)$named[0]['product_name_snapshot']]);
checkClaimRecord((int)$nameResult['count'] >= 1 && count(array_filter($nameResult['list'],
    static function (array $item) use ($named): bool { return (string)$item['claim_id'] === (string)$named[0]['claim_id']; })) === 1,
    '中文品名筛选命中对应领用事实');
$missingName = $method->invoke($service, $nameScope, ['keyword' => '不存在的领用品名']);
checkClaimRecord((int)$missingName['count'] === 0 && $missingName['list'] === [], '无匹配品名返回空结果及完整总数');
foreach (['source_kind' => (string)$sample['source_kind'], 'status' => (string)$sample['claim_status'],
    'start_date' => (string)$sample['business_date'], 'end_date' => (string)$sample['business_date']] as $field => $value) {
    $filtered = $method->invoke($service, $scope, array_merge($criteria, [$field => $value]));
    checkClaimRecord((int)$filtered['count'] === 1, $field . ' 与同一领用事实一致');
}
$denied = $method->invoke($service, ['tenantId' => $scope['tenantId'], 'storeIds' => []], $criteria);
checkClaimRecord((int)$denied['count'] === 0 && $denied['list'] === [], '空门店权限不能读取记录');
$otherStore = (int)$sample['store_id'] === 1 ? 2 : 1;
$outside = $method->invoke($service, ['tenantId' => $scope['tenantId'], 'storeIds' => [$otherStore]], $criteria);
checkClaimRecord((int)$outside['count'] === 0, '其他门店不能读取该单号');
$paged = $method->invoke($service, $scope, ['page' => 1, 'limit' => 1]);
checkClaimRecord((int)$paged['count'] >= 1 && count($paged['list']) === 1 && (int)$paged['limit'] === 1,
    '服务端分页保留完整命中总数');
