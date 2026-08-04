<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$service = $root . '/后端代码/app/services/cashier/v3/CashierV3BusinessDocumentNumberServices.php';
$files = [
    $root . '/后端代码/app/services/cashier/v3/member/CashierV3RechargeModule.php',
    $root . '/后端代码/app/services/cashier/v3/settlement/CashierV3RechargeDebtRepaymentServices.php',
    $root . '/后端代码/app/services/cashier/v3/reservation/CashierV3ReservationModule.php',
    $root . '/后端代码/app/services/cashier/v3/member/CashierV3RechargeGiftIssuanceServices.php',
    $root . '/后端代码/app/services/cashier/v3/card/CashierV3CardOperationAuthorityServices.php',
    $root . '/后端代码/app/services/cashier/v3/settlement/CashierV3SaleOnlyCheckoutSubmissionServices.php',
    $root . '/后端代码/app/services/cashier/v3/settlement/ThinkPhpCashierV3CheckoutSubmissionExecutionPort.php',
    $root . '/后端代码/app/services/order/StoreOrderRefundServices.php',
    $root . '/后端代码/app/services/order/StoreDebtServices.php',
];

function must(bool $value, string $message): void
{
    if (!$value) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$source = (string)file_get_contents($service);
foreach (["self::SALES_ORDER => 'XS'", "self::RECHARGE => 'CZ'", "self::REFUND => 'TH'", "self::SERVICE => 'FW'", "self::DEBT_REPAYMENT => 'BJ'", "self::GIFT => 'ZS'", "self::CARD_OPERATION => 'CK'"] as $fragment) {
    must(strpos($source, $fragment) !== false, "missing prefix {$fragment}");
}
must(strpos($source, "str_pad((string)\$sequence, 5, '0', STR_PAD_LEFT)") !== false, 'daily sequence must be five digits');
must(strpos($source, "CashierV3TransactionGuard::assertInTransaction") !== false, 'allocator must require transaction');
must(strpos($source, 'cashier_v3_business_document_no') !== false, 'allocator must persist source mapping');
must(strpos($source, "Db::name('cashier_v3_business_document_no')->update") === false, 'allocator must never rewrite historic business records');
foreach ($files as $file) {
    $body = (string)file_get_contents($file);
    must(strpos($body, 'CashierV3BusinessDocumentNumberServices') !== false, "not integrated: {$file}");
}
$orderCenter = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php');
must(strpos($orderCenter, 'legacy_store_order_refund') !== false, 'refund list must prefer the new display number');
must(strpos($orderCenter, 'legacy_store_debt_repayment') !== false, 'legacy repayment list must prefer the new display number');
$upgrade = (string)file_get_contents($root . '/后端代码/database/upgrades/2026-08-03-收银V3业务单号统一/02-正式升级.sql');
must(strpos($upgrade, 'UPDATE `eb_') === false, 'migration must not rewrite historic data');
must(strpos($upgrade, 'gift_no` varchar(32)') !== false && strpos($upgrade, 'NULL DEFAULT NULL') !== false, 'new gift number must be nullable for historic rows');
echo "PASS cashier-v3-business-document-number static contract\n";
