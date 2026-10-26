<?php

declare(strict_types=1);

/** Verify checkout error translation without opening a transaction or changing inventory. */

$root = dirname(__DIR__, 3);
foreach ([
    'CashierV3ResultCode.php',
    'CashierV3CommandException.php',
] as $file) {
    require_once $root . '/后端代码/app/services/cashier/v3/' . $file;
}
foreach ([
    'CashierV3CheckoutSubmissionExecutionPort.php',
    'CashierV3CheckoutSettlementContractException.php',
    'CashierV3CheckoutInventoryFailureMessage.php',
    'ThinkPhpCashierV3CheckoutSubmissionExecutionPort.php',
] as $file) {
    require_once $root . '/后端代码/app/services/cashier/v3/settlement/' . $file;
}

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\settlement\CashierV3CheckoutInventoryFailureMessage;
use app\services\cashier\v3\settlement\CashierV3CheckoutSettlementContractException;
use app\services\cashier\v3\settlement\ThinkPhpCashierV3CheckoutSubmissionExecutionPort;

$cases = [
    ['sale_inventory_shortage_denied', '库存不足，未完成结账。请核对当前门店库存后重试。', CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE, CashierV3ResultCode::STATUS_FAILED],
    ['inventory_shortage_denied', '库存不足，未完成结账。请核对当前门店库存后重试。', CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE, CashierV3ResultCode::STATUS_FAILED],
    ['sale_inventory_stock_not_ready', '库存不足，未完成结账。请核对当前门店库存后重试。', CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE, CashierV3ResultCode::STATUS_FAILED],
    ['inventory_stock_not_ready', '库存不足，未完成结账。请核对当前门店库存后重试。', CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE, CashierV3ResultCode::STATUS_FAILED],
    ['sale_inventory_default_location_not_ready', '当前门店库存库位未配置好，未完成结账。请联系管理员核对库位。', CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE, CashierV3ResultCode::STATUS_FAILED],
    ['sale_inventory_stock_batch_balance_mismatch', '库存数据异常，未完成结账。请联系管理员核对库存。', CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE, CashierV3ResultCode::STATUS_FAILED],
    ['sale_inventory_stock_changed', '库存已发生变化，未完成结账。请核对当前门店库存后重试。', CashierV3ResultCode::RESOURCE_VERSION_CONFLICT, CashierV3ResultCode::STATUS_CONFLICT],
    ['sale_inventory_batch_changed', '库存已发生变化，未完成结账。请核对当前门店库存后重试。', CashierV3ResultCode::RESOURCE_VERSION_CONFLICT, CashierV3ResultCode::STATUS_CONFLICT],
    ['inventory_batch_changed', '库存已发生变化，未完成结账。请核对当前门店库存后重试。', CashierV3ResultCode::RESOURCE_VERSION_CONFLICT, CashierV3ResultCode::STATUS_CONFLICT],
    ['checkout_state_version_changed', '结账资料刚刚发生变化，本次操作已回滚，请刷新后重试。', CashierV3ResultCode::RESOURCE_VERSION_CONFLICT, CashierV3ResultCode::STATUS_CONFLICT],
    ['payment_collection_invalid', '正式结账未能完整落账，本次操作已全部回滚，请重试。', CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE, CashierV3ResultCode::STATUS_FAILED],
];

$port = (new ReflectionClass(ThinkPhpCashierV3CheckoutSubmissionExecutionPort::class))
    ->newInstanceWithoutConstructor();
$translate = new ReflectionMethod(ThinkPhpCashierV3CheckoutSubmissionExecutionPort::class, 'domainCall');
$saleOnlySource = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/settlement/CashierV3SaleOnlyCheckoutSubmissionServices.php');
if (strpos($saleOnlySource, 'CashierV3CheckoutInventoryFailureMessage::forReason($reason)') === false) {
    throw new RuntimeException('Sale-only checkout must share the inventory message mapping');
}

foreach ($cases as [$reason, $message, $resultCode, $status]) {
    if (CashierV3CheckoutInventoryFailureMessage::forReason($reason)
        !== (in_array($reason, ['payment_collection_invalid', 'checkout_state_version_changed'], true) ? null : $message)) {
        throw new RuntimeException("Unexpected inventory message for {$reason}");
    }

    try {
        $translate->invoke($port, static function () use ($reason): void {
            throw new CashierV3CheckoutSettlementContractException($reason, ['stockId' => 17]);
        });
        throw new RuntimeException("Expected checkout failure for {$reason}");
    } catch (CashierV3CommandException $exception) {
        if ($exception->getMessage() !== $message
            || $exception->getResultCode() !== $resultCode
            || $exception->getResultStatus() !== $status
            || $exception->getDetail()['reason'] !== $reason
            || $exception->getDetail()['stockId'] !== 17) {
            throw new RuntimeException("Unexpected checkout error translation for {$reason}");
        }
    }
    echo "PASS {$reason}\n";
}
