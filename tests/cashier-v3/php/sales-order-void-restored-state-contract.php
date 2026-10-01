<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
require_once $root . '/后端代码/app/services/cashier/v3/CashierV3ResultCode.php';
require_once $root . '/后端代码/app/services/cashier/v3/CashierV3CommandException.php';
require_once $root . '/后端代码/app/services/cashier/v3/order/CashierV3SalesOrderReversalServices.php';

use app\services\cashier\v3\order\CashierV3SalesOrderReversalServices;

// 只测原核销与成功服务作废的逐笔配对；未作废、数量不符或重复返还都不能放行。
$method = new ReflectionMethod(CashierV3SalesOrderReversalServices::class, 'writeoffsFullyReversed');
$writeoffs = [
    ['writeoff_id' => 'EWO-1', 'source_kind' => 'count_card', 'source_detail_id' => 10, 'holder_id' => 20, 'quantity' => 1],
    ['writeoff_id' => 'EWO-2', 'source_kind' => 'count_card', 'source_detail_id' => 10, 'holder_id' => 20, 'quantity' => 1],
];
$reversals = [
    ['original_writeoff_id' => 'EWO-1', 'service_fact_id' => 31, 'source_detail_id' => 10, 'holder_id' => 20, 'quantity' => 1, 'operation_id' => 'VOID-1'],
    ['original_writeoff_id' => 'EWO-2', 'service_fact_id' => 32, 'source_detail_id' => 10, 'holder_id' => 20, 'quantity' => 1, 'operation_id' => 'VOID-2'],
];
$operations = [
    ['operation_id' => 'VOID-1', 'service_fact_id' => 31, 'status' => 'succeeded'],
    ['operation_id' => 'VOID-2', 'service_fact_id' => 32, 'status' => 'succeeded'],
];
$matched = $method->invoke(null, $writeoffs, $reversals, $operations);
$missing = $method->invoke(null, $writeoffs, [$reversals[0]], $operations);
$duplicate = $method->invoke(null, $writeoffs, [$reversals[0], $reversals[0]], $operations);
$wrongQuantity = $reversals;
$wrongQuantity[1]['quantity'] = 2;
$mismatch = $method->invoke(null, $writeoffs, $wrongQuantity, $operations);
$wrongHolder = $reversals;
$wrongHolder[1]['holder_id'] = 99;
$crossCard = $method->invoke(null, $writeoffs, $wrongHolder, $operations);
$pendingOperations = $operations;
$pendingOperations[1]['status'] = 'failed';
$notVoided = $method->invoke(null, $writeoffs, $reversals, $pendingOperations);
$timeCard = $writeoffs;
$timeCard[1]['source_kind'] = 'time_card';
$timeCardReversals = $reversals;
$timeCardReversals[1]['quantity'] = 0;
$timeCardVoided = $method->invoke(null, $timeCard, $timeCardReversals, $operations);

$source = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/order/CashierV3SalesOrderReversalServices.php');
$passed = $matched === true && $timeCardVoided === true && $missing === false && $duplicate === false
    && $mismatch === false && $crossCard === false && $notVoided === false
    && strpos($source, "->whereIn('status', [0, 1, 3])") !== false
    && strpos($source, "->where('status', 'ACTIVE')") !== false
    && strpos($source, "if (\$repaid > 0) throw self::failure('sales_reversal_debt_already_repaid')") !== false
    && strpos($source, "->where('debt_id', (int)\$debt['debt_id'])->where('status', 'succeeded')") !== false;

echo $passed ? "SALES_ORDER_VOID_RESTORED_STATE=PASS\n" : "SALES_ORDER_VOID_RESTORED_STATE=FAIL\n";
exit($passed ? 0 : 1);
