<?php

$root = dirname(__DIR__, 3);
$providerPath = $root
    . '/后端代码/app/services/cashier/v3/checkout/provider/CashierV3PerformanceRuleProvider.php';
$storeProductPath = $root
    . '/后端代码/app/services/product/product/StoreProductServices.php';
$selectorPath = $root
    . '/后端代码/app/services/cashier/v3/member/CashierV3QueryEntitySelectorServices.php';
$workspacePath = $root
    . '/后端代码/app/services/cashier/v3/cashier/CashierV3CashierWorkspaceServices.php';
$migrationDir = $root
    . '/后端代码/database/upgrades/2026-07-29-收银V3项目业绩规则权威';

$passed = 0;
$failed = 0;
function performanceRuleOk(string $name, bool $condition): void
{
    global $passed, $failed;
    $condition ? $passed++ : $failed++;
    echo ($condition ? 'PASS ' : 'FAIL ') . $name . "\n";
}

function performanceRuleRead(string $path): string
{
    $contents = file_get_contents($path);
    if (!is_string($contents)) {
        throw new RuntimeException('cannot read ' . $path);
    }
    return $contents;
}

$provider = performanceRuleRead($providerPath);
$storeProduct = performanceRuleRead($storeProductPath);
$selector = performanceRuleRead($selectorPath);
$workspace = performanceRuleRead($workspacePath);
$precheck = performanceRuleRead($migrationDir . '/01-升级前检查.sql');
$apply = performanceRuleRead($migrationDir . '/02-正式升级.sql');
$postcheck = performanceRuleRead($migrationDir . '/03-升级后验证.sql');
$recovery = performanceRuleRead($migrationDir . '/05-部分创表恢复.sql');

performanceRuleOk('provider is a data-scoped positive-version authority',
    strpos($provider, 'implements CashierV3DataScopedVersionProvider') !== false
        && strpos($provider, "cashier-v3-project-performance-rule-v1") !== false
        && strpos($provider, "public const KIND = 'performance_rule'") !== false);

performanceRuleOk('readiness covers both the project authority and rule schema',
    strpos($provider, "'eb_store_product' =>") !== false
        && strpos($provider, "'id', 'type', 'relation_id', 'product_type', 'is_del'") !== false
        && strpos($provider, "'eb_' . self::TABLE") !== false);

performanceRuleOk('every discovery lock and snapshot path rechecks authorized project scope',
    substr_count($provider, '$this->assertAuthorizedProject(') === 4
        && strpos($provider, 'CashierV3EntitlementProviderDataScope::assertStore(') !== false
        && strpos($provider, 'CashierV3EntitlementProviderDataScope::assertTenant(') !== false);

performanceRuleOk('default creation uses the configured table prefix and only tolerates its unique-key race',
    strpos($provider, 'Db::name(self::TABLE)->insert([') !== false
        && strpos($provider, "'uk_tenant_project'") !== false
        && strpos($provider, 'INSERT IGNORE') === false
        && strpos($provider, '`eb_cashier_v3_project_performance_rule`') === false);

performanceRuleOk('checkout cannot mutate a read-only configuration version',
    strpos($provider, "throw self::failure('performance_rule_read_only'") !== false
        && strpos($provider, "Db::raw('current_version + 1')") === false);

performanceRuleOk('store copies project manual fee from their master product rule',
    strpos($storeProduct, '(int)($item[\'pid\'] ?? 0)') !== false
        && strpos($storeProduct, '$ruleProjectId') !== false
        && strpos($storeProduct, 'intdiv((int)($laborFeeByProject[$ruleProjectId] ?? 0), 100)') !== false);

