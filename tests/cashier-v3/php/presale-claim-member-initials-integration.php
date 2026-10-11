<?php
declare(strict_types=1);

/** 预售/赠送资格只读回归：首字母扩展会员搜索，但不扩大门店、状态与日期范围。 */
require '/var/www/html/vendor/autoload.php';

use app\services\cashier\v3\presale\CashierV3PresaleClaimServices;
use think\facade\Db;

$app = new \think\App('/var/www/html/');
$app->initialize();

function assertClaimInitial(bool $condition, string $message): void
{
    if (!$condition) throw new \RuntimeException('FAIL ' . $message);
    echo 'PASS ' . $message . PHP_EOL;
}

$service = new CashierV3PresaleClaimServices();
$list = new \ReflectionMethod($service, 'list');
$list->setAccessible(true);
$samples = Db::query("SELECT c.tenant_id,c.store_id,c.member_id,c.claimable_line_id,
        c.source_kind,c.claim_status,c.source_order_no_snapshot,c.member_phone_snapshot,
        c.product_name_snapshot,s.initials
    FROM eb_cashier_v3_presale_claimable_line c
    JOIN eb_member_name_initial_search s ON s.uid=c.member_id
    WHERE c.member_id>0 AND s.initials REGEXP '^[A-Z]{3,60}$'
    GROUP BY c.source_kind ORDER BY c.id DESC");
assertClaimInitial(count($samples) >= 2, '本地预售与赠送各有可验证的会员资格');

foreach ($samples as $sample) {
    $kind = (string)$sample['source_kind'];
    $scope = ['tenantId' => (string)$sample['tenant_id'], 'storeIds' => [(int)$sample['store_id']]];
    $criteria = ['source_kind' => $kind, 'keyword' => strtolower((string)$sample['initials']),
        'page' => 1, 'limit' => 100];
    $result = $list->invoke($service, $scope, $criteria);
    assertClaimInitial((int)$result['count'] >= count($result['list'])
        && in_array((string)$sample['claimable_line_id'], array_column($result['list'], 'claimable_line_id'), true),
        $kind . ' 首字母匹配纳入总数和第一页');
    $phone = (string)$sample['member_phone_snapshot'];
    if ($phone !== '') {
        $phoneResult = $list->invoke($service, $scope, array_replace($criteria, ['keyword' => $phone]));
        assertClaimInitial(in_array((string)$sample['claimable_line_id'], array_column($phoneResult['list'], 'claimable_line_id'), true),
            $kind . ' 手机号仍能查询');
    }
    $orderResult = $list->invoke($service, $scope, array_replace($criteria,
        ['keyword' => (string)$sample['source_order_no_snapshot']]));
    assertClaimInitial(in_array((string)$sample['claimable_line_id'], array_column($orderResult['list'], 'claimable_line_id'), true),
        $kind . ' 原订单号仍能查询');
    $productResult = $list->invoke($service, $scope, array_replace($criteria,
        ['keyword' => (string)$sample['product_name_snapshot']]));
    assertClaimInitial(in_array((string)$sample['claimable_line_id'], array_column($productResult['list'], 'claimable_line_id'), true),
        $kind . ' 商品名称仍能查询');
    $otherStatus = (string)$sample['claim_status'] === 'CLOSED_AFTER_SALE_REVERSAL'
        ? 'FULLY_CLAIMED' : 'CLOSED_AFTER_SALE_REVERSAL';
    $statusResult = $list->invoke($service, $scope, array_replace($criteria, ['status' => $otherStatus]));
    assertClaimInitial(!in_array((string)$sample['claimable_line_id'], array_column($statusResult['list'], 'claimable_line_id'), true),
        $kind . ' 状态过滤不能被首字母绕过');
    $dated = $list->invoke($service, $scope, array_replace($criteria, ['start_date' => '2099-01-01']));
    assertClaimInitial((int)$dated['count'] === 0, $kind . ' 日期过滤不能被首字母绕过');
    $denied = $list->invoke($service, ['tenantId' => $scope['tenantId'], 'storeIds' => []], $criteria);
    assertClaimInitial((int)$denied['count'] === 0 && $denied['list'] === [], $kind . ' 空门店权限返回空集');
    $otherStore = (int)$sample['store_id'] === 1 ? 2 : 1;
    $otherResult = $list->invoke($service,
        ['tenantId' => $scope['tenantId'], 'storeIds' => [$otherStore]], $criteria);
    assertClaimInitial(!in_array((string)$sample['claimable_line_id'],
        array_column($otherResult['list'], 'claimable_line_id'), true), $kind . ' 其他门店不可见该资格');
    $paged = $list->invoke($service, $scope, array_replace($criteria, ['limit' => 1]));
    assertClaimInitial((int)$paged['count'] === (int)$result['count'] && count($paged['list']) === 1,
        $kind . ' 翻页不改变查询总数');
}
