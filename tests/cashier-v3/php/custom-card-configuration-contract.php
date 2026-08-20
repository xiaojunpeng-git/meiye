<?php
/** Custom-card configuration source and migration contract; no database writes. */

$root = getenv('CASHIER_V3_BACKEND_ROOT');
$root = is_string($root) && $root !== '' ? rtrim($root, '/') : __DIR__ . '/../../../后端代码';

$passed = 0;
$failed = 0;
function customCardCheck(string $name, bool $condition): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    echo "FAIL {$name}\n";
}

$configuration = file_get_contents($root . '/app/services/cashier/v3/card/CashierV3CustomCardConfigurationServices.php');
$issuer = file_get_contents($root . '/app/services/cashier/v3/card/CashierV3CardPurchaseIssuanceServices.php');
$provider = file_get_contents($root . '/app/services/cashier/v3/card/CashierV3CustomCardConfigurationVersionProvider.php');
$catalog = file_get_contents($root . '/app/services/cashier/v3/cashier/CashierV3SaleCatalogServices.php');
$workspace = file_get_contents($root . '/app/services/cashier/v3/cashier/CashierV3CashierWorkspaceServices.php');
$module = file_get_contents($root . '/app/services/cashier/v3/cashier/CashierV3CashierModule.php');
$preparation = file_get_contents($root . '/app/services/cashier/v3/settlement/CashierV3CheckoutPreparationServices.php');
$kindCatalog = file_get_contents($root . '/app/services/cashier/v3/CashierV3ResourceKindCatalog.php');
$backendManifest = file_get_contents($root . '/app/services/cashier/v3/manifest/CashierV3C2CashierModule.php');
$frontendRoot = getenv('CASHIER_V3_FRONTEND_ROOT');
$frontendRoot = is_string($frontendRoot) && $frontendRoot !== ''
    ? rtrim($frontendRoot, '/')
    : dirname($root) . '/前端代码/cashier-v3';
$frontendManifest = file_get_contents($frontendRoot . '/src/services/cashierV3ActionManifest.js');
$workbench = file_get_contents($frontendRoot . '/src/views/CashierWorkbenchView.vue');
$migrationDir = $root . '/database/upgrades/2026-07-31-收银V3定制卡配置与签发';
$migration = file_get_contents($migrationDir . '/02-正式升级.sql');
$postcheck = file_get_contents($migrationDir . '/03-升级后验证.sql');
$manifest = file_get_contents($migrationDir . '/00-升级清单.md');

customCardCheck('CCC-01 action is registered by both client and server manifests',
    strpos($backendManifest, "'create-custom-card-configuration'") !== false
    && strpos($frontendManifest, "'create-custom-card-configuration'") !== false
    && strpos($module, "registerCommand('create-custom-card-configuration'") !== false);
customCardCheck('CCC-02 creation locks server-discovered card shell and project SKU resources',
    strpos($configuration, 'discoverCreateResources(') !== false
    && strpos($configuration, 'discoverCustomCardShellResources(') !== false
    && strpos($configuration, 'discoverItemResources(') !== false
    && strpos($module, 'registerCreateCustomCardConfigurationPolicy') !== false);
customCardCheck('CCC-03 configuration is immutable, idempotent, and member/workspace-bound',
    strpos($configuration, "'immutable_fingerprint'") !== false
    && strpos($configuration, "'created_command_idempotency_key'") !== false
    && strpos($configuration, "'workspace_line_key'") !== false
    && strpos($migration, 'UNIQUE KEY `uk_tenant_command`') !== false
    && strpos($migration, 'UNIQUE KEY `uk_tenant_workspace_line`') !== false);
customCardCheck('CCC-04 only project components and whole-yuan total authority are accepted',
    strpos($configuration, "'custom_card_component_not_project'") !== false
    && strpos($configuration, "'configuredAmountCents'") !== false
    && strpos($configuration, "'项目金额必须填写整数元。'") !== false
    && strpos($catalog, "'totalAmountCents'") !== false
    && strpos($configuration, 'moneyToCents(') !== false);
customCardCheck('CCC-05 custom card cannot be mixed with normal sale lines',
    strpos($configuration, "'custom_card_cart_conflict'") !== false
    && strpos($workspace, "'custom_card'") !== false);
