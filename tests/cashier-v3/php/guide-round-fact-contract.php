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
$guestMigration = (string)file_get_contents($root . '/后端代码/database/upgrades/2026-09-22-游客导购无轮次归属/01-正式升级.sql');
$service = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/report/CashierV3GuideRoundFactServices.php');
$submission = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/settlement/CashierV3SaleOnlyCheckoutSubmissionServices.php');
$workspace = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/cashier/CashierV3CashierWorkspaceServices.php');
$workspaceMigration = (string)file_get_contents($root . '/后端代码/database/upgrades/2026-08-13-收银V3导购轮次事实/05-工作台导购选择快照.sql');
$draftAuthorityRebuilder = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutDraftAuthorityRebuilder.php');
$checkoutProjection = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutProjectionServices.php');
$checkoutKernel = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutSettlementKernel.php');
$salesOrderPlan = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/order/settlement/CashierV3SalesOrderPlanV1.php');
$hangDraftController = (string)file_get_contents($root . '/后端代码/app/controller/cashier/v3/HangDraft.php');
$hangDraftRoute = (string)file_get_contents($root . '/后端代码/route/cashier-v3.php');

$checks = [
    'guide round fact table is immutable and tenant idempotent' => strpos($migration, 'eb_cashier_v3_customer_guide_round_fact') !== false
        && strpos($migration, 'immutable_fingerprint') !== false
        && strpos($migration, 'uk_tenant_natural') !== false
        && strpos($migration, 'uk_tenant_fact') !== false,
    'fact stores formal checkout and business-date snapshots' => strpos($migration, 'checkout_request_id') !== false
        && strpos($migration, 'business_date') !== false
        && strpos($migration, 'order_no_snapshot') !== false,
    'round number is bounded to three' => strpos($service, 'guide_round_no') !== false
        && strpos($service, 'guide_round_required') !== false
        && strpos($service, '$roundNo < 1 || $roundNo > 3') !== false,
    'guest guide keeps attribution without inventing round zero' => strpos($guestMigration, 'guide_round_no` tinyint(1) unsigned NULL') !== false
        && strpos($service, "'guide_round_no' => \$roundNo === 0 ? null : \$roundNo") !== false
        && strpos($service, 'assertCustomerRound') !== false
        && strpos($service, '$memberId === 0 && $roundNo !== 0') !== false,
    'round is explicitly selected and an order cannot mix rounds' => strpos($service, 'guideRoundNo') !== false
        && strpos($service, 'guide_round_conflict') !== false
        && strpos($service, 'guide_round_order_conflict') !== false,
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
    'guide save only persists a validated guide and round snapshot' => strpos($workspace, 'authoritativeGuideSelectionsInTx') !== false
        && strpos($workspace, 'assertGuideRoundDateInTx') === false
        && strpos($workspace, 'guide_round_date_limit_exceeded') === false,
    'formal checkout rejects only a same round used on another date' => strpos($service, 'guide_round_date_conflict:') !== false
        && strpos($service, '$round === $roundNo && (string)$row[\'business_date\'] !== $businessDate') !== false
        && strpos($service, 'guide_date_round_conflict') === false,
    'only settled normal sales orders occupy a guide round' => strpos($service, "->where('sales_order.order_status', 'settled')") !== false
        && strpos($service, "->whereIn('lifecycle.operation_type', ['refund', 'void'])") !== false,
    'cross-date conflict returns the occupied settlement date to the cashier' => strpos($submission, 'guide_round_date_conflict:(\d{4}-\d{2}-\d{2})') !== false
        && strpos($submission, '该会员已于 ') !== false,
    'guide round has no payment-step preflight endpoint' => strpos($hangDraftController, 'public function validateGuideRound()') === false
        && strpos($hangDraftRoute, "cashier-drafts/validate-guide-round") === false
        && strpos($submission, 'guide_round_date_conflict:') !== false,
    'guide round survives every checkout snapshot rebuild' => strpos($draftAuthorityRebuilder, "\$snapshot['guideRoundNo'] = \$roundNo") !== false
        && strpos($checkoutProjection, "\$snapshot['guideRoundNo'] = \$roundNo") !== false
        && strpos($checkoutKernel, "\$normalizedSelection['guideRoundNo'] = \$roundNo") !== false
        && strpos($salesOrderPlan, "\$snapshot['guideRoundNo'] = \$roundNo") !== false
        && strpos($draftAuthorityRebuilder, 'checkout_draft_guide_round_invalid') !== false
        && strpos($checkoutProjection, 'checkout_projection_guide_round_invalid') !== false
        && strpos($salesOrderPlan, 'sales_order_guide_round_invalid') !== false,
];
$failed = [];
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$passed) $failed[] = $name;
}
exit($failed ? 1 : 0);
