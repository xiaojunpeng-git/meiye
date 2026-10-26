<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$service = $root . '/后端代码/app/services/cashier/v3/service';
require_once $service . '/CashierV3ServiceOrderAuthorityException.php';
require_once $service . '/CashierV3ServiceOrderState.php';
require_once $service . '/CashierV3ServiceOrderRepository.php';
require_once $service . '/ThinkPhpCashierV3ServiceOrderRepository.php';
require_once $service . '/CashierV3GenericServiceLinePlanV1.php';

use app\services\cashier\v3\service\CashierV3GenericServiceLinePlanV1;
use app\services\cashier\v3\service\CashierV3ServiceOrderAuthorityException;

$passed = 0;
$failed = 0;
function genericOk(string $name, bool $condition): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    echo "FAIL {$name}\n";
}
function genericReject(string $name, string $reason, callable $callback): void
{
    try {
        $callback();
        genericOk($name, false);
    } catch (CashierV3ServiceOrderAuthorityException $exception) {
        genericOk($name, $exception->reason() === $reason);
    }
}
function genericInput(): array
{
    return [
        'tenantId' => 'tenant-1',
        'serviceOrderId' => 901,
        'lineKey' => 'hang-line:701',
        'sourceId' => 501,
        'sourceVersion' => 3,
        'hangLineId' => 701,
        'projectId' => 301,
        'projectNameSnapshot' => '光电项目',
        'serviceQuantity' => 2,
        'serviceTarget' => 'SELF',
        'isExperience' => 0,
        'artisanStaffId' => 0,
        'artisanEmployeeId' => 0,
        'artisanNameSnapshot' => '',
        'authorityFingerprint' => str_repeat('a', 64),
        'createdAt' => 1785286800,
    ];
}

$row = CashierV3GenericServiceLinePlanV1::fromStartService(genericInput())->row();
genericOk('sale project source is explicit', $row['source_type'] === 'SALE_PROJECT');
genericOk('hang identity and service quantity persist', $row['hang_line_id'] === 701 && $row['service_quantity'] === 2);
genericOk('sale project never occupies entitlement', $row['entitlement_source_detail_id'] === 0 && $row['entitlement_instance_id'] === 0 && $row['occupied_times'] === 0);
genericOk('authority snapshot is frozen', $row['source_id'] === 501 && $row['source_version_snapshot'] === 3 && strlen($row['authority_fingerprint']) === 64);
genericOk('new service line starts active and versioned', $row['status'] === 'ACTIVE' && $row['version'] === 1);

$invalid = genericInput();
$invalid['serviceQuantity'] = 0;
genericReject('zero service quantity is rejected', 'generic_service_line_quantity_invalid', static function () use ($invalid): void {
    CashierV3GenericServiceLinePlanV1::fromStartService($invalid);
});
$invalid = genericInput();
$invalid['authorityFingerprint'] = 'client-value';
genericReject('untrusted fingerprint shape is rejected', 'generic_service_line_authority_fingerprint_invalid', static function () use ($invalid): void {
    CashierV3GenericServiceLinePlanV1::fromStartService($invalid);
});
$invalid = genericInput();
$invalid['extra'] = true;
genericReject('unknown input fields fail closed', 'generic_service_line_shape_invalid', static function () use ($invalid): void {
    CashierV3GenericServiceLinePlanV1::fromStartService($invalid);
});

$repositorySource = file_get_contents($service . '/ThinkPhpCashierV3ServiceOrderRepository.php');
$writerSource = file_get_contents($service . '/ThinkPhpCashierV3EntitlementCompletionOccupationWriter.php');
$adapterSource = file_get_contents($root . '/后端代码/app/services/cashier/v3/checkout/CashierV3EntitlementCompletionAuthorityAdapter.php');
$migration = file_get_contents($root . '/后端代码/database/upgrades/2026-07-29-C3服务单通用项目明细/02-正式升级.sql');
genericOk('occupation discovery filters entitlement source', strpos($repositorySource, "->where('source_type', self::LINE_SOURCE_ENTITLEMENT)") !== false);
genericOk('completion writer skips non entitlement lines', strpos($writerSource, '!== ThinkPhpCashierV3ServiceOrderRepository::LINE_SOURCE_ENTITLEMENT') !== false);
genericOk('checkout discovery filters entitlement source', strpos($adapterSource, "->where('source_type', ThinkPhpCashierV3ServiceOrderRepository::LINE_SOURCE_ENTITLEMENT)") !== false);
genericOk('legacy rows default to entitlement', strpos($migration, "DEFAULT ''ENTITLEMENT''") !== false);
genericOk('migration carries required source fields', array_reduce([
    '`source_type`', '`source_id`', '`source_version_snapshot`', '`hang_line_id`',
    '`service_quantity`', '`authority_fingerprint`',
], static function (bool $ok, string $field) use ($migration): bool {
    return $ok && strpos($migration, $field) !== false;
}, true));

echo "C3_GENERIC_LINE_CONTRACT passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
