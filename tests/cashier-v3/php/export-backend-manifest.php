<?php
/**
 * 导出后端 action manifest JSON（仅 stdout 最后输出纯 JSON；诊断写 stderr）。
 * 用法：php export-backend-manifest.php [/evidence/backend-manifest.json]
 */
require __DIR__ . '/../lib/boot-env.php';
require '/var/www/html/vendor/autoload.php';

use app\services\cashier\v3\CashierV3IdempotencyKeyServices;
use app\services\cashier\v3\bootstrap\CashierV3Bootstrap;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;

$outFile = $argv[1] ?? '';

CashierV3ActionManifest::flushCache();
CashierV3Bootstrap::resetForTests();

$all = CashierV3ActionManifest::all();
$out = [
    'actions' => [],
    'idempotency_prefixes' => CashierV3IdempotencyKeyServices::registeredPrefixes(),
];
foreach ($all as $action => $def) {
    $row = [
        'canonical' => (string)$def['canonical'],
        'type' => (string)$def['type'],
        'owner' => (string)$def['owner'],
        'permission' => $def['permission'] ?? null,
        'permissionPolicyId' => $def['permissionPolicyId'] ?? null,
        'alias_of' => $def['alias_of'] ?? null,
    ];
    if (($def['type'] ?? '') === 'command' && empty($def['alias_of'])) {
        $row['recovery'] = $def['recovery'] ?? CashierV3ActionManifest::recoveryContractFor($action);
    }
    $out['actions'][$action] = $row;
}

ob_start();
try {
    require_once __DIR__ . '/../lib/_lib.php';
    c1aBootThinkApp('/var/www/html/');
    CashierV3Bootstrap::resetForTests();
    CashierV3Bootstrap::dispatcher();
    $check = CashierV3Bootstrap::lastSelfCheck();
    $out['active_matrix'] = $check['active_matrix'] ?? [];
    $out['lock_contract'] = $check['lock_contract'] ?? null;
    $out['root_full_ready'] = !empty($check['root_full_ready']);
} catch (\Throwable $e) {
    $out['bootstrap_error'] = $e->getMessage();
}
$noise = ob_get_clean();
if ($noise !== '') {
    fwrite(STDERR, $noise);
}

$json = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
if ($outFile !== '') {
    if (file_put_contents($outFile, $json) === false) {
        fwrite(STDERR, "FAIL: cannot write {$outFile}\n");
        exit(1);
    }
    fwrite(STDERR, "GATE_PASS=EXPORT-MANIFEST\n");
    echo "MANIFEST_WRITTEN={$outFile}\n";
} else {
    echo $json;
}
