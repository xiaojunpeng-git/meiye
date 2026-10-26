<?php

declare(strict_types=1);

/** 第22轮大单整卡退款回归：只读本地 Docker 数据，不写业务表。 */
require '/var/www/html/vendor/autoload.php';

use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\report\StoreUnifiedReportPhaseTwoServices;
use think\facade\Db;

$app = new think\App('/var/www/html/');
$app->env->load('/var/www/html/.env');
$app->initialize();

function r22WholeCardAssert(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException('FAIL ' . $message);
    echo 'PASS ', $message, "\n";
}

$service = new StoreUnifiedReportPhaseTwoServices();
$allocator = new ReflectionMethod($service, 'allocateLargeOrderRefundCategories');
$allocator->setAccessible(true);
$single = $allocator->invoke(null, -6000000, [[
    'id' => 1, 'path' => '六维/自营', 'platform_product_id' => 1, 'weight_cents' => 0,
]]);
r22WholeCardAssert(count($single) === 1 && (int)$single[0]['amount_cents'] === -6000000,
    '单一已验证分类承接整笔退款，不因整卡没有组件权重而丢失');
$weighted = $allocator->invoke(null, -100, [
    ['id' => 1, 'path' => '生美/面部', 'platform_product_id' => 1, 'weight_cents' => 1],
    ['id' => 2, 'path' => '生美/身体', 'platform_product_id' => 2, 'weight_cents' => 2],
]);
r22WholeCardAssert(array_sum(array_column($weighted, 'amount_cents')) === -100
    && (int)$weighted[0]['amount_cents'] === -33 && (int)$weighted[1]['amount_cents'] === -67,
    '多分类按已验证权重分摊并由稳定末项承接整数尾差');
$ambiguous = $allocator->invoke(null, -100, [
    ['id' => 1, 'path' => '生美/面部', 'platform_product_id' => 1, 'weight_cents' => 0],
    ['id' => 2, 'path' => '生美/身体', 'platform_product_id' => 2, 'weight_cents' => 0],
]);
r22WholeCardAssert($ambiguous === [], '多分类没有可信权重时不猜测退款归属');

// Reuse a real local holder/order/category link, but construct only an in-memory
// whole-card refund projection. This proves card_component_id=0 reaches the shared
// category authority without creating a refund, changing a holder, or writing facts.
$holders = Db::name('user_card_holder')->alias('holder')
    ->join('store_order original', 'original.id=holder.oid AND original.uid=holder.uid')
    ->where('holder.product_id', '>', 0)->where('original.store_id', '>', 0)
    ->field('holder.id,holder.uid,holder.oid,original.store_id')
    ->order('holder.id', 'desc')->limit(500)->select()->toArray();
$projection = new ReflectionMethod($service, 'currentBeautyLargeOrderRefundFacts');
$projection->setAccessible(true);
$projected = [];
foreach ($holders as $holder) {
    $fixture = [
        'tenant_id' => CashierV3ScopeResolver::TENANT_SCOPE_ID,
        'store_id' => (int)$holder['store_id'], 'organization_id' => 'R22-READONLY',
        'organization_path_snapshot' => '', 'division_name' => 'R22只读', 'store_name' => 'R22只读',
        'business_date' => '2026-10-18', 'employee_id' => 220018,
        'salesperson' => 'R22只读人员', 'member_id' => (int)$holder['uid'],
        'member_name_snapshot' => 'R22只读会员', 'role_snapshot' => 'salesperson',
        'order_id' => 'R22-WHOLE-CARD', 'source_line_id' => 'R22-WHOLE-CARD',
        'sale_fact_id' => 'R22-WHOLE-CARD-SALE', 'operation_id' => 'R22-WHOLE-CARD',
        'source_type' => 'card', 'source_order_id' => (string)$holder['oid'],
        'refund_card_holder_id' => (int)$holder['id'], 'card_component_id' => 0,
        'direct_amount_cents' => -6000000, 'refund_no' => 'R22-READONLY',
    ];
    $projected = $projection->invoke($service, [$fixture]);
    if ($projected !== []) break;
}
r22WholeCardAssert($projected !== [], '本地真实卡项关系可生成整卡退款的大单分类事实');
r22WholeCardAssert(array_sum(array_column($projected, 'amount_cents')) === -6000000,
    '整卡退款分类事实金额守恒且保持负向');
r22WholeCardAssert(count(array_filter($projected, static function (array $row): bool {
    return (string)($row['role_snapshot'] ?? '') === 'salesperson'
        && (int)($row['employee_id'] ?? 0) === 220018
        && (int)($row['member_id'] ?? 0) > 0
        && trim((string)($row['beauty_category'] ?? '')) !== '';
})) === count($projected), '退款事实保留人员、会员、角色与有效大单分类');

// Execute the complete report query once so SQL joins, refund projections, qualification and
// pagination are compiled together. The chosen store may legitimately have zero October awards.
$runtime = $service->query('salesperson_large_order_statistics', [(int)$holder['store_id']], [
    'start' => '2026-10-01', 'end' => '2026-10-31',
], ['month' => '2026-10', '_internal_all' => true]);
r22WholeCardAssert((string)($runtime['metric_version'] ?? '') === 'large-order-gross-cash-refund-display-v11'
    && is_array($runtime['records'] ?? null), '完整大单报表查询使用整卡退款口径版本并正常返回');
$columnKeys = array_column($runtime['columns'] ?? [], 'key');
$awardColumn = array_search('award_amount', $columnKeys, true);
$refundColumn = array_search('refund_amount', $columnKeys, true);
r22WholeCardAssert($awardColumn !== false && $refundColumn === $awardColumn + 1,
    '退款金额紧跟达标月累计现金业绩，并由查询、合计和导出共用列契约');

echo "ASSERT_FAILED=0\n";
