<?php
declare(strict_types=1);

// Read-only integration check: old unlinked refunds follow SKU -> product -> category IDs.
require '/var/www/html/vendor/autoload.php';

use app\services\cashier\v3\order\CashierV3OrderCenterRecordQueryServices;
use think\facade\Db;

$app = new \think\App('/var/www/html/');
$app->env->load('/var/www/html/.env');
$app->initialize();

$fixture = (array)Db::name('store_product_attr_value')->alias('sku')
    ->join('store_product p', 'p.id=sku.product_id AND p.type=1 AND p.is_del=0')
    ->join('store_product_category c', 'c.id=p.cate_id AND c.type=0 AND c.relation_id=0')
    ->where('sku.type', 0)->where('p.relation_id', '>', 0)
    ->whereIn('p.product_type', [0, 4, 5, 6])
    ->field('sku.id sku_id,p.id product_id,p.relation_id store_id,c.id category_id')
    ->order('sku.id desc')->find();
if ($fixture === []) {
    echo "SKIP no store catalog SKU with a platform category\n";
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
    'operation_id' => 'R24-READONLY-FIXTURE', 'source_type' => 'catalog',
    'store_id' => (int)$fixture['store_id'], 'catalog_item_id' => (int)$fixture['sku_id'],
    'request_json' => '{}', 'adjustment_target_name' => 'Display name is not a join key',
];
$reader = new CashierV3OrderCenterRecordQueryServices();
$method = new ReflectionMethod(CashierV3OrderCenterRecordQueryServices::class, 'platformCatalogCategoriesForRefunds');
if (PHP_VERSION_ID < 80100) $method->setAccessible(true);
$actual = $method->invoke($reader, [$row]);
if (($actual[$row['operation_id']] ?? '') !== $expected) {
    throw new RuntimeException('catalog SKU platform category mismatch: ' . json_encode($actual, JSON_UNESCAPED_UNICODE));
}

// Snapshot, store scope and exact SKU association must all prevent a false fallback.
$wrongStore = $row;
$wrongStore['store_id']++;
$wrongSku = $row;
$wrongSku['catalog_item_id'] = 0;
$savedSnapshot = $row;
$savedSnapshot['request_json'] = json_encode(['_productCategoryNameSnapshot' => '原退款分类'], JSON_UNESCAPED_UNICODE);
foreach ([$wrongStore, $wrongSku, $savedSnapshot] as $invalid) {
    if ($method->invoke($reader, [$invalid]) !== []) {
        throw new RuntimeException('unverified catalog category fallback was accepted');
    }
}
echo "PASS old-catalog refund current platform category: {$expected}\n";

// Exercise the same query used by order-center list and export when an old row exists.
$target = (array)Db::name('cashier_v3_order_lifecycle_operation')->alias('r')
    ->join('cashier_v3_refund_adjustment a', 'a.operation_id=r.operation_id AND a.tenant_id=r.tenant_id')
    ->join('store_product_attr_value sku', 'sku.id=a.catalog_item_id AND sku.type=0')
    ->join('store_product p', 'p.id=sku.product_id AND p.type=1 AND p.relation_id=r.store_id AND p.is_del=0')
    ->where('r.source_type', 'catalog')->where('r.status', 'succeeded')
    ->whereIn('p.product_type', [0, 4, 5, 6])
    ->where('r.request_json', 'not like', '%_productCategoryNameSnapshot%')
    ->whereRaw("p.cate_id REGEXP '^[1-9][0-9]*$'")
    ->field('r.operation_id,r.operation_no,r.tenant_id,r.store_id,r.request_json,p.cate_id')
    ->order('r.id desc')->find();
if ($target !== []) {
    $targetPath = $categoryPath((int)$target['cate_id']);
    if ($targetPath === '') throw new RuntimeException('target platform category hierarchy is incomplete');
    $read = new ReflectionMethod(CashierV3OrderCenterRecordQueryServices::class, 'readRefund');
    if (PHP_VERSION_ID < 80100) $read->setAccessible(true);
    [$records, $total] = $read->invoke($reader, [
        'tenantId' => (string)$target['tenant_id'], 'allowedStoreIds' => [(int)$target['store_id']],
        'status' => 'completed', 'dataScope' => 'normal', 'businessDateFrom' => '',
        'businessDateTo' => '', 'keyword' => (string)$target['operation_no'],
        'page' => 1, 'pageSize' => 20,
    ]);
    $matches = array_values(array_filter($records, static fn(array $record): bool =>
        (string)$record['id'] === 'refund:' . (string)$target['operation_id']));
    if ($total < 1 || count($matches) !== 1 || (string)$matches[0]['productCategory'] !== $targetPath) {
        throw new RuntimeException('old-catalog refund list category mismatch: ' . json_encode($matches, JSON_UNESCAPED_UNICODE));
    }
    echo "PASS {$target['operation_no']} order-center current category: {$targetPath}\n";
}
