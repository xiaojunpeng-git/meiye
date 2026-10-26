<?php

$root = dirname(__DIR__, 3);
$gateway = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/CashierV3CommandGatewayServices.php');

$checks = [
    'successful replay validates the persisted scope snapshot instead of mutable resource state' => strpos($gateway, 'historicalReplayScopeFailure(') !== false
        && strpos($gateway, ': $this->historicalReplayScopeFailure($storedContexts, $operatorScope, $txnDataScope)') !== false,
    'historical replay still binds scope snapshots to the current tenant store organization and operator' => strpos($gateway, 'replay_scope_identity_changed') !== false
        && strpos($gateway, 'stored_context_store_denied') !== false
        && strpos($gateway, 'stored_context_tenant_denied') !== false,
    'historical replay no longer re-resolves terminal resources through attachScopes' => strpos($gateway, 'attachScopes(\n                $replayContexts') === false,
    'empty-context refund replay checks committed operation and current store scope' => strpos($gateway, "['refund-card-or-line', 'adjust-refund-personnel']") !== false
        && strpos($gateway, 'unifiedRefundReplayScopeFailure(') !== false
        && strpos($gateway, "->where('command_idempotency_key', (string)(\$existing['idempotency_key'] ?? ''))") !== false
        && strpos($gateway, 'refund_replay_store_denied') !== false,
    'empty-context refund personnel replay checks its own audit and operator scope' => strpos($gateway, 'refundPersonnelReplayScopeFailure(') !== false
        && strpos($gateway, "->where('r.operator_id', \$operatorScope->operatorId())") !== false
        && strpos($gateway, 'refund_personnel_replay_revision_missing') !== false,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$passed) $failed[] = $name;
}
exit($failed ? 1 : 0);
