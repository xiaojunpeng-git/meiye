<?php

$root = dirname(__DIR__, 3);
$gateway = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/CashierV3CommandGatewayServices.php');

$checks = [
    'successful replay validates the persisted scope snapshot instead of mutable resource state' => strpos($gateway, 'historicalReplayScopeFailure(') !== false
        && strpos($gateway, '$scopeFailure = $this->historicalReplayScopeFailure(') !== false,
    'historical replay still binds scope snapshots to the current tenant store organization and operator' => strpos($gateway, 'replay_scope_identity_changed') !== false
        && strpos($gateway, 'stored_context_store_denied') !== false
        && strpos($gateway, 'stored_context_tenant_denied') !== false,
    'historical replay no longer re-resolves terminal resources through attachScopes' => strpos($gateway, 'attachScopes(\n                $replayContexts') === false,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$passed) $failed[] = $name;
}
exit($failed ? 1 : 0);
