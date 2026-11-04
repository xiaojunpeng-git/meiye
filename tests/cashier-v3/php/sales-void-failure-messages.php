<?php

declare(strict_types=1);

/** The void response must explain the failed business guard without weakening it. */
$root = dirname(__DIR__, 3);
require $root . '/后端代码/vendor/autoload.php';

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3CommandFailureEnvelopeServices;
use app\services\cashier\v3\order\CashierV3SalesVoidFailureMessages;
use app\services\cashier\v3\order\CashierV3OrderLifecycleServices;
use app\services\cashier\v3\order\CashierV3SalesOrderReversalServices;
use app\services\cashier\v3\order\CashierV3SalesOrderInventoryReversalServices;
use app\services\cashier\v3\order\CashierV3CardOperationReversalServices;
use app\services\cashier\v3\order\CashierV3ServiceRecordVoidServices;

$generic = '订单资料或后续使用状态已变化，本次操作未提交。';
$cases = [
    'sales_order_not_mutable' => ['已结算订单对不上', '重新打开详情'],
    'sales_reversal_card_issuance_incomplete' => ['发卡记录', '联系管理员'],
    'sales_reversal_member_project_mapping_invalid' => ['项目权益', '联系管理员'],
    'sales_void_inventory_receipt_incomplete' => ['出库记录', '联系管理员'],
    'card_operation_reversal_project_target_used' => ['项目已经使用', '核对项目使用记录'],
    'service_void_benefit_detail_missing' => ['项目权益记录缺失', '联系管理员'],
    'order_already_reversed' => ['已经作废或退款', '查看订单操作记录'],
];
foreach ($cases as $reason => $phrases) {
    $message = CashierV3SalesVoidFailureMessages::forReason($reason, $generic);
    foreach ($phrases as $phrase) {
        if (strpos($message, $phrase) === false) throw new RuntimeException("Missing customer guidance: {$reason}");
    }
}

$exception = new CashierV3CommandException('COMMAND_RESULT_INCOMPLETE', $generic, 'failed', [
    'reason' => 'sales_order_not_mutable',
]);
$envelope = (new CashierV3CommandFailureEnvelopeServices())->fromException(['action' => 'void-sales-order'], $exception);
if (strpos((string)$envelope['result']['message'], '已结算订单对不上') === false
    || $envelope['result']['status'] !== 'failed'
    || $envelope['result']['code'] !== 'COMMAND_RESULT_INCOMPLETE') {
    throw new RuntimeException('Void response lost exact rejection or changed result status');
}
$other = (new CashierV3CommandFailureEnvelopeServices())->fromException(['action' => 'reopen-sales-order'], $exception);
if ($other['result']['message'] !== $generic) throw new RuntimeException('Unrelated actions must keep their own message');
$presale = (new CashierV3CommandFailureEnvelopeServices())->fromThrowable(
    ['action' => 'void-sales-order'], new RuntimeException('presale_claim_close_changed')
);
if (strpos($presale['result']['message'], '预售领取状态') === false) {
    throw new RuntimeException('Presale close race lost its business guidance');
}

// A card-name-specific domain refusal remains more useful than a generic
// translation and must never be overwritten by the gateway.
$named = '卡项「体验卡」已使用过，不能直接作废原销售订单，使用过的卡项只能停用。';
if (CashierV3SalesVoidFailureMessages::forReason('sales_reversal_card_already_changed', $named) !== $named) {
    throw new RuntimeException('Specific card usage guidance was overwritten');
}

// Scan every named guard in the void cascade. A newly added guard must still
// produce Chinese customer guidance rather than the old catch-all message.
foreach ([
    CashierV3OrderLifecycleServices::class,
    CashierV3SalesOrderReversalServices::class,
    CashierV3SalesOrderInventoryReversalServices::class,
    CashierV3CardOperationReversalServices::class,
    CashierV3ServiceRecordVoidServices::class,
] as $service) {
    $reflection = new ReflectionClass($service);
    $source = (string)file_get_contents((string)$reflection->getFileName());
    preg_match_all("/self::failure\\('([^']+)'/", $source, $matches);
    $failure = $reflection->getMethod('failure');
    foreach (array_unique($matches[1]) as $reason) {
        $rejection = $failure->invoke(null, $reason);
        $message = CashierV3SalesVoidFailureMessages::forReason($reason, $rejection->getMessage());
        if ($message === '' || $message === $generic
            || preg_match('/[\\p{Han}]/u', $message) !== 1
            || strpos($message, $reason) !== false) {
            throw new RuntimeException("Unreadable void refusal: {$service}/{$reason}");
        }
    }
}
echo "SALES_VOID_FAILURE_MESSAGES=PASS\n";
