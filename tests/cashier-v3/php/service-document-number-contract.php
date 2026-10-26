<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$writer = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/checkout/persistence/ThinkPhpCashierV3EntitlementCompletionWriter.php');
$numbers = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/CashierV3BusinessDocumentNumberServices.php');
$migration = $root . '/后端代码/database/upgrades/2026-08-05-收银V3服务记录单号';
$apply = (string)file_get_contents($migration . '/02-正式升级.sql');
$postcheck = (string)file_get_contents($migration . '/03-升级后验证.sql');
$checks = [
    'service document allocator uses FW plus MMDD and five digits' => strpos($numbers, "self::SERVICE => 'FW'") !== false
        && strpos($numbers, "self::SERVICE ? \$date->format('md') : \$date->format('ymd')") !== false,
    'reservation allocator uses YY plus YYMMDD and four digits' => strpos($numbers, "public const RESERVATION = 'reservation'") !== false
        && strpos($numbers, "self::RESERVATION => 'YY'") !== false
        && strpos($numbers, "[self::RESERVATION, self::DEBT]") !== false,
    'debt allocator uses QK plus YYMMDD and four digits' => strpos($numbers, "public const DEBT = 'debt'") !== false
        && strpos($numbers, "self::DEBT => 'QK'") !== false,
    'writer allocates after a completion receipt is claimed' => strpos($writer, 'allocateServiceDocumentNumbersInTx') !== false
        && strpos($writer, "'entitlement_service_fact'") !== false
        && strpos($writer, "CashierV3BusinessDocumentNumberServices::SERVICE") !== false,
    'writer retains ESF only as internal source identity' => strpos($writer, "['service_fact_id']") !== false
        && strpos($writer, "['service_record_no']") !== false,
    'migration adds nullable visible number without backfilling ESF history' => strpos($apply, '`service_record_no` varchar(32)') !== false
        && strpos($apply, 'UPDATE eb_cashier_v3_entitlement_service_fact') === false,
    'postcheck accepts only FW plus MMDD plus five digit sequence' => strpos($postcheck, "'^FW[0-9]{9}$'") !== false,
];
$failed = 0;
foreach ($checks as $name => $condition) {
    echo ($condition ? 'PASS ' : 'FAIL ') . $name . "\n";
    if (!$condition) $failed++;
}
exit($failed === 0 ? 0 : 1);
