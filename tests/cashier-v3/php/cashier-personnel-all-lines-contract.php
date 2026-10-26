<?php
declare(strict_types=1);

/** Regression contract for mixed personnel assignment on sale-only carts. */
$root = dirname(__DIR__, 3);
$modulePath = $root . '/后端代码/app/services/cashier/v3/cashier/CashierV3CashierModule.php';
$module = is_file($modulePath) ? (string)file_get_contents($modulePath) : '';

$checks = [
    'combined_action_catches_only_missing_service_lines' => strpos($module, "service_lines_missing_for_apply_all") !== false,
    'combined_action_reloads_draft_after_skipping_craftsmen' => strpos($module, 'readDraft($workspaceId, $stateContextId, $scope[\'operator_scope\'], true)') !== false,
    'combined_action_still_applies_salespeople' => strpos($module, 'applySalespeopleToAllSaleLinesInTx(') !== false,
    'combined_action_catches_only_missing_sale_lines' => strpos($module, "sale_lines_missing_for_apply_all") !== false,
    'combined_action_reloads_draft_after_skipping_salespeople' => substr_count($module, 'readDraft($workspaceId, $stateContextId, $scope[\'operator_scope\'], true)') >= 3,
    'combined_action_returns_draft_when_no_role_applies' => strpos($module, 'if ($draft === null)') !== false,
];

$failed = 0;
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS ' : 'FAIL ') . $name . "\n";
    if (!$passed) $failed++;
}
echo 'cashier-personnel-all-lines-contract: passed=' . (count($checks) - $failed) . ' failed=' . $failed . "\n";
exit($failed === 0 ? 0 : 1);
