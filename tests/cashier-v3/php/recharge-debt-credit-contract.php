<?php
declare(strict_types=1);

$root = is_dir('/var/www/html/app') ? '/var/www/html' : dirname(__DIR__, 3) . '/后端代码';
$source = file_get_contents($root . '/app/services/cashier/v3/member/CashierV3RechargeModule.php');
if ($source === false) {
    throw new RuntimeException('无法读取充值模块源码。');
}

$failed = 0;
$check = static function (bool $condition, string $name) use (&$failed): void {
    if (!$condition) {
        $failed++;
        fwrite(STDERR, "FAIL: {$name}\n");
        return;
    }
    echo "PASS: {$name}\n";
};

$principalCents = 1000 * 100;
$debtCents = 300 * 100;
$creditedPrincipalCents = $principalCents - $debtCents;
$check($creditedPrincipalCents === 700 * 100, '充值 1000、欠款 300 时到账本金为 700');
$check(
    strpos($source, "\$input['creditedPrincipalCents'] = \$input['principalCents'] - \$input['debtCents'];") !== false,
    '充值模块在服务端计算到账本金'
);
$check(
    preg_match(
        '~resolvePaymentLines\(\s*\(array\)\(\$input\[\'paymentLines\'\].*?\$input\[\'creditedPrincipalCents\'\]\s*\)~s',
        $source
    ) === 1
        && preg_match(
            '~resolveSalespeople\(\s*\(array\)\(\$input\[\'salespersonAllocations\'\].*?\$input\[\'creditedPrincipalCents\'\]~s',
            $source
        ) === 1,
    '收款与销售人业绩均以到账本金为上限'
);
$check(
    preg_match(
        '~creditRechargeInTx\(\[.*?\'principalCents\'\s*=>\s*\$input\[\'creditedPrincipalCents\'\]~s',
        $source
    ) === 1,
    '权威余额写入仅贷记到账本金'
);
$check(
    strpos($source, "'price' => \$this->centsToMoney(\$input['principalCents'])") !== false
        && strpos($source, "'debt_amount' => \$this->centsToMoney(\$input['debtCents'])") !== false,
    '充值单保留原始本金与欠款作为后续补交依据'
);
$check(
    strpos($source, "'creditedPrincipalCents' => \$input['creditedPrincipalCents']") !== false
        && strpos($source, "'creditedPrincipalAmount' => \$this->centsToMoney(\$input['creditedPrincipalCents'])") !== false,
    '业务事件与成功回执保留到账本金审计字段'
);

echo "RECHARGE_DEBT_CREDIT_CONTRACT_FAILED={$failed}\n";
exit($failed === 0 ? 0 : 1);
