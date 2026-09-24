<?php

$root = dirname(__DIR__, 3);
$read = static function (string $path): string {
    $value = file_get_contents($path);
    if ($value === false) {
        fwrite(STDERR, "cannot read {$path}\n");
        exit(1);
    }
    return $value;
};
$assert = static function (string $name, bool $condition): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL {$name}\n");
        exit(1);
    }
    echo "PASS {$name}\n";
};

$preparation = $read($root . '/后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutPreparationServices.php');
$workspace = $read($root . '/后端代码/app/services/cashier/v3/cashier/CashierV3CashierWorkspaceServices.php');
$repository = $read($root . '/后端代码/app/services/cashier/v3/settlement/ThinkPhpCashierV3CheckoutRequestRepository.php');
$binding = $read($root . '/后端代码/app/services/cashier/v3/hang/CashierV3HangCheckoutBindingServices.php');
$module = $read($root . '/后端代码/app/services/cashier/v3/cashier/CashierV3CashierModule.php');
$salesPlan = $read($root . '/后端代码/app/services/cashier/v3/order/settlement/CashierV3SalesOrderPlanV1.php');
$factAssembler = $read($root . '/后端代码/app/services/cashier/v3/fact/CashierV3SaleOnlyFactAssembler.php');
$paymentPlan = $read($root . '/后端代码/app/services/cashier/v3/settlement/payment/CashierV3PaymentCollectionPlanV1.php');

$assert('resumed hang is not rediscovered as a checkout resource', strpos($preparation, 'discoverForWorkspaceDraft') === false);
$assert('resumed hang is not validated as a checkout source during prepare', strpos($preparation, 'assertWorkspaceBindingInTx') === false);
$assert('resumed hang is excluded from checkout source-document kinds', strpos($preparation, "'hang_order', 'service_order'") === false);
$assert('prepared request stores only an internal resumed-hang reference', strpos($preparation, 'bindResumedHangOrderInTx') !== false);
$assert('snapshot checkout inherits the server-side resumed-hang reference', strpos($preparation, 'checkoutDraftMetadata(') !== false
    && strpos($preparation, "'resumed_hang_order_id' => (string)(\$workspaceMetadata['resumed_hang_order_id'] ?? '')") !== false);
$assert('resumed-hang metadata cannot be supplied by the browser snapshot', strpos($workspace, 'public function checkoutDraftMetadata(') !== false
    && strpos($workspace, "'resumed_hang_order_id' => trim((string)(\$draft['resumed_hang_order_id'] ?? ''))") !== false);
$assert('fresh browser-snapshot checkout treats a missing server draft as a non-hang checkout', strpos($workspace, 'if (!$draft) {') !== false
    && strpos($workspace, "return ['resumed_hang_order_id' => ''];") !== false
    && strpos($workspace, '$this->assertWorkspaceIdentity($workspaceId, $stateContextId, $operatorScope);') !== false);
$assert('request repository binds the reference only while editing', strpos($repository, "->where('request_status', 'editing')") !== false);
$assert('completion reads the request-local internal reference', strpos($binding, "resumed_hang_order_id") !== false);
$assert('completion no longer scans generic checkout sources for the hang', strpos($binding, 'function hangSource') === false);
$assert('submission preparation does not lock or rediscover the resumed draft', strpos($preparation, 'discoverSettlementCleanupResource') === false);
$assert('completion physically deletes the request-local draft without re-reading it', strpos($binding, 'readResumedHeader(') !== false
    && strpos($binding, "->where('hang_order_id', \$hangOrderId)\n            ->delete();") !== false);
$submissionPolicyOffset = strpos($module, 'private static function registerSubmitCheckoutPolicy');
$submissionPolicyEnd = $submissionPolicyOffset === false
    ? false
    : strpos($module, '$dispatcher->policies()->register($policy);', $submissionPolicyOffset);
$submissionPolicy = $submissionPolicyOffset === false
    ? ''
    : substr($module, $submissionPolicyOffset, $submissionPolicyEnd === false ? null : $submissionPolicyEnd - $submissionPolicyOffset);
$assert('submission policy does not declare the internal resumed-draft reference', $submissionPolicy !== ''
    && strpos($submissionPolicy, "'resumed_hang_order_id'") === false);
$assert('sales plan accepts the internal cleanup reference', strpos($salesPlan, "'resumed_hang_order_id'") !== false);
$assert('fact assembler accepts the internal cleanup reference', strpos($factAssembler, "'resumed_hang_order_id'") !== false);
$assert('payment plan accepts the internal cleanup reference', strpos($paymentPlan, "'resumed_hang_order_id'") !== false);

echo "PASS hang draft checkout decoupling contract\n";
