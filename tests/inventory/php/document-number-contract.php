<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$numberService = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryBusinessDocumentNumberServices.php');
$inbound = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryManualInboundServices.php');
$outbound = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryManualOutboundServices.php');
$count = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryStockCountServices.php');
$request = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryStockRequestServices.php');
$usage = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/InventorySalonUsageServices.php');
$transfer = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryCrossSubjectTransferServices.php');
$readModel = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryStoreReadModelServices.php');
$operational = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/query/InventoryOperationalUnifiedQueryProvider.php');
$upgrade = (string)file_get_contents($root . '/后端代码/database/upgrades/2026-08-03-库存V3业务单号统一/02-正式升级.sql');
$precheck = (string)file_get_contents($root . '/后端代码/database/upgrades/2026-08-03-库存V3业务单号统一/01-升级前检查.sql');
$postcheck = (string)file_get_contents($root . '/后端代码/database/upgrades/2026-08-03-库存V3业务单号统一/03-升级后验证.sql');
$failed = 0;

function documentNumberAssert(string $name, bool $condition): void
{
    global $failed;
    echo ($condition ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$condition) $failed++;
}

documentNumberAssert('number service uses type-prefixed YYMMDD four-digit daily sequence',
    strpos($numberService, "self::INBOUND => 'RK'") !== false
    && strpos($numberService, "self::OUTBOUND => 'CK'") !== false
    && strpos($numberService, "self::COUNT => 'PD'") !== false
    && strpos($numberService, "self::REQUEST => 'QH'") !== false
    && strpos($numberService, "self::TRANSFER => 'DB'") !== false
    && strpos($numberService, "self::SALON_ISSUE => 'YZLY'") !== false
    && strpos($numberService, "self::SALON_RETURN => 'YZTH'") !== false
    && strpos($numberService, "\$date->format('ymd')") !== false
    && strpos($numberService, "str_pad((string)\$sequence, 4") !== false);
documentNumberAssert('daily allocation is database-atomic and rejects overflow',
    strpos($numberService, 'ON DUPLICATE KEY UPDATE') !== false
    && strpos($numberService, 'LAST_INSERT_ID(`current_value`+1)') !== false
    && strpos($numberService, 'lock(true)') !== false
    && strpos($numberService, 'inventory_document_number_daily_limit_reached') !== false);
documentNumberAssert('manual in and out keep technical idempotency but map only new documents',
    strpos($numberService, 'inventory_business_document_no') !== false
    && strpos($numberService, 'Never backfill or mutate pre-existing facts') !== false
    && strpos($inbound, "'manual_inbound'") !== false
    && strpos($outbound, "'manual_outbound'") !== false
    && strpos($inbound, "'document_no' => \$command['documentNo']") !== false
    && strpos($outbound, "'document_no' => \$command['documentNo']") !== false);
documentNumberAssert('all new document authorities allocate the visible business number',
    strpos($count, 'InventoryBusinessDocumentNumberServices::COUNT') !== false
    && strpos($request, 'InventoryBusinessDocumentNumberServices::REQUEST') !== false
    && strpos($usage, 'InventoryBusinessDocumentNumberServices::SALON_ISSUE') !== false
    && strpos($usage, 'InventoryBusinessDocumentNumberServices::SALON_RETURN') !== false
    && strpos($transfer, 'InventoryBusinessDocumentNumberServices::TRANSFER') !== false);
documentNumberAssert('new manual numbers are projected while historic fact source ids remain untouched',
    strpos($readModel, 'COALESCE(n.document_no,f.source_id)') !== false
    && strpos(preg_replace('/\s+/', '', $operational), 'COALESCE(n.document_no,f.source_id)') !== false
    && strpos($upgrade, 'CREATE TABLE IF NOT EXISTS `eb_inventory_document_sequence`') !== false
    && strpos($upgrade, 'CREATE TABLE IF NOT EXISTS `eb_inventory_business_document_no`') !== false
    && strpos($upgrade, 'historic data backfill') !== false);
documentNumberAssert('migration fails closed and verifies both new tables',
    strpos($precheck, '20260803-001-inventory-v3-business-document-numbers') !== false
    && strpos($precheck, '@upgrade_key_registered=0') !== false
    && strpos($postcheck, '@sequence_table_ok=1') !== false
    && strpos($postcheck, '@mapping_table_ok=1') !== false);

echo "DOCUMENT_NUMBER_CONTRACT_RESULT failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
