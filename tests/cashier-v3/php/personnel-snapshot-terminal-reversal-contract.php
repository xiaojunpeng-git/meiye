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
$fact = static function (string $id, string $direction, string $name, string $command, string $reversalOf = ''): array {
    return [
        'fact_id' => $id,
        'fact_direction' => $direction,
        'employee_name_snapshot' => $name,
        'command_idempotency_key' => $command,
        'reversal_of' => $reversalOf,
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

echo "PERSONNEL_SNAPSHOT_TERMINAL_REVERSAL_CONTRACT=PASS\n";
