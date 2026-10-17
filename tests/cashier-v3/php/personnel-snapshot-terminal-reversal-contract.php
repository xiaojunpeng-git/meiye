<?php

require dirname(__DIR__, 3) . '/后端代码/app/services/cashier/v3/order/CashierV3SalesOrderQueryServices.php';

use app\services\cashier\v3\order\CashierV3SalesOrderQueryServices;

function personnelSnapshotAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL {$message}\n");
        exit(1);
    }
    echo "PASS {$message}\n";
}

$service = new CashierV3SalesOrderQueryServices();
$method = new ReflectionMethod($service, 'displayedPersonnelFacts');
$fact = static function (string $id, string $direction, string $name, string $command, string $reversalOf = '', string $rule = ''): array {
    return [
        'fact_id' => $id,
        'fact_direction' => $direction,
        'employee_name_snapshot' => $name,
        'command_idempotency_key' => $command,
        'reversal_of' => $reversalOf,
        'rule_code_snapshot' => $rule,
    ];
};

$original = $fact('original', 'forward', '原销售人', 'checkout');
$voidReversal = $fact('void-reversal', 'reversal', '原销售人', 'void-command', 'original');
$voidResult = $method->invoke($service, [$original, $voidReversal], []);
personnelSnapshotAssert(
    array_column($voidResult, 'employee_name_snapshot') === ['原销售人'],
    '退款或作废冲销不抹掉原订单人员快照'
);

$adjustmentReversal = $fact('adjust-reversal', 'reversal', '原销售人', 'adjust-command', 'original');
$adjusted = $fact('adjusted', 'forward', '调整后销售人', 'adjust-command');
$refundOriginalReversal = $fact('refund-original', 'reversal', '原销售人', 'refund-command', 'original');
$refundAdjustedReversal = $fact('refund-adjusted', 'reversal', '调整后销售人', 'refund-command', 'adjusted');
$refundResult = $method->invoke(
    $service,
    [$original, $adjustmentReversal, $adjusted, $refundOriginalReversal, $refundAdjustedReversal],
    ['adjust-command' => true]
);
personnelSnapshotAssert(
    array_column($refundResult, 'employee_name_snapshot') === ['调整后销售人'],
    '人员调整后退款仍展示最后一次有效人员快照'
);

$repairResult = $method->invoke($service, [
    $fact('shared-original', 'forward', '原手艺人', 'checkout'),
    $fact('shared-reversal', 'reversal', '原手艺人', 'r32_shared_purchase:14473', 'shared-original', 'SHARED-CARD-PURCHASE-REPAIR-R32'),
    $fact('shared-corrected', 'forward', '更正后手艺人', 'r32_shared_purchase:14473'),
], []);
personnelSnapshotAssert(
    array_column($repairResult, 'employee_name_snapshot') === ['更正后手艺人'],
    '共享次数卡更正只显示有效手艺人，不叠加原事实'
);

$dateRepairResult = $method->invoke($service, [
    $fact('dated-original', 'forward', '原销售人', 'adjust-command'),
    $fact(
        'dated-reversal',
        'reversal',
        '原销售人',
        'r47_personnel_date:dated-original',
        'dated-original',
        'ORDER-PERSONNEL-ADJUST-V1'
    ),
    $fact('dated-corrected', 'forward', '原销售人', 'r47_personnel_date:dated-original'),
], []);
personnelSnapshotAssert(
    array_column($dateRepairResult, 'fact_id') === ['dated-corrected'],
    '人员调整日期修复只显示正确业务日期上的替代事实'
);

echo "PERSONNEL_SNAPSHOT_TERMINAL_REVERSAL_CONTRACT=PASS\n";