performanceRuleOk('cashier selector and workspace resolve a missing store-copy rule through pid',
    strpos($selector, "->where('type', 1)") !== false
        && strpos($selector, "->where('product_type', 6)") !== false
        && strpos($selector, '->find()') !== false
        && strpos($workspace, "->where('type', 1)") !== false
        && strpos($workspace, "->where('product_type', 6)") !== false
        && strpos($workspace, 'array_key_exists(\'labor_configured_unit_amount_cents\', $masterRule)') !== false);

performanceRuleOk('provider validates supported modes and the kernel money ceiling',
    strpos($provider, 'private const MAX_MONEY_CENTS = 100000000000;') !== false
        && strpos($provider, 'CashierV3EntitlementCompletionKernel::PERFORMANCE_ACTUAL') !== false
        && strpos($provider, 'CashierV3EntitlementCompletionKernel::PERFORMANCE_CONFIGURED') !== false
        && strpos($provider, 'databaseUnsignedInt(') !== false
        && strpos($provider, '$value < 0 || $value > $maximum') !== false);

performanceRuleOk('apply SQL is MySQL 5.6 compatible and owns the exact rule identity',
    strpos($apply, 'CREATE TABLE IF NOT EXISTS `eb_cashier_v3_project_performance_rule`') !== false
        && strpos($apply, 'UNIQUE KEY `uk_tenant_project` (`tenant_id`,`project_id`)') !== false
        && strpos($apply, 'KEY `idx_tenant_updated` (`tenant_id`,`updated_at`,`id`)') !== false
        && stripos($apply, ' CHECK ') === false);

performanceRuleOk('precheck fails closed on registration schema indexes and unregistered data',
    strpos($precheck, 'PRECHECK_OK') !== false
        && strpos($precheck, 'STOP_CASHIER_V3_PROJECT_PERFORMANCE_RULE_PRECHECK_FAILED') !== false
        && strpos($precheck, '@pr_exact_column_count=10') !== false
        && strpos($precheck, '@pr_exact_index_count=3') !== false
        && strpos($precheck, '@pr_registered=0 AND @pr_row_count<>0') !== false);

performanceRuleOk('postcheck validates exact structure indexes and authoritative row invariants',
    strpos($postcheck, 'POSTCHECK_OK') !== false
        && strpos($postcheck, 'STOP_CASHIER_V3_PROJECT_PERFORMANCE_RULE_POSTCHECK_FAILED') !== false
        && strpos($postcheck, "consumption_mode NOT IN ('actual_entitlement_amount','project_configured_amount')") !== false
        && strpos($postcheck, 'consumption_configured_unit_amount_cents>100000000000') !== false
        && strpos($postcheck, '@pr_bad_rows=0') !== false);

performanceRuleOk('recovery is read-only and only admits missing or exact empty unregistered state',
    strpos($recovery, 'RECOVERY_READY_RUN_CANONICAL_APPLY') !== false
        && strpos($recovery, 'STOP_CASHIER_V3_PROJECT_PERFORMANCE_RULE_RECOVERY_FAILED') !== false
        && strpos($recovery, '@pr_registered=0') !== false
        && strpos($recovery, '@pr_row_count=0') !== false
        && stripos($recovery, 'DROP TABLE') === false
        && stripos($recovery, 'TRUNCATE') === false);

$checksumLines = file($migrationDir . '/SHA256SUMS.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$checksumValid = is_array($checksumLines) && count($checksumLines) === 6;
$seen = [];
foreach ((array)$checksumLines as $line) {
    if (preg_match('/^([a-f0-9]{64})  ([^\/]+)$/D', $line, $matches) !== 1) {
        $checksumValid = false;
        continue;
    }
    $path = $migrationDir . '/' . $matches[2];
    $checksumValid = $checksumValid
        && is_file($path)
        && hash_equals($matches[1], hash_file('sha256', $path));
    $seen[$matches[2]] = true;
}
performanceRuleOk('migration checksum manifest covers every executable and instruction file',
    $checksumValid
        && count($seen) === 6
        && !isset($seen['SHA256SUMS.txt']));

echo "performance rule contract: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
