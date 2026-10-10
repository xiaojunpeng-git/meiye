<?php
declare(strict_types=1);

define('RH_CONSUME_DEBT_BATCH_FUNCTIONS_ONLY', true);
require __DIR__.'/../../../后端代码/database/upgrades/2026-10-10-瑞昊历史消费欠款商品关联/03-批量修复.php';

$assert = static function (bool $valid, string $message): void {
    if (!$valid) throw new RuntimeException($message);
};
$rejects = static function (callable $action) use ($assert): void {
    try { $action(); } catch (RuntimeException $error) { return; }
    $assert(false, 'invalid source or mapping was accepted');
};

$assert(batchCents('1010.00') === 101000, 'whole-money cents');
$assert(batchCents('2.5') === 250, 'fractional cents');
$rejects(static fn() => batchCents('2.555'));
$rejects(static fn() => batchProvenance('source_db=other;source_kind=CONSUME;source_key=bad'));

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$db->exec('CREATE TABLE mig_rh_source_map (source_table TEXT,source_pk TEXT,target_type TEXT,target_id INTEGER)');
$db->exec('CREATE TABLE eb_store_product (id INTEGER,pid INTEGER,type INTEGER,relation_id INTEGER,product_type INTEGER,is_del INTEGER,cate_id TEXT,keyword TEXT)');
$db->exec('CREATE TABLE eb_store_product_category (id INTEGER,pid INTEGER,type INTEGER,relation_id INTEGER,cate_name TEXT)');
$db->exec("INSERT INTO mig_rh_source_map VALUES ('bd_goods','0091001456','store_product',78739)");
$db->exec("INSERT INTO eb_store_product VALUES (78739,0,0,0,0,0,'26321','root')");
$db->exec("INSERT INTO eb_store_product VALUES (462363,78739,1,117,0,0,'','RH_GOODS:0091001456:STORE:117')");
$db->exec("INSERT INTO eb_store_product_category VALUES (26320,0,0,0,'花园')");
$db->exec("INSERT INTO eb_store_product_category VALUES (26321,26320,0,0,'花园产品')");
$product = batchMappedProduct($db, 'jsj', 'bd_goods', '0091001456', 117);
$category = batchCategory($db, $product);
$assert((int)$product['id'] === 462363 && $category === ['id'=>26321,'path'=>'花园/花园产品'],
    'salary-report parent category inheritance');
$db->exec("UPDATE eb_store_product SET cate_id='26320,26321' WHERE id=462363");
$product = batchMappedProduct($db, 'jsj', 'bd_goods', '0091001456', 117);
$assert(batchCategory($db, $product)['id'] === 26321, 'child chooses deepest current category');
$rejects(static fn() => batchMappedProduct($db, 'jsj', 'bd_goods', '0091001456', 118));

$source = ['ShopId'=>'S1','CustomerId'=>'C1','CardDetailId'=>'CARD1','DebtDetail'=>'花园SPA生态平衡调理',
    'SaleDate'=>'2026-10-08 09:00:00','identity_count'=>1,'source_table'=>'bd_goods',
    'source_item_id'=>'0091001456','source_amount'=>'1010.00','target_amount'=>'1010'];
$key = batchSourceKey('jsj', $source);
$provenance = 'source_db=jsj;source_kind=CONSUME;source_key='.$key
    .';source_customer=C1;source_shop=S1;source_amount=1010.00';
$item = ['debt_item_id'=>325335,'debt_id'=>325331,'debt_no'=>'JCD'.substr($key,0,29),
    'uid'=>100,'authority_member_id'=>100,'store_id'=>117,'authority_store_id'=>117,
    'item_count'=>1,'product_id'=>0,'product_type'=>0,'cart_info_id'=>0,
    'product_name'=>'花园SPA生态平衡调理','debt_amount'=>'1010.00','remark'=>$provenance,
    'add_time'=>(new DateTimeImmutable('2026-10-08 09:00:00',new DateTimeZone('Asia/Shanghai')))->getTimestamp()];
$resolved = batchResolve($db,$item,['jsj'=>[$key=>$source]]);
$assert($resolved['target_product_id']===462363 && $resolved['category_id']===26321
    && $resolved['amount_cents']===101000,'unique original product resolution');
$bad = $source; $bad['identity_count'] = 2;
$rejects(static fn() => batchResolve($db,$item,['jsj'=>[$key=>$bad]]));
$wrongStore = $item; $wrongStore['store_id'] = 118; $wrongStore['authority_store_id'] = 118;
$rejects(static fn() => batchResolve($db,$wrongStore,['jsj'=>[$key=>$source]]));
$db->exec("UPDATE eb_store_product SET is_del=1 WHERE id=462363");
$rejects(static fn() => batchMappedProduct($db,'jsj','bd_goods','0091001456',117));
echo "R39 consumption-debt batch product repair contract passed\n";