customCardCheck('CCC-06 configuration resource is store-scoped and requires an in-cart version lock',
    strpos($kindCatalog, "'custom_card_configuration'") !== false
    && strpos($provider, "->where('status', 'in_cart')") !== false
    && strpos($provider, "->where('store_id', \$storeId)") !== false);
customCardCheck('CCC-07 final browser snapshot creates and locks the configuration with its member identity',
    strpos($module, "'checkout_custom_card_configuration'") !== false
    && strpos($module, "'custom_card_configuration'") !== false
    && strpos($preparation, 'createSaleLineAfterGatewayLocksInTx(') !== false
    && strpos($preparation, "(int)(\$snapshot['memberId'] ?? 0),") !== false
    && strpos($preparation, "                        true\n                    );") !== false
    && strpos($preparation, "                        true\n                    ) as \$resource") !== false
    && strpos($configuration, 'bool $directSnapshot = false') !== false
    && strpos($configuration, 'if ($directSnapshot) {') !== false);
customCardCheck('CCC-08 custom validity snapshot is clock-stable before settlement',
    strpos($catalog, "'writeStart' => 0, 'writeEnd' => \$end") !== false
    && strpos($catalog, "'writeStart' => time()") === false);
customCardCheck('CCC-09 canonical migration provides all fields and postcheck counts index names',
    strpos($migration, 'CREATE TABLE IF NOT EXISTS `eb_cashier_v3_custom_card_configuration`') !== false
    && strpos($migration, '`resource_version`') !== false
    && strpos($migration, '`configuration_snapshot_json`') !== false
    && strpos($postcheck, 'GROUP BY INDEX_NAME') !== false
    && strpos($manifest, '20260731-005-cashier-v3-custom-card-configuration') !== false);
customCardCheck('CCC-10 legacy hidden custom-card shell is recognized before ordinary project handling',
    strpos($catalog, 'if ($productId === self::CUSTOM_CARD_SHELL_PRODUCT_ID || $pid === self::CUSTOM_CARD_SHELL_PRODUCT_ID)') !== false
    && strpos($catalog, "if (\$normalized['kindCode'] === 'custom_card') {") !== false
    && strpos($provider, 'customCardConfigurationVersion') !== false);
customCardCheck('CCC-11 component cost is frozen and initial configured total respects the cost floor',
    strpos($configuration, "'configuredCostCents' => \$configuredCostCents") !== false
    && strpos($configuration, "'custom_card_total_below_cost'") !== false
    && strpos($configuration, 'roundUpToWholeYuan($configuredCostTotalCents)') !== false
    && strpos($catalog, "'custom_card_component_cost_overflow'") !== false);
customCardCheck('CCC-12 direct snapshots never validate the hidden custom-card shell as a sellable SKU',
    strpos($catalog, 'readCustomCardShell($operatorScope, $dataScope, !$directSnapshot)') !== false
    && strpos($catalog, 'readCustomCardShell($operatorScope, $dataScope, false);') !== false);
customCardCheck('CCC-13 direct snapshots bind custom configuration to the browser line identity',
    strpos($configuration, 'string $snapshotLineId = \'\'') !== false
    && strpos($configuration, "? 'sale:' . substr(\$snapshotLineId, 0, 59)") !== false
    && strpos($preparation, "(string)(\$line['lineId'] ?? 'snapshot-' . \$index)") !== false);
customCardCheck('CCC-14 final issue falls back to the exact snapshot command idempotency key',
    strpos($issuer, 'created_command_idempotency_key') !== false
    && strpos($issuer, "\$commandIdempotencyKey . ':snapshot:0'") !== false);
customCardCheck('CCC-15 custom-card snapshot has cached components and cannot enter inventory settlement',
    strpos($workbench, 'function customCardPurchaseSnapshot(configuration = {})') !== false
    && strpos($workbench, 'cardPurchaseSnapshot: customCardPurchaseSnapshot(payload)') !== false
    && strpos($workbench, 'return isProductLine(line) && !isCustomCardPurchase(line)') !== false
    && strpos($preparation, "'isPresale' => \$customCard ? 0") !== false
    && strpos($preparation, "'inventoryOutboundRequired' => \$customCard") !== false);

echo "CUSTOM_CARD_CONFIGURATION_CONTRACT passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
