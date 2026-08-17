<?php
declare(strict_types=1);

$root = is_dir('/var/www/html/app') ? '/var/www/html' : dirname(__DIR__, 3) . '/后端代码';
$service = file_get_contents($root . '/app/services/cashier/v3/presale/CashierV3PresaleClaimServices.php');
$gift = file_get_contents($root . '/app/services/cashier/v3/member/CashierV3DirectGiftIssuanceServices.php');
$projection = file_get_contents($root . '/app/services/cashier/v3/member/CashierV3RechargeGiftIssuanceServices.php');
$number = file_get_contents($root . '/app/services/product/inventory/InventoryBusinessDocumentNumberServices.php');
$migration = file_get_contents($root . '/database/upgrades/2026-08-18-收银V3赠送产品领用出库/02-正式升级.sql');
$frontend = file_get_contents(dirname($root) . '/前端代码/cashier-v3/src/views/PresaleClaimView.vue');
$failed = 0;
$check = static function (bool $ok, string $label) use (&$failed): void {
    if (!$ok) { $failed++; fwrite(STDERR, "FAIL: {$label}\n"); return; }
    echo "PASS: {$label}\n";
};

$check(strpos($gift, 'registerIssuedGiftProductInTx') !== false && strpos($gift, "if (\$item['kind'] === 'product')") !== false, '产品赠送签发时注册待领用事实');
$check(strpos($projection, 'Direct product gifts are an issuance record') !== false && strpos($projection, "'legacyOrderId' => 0") !== false, '产品赠送不创建零金额兼容销售订单');
$check(strpos($service, "private const SOURCE_GIFT = 'GIFT'") !== false && strpos($service, 'gift_product_claim_outbound') !== false, '赠送领用复用预售事务并生成独立出库来源');
$check(strpos($service, "where('source_kind', \$sourceKind)") !== false && strpos($service, "source_kind' => self::SOURCE_GIFT") !== false, '领用列表按来源类型隔离且支持赠送');
$check(strpos($service, 'CashierV3TransactionGuard::assertInTransaction') !== false && strpos($service, 'lockStock') !== false && strpos($service, 'decreaseBalances') !== false, '库存只在领用事务内加锁和扣减');
$check(strpos($service, "gift_product_claim_void") !== false && strpos($service, 'reversalOf') !== false, '赠送领用作废写库存反向事实');
$check(strpos($number, "GIFT_PRODUCT_CLAIM = 'GIFT_PRODUCT_CLAIM'") !== false && strpos($number, "GIFT_PRODUCT_CLAIM => 'ZPLY'") !== false, '赠送产品出库单使用独立单号前缀');
$check(strpos($migration, 'source_kind') !== false && strpos($migration, 'gift_item_id') !== false && strpos($migration, 'idx_scope_source_status_date') !== false, '迁移增加来源字段和查询索引');
$check(strpos($frontend, 'const sourceKind = ref(\'PRESALE\')') !== false && strpos($frontend, 'switchSource') !== false && strpos($frontend, 'source_kind: sourceKind.value') !== false, '前端默认预售并支持预售/赠送滑块筛选');
$check(strpos($frontend, '赠送产品出库单') !== false && strpos($frontend, 'detail.claimable') !== false, '赠送页面显示独立出库单和明细');
echo "GIFT_PRODUCT_PRESALE_CLAIM_CONTRACT_FAILED={$failed}\n";
exit($failed === 0 ? 0 : 1);
