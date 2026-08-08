<?php

$root = is_dir('/var/www/html/app') ? '/var/www/html' : dirname(__DIR__, 3) . '/后端代码';
$file = $root . '/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php';
$source = file_get_contents($file);
$failed = 0;

$check = static function (bool $condition, string $name) use (&$failed): void {
    if (!$condition) {
        $failed++;
        fwrite(STDERR, "FAIL: {$name}\n");
        return;
    }
    echo "PASS: {$name}\n";
};

$start = strpos($source, 'private function readV3DirectGifts');
$end = $start === false ? false : strpos($source, 'private function readLegacyGifts', $start);
$directGiftReader = $start === false ? '' : substr($source, $start, ($end === false ? strlen($source) : $end) - $start);

$check($directGiftReader !== '', '独立赠送记录读取器存在');
$check(strpos($directGiftReader, "->leftJoin('cashier_v3_direct_gift_item gi'") !== false,
    '独立赠送记录按赠送明细关联，而非只读取主赠送记录');
$check(strpos($directGiftReader, "'gi.content_snapshot_json AS item_content_snapshot_json'") !== false,
    '独立赠送记录读取每项不可变有效期快照');
$check(strpos($directGiftReader, "['validityEnd'] ?? \$snapshot['validity_end'] ?? 0") !== false,
    '独立赠送记录兼容有效期快照字段命名');
$check(strpos($directGiftReader, "'expiresAt' => \$validityEnd > 0 ? \$this->dateTime(\$validityEnd) : null") !== false,
    '独立赠送记录将每项有效期映射为到期时间');

echo "DIRECT_GIFT_ORDER_CENTER_EXPIRY_CONTRACT_FAILED={$failed}\n";
exit($failed === 0 ? 0 : 1);
