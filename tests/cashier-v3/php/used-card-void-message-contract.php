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

// 已作废的核销仍会留下历史事实；按核销、补交等实际拒绝原因给短提示。
$failure = new ReflectionMethod(CashierV3SalesOrderReversalServices::class, 'failure');
$downstream = $failure->invoke(null, 'sales_reversal_card_downstream_use_exists');
$repaid = $failure->invoke(null, 'sales_reversal_debt_already_repaid');
$activeRepayment = $failure->invoke(null, 'sales_reversal_debt_repayment_active');
$occupation = $failure->invoke(null, 'sales_reversal_card_active_occupation_exists');
$other = $failure->invoke(null, 'sales_reversal_debt_state_invalid');
$fallback = $failure->invoke(null, 'unexpected_reason');
$passed = $passed
    && $downstream instanceof CashierV3CommandException
    && $downstream->getMessage() === '卡项有未作废的核销记录，销售订单未作废。'
    && ($downstream->getDetail()['reason'] ?? '') === 'sales_reversal_card_downstream_use_exists'
    && $repaid instanceof CashierV3CommandException
    && $repaid->getMessage() === '订单欠款仍有补交金额，销售订单未作废。'
    && ($repaid->getDetail()['reason'] ?? '') === 'sales_reversal_debt_already_repaid'
    && $activeRepayment->getMessage() === '订单欠款有未作废的补交单，销售订单未作废。'
    && $occupation->getMessage() === '卡项有未结束的预约或服务单，销售订单未作废。'
    && $other instanceof CashierV3CommandException
    && $other->getMessage() === '订单欠款状态已变化，销售订单未作废。'
    && ($other->getDetail()['reason'] ?? '') === 'sales_reversal_debt_state_invalid'
    && $fallback instanceof CashierV3CommandException
    && $fallback->getMessage() === '订单相关记录异常，操作未完成。';

echo $passed ? "USED_CARD_VOID_MESSAGE_CONTRACT=PASS\n" : "USED_CARD_VOID_MESSAGE_CONTRACT=FAIL\n";
exit($passed ? 0 : 1);
