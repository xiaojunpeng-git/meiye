<?php
declare(strict_types=1);

define('RH_CONSUME_DEBT_REPAIR_FUNCTIONS_ONLY', true);
require __DIR__.'/../../../后端代码/database/upgrades/2026-10-10-瑞昊历史消费欠款商品关联/01-修复.php';

/** The repair must fail closed when provenance, store or source mapping is ambiguous. */
function expectFailure(callable $operation, string $case): void
{
    try {
        $operation();
    } catch (RuntimeException $error) {
        return;
    }
    throw new RuntimeException($case.' unexpectedly passed');
}

if (cents('1010.00') !== 101000 || cents('0.5') !== 50) {
    throw new RuntimeException('decimal amount guard changed');
}
expectFailure(static function (): void { cents('-1.00'); }, 'negative amount');
$group = [
    'ShopId' => '720091000165', 'CustomerId' => 'customer-fixture',
    'CardDetailId' => '', 'DebtDetail' => 'fixture product', 'SaleDate' => '2026-09-09 00:00:00',
];
$expected = hash('sha256', '["jsj","720091000165","customer-fixture","","fixture product","2026-09-09 00:00:00"]');
if (sourceKey('jsj', $group) !== $expected) throw new RuntimeException('import source key changed');
$remark = sourceRemark('source_db=jsj;source_kind=CONSUME;source_key='.$expected
    .';source_customer=customer-fixture;source_shop=720091000165;source_amount=1010.00');
if ($remark['source_key'] !== $expected) throw new RuntimeException('provenance parser changed');
expectFailure(static function () use ($expected): void {
    sourceRemark('source_db=other;source_kind=CONSUME;source_key='.$expected
        .';source_customer=customer-fixture;source_shop=720091000165;source_amount=1010.00');
}, 'foreign source');

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE mig_rh_source_map (source_table TEXT,source_pk TEXT,target_type TEXT,target_id INTEGER)');
$db->exec('CREATE TABLE eb_store_product (id INTEGER,pid INTEGER,type INTEGER,relation_id INTEGER,product_type INTEGER,is_del INTEGER,cate_id TEXT,keyword TEXT)');
$db->exec('CREATE TABLE eb_store_product_category (id INTEGER,pid INTEGER,cate_name TEXT,type INTEGER,relation_id INTEGER)');
$db->exec("INSERT INTO mig_rh_source_map VALUES ('bd_goods','0091001456','store_product',78739)");
$db->exec("INSERT INTO eb_store_product VALUES (78739,0,0,0,0,0,'26321','')");
$db->exec("INSERT INTO eb_store_product VALUES (462363,78739,1,117,0,0,'26321','RH_GOODS:0091001456:STORE:117')");
$db->exec("INSERT INTO eb_store_product_category VALUES (26300,0,'花园',0,0)");
$db->exec("INSERT INTO eb_store_product_category VALUES (26321,26300,'花园产品',0,0)");
$product = mappedProduct($db, 'jsj', 'bd_goods', '0091001456', 117);
$category = currentCategory($db, $product);
if ((int)$product['id'] !== 462363 || $category !== ['id' => 26321, 'path' => '花园 / 花园产品']) {
    throw new RuntimeException('source-to-store category resolution changed');
}
expectFailure(static function () use ($db): void {
    mappedProduct($db, 'jsj', 'bd_goods', '0091001456', 118);
}, 'cross-store mapping');
$db->exec("INSERT INTO eb_store_product VALUES (462364,78739,1,117,0,0,'26321','RH_GOODS:0091001456:STORE:117')");
expectFailure(static function () use ($db): void {
    mappedProduct($db, 'jsj', 'bd_goods', '0091001456', 117);
}, 'ambiguous store mapping');

echo "R39 consumption-debt product repair contract passed\n";
