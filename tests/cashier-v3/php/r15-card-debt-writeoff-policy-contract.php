<?php
declare(strict_types=1);

$backend = getenv('CASHIER_V3_BACKEND') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';

use app\services\order\store\WriteOffOrderServices;
use app\services\cashier\v3\card\CashierV3CardPurchaseIssuanceServices;
use think\exception\ValidateException;

function cardDebtPolicyCheck(string $label, bool $passed): void
{
    if (!$passed) {
        fwrite(STDERR, 'FAIL: ' . $label . PHP_EOL);
        exit(1);
    }
    echo 'PASS: ' . $label . PHP_EOL;
}

// 固定来源、未取消额及共享次数作为只读输入；业务提交仍重新读取数据库和锁定债务。
$writeoff = new class extends WriteOffOrderServices {
    public function __construct() {}
};
$order = ['id' => 42, 'uid' => 7, 'tenant_id' => '0'];
$detail = [
    'id' => 101,
    'oid' => 42,
    'write_times' => 1,
    'write_surplus_times' => 1,
    'debt_amount' => '0.00',
    'repaid_debt_amount' => '0.00',
    'cart_info' => json_encode(['block_writeoff_on_debt' => 1]),
];
$usable = static function (array $row, string $pending) use ($writeoff, $order): int {
    return $writeoff->calcEffectiveWriteSurplusTimes(
        $row, 0.0, $order, '99.00', [$row], false, $pending, '0.00', 1
    );
};

cardDebtPolicyCheck('enabled card blocks every use while its own debt remains', $usable($detail, '50.00') === 0);
cardDebtPolicyCheck('fully repaid card regains its available use', $usable($detail, '0.00') === 1);
$oldDetail = $detail;
$oldDetail['cart_info'] = '{}';
cardDebtPolicyCheck('old sold-card snapshot is not retroactively blocked', $usable($oldDetail, '50.00') === 1);

// Issuance checks only newly purchased cards. A stale page cannot freeze the
// opposite debt policy even if its sale identity and accepted price are valid.
$issuer = (new ReflectionClass(CashierV3CardPurchaseIssuanceServices::class))->newInstanceWithoutConstructor();
$policyCheck = new ReflectionMethod(CashierV3CardPurchaseIssuanceServices::class, 'assertDebtWriteoffPolicyCurrent');
$currentOn = ['authority_snapshot' => ['cardPurchase' => ['blockWriteoffOnDebt' => 1]]];
$currentOff = ['authority_snapshot' => ['cardPurchase' => ['blockWriteoffOnDebt' => 0]]];
$policyCheck->invoke($issuer, ['blockWriteoffOnDebt' => 1], $currentOn, false);
$policyCheck->invoke($issuer, ['blockWriteoffOnDebt' => 0], $currentOff, false);
cardDebtPolicyCheck('matching selected and current card rules can be issued', true);
try {
    $policyCheck->invoke($issuer, ['blockWriteoffOnDebt' => 0], $currentOn, false);
    cardDebtPolicyCheck('stale debt-blocking rule fails closed', false);
} catch (\app\services\cashier\v3\CashierV3CommandException $exception) {
    cardDebtPolicyCheck('stale debt-blocking rule fails closed',
        strpos($exception->getMessage(), '刷新商品') !== false);
}
try {
    $policyCheck->invoke($issuer, ['blockWriteoffOnDebt' => 1], $currentOff, false);
    cardDebtPolicyCheck('stale rule cannot impose an unselected policy', false);
} catch (\app\services\cashier\v3\CashierV3CommandException $exception) {
    cardDebtPolicyCheck('stale rule cannot impose an unselected policy',
        strpos($exception->getMessage(), '刷新商品') !== false);
}

$wrongOrigin = $detail;
$wrongOrigin['oid'] = 43;
try {
    $usable($wrongOrigin, '0.00');
    cardDebtPolicyCheck('wrong order origin fails closed', false);
} catch (ValidateException $exception) {
    cardDebtPolicyCheck('wrong order origin fails closed', $exception->getMessage() === '卡项来源不完整，暂不能核销');
}

echo "R15_CARD_DEBT_WRITEOFF_POLICY=PASS\n";
