<?php

require '/var/www/html/vendor/autoload.php';

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3RequestNormalizer;

function personnelAllocationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL {$message}\n");
        exit(1);
    }
}

$weighted = CashierV3RequestNormalizer::normalize('update-cart-line-service-settings', [
    'lineId' => 'sale:weighted-project',
    'craftsmen' => [
        ['staffId' => 11, 'laborWeight' => 60, 'isPointCustomer' => true],
        ['staffId' => 12, 'laborWeight' => 40, 'isPointCustomer' => false],
    ],
])['normalized'];
personnelAllocationAssert(
    ($weighted['craftsmen'][0]['staffId'] ?? 0) === 11
        && ($weighted['craftsmen'][0]['laborWeight'] ?? 0) === 60
        && ($weighted['craftsmen'][0]['craftsmanPerformanceType'] ?? '') === 'commission_labor'
        && ($weighted['craftsmen'][1]['staffId'] ?? 0) === 12
        && ($weighted['craftsmen'][1]['laborWeight'] ?? 0) === 40,
    '完整模式手艺人比例和点客标记必须进入规范化命令'
);

$legacy = CashierV3RequestNormalizer::normalize('update-cart-line-service-settings', [
    'lineId' => 'sale:legacy-project',
    'craftsmanIds' => [11, 12, 13],
])['normalized'];
personnelAllocationAssert(
    array_column($legacy['craftsmen'], 'laborWeight') === [34, 33, 33],
    '旧客户端只传人员 ID 时必须稳定平均并把余数给第一人'
);

$rejected = false;
try {
    CashierV3RequestNormalizer::normalize('update-cart-line-service-settings', [
        'lineId' => 'sale:invalid-project',
        'craftsmen' => [
            ['staffId' => 11, 'laborWeight' => 80],
            ['staffId' => 12, 'laborWeight' => 30],
        ],
    ]);
} catch (CashierV3CommandException $exception) {
    $rejected = ($exception->getDetail()['reason'] ?? '') === 'craftsman_weight_sum_invalid';
}
personnelAllocationAssert($rejected, '手艺人比例合计不是 100% 必须拒绝');

$manual = CashierV3RequestNormalizer::normalize('update-cart-line-service-settings', [
    'lineId' => 'sale:manual-fee',
    'laborManualFee' => '12',
])['normalized'];
personnelAllocationAssert(
    ($manual['laborManualFee'] ?? null) === '12',
    '完整模式本次手工费必须按整数元规范化'
);

$manualRejected = false;
try {
    CashierV3RequestNormalizer::normalize('update-cart-line-service-settings', [
        'lineId' => 'sale:manual-fee-invalid',
        'laborManualFee' => '-1',
    ]);
} catch (CashierV3CommandException $exception) {
    $manualRejected = ($exception->getDetail()['reason'] ?? '') === 'labor_manual_fee_invalid';
}
personnelAllocationAssert($manualRejected, '负数临时手工费必须拒绝');

$decimalRejected = false;
try {
    CashierV3RequestNormalizer::normalize('update-cart-line-service-settings', [
        'lineId' => 'sale:manual-fee-decimal',
        'laborManualFee' => '12.34',
    ]);
} catch (CashierV3CommandException $exception) {
    $decimalRejected = ($exception->getDetail()['reason'] ?? '') === 'labor_manual_fee_invalid';
}
personnelAllocationAssert($decimalRejected, '小数临时手工费必须拒绝');

$ordinarySettingError = false;
try {
    CashierV3RequestNormalizer::normalize('update-cart-line-service-settings', [
        'lineId' => 'sale:ordinary-project',
        'craftsmen' => [
            ['staffId' => 11, 'laborWeight' => 0],
        ],
    ]);
} catch (CashierV3CommandException $exception) {
    $ordinarySettingError = $exception->getMessage() === '购物车服务设置无效，请重新选择。'
        && ($exception->getDetail()['reason'] ?? '') === 'craftsman_weight_invalid';
}
personnelAllocationAssert(
    $ordinarySettingError,
    '普通项目人员分配校验失败不得误报为卡内项目明细无效'
);

echo "PASS personnel-allocation-normalizer-contract\n";
