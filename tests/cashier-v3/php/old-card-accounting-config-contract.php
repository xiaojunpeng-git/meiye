<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$configPath = $root . '/后端代码/app/services/cashier/v3/config/CashierV3BusinessConfigServices.php';
$kernelPath = $root . '/后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutSettlementKernel.php';
$factPath = $root . '/后端代码/app/services/cashier/v3/fact/CashierV3CheckoutFactPlanV1.php';
$migrationPath = $root . '/后端代码/database/upgrades/2026-08-06-收银V3旧卡录入记账方式/02-正式升级.sql';

foreach ([$configPath, $kernelPath, $factPath, $migrationPath] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "FAIL missing file: {$path}\n");
        exit(1);
    }
}

$config = (string)file_get_contents($configPath);
$kernel = (string)file_get_contents($kernelPath);
$facts = (string)file_get_contents($factPath);
$migration = (string)file_get_contents($migrationPath);

assertContains("'old_card_entry' => '旧卡录入'", $config, '旧卡录入应属于可配置的稳定目录');
assertContains("->where('code', '<>', 'old_card_entry')", $config, '旧卡录入不能充当最后一种记账收款方式');
assertContains("('old_card_entry','旧卡录入','旧卡录入',1,80,1", $migration, '追加迁移应写入默认旧卡录入配置');
assertContains('ON DUPLICATE KEY UPDATE default_name=VALUES(default_name)', $migration, '迁移不得覆盖既有显示名称或状态');
assertContains('PAYMENT_OLD_CARD_ENTRY', $kernel, '结算内核应保留旧卡录入的独立方式代码');
assertContains("throw self::failure('old_card_entry_separate_flow_required'", $kernel, '旧卡录入不可进入正常结账收款');
assertContains("if (\$method === 'old_card_entry')", $facts, '旧卡录入不得生成收款事实');
assertContains("throw self::failure('old_card_entry_payment_fact_forbidden')", $facts, '旧卡录入不得进入业绩事实链路');

echo "PASS old-card accounting config contract\n";

function assertContains(string $needle, string $haystack, string $label): void
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, "FAIL {$label}: missing {$needle}\n");
        exit(1);
    }
}
