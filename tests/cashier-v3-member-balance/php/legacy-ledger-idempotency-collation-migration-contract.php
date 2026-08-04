<?php
declare(strict_types=1);

$dir = getenv('CHECKOUT_BALANCE_LEGACY_KEY_MIGRATION_DIR')
    ?: '/workspace/后端代码/database/upgrades/2026-07-31-收银V3余额流水幂等键字符集';
$files = ['00-升级清单.md','01-升级前检查.sql','02-正式升级.sql','03-升级后验证.sql','04-回滚或应急说明.md','SHA256SUMS.txt'];
$failed = 0;
foreach ($files as $file) {
    if (!is_file($dir . '/' . $file)) {
        ++$failed;
        echo "FAIL missing {$file}\n";
    } else {
        echo "PASS exists {$file}\n";
    }
}
$pre = (string)file_get_contents($dir . '/01-升级前检查.sql');
$apply = (string)file_get_contents($dir . '/02-正式升级.sql');
$post = (string)file_get_contents($dir . '/03-升级后验证.sql');
$ok = strpos($pre, "REGEXP '[^ -~]'") !== false
    && strpos($pre, 'duplicate_key_count') !== false
    && strpos($apply, 'CHARACTER SET ascii COLLATE ascii_bin') !== false
    && strpos($apply, 'ALTER TABLE `eb_user_money` MODIFY COLUMN `idempotency_key`') !== false
    && strpos($post, 'POSTCHECK_OK') !== false;
echo ($ok ? 'PASS' : 'FAIL') . " legacy key migration preserves values and requires binary ASCII identity\n";
exit($failed === 0 && $ok ? 0 : 1);
