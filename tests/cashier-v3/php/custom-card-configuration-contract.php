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
$provider = file_get_contents($root . '/app/services/cashier/v3/card/CashierV3CustomCardConfigurationVersionProvider.php');
$catalog = file_get_contents($root . '/app/services/cashier/v3/cashier/CashierV3SaleCatalogServices.php');
$workspace = file_get_contents($root . '/app/services/cashier/v3/cashier/CashierV3CashierWorkspaceServices.php');
$module = file_get_contents($root . '/app/services/cashier/v3/cashier/CashierV3CashierModule.php');
$kindCatalog = file_get_contents($root . '/app/services/cashier/v3/CashierV3ResourceKindCatalog.php');
$backendManifest = file_get_contents($root . '/app/services/cashier/v3/manifest/CashierV3C2CashierModule.php');
$frontendRoot = getenv('CASHIER_V3_FRONTEND_ROOT');
$frontendRoot = is_string($frontendRoot) && $frontendRoot !== ''
    ? rtrim($frontendRoot, '/')
    : dirname($root) . '/前端代码/cashier-v3';
$frontendManifest = file_get_contents($frontendRoot . '/src/services/cashierV3ActionManifest.js');
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
customCardCheck('CCC-04 only project components and integer-cent total authority are accepted',
    strpos($configuration, "'custom_card_component_not_project'") !== false
    && strpos($configuration, "'configuredAmountCents'") !== false
    && strpos($catalog, "'totalAmountCents'") !== false
    && strpos($configuration, 'moneyToCents(') !== false);
customCardCheck('CCC-05 custom card cannot be mixed with normal sale lines',
    strpos($configuration, "'custom_card_cart_conflict'") !== false
    && strpos($workspace, "'custom_card'") !== false);
customCardCheck('CCC-06 configuration resource is store-scoped and requires an in-cart version lock',
    strpos($kindCatalog, "'custom_card_configuration'") !== false
    && strpos($provider, "->where('status', 'in_cart')") !== false
    && strpos($provider, "->where('store_id', \$storeId)") !== false);
customCardCheck('CCC-07 checkout locks the configuration in both preparation phases',
    substr_count($module, "'checkout_custom_card_configuration'") === 2
    && substr_count($module, "'custom_card_configuration'") >= 2);
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

echo "CUSTOM_CARD_CONFIGURATION_CONTRACT passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
