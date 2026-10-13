<?php
declare(strict_types=1);

// Read-only integration check: old-card display follows an exact holder/product link.
require '/var/www/html/vendor/autoload.php';

use app\services\cashier\v3\order\CashierV3OrderCenterRecordQueryServices;
use think\facade\Db;

$app = new \think\App('/var/www/html/');
$app->env->load('/var/www/html/.env');
$app->initialize();

$fixture = (array)Db::name('user_card_holder')->alias('h')
    ->join('store_order o', 'o.id=h.oid AND o.uid=h.uid')
    ->join('store_product p', 'p.id=h.product_id AND p.is_del=0')
    ->join('store_product_category c', 'c.id=p.cate_id AND c.type=0 AND c.relation_id=0')
    ->where('h.product_id', '>', 0)->where('h.uid', '>', 0)
    ->field('h.id,h.uid,h.oid,h.product_id,c.id category_id,c.cate_name')
    ->order('h.id desc')->find();
if ($fixture === []) {
    echo "SKIP no card holder with a current platform category\n";
    exit(0);
}

$categoryPath = static function (int $categoryId): string {
    $names = [];
    $seen = [];
    while ($categoryId > 0 && !isset($seen[$categoryId]) && count($names) < 12) {
        $seen[$categoryId] = true;
        $category = (array)Db::name('store_product_category')->where('id', $categoryId)
            ->where('type', 0)->where('relation_id', 0)->field('pid,cate_name')->find();
        if ($category === [] || trim((string)$category['cate_name']) === '') return '';
        $names[] = trim((string)$category['cate_name']);
        $categoryId = (int)$category['pid'];
    }
    return $categoryId === 0 ? implode('/', array_reverse($names)) : '';
};
$expected = $categoryPath((int)$fixture['category_id']);
if ($expected === '') throw new RuntimeException('fixture category hierarchy is incomplete');
$row = [
    'operation_id' => 'R20-READONLY-FIXTURE', 'source_type' => 'card',
    'source_order_id' => (string)$fixture['oid'], 'member_id' => (int)$fixture['uid'],
    'refund_card_holder_id' => (int)$fixture['id'], 'card_category_name' => '',
];
$method = new ReflectionMethod(CashierV3OrderCenterRecordQueryServices::class, 'platformCardCategoriesForRefunds');
if (PHP_VERSION_ID < 80100) $method->setAccessible(true);
$reader = new CashierV3OrderCenterRecordQueryServices();
$actual = $method->invoke($reader, [$row]);
if (($actual[$row['operation_id']] ?? '') !== $expected) {
    throw new RuntimeException('old card platform category mismatch: ' . json_encode($actual, JSON_UNESCAPED_UNICODE));
}

// A holder from another member/order and a card with a sale snapshot must not use this fallback.
$wrongMember = $row;
$wrongMember['member_id']++;
$wrongOrder = $row;
$wrongOrder['source_order_id'] = '0';
$saleSnapshot = $row;
$saleSnapshot['card_category_name'] = '原销售分类';
foreach ([$wrongMember, $wrongOrder, $saleSnapshot] as $invalid) {
    if ($method->invoke($reader, [$invalid]) !== []) {
        throw new RuntimeException('unverified card link or sale snapshot used current platform category');
    }
}
echo "PASS old-card refund current platform category: {$expected}\n";

// Exercise the complete list query with an old card that has no purchase receipt.
$target = (array)Db::name('cashier_v3_order_lifecycle_operation')->alias('r')
    ->join('cashier_v3_refund_adjustment a', 'a.operation_id=r.operation_id AND a.tenant_id=r.tenant_id')
    ->join('user_card_holder h', 'h.id=a.card_holder_id AND h.uid=r.member_id AND h.oid=r.source_order_id')
    ->join('store_product p', 'p.id=h.product_id AND p.is_del=0')
    ->leftJoin('cashier_v3_card_purchase_receipt cpr', "cpr.tenant_id=r.tenant_id AND cpr.card_holder_id=a.card_holder_id AND cpr.legacy_order_id=r.source_order_id AND cpr.status='completed'")
    ->where('r.source_type', 'card')->where('r.status', 'succeeded')->whereNull('cpr.id')
    ->whereRaw("p.cate_id REGEXP '^[1-9][0-9]*$'")
    ->field('r.operation_id,r.operation_no,r.tenant_id,r.store_id,p.cate_id')->order('r.id desc')->find();
if ($target !== []) {
    $targetCategoryId = (int)$target['cate_id'];
    $targetPath = $categoryPath($targetCategoryId);
    if ($targetPath === '') throw new RuntimeException('reported refund has no valid current platform category');
    $read = new ReflectionMethod(CashierV3OrderCenterRecordQueryServices::class, 'readRefund');
    if (PHP_VERSION_ID < 80100) $read->setAccessible(true);
    [$records, $total] = $read->invoke($reader, [
        'tenantId' => (string)$target['tenant_id'], 'allowedStoreIds' => [(int)$target['store_id']],
        'status' => 'completed', 'dataScope' => 'normal', 'businessDateFrom' => '',
        'businessDateTo' => '', 'keyword' => (string)$target['operation_no'],
        'page' => 1, 'pageSize' => 20,
    ]);
    $matches = array_values(array_filter($records, static function (array $record) use ($target): bool {
        return (string)$record['id'] === 'refund:' . (string)$target['operation_id'];
    }));
    if ($total < 1 || count($matches) !== 1 || (string)$matches[0]['productCategory'] !== $targetPath) {
        throw new RuntimeException('old-card refund list category mismatch: ' . json_encode($matches, JSON_UNESCAPED_UNICODE));
    }
    echo "PASS {$target['operation_no']} order-center current category: {$targetPath}\n";
}
