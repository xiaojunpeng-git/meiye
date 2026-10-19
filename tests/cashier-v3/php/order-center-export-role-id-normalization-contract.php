<?php

require dirname(__DIR__, 3) . '/后端代码/vendor/autoload.php';

use app\services\cashier\v3\CashierV3PermissionSnapshotServices;

$root = dirname(__DIR__, 3);
$snapshotSource = (string)file_get_contents(
    $root . '/后端代码/app/services/cashier/v3/CashierV3PermissionSnapshotServices.php'
);
$workerSource = (string)file_get_contents(
    $root . '/后端代码/app/services/cashier/v3/order/CashierV3OrderCenterUnifiedQueryWorkerContextResolver.php'
);
if (strpos($snapshotSource, "self::normalizeRoleIds(\$staff['roles'] ?? [])") === false
    || strpos($workerSource, 'CashierV3PermissionSnapshotServices::normalizeRoleIds(') === false) {
    fwrite(STDERR, "[FAIL] export creator and worker do not share role ID normalization\n");
    exit(1);
}

$cases = [
    'comma_string' => '775,776',
    'json_string' => '[775,"776"]',
    'model_string_array' => ['775', '776'],
    'integer_array' => [775, 776],
];

foreach ($cases as $label => $input) {
    $actual = CashierV3PermissionSnapshotServices::normalizeRoleIds($input);
    if ($actual !== [775, 776]) {
        fwrite(STDERR, '[FAIL] ' . $label . ': ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . PHP_EOL);
        exit(1);
    }
}

if (CashierV3PermissionSnapshotServices::normalizeRoleIds(['0', '-1', '', null]) !== []) {
    fwrite(STDERR, "[FAIL] invalid role IDs were not removed\n");
    exit(1);
}

echo "ORDER_CENTER_EXPORT_ROLE_ID_NORMALIZATION=PASS\n";
