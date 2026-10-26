<?php
$root = dirname(__DIR__, 3);
$migration = (string)file_get_contents($root . '/后端代码/database/upgrades/2026-08-13-收银V3销售经理快照/02-正式升级.sql');
$service = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/report/CashierV3SalesManagerFactServices.php');
$workspace = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/cashier/CashierV3CashierWorkspaceServices.php');
$submission = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/settlement/CashierV3SaleOnlyCheckoutSubmissionServices.php');
$report = (string)file_get_contents($root . '/后端代码/app/services/report/StoreUnifiedReportServices.php');
$checks = [
    'manager fact and workspace snapshot are immutable inputs' => strpos($migration, 'eb_cashier_v3_sales_manager_fact') !== false && strpos($migration, 'sales_manager_selections_json') !== false && strpos($migration, 'immutable_fingerprint') !== false,
    'manager fact stores independent allocation money' => strpos($service, 'sales_manager_employee_id') !== false && strpos($service, 'allocation_base_amount_cents') !== false && strpos($service, 'amount_cents') !== false && strpos($service, 'allocationWeight') !== false,
    'group employee is server re-read and locked' => strpos($service, "Db::name('employee')") !== false && strpos($service, 'where(\'status\', 1)') !== false && strpos($service, 'lock(true)') !== false,
    'workspace accepts and re-reads manager selections' => strpos($workspace, 'salesManagerSelections') !== false && strpos($workspace, 'authoritativeSalesManagerSelectionsInTx') !== false,
    'checkout writes manager fact inside transaction' => strpos($submission, 'CashierV3SalesManagerFactServices') !== false && strpos($submission, 'salesManagerSelectionsByLine') !== false && strpos($submission, 'persistInTx') !== false,
    'report filters by manager snapshot' => strpos($report, 'sales_manager_id') !== false && strpos($report, 'cashier_v3_sales_manager_fact') !== false,
];
$failed = [];
foreach ($checks as $name => $ok) { echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL; if (!$ok) $failed[] = $name; }
exit($failed ? 1 : 0);
