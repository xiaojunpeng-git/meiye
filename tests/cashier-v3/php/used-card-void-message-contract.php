<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
require_once $root . '/后端代码/app/services/cashier/v3/CashierV3ResultCode.php';
require_once $root . '/后端代码/app/services/cashier/v3/CashierV3CommandException.php';
require_once $root . '/后端代码/app/services/cashier/v3/order/CashierV3SalesOrderReversalServices.php';

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\order\CashierV3SalesOrderReversalServices;

// 本合同只验证作废拒绝的对客语言和诊断数据，不连接数据库、不执行作废。
$method = new ReflectionMethod(CashierV3SalesOrderReversalServices::class, 'cardUsageFailure');
$exception = $method->invoke(null, [
    'card_name' => '6980随心挑',
    'write_times' => 66,
    'write_surplus_times' => 63,
]);

$expectedMessage = '卡项「6980随心挑」已使用过，不能直接作废原销售订单，使用过的卡项只能停用。';
$detail = $exception instanceof CashierV3CommandException ? $exception->getDetail() : [];
$passed = $exception instanceof CashierV3CommandException
    && $exception->getMessage() === $expectedMessage
    && ($detail['reason'] ?? '') === 'sales_reversal_card_already_changed'
    && ($detail['cardNameSnapshot'] ?? '') === '6980随心挑'
    && ($detail['totalTimes'] ?? -1) === 66
    && ($detail['usedTimes'] ?? -1) === 3
    && ($detail['remainingTimes'] ?? -1) === 63;

echo $passed ? "USED_CARD_VOID_MESSAGE_CONTRACT=PASS\n" : "USED_CARD_VOID_MESSAGE_CONTRACT=FAIL\n";
exit($passed ? 0 : 1);
