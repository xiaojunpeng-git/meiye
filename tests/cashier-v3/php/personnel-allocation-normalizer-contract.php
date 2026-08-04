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
    ($weighted['craftsmen'][0] ?? null) === [
        'staffId' => 11,
        'laborWeight' => 60,
        'isPointCustomer' => true,
    ] && ($weighted['craftsmen'][1] ?? null) === [
        'staffId' => 12,
        'laborWeight' => 40,
        'isPointCustomer' => false,
    ],
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

echo "PASS personnel-allocation-normalizer-contract\n";
