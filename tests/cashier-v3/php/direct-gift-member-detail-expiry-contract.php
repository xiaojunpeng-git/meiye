<?php

$root = is_dir('/var/www/html/app') ? '/var/www/html' : dirname(__DIR__, 3) . '/后端代码';
$file = $root . '/app/services/cashier/v3/member/CashierV3MemberDetailQueryServices.php';
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

$start = strpos($source, 'private function gifts');
$end = $start === false ? false : strpos($source, 'private function care', $start);
$giftReader = $start === false ? '' : substr($source, $start, ($end === false ? strlen($source) : $end) - $start);

$check($giftReader !== '', '会员详情赠送记录读取器存在');
$check(strpos($giftReader, "->leftJoin(\n                    'cashier_v3_direct_gift_item gi'") !== false,
    '会员详情直接赠送按明细关联，不因历史缺失明细而丢失记录');
$check(strpos($giftReader, "'gi.content_snapshot_json AS item_content_snapshot_json,ga.validity_end AS authority_validity_end'") !== false,
    '会员详情读取每项有效期快照并保留旧主记录回退');
$check(strpos($giftReader, "['validityEnd'] ?? \$snapshot['validity_end'] ?? 0") !== false,
    '会员详情兼容有效期快照字段命名');
$check(strpos($giftReader, "'expiresAt' => \$validityEnd > 0 ? \$this->dateTime(\$validityEnd) : null") !== false,
    '会员详情将赠送有效期返回给前端');

echo "DIRECT_GIFT_MEMBER_DETAIL_EXPIRY_CONTRACT_FAILED={$failed}\n";
exit($failed === 0 ? 0 : 1);
