<?php

require_once '/tests/cashier-v3/lib/boot-env.php';
require_once '/var/www/html/vendor/autoload.php';
require_once '/tests/cashier-v3/lib/_lib.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\checkout\provider\CashierV3EntitlementDebtGuardProvider;
use think\facade\Db;

c1aBootThinkApp('/var/www/html/');

$role = getenv('C2P_WORKER_ROLE') ?: '';
$marker = getenv('C2P_WORKER_MARKER') ?: '/coord/a.locked';
$holdMs = max(0, (int)(getenv('C2P_WORKER_HOLD_MS') ?: 0));
$minimumElapsedMs = max(0, (int)(getenv('C2P_WORKER_MIN_ELAPSED_MS') ?: 0));
if (!in_array($role, ['A', 'B'], true)) {
    throw new RuntimeException('worker_role_invalid');
}

$operator = new CashierV3OperatorScope(8, 10, '1', '0');
$scope = new CashierV3DataScopeContext(
    10,
    1010,
    8,
    '0',
    '1',
    [8],
    CashierV3DataScopeContext::MODE_STORES,
    [],
    false,
    '',
    'permission-v1',
    ['cashier.v3.cashier', 'cashier.v3.writeoff'],
    ['id' => 10, 'employee_id' => 1010]
);
$provider = new CashierV3EntitlementDebtGuardProvider();
$started = microtime(true);
Db::startTrans();
try {
    $snapshot = $provider->lockOrCreateSnapshotInTx(601, $operator, $scope);
    if ($role === 'A') {
        file_put_contents($marker, 'locked');
        if ($holdMs > 0) {
            usleep($holdMs * 1000);
        }
    }
    Db::commit();
} catch (Throwable $exception) {
    Db::rollback();
    throw $exception;
}
$elapsedMs = (int)round((microtime(true) - $started) * 1000);
if ((int)$snapshot['version'] !== 1) {
    throw new RuntimeException('worker_guard_version_invalid:' . (int)$snapshot['version']);
}
if ($elapsedMs < $minimumElapsedMs) {
    throw new RuntimeException('worker_did_not_block:elapsed=' . $elapsedMs);
}
echo 'WORKER=' . $role . ' VERSION=' . (int)$snapshot['version'] . ' ELAPSED_MS=' . $elapsedMs . "\n";
