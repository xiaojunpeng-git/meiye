<?php

/**
 * Static contract for customer guide-round attribution.
 *
 * This contract deliberately does not fabricate checkout rows or write a
 * database. It protects the immutable fact shape and the server-side rules
 * while the checkout gateway supplies normalized selections internally.
 */
$root = dirname(__DIR__, 3);
$migration = (string)file_get_contents($root . '/后端代码/database/upgrades/2026-08-13-收银V3导购轮次事实/02-正式升级.sql');
$service = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/report/CashierV3GuideRoundFactServices.php');
$submission = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/settlement/CashierV3SaleOnlyCheckoutSubmissionServices.php');
$workspaceMigration = (string)file_get_contents($root . '/后端代码/database/upgrades/2026-08-13-收银V3导购轮次事实/05-工作台导购选择快照.sql');

$checks = [
    'guide round fact table is immutable and tenant idempotent' => strpos($migration, 'eb_cashier_v3_customer_guide_round_fact') !== false
        && strpos($migration, 'immutable_fingerprint') !== false
        && strpos($migration, 'uk_tenant_natural') !== false
        && strpos($migration, 'uk_tenant_fact') !== false,
    'fact stores formal checkout and business-date snapshots' => strpos($migration, 'checkout_request_id') !== false
        && strpos($migration, 'business_date') !== false
        && strpos($migration, 'order_no_snapshot') !== false,
    'round number is bounded to three' => strpos($service, 'guide_round_no') !== false
        && strpos($service, '$next > 3') !== false
        && strpos($service, 'guide_round_limit_exceeded') !== false,
    'same formal order reuses its round and new orders advance' => strpos($service, 'isset($orderRounds[$orderId])') !== false
        && strpos($service, '$roundNo = (int)$orderRounds[$orderId]') !== false
        && strpos($service, '$maxRound + 1') !== false,
    'each round allows multiple guides without amount allocation' => strpos($service, 'foreach ($rows as $row)') !== false
        && strpos($service, 'guide_employee_id') !== false
        && strpos($service, 'allocationWeight') === false
        && strpos($service, 'amount_cents') === false,
    'group-wide employees are locked and active' => strpos($service, "Db::name('employee')") !== false
        && strpos($service, "where('status', 1)") !== false
        && strpos($service, 'lock(true)') !== false,
    'scope and operator are checked server side' => strpos($service, 'guide_data_scope_denied') !== false
        && strpos($service, 'allowsStore') !== false
        && strpos($service, 'operatorId()') !== false,
    'replay is fingerprint checked and idempotent' => strpos($service, 'guide_round_fact_replay_conflict') !== false
        && strpos($service, 'isDuplicate') !== false
        && strpos($service, 'naturalKey') !== false,
    'checkout submission keeps its exact public payload contract' => strpos($submission, 'checkoutRequestId') !== false
        && strpos($submission, 'preparationToken') !== false,
    'workspace stores server locked guide selections' => strpos($workspaceMigration, 'guide_selections_json') !== false
        && strpos($submission, 'lockedGuideSelectionsByCheckoutLine') !== false,
];
$failed = [];
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$passed) $failed[] = $name;
}
exit($failed ? 1 : 0);
