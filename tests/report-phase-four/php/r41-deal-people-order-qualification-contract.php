<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/后端代码/vendor/autoload.php';

use app\services\report\StoreDealPeopleQualificationServices;

/** 同一原单累计、首次达标日期、跨店归原店和全实例会员日去重的纯逻辑合同。 */
function receipt(string $order, int $member, string $date, int $time, int $cents, int $threshold, int $store): array
{
    return [
        'sales_order_id' => $order, 'member_id' => $member,
        'business_date' => $date, 'settled_at' => $time, 'fact_id' => $order . ':' . $time,
        'amount_cents' => $cents, 'threshold_cents' => $threshold,
        'store_id' => $store,
    ];
}

$qualifier = new StoreDealPeopleQualificationServices();
$events = $qualifier->qualify([
    // A 单首付 500、两次补交 200/300；跨店补交仍归原店 12。
    receipt('XS001', 1, '2026-10-01', 1, 50000, 100000, 12),
    receipt('XS001', 1, '2026-10-02', 2, 20000, 100000, 12),
    receipt('XS001', 1, '2026-10-03', 3, 30000, 100000, 12),
    receipt('XS001', 1, '2026-10-04', 4, 10000, 100000, 12),
    // 退款只影响财务金额，不扣减原单累计或撤销已取得的成交资格。
    receipt('XS001', 1, '2026-10-03', 6, -90000, 100000, 12),
    // 另一单当天也达标，不能给同一会员再加一人。
    receipt('XS003', 1, '2026-10-03', 5, 50000, 50000, 9),
    receipt('XS002', 2, '2026-10-01', 1, 20000, 50000, 9),
    receipt('XS002', 2, '2026-10-02', 2, 30000, 50000, 9),
    // 同一会员的另一张独立达标单在次日应另计一次。
    receipt('XS004', 1, '2026-10-04', 4, 50000, 50000, 9),
    receipt('XS005', 0, '2026-10-04', 4, 999999, 50000, 9),
    // 两张各自未达标的单据不能跨单凑门槛。
    receipt('XS006', 3, '2026-10-04', 4, 60000, 100000, 9),
    receipt('XS007', 3, '2026-10-04', 4, 40000, 100000, 9),
]);
if (array_keys($events) !== ['2026-10-02|2', '2026-10-03|1', '2026-10-04|1']) {
    throw new RuntimeException('原单累计或会员日去重结果错误：' . implode(',', array_keys($events)));
}
if ($events['2026-10-03|1']['sales_order_id'] !== 'XS001'
    || $events['2026-10-03|1']['store_id'] !== 12) {
    throw new RuntimeException('跨店补交的成交资格未归原销售门店');
}
if ($events['2026-10-03|1']['qualifying_payment_fact_ids'] !== ['XS001:1', 'XS001:2', 'XS001:3']) {
    throw new RuntimeException('分类归属必须只使用首次达标之前的收款');
}
$category = static fn(int $root, int $leaf, int $item): array => [
    'root_id' => $root, 'id' => $leaf, 'path' => $root . '/' . $leaf,
    'platform_product_id' => $item,
];
$winner = StoreDealPeopleQualificationServices::largestCategory([
    ['item_id' => 11, 'amount_cents' => 30000, 'sequence' => '0001', 'category' => $category(1, 101, 11)],
    ['item_id' => 11, 'amount_cents' => 20000, 'sequence' => '0001', 'category' => $category(1, 101, 11)],
    ['item_id' => 22, 'amount_cents' => 40000, 'sequence' => '0002', 'category' => $category(2, 201, 22)],
]);
if ((int)$winner['root_id'] !== 1 || (int)$winner['item_id'] !== 11) {
    throw new RuntimeException('分类应先按原单累计现金分摊金额比较');
}
$tie = StoreDealPeopleQualificationServices::largestCategory([
    ['item_id' => 11, 'amount_cents' => 50000, 'sequence' => '0002', 'category' => $category(1, 101, 11)],
    ['item_id' => 22, 'amount_cents' => 50000, 'sequence' => '0001', 'category' => $category(2, 201, 22)],
]);
if ((int)$tie['root_id'] !== 2 || StoreDealPeopleQualificationServices::largestCategory([]) !== []) {
    throw new RuntimeException('分类同额必须按明细顺序决定，无法核实时不得猜测');
}
$fullPath = StoreDealPeopleQualificationServices::largestCategory([
    ['item_id' => 11, 'amount_cents' => 30000, 'sequence' => '0001', 'category' => $category(1, 101, 11)],
    ['item_id' => 12, 'amount_cents' => 30000, 'sequence' => '0002', 'category' => $category(1, 102, 12)],
    ['item_id' => 22, 'amount_cents' => 50000, 'sequence' => '0003', 'category' => $category(2, 201, 22)],
]);
if ((int)$fullPath['id'] !== 201) {
    throw new RuntimeException('完整分类分别比较金额，同一大类下不同小类不能合并抢人头');
}
// A settings change at second-level precision must not retroactively reprice
// an original order settled in the same second; later orders use the new version.
$append = new ReflectionMethod(StoreDealPeopleQualificationServices::class, 'appendReceipt');
$policies = [
    ['effective_at' => 0, 'a_threshold_cents' => 100000, 'other_threshold_cents' => 50000],
    ['effective_at' => 100, 'a_threshold_cents' => 200000, 'other_threshold_cents' => 80000],
    ['effective_at' => 101, 'a_threshold_cents' => 300000, 'other_threshold_cents' => 90000],
];
$orders = [
    'OA' => ['settled_at' => 100, 'business_source_primary_id' => 1,
        'business_source_primary_name_snapshot' => 'A老客首护', 'store_id' => 12, 'member_id' => 1],
    'OB' => ['settled_at' => 102, 'business_source_primary_id' => 2,
        'business_source_primary_name_snapshot' => 'B导购引流', 'store_id' => 9, 'member_id' => 2],
];
$payment = ['fact_id' => 'payment-1', 'order_id' => 'OA', 'business_date' => '2026-10-01',
    'settled_at' => 100, 'amount_cents' => 10000, 'payment_method' => 'wechat'];
$projected = [];
$append->invokeArgs($qualifier, [&$projected, $payment, 'OA', $orders, $policies]);
$payment['order_id'] = 'OB';
$append->invokeArgs($qualifier, [&$projected, $payment, 'OB', $orders, $policies]);
if ($projected[0]['threshold_cents'] !== 100000 || $projected[1]['threshold_cents'] !== 90000) {
    throw new RuntimeException('门槛必须按原单形成时生效的版本和来源选定');
}
echo "PASS R41 deal-people order qualification\n";
