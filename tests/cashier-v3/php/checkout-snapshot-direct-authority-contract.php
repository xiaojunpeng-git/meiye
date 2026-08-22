<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$backend = $root . '/后端代码';
require_once $backend . '/vendor/autoload.php';

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3RequestNormalizer;

$passed = 0;
$failed = 0;
$check = static function (string $name, bool $condition) use (&$passed, &$failed): void {
    $condition ? $passed++ : $failed++;
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;
};

$payload = [
    'preparationRequestId' => 'CHECKOUT_PREPARE-00000000-0000-4000-8000-000000000001',
    'checkoutSnapshot' => [
        'contractVersion' => 'cashier-v3-checkout-snapshot-v1',
        'revision' => 99,
        'request_version' => 7,
        'state_context_id' => 'ctx-old',
        'preparation_request_id' => 'CHECKOUT_PREPARE-old',
        'snapshotToken' => 'old-token',
        'customerMode' => 'member',
        'memberId' => 7,
        'occurredAt' => 1787299200,
        'customerSource' => ['id' => 12, 'name' => '老客转介绍'],
        'coupon' => ['id' => 44, 'amountCents' => 300],
        'salesManagerSelections' => [['id' => 8, 'name' => '经理甲']],
        'lines' => [
            ['id' => 'local-sale-1', 'lineRole' => 'sale', 'itemId' => 101, 'quantity' => 2, 'revision' => 11, 'salespeople' => [], 'inventoryOutboundRequired' => 0, 'isPresale' => 1],
            [
                'id' => 'local-entitlement-1', 'lineRole' => 'entitlement_service',
                'entitlementInstanceId' => 201, 'entitlementSourceDetailId' => 202,
                'entitlementSourceVersion' => 3, 'projectId' => 203,
                'projectVersion' => 4, 'quantity' => 1, 'craftsmen' => [],
            ],
        ],
    ],
];
$payload = ['checkoutSnapshot' => $payload['checkoutSnapshot']];
$normalized = CashierV3RequestNormalizer::normalize('submit-checkout', $payload)['normalized'];
$snapshot = $normalized['checkoutSnapshot'] ?? [];
$check('snapshot drops generated version and token metadata',
    !array_key_exists('contractVersion', $snapshot)
    && !array_key_exists('revision', $snapshot)
    && !array_key_exists('request_version', $snapshot)
    && !array_key_exists('state_context_id', $snapshot)
    && !array_key_exists('preparation_request_id', $snapshot)
    && !array_key_exists('snapshotToken', $snapshot)
    && !array_key_exists('revision', $snapshot['lines'][0] ?? [])
);
$check('snapshot member binding is normalized', ($snapshot['customerMode'] ?? '') === 'member' && ($snapshot['memberId'] ?? 0) === 7);
$check('snapshot retains the checkout-time boundary', ($snapshot['occurredAt'] ?? 0) === 1787299200);
$check('snapshot lines retain canonical role identity and quantity',
    ($snapshot['lines'][0]['lineId'] ?? '') === 'local-sale-1'
    && ($snapshot['lines'][0]['itemId'] ?? 0) === 101
    && ($snapshot['lines'][0]['quantity'] ?? 0) === 2
    && ($snapshot['lines'][1]['lineRole'] ?? '') === 'entitlement_service'
    && ($snapshot['lines'][1]['entitlementSourceDetailId'] ?? 0) === 202);
$check('snapshot retains non-authoritative interaction metadata for final persistence',
    ($snapshot['customerSource']['id'] ?? 0) === 12
    && ($snapshot['coupon']['id'] ?? 0) === 44
    && ($snapshot['salesManagerSelections'][0]['id'] ?? 0) === 8
    && ($snapshot['lines'][0]['inventoryOutboundRequired'] ?? null) === 0
    && ($snapshot['lines'][0]['isPresale'] ?? null) === 1);

$duplicate = $payload;
$duplicate['checkoutSnapshot']['lines'][1]['id'] = 'local-sale-1';
$rejected = false;
try {
    CashierV3RequestNormalizer::normalize('submit-checkout', ['checkoutSnapshot' => $duplicate['checkoutSnapshot']]);
} catch (CashierV3CommandException $exception) {
    $rejected = ($exception->getDetail()['reason'] ?? '') === 'line_identity_invalid';
}
$check('duplicate snapshot line identity is rejected', $rejected);

$invalidBinding = $payload;
$invalidBinding['checkoutSnapshot']['memberId'] = 0;
$rejected = false;
try {
    CashierV3RequestNormalizer::normalize('submit-checkout', ['checkoutSnapshot' => $invalidBinding['checkoutSnapshot']]);
} catch (CashierV3CommandException $exception) {
    $rejected = ($exception->getDetail()['reason'] ?? '') === 'member_binding_invalid';
}
$check('member snapshot cannot be submitted without a member id', $rejected);

$guest = CashierV3RequestNormalizer::normalize('submit-checkout', ['checkoutSnapshot' => [
    'occurredAt' => 1787299200,
    'customerMode' => 'guest',
    'memberId' => 0,
    'lines' => [[
        'id' => 'guest-sale-1',
        'lineRole' => 'sale',
        'itemId' => 102,
        'quantity' => 1,
    ]],
]])['normalized']['checkoutSnapshot'] ?? [];
$check('guest snapshot retains explicit guest identity for final checkout',
    ($guest['customerMode'] ?? '') === 'guest' && ($guest['memberId'] ?? -1) === 0);

$invalidGuest = false;
try {
    CashierV3RequestNormalizer::normalize('submit-checkout', ['checkoutSnapshot' => [
        'customerMode' => 'guest',
        'memberId' => 7,
        'lines' => [[
            'id' => 'invalid-guest-sale',
            'lineRole' => 'sale',
            'itemId' => 102,
            'quantity' => 1,
        ]],
    ]]);
} catch (CashierV3CommandException $exception) {
    $invalidGuest = ($exception->getDetail()['reason'] ?? '') === 'member_binding_invalid';
}
$check('guest snapshot cannot carry a member id', $invalidGuest);

$service = file_get_contents($backend . '/app/services/cashier/v3/settlement/CashierV3CheckoutPreparationServices.php');
$cashierModule = file_get_contents($backend . '/app/services/cashier/v3/cashier/CashierV3CashierModule.php');
$cardIssuer = file_get_contents($backend . '/app/services/cashier/v3/card/CashierV3CardPurchaseIssuanceServices.php');
$saleInventory = file_get_contents($backend . '/app/services/cashier/v3/settlement/CashierV3SaleInventorySettlementServices.php');
$inventoryPolicy = file_get_contents($backend . '/app/services/cashier/v3/checkout/provider/CashierV3InventoryResourceVersionProvider.php');
$performanceRule = file_get_contents($backend . '/app/services/cashier/v3/checkout/provider/CashierV3PerformanceRuleProvider.php');
$cardOperationAuthority = file_get_contents($backend . '/app/services/cashier/v3/card/CashierV3CardOperationAuthorityServices.php');
$cardRuleAuthority = file_get_contents($backend . '/app/services/cashier/v3/card/CashierV3CardRuleEntitlementAuthorityServices.php');
$entitlementAdapter = file_get_contents($backend . '/app/services/cashier/v3/checkout/CashierV3DirectSnapshotEntitlementSettlementServices.php');
$entitlementKernel = file_get_contents($backend . '/app/services/cashier/v3/checkout/CashierV3EntitlementCompletionKernel.php');
$rebuilder = file_get_contents($backend . '/app/services/cashier/v3/settlement/CashierV3CheckoutDraftAuthorityRebuilder.php');
$executionPort = file_get_contents($backend . '/app/services/cashier/v3/settlement/ThinkPhpCashierV3CheckoutSubmissionExecutionPort.php');
$saleOnly = file_get_contents($backend . '/app/services/cashier/v3/settlement/CashierV3SaleOnlyCheckoutSubmissionServices.php');
$workspace = file_get_contents($backend . '/app/services/cashier/v3/cashier/CashierV3CashierWorkspaceServices.php');
$contextPolicy = file_get_contents($backend . '/app/services/cashier/v3/registry/CashierV3ContextPolicy.php');
$commandGateway = file_get_contents($backend . '/app/services/cashier/v3/CashierV3CommandGatewayServices.php');
$entitlementProjection = file_get_contents($backend . '/app/services/cashier/v3/cashier/CashierV3EntitlementProjectionServices.php');
$start = is_string($service) ? strpos($service, 'public function prepareInTx') : false;
$end = is_string($service) ? strpos($service, '$authority = $this->workspace->checkoutSaleSourceSetInTx', $start === false ? 0 : $start) : false;
$prefix = $start === false || $end === false ? '' : substr($service, $start, $end - $start);
$check('snapshot path builds authority before any workspace materializer',
    str_contains($prefix, 'authorityFromCheckoutSnapshot(')
    && !str_contains($prefix, 'materializeCheckoutSnapshotInTx(')
    && !str_contains($prefix, 'checkoutSaleSourceSetInTx('));
$check('snapshot resource discovery skips payment rows for final settlement validation',
    is_string($service)
    && str_contains($service, "in_array(\$role, ['payment', 'balance_payment'], true)")
    && str_contains($service, "authorityFromCheckoutSnapshot")
    && str_contains($service, "if (\$role === 'payment')"));
$check('browser snapshot authority does not depend on workspace projection version',
    is_string($service)
    && str_contains($service, "\$authoritySnapshotVersion = \$browserSnapshot !== []")
    && str_contains($service, "? 1\n            : \$this->contextVersion(\$contexts, 'cashier_workspace', \$workspaceId)")
);
$check('no row-by-row snapshot workspace materializer remains',
    is_string($service) && !str_contains($service, 'function materializeCheckoutSnapshotInTx'));
$check('snapshot completion clears the shell without comparing legacy workspace lines',
    is_string($service) && str_contains($service, "'type' => 'cashier_snapshot'")
    && is_string($executionPort) && str_contains($executionPort, 'completeSnapshotCheckoutInTx('));
$check('snapshot salespeople never fall back to legacy workspace rows',
    is_string($saleOnly)
    && str_contains($saleOnly, "source_document_type'] ?? '') === 'cashier_snapshot'")
    && is_string($executionPort)
    && str_contains($executionPort, "source_document_type'] ?? '') === 'cashier_snapshot'")
    && substr_count($saleOnly, 'lockedSalespeopleByCheckoutLineInTx(') === 1
    && substr_count($executionPort, 'lockedSalespeopleByCheckoutLineInTx(') === 1);
$check('custom-card snapshot derives its member binding from the same browser snapshot',
    is_string($service)
    && str_contains($service, "(int)(\$snapshot['memberId'] ?? 0),\n                        true")
    && str_contains($service, 'createSaleLineAfterGatewayLocksInTx('));
$check('empty salesperson snapshot is a valid no-salesperson selection',
    is_string($workspace)
    && str_contains($workspace, 'A persisted snapshot with no salesperson is a valid empty')
    && str_contains($workspace, '$result[$checkoutLineId] = [];'));
$catalog = file_get_contents($backend . '/app/services/cashier/v3/cashier/CashierV3SaleCatalogServices.php');
$check('snapshot sale authority preserves locked catalog category for service facts',
    is_string($service)
    && str_contains($service, "'categoryIdSnapshot' => (int)(\$server['category_id_snapshot'] ?? \$server['categoryId'] ?? 0)")
    && is_string($catalog)
    && str_contains($catalog, "'category_id_snapshot' => (int)(\$normalized['categoryId'] ?? 0)"));
$check('snapshot entitlement authority preserves selected service settings',
    is_string($service)
    && str_contains($service, "'craftsmen' => is_array(\$line['craftsmen'] ?? null) ? \$line['craftsmen'] : []")
    && str_contains($service, "'serviceObject' => (string)(\$line['serviceObject'] ?? '')"));
$check('direct entitlement snapshot preserves source and project display identity',
    is_string($entitlementAdapter)
    && str_contains($entitlementAdapter, "'source_name_snapshot' => (string)(")
    && str_contains($entitlementAdapter, "'source_code_snapshot' => (string)(")
    && str_contains($entitlementAdapter, "'project_name_snapshot' => (string)("));
$check('direct entitlement snapshot preserves the browser actual amount for kernel comparison',
    is_string($entitlementAdapter)
    && str_contains($entitlementAdapter, '\'entitlement_actual_amount_cents\' => $this->snapshotActualAmountCents(')
    && str_contains($entitlementAdapter, '$line[\'actualAmount\'] ?? $line[\'actualEntitlementAmount\'] ?? null')
    && str_contains($entitlementAdapter, 'private function snapshotActualAmountCents($amount): int')
    && str_contains($entitlementAdapter, 'is_float($amount)')
    && str_contains($entitlementAdapter, "authority_checkout_actual_amount_invalid"));
$check('final entitlement snapshot does not apply current-time or validity-window blockers',
    is_string($entitlementAdapter)
    && !str_contains($entitlementAdapter, 'authority_entitlement_not_usable_at_settlement')
    && !str_contains($entitlementAdapter, '$validityStart')
    && !str_contains($entitlementAdapter, '$validityEnd'));
$check('direct entitlement eligibility reads the checkout snapshot time, not the server audit time',
    is_string($entitlementAdapter)
    && str_contains($entitlementAdapter, "\$request['operation_occurred_at'] ?? null")
    && str_contains($entitlementAdapter, "'authority_locked_snapshot_occurred_at_invalid'")
    && !str_contains($entitlementAdapter, "\$request['occurred_at'] ?? null")
    && is_string($cardRuleAuthority)
    && str_contains($cardRuleAuthority, "\$occurredAt = (int)(\$context['occurred_at'] ?? 0);")
    && !str_contains($cardRuleAuthority, "\$occurredAt = (int)(\$context['recorded_at'] ?? 0);"));
$check('entitlement-only snapshot clears stale payment projection',
    is_string($service)
    && str_contains($service, "if (\$saleLines === [])")
    && str_contains($service, "\$snapshot['payment'] = ['selectedLines' => []]")
    && str_contains($service, "\$snapshot['balancePaymentAmount'] = \$snapshotBalanceAmount"));
$check('entitlement service intent is decoded separately from sale personnel snapshot',
    is_string($rebuilder)
    && str_contains($rebuilder, "'craftsmen' => self::entitlementCraftsmenSnapshot(")
    && str_contains($rebuilder, 'private static function entitlementCraftsmenSnapshot($json)')
    && str_contains($rebuilder, 'checkout_draft_entitlement_craftsmen_weight_invalid'));
$check('final snapshot sale validation does not reject product lifecycle status',
    is_string($saleOnly)
    && !str_contains($saleOnly, "->where('is_del', 0)")
    && is_string($catalog)
    && str_contains($catalog, 'function selectCheckoutSaleLineInTx(')
    && is_string($cardIssuer)
    && str_contains($cardIssuer, 'selectCheckoutSaleLineInTx('));
$cardPurchaseStart = is_string($catalog) ? strpos($catalog, 'private function cardPurchaseSnapshot(') : false;
$cardPurchaseEnd = is_string($catalog) ? strpos($catalog, 'private static function cardRuleLabel(', $cardPurchaseStart === false ? 0 : $cardPurchaseStart) : false;
$cardPurchaseSnapshot = $cardPurchaseStart === false || $cardPurchaseEnd === false
    ? '' : substr($catalog, $cardPurchaseStart, $cardPurchaseEnd - $cardPurchaseStart);
$check('card components in a final snapshot do not reapply catalogue lifecycle state',
    $cardPurchaseSnapshot !== ''
    && !str_contains($cardPurchaseSnapshot, 'isCardComponentSnapshotEligible')
    && !str_contains($cardPurchaseSnapshot, "['isDeleted']")
    && !str_contains($cardPurchaseSnapshot, "['isVerified']"));
$check('card purchase definition is persisted from the one browser snapshot through issuance',
    is_string($service)
    && str_contains($service, 'browserCardPurchaseSnapshot($line, $server)')
    && str_contains($service, "'cardPurchaseSnapshot' => \$cardPurchaseSnapshot")
    && is_string($cardIssuer)
    && str_contains($cardIssuer, "\$salesLine['card_purchase_snapshot_json']")
    && str_contains($cardIssuer, 'salesLineCardPurchaseSnapshot($salesLine)'));
$check('final snapshot inventory locks identities without applying product or SKU lifecycle flags',
    is_string($saleInventory)
    && str_contains($saleInventory, "->field('id,type,relation_id,product_type,is_inventory,store_name')")
    && str_contains($saleInventory, "->field('id,product_id,unique,type')")
    && !str_contains($saleInventory, "(int)\$row['is_show']")
    && !str_contains($saleInventory, "(int)\$row['is_verify']")
    && !str_contains($saleInventory, "(int)\$row['is_del']")
    && !str_contains($saleInventory, "(int)\$sku['is_show']"));
$check('entitlement inventory authority does not reject an already-sold project by catalogue lifecycle',
    is_string($inventoryPolicy)
    && !str_contains($inventoryPolicy, "->where('is_del', 0)")
    && str_contains($inventoryPolicy, 'private function project(int $projectId, int $storeId): array'));
$check('entitlement performance authority does not reject an already-sold project by catalogue lifecycle',
    is_string($performanceRule)
    && !str_contains($performanceRule, "->where('is_del', 0)")
    && str_contains($performanceRule, 'private function assertProject(int $projectId): void'));
$projection = file_get_contents($backend . '/app/services/cashier/v3/settlement/CashierV3CheckoutProjectionServices.php');
$check('snapshot salesperson array participates in projection fingerprint',
    is_string($projection)
    && str_contains($projection, '$salespeopleJson = $row[\'salespeople_snapshot_json\'] ?? null;')
    && str_contains($projection, "\$fingerprintInput['salespeople'] = \$salespeople;")
    && str_contains($projection, 'checkout_projection_salespeople_weight_invalid'));
$check('snapshot projection verifies the persisted card definition fingerprint',
    is_string($projection)
    && str_contains($projection, "\$row['card_purchase_snapshot_json']")
    && str_contains($projection, "\$fingerprintInput['cardPurchaseSnapshot']"));
$repository = file_get_contents($backend . '/app/services/cashier/v3/settlement/ThinkPhpCashierV3CheckoutRequestRepository.php');
$check('snapshot projection keeps a positive workspace version when the shell is uninitialized',
    is_string($repository)
    && str_contains($repository, "'workspaceCurrentVersion' => \$isSnapshot\n                ? max(\$workspaceVersion, 1)\n                : \$workspaceVersion,"));
$check('direct snapshot submit does not report browser workspace mutations',
    is_string($cashierModule)
    && str_contains($cashierModule, "\$isBrowserSnapshot = is_array(\$payload['checkoutSnapshot'] ?? null)")
    && str_contains($cashierModule, "if (\$isBrowserSnapshot) {\n                \$touched = [];\n            }"));
$check('direct snapshot execution payload is an explicit internal three-field contract',
    is_string($cashierModule)
    && str_contains($cashierModule, "'checkoutRequestId' => (string)\$prepared['checkoutRequestId']")
    && str_contains($cashierModule, "'checkoutRequestVersion' => (int)\$promoted['checkoutRequestVersion']")
    && str_contains($cashierModule, "'preparationRequestId' => (string)\$submissionPreparationScope['idempotency_key']"));
$check('direct entitlement snapshot never locks or passes the legacy workspace resource',
    is_string($entitlementAdapter)
    && str_contains($entitlementAdapter, "\$directSnapshot = !empty(\$scope['direct_snapshot_submission']);")
    && str_contains($entitlementAdapter, "\$workspaceVersion = \$directSnapshot\n            ? 1")
    && str_contains($entitlementAdapter, "\$this->kernelResourceSubset(\$gateway, \$snapshot, \$directSnapshot)")
    && str_contains($entitlementAdapter, 'if (!$directSnapshot) {'));
$check('direct entitlement snapshot carries a server-derived workspace-lock boundary into the kernel',
    is_string($entitlementAdapter)
    && str_contains($entitlementAdapter, "'workspaceLockRequired' => \$workspaceLockRequired")
    && str_contains($entitlementAdapter, ' !$directSnapshot')
    && is_string($entitlementKernel)
    && str_contains($entitlementKernel, "if (\$snapshot['workspaceLockRequired']) {")
    && str_contains($entitlementKernel, "'workspace_lock_requirement_invalid'"));
$check('direct sale snapshot does not require the removed browser preparation token',
    is_string($saleOnly)
    && str_contains($saleOnly, "self::assertPayload(\$payload, !empty(\$scope['direct_snapshot_submission']));")
    && str_contains($saleOnly, 'private static function assertPayload(array $payload, bool $directSnapshot = false): void')
    && str_contains($saleOnly, "if (!\$directSnapshot) {\n            \$expected[] = 'preparationToken';")
    && str_contains($saleOnly, "(!\$directSnapshot && (!is_string(\$payload['preparationToken'] ?? null)"));
$check('direct sale snapshot does not require browser workspace contexts',
    is_string($saleOnly)
    && str_contains($saleOnly, "if (\$directSnapshotSubmission) {\n            return;\n        }\n        \$found = [];"));
$check('direct snapshot result does not require a browser projection version bump',
    is_string($contextPolicy)
    && str_contains($contextPolicy, "\$out['allows_empty_touched_result'] = true;")
    && is_string($commandGateway)
    && str_contains($commandGateway, "|| !empty(\$contract['allows_empty_touched_result'])"));
$dispatcher = file_get_contents($backend . '/app/services/cashier/v3/CashierV3ActionDispatcher.php');
$check('direct snapshot response bypasses legacy root projection replay',
    is_string($dispatcher)
    && str_contains($dispatcher, "\$canonical === 'submit-checkout' && is_array(\$payload['checkoutSnapshot'] ?? null)")
    && !str_contains($dispatcher, '$checkoutDraftProjection'));
$check('card upgrade intent is materialized only inside final snapshot submit',
    is_string($cashierModule)
    && str_contains($cashierModule, "if (is_array(\$payload['checkoutSnapshot'] ?? null))")
    && str_contains($cashierModule, "\$operationScope['direct_snapshot_operation'] = true")
    && str_contains($cashierModule, "\$snapshot['lines'][\$lineIndex]['cardOperationUpgrade'] = \$upgradeBinding")
    && is_string($cardOperationAuthority)
    && str_contains($cardOperationAuthority, "if (!\$isDirect && !empty(\$scope['direct_snapshot_operation']))")
    && str_contains($cardOperationAuthority, 'cardOperationUpgradeSaleLineAfterGatewayLocksInTx('));
$check('all local card operations are materialized only inside final snapshot submit',
    is_string($cashierModule)
    && str_contains($cashierModule, "\$operationPayload = is_array(\$line['localCardOperation'] ?? null)")
    && str_contains($cashierModule, "\$operationScope['direct_snapshot_operation'] = true")
    && str_contains($cashierModule, "\$isUpgradeOperation = in_array(\$operationType, ['card_upgrade', 'project_upgrade'], true);")
    && str_contains($cashierModule, "\$operationScope['snapshot_occurred_at'] = (int)(\$snapshot['occurredAt'] ?? 0);")
    && str_contains($cashierModule, "\$operationScope['snapshot_business_date'] = (string)(\$snapshot['businessDate'] ?? '');")
    && str_contains($cashierModule, 'if (!$isUpgradeOperation)')
    && str_contains($cashierModule, "'composition' => 'card_operation_only'")
    && str_contains($cashierModule, "'event_type' => 'checkout.completed'")
    && str_contains($cashierModule, "'aggregate_type' => 'card_operation'")
    && str_contains($cashierModule, "'touched' => [],")
    && is_string($cardOperationAuthority)
    && str_contains($cardOperationAuthority, "\$directSnapshot = !empty(\$scope['direct_snapshot_operation']);"));
$check('standalone card-operation snapshot rows bypass sale and entitlement discovery',
    is_string($service)
    && str_contains($service, "if (\$role === 'card_operation') {")
    && strpos($service, "if (\$role === 'card_operation') {")
        < strpos($service, "if (!in_array(\$role, ['entitlement_service'"));
$selectorStart = is_string($entitlementProjection) ? strpos($entitlementProjection, 'public function openSelector') : false;
$selectorEnd = is_string($entitlementProjection) ? strpos($entitlementProjection, 'public function validateSelectedLinesInTx', $selectorStart === false ? 0 : $selectorStart) : false;
$selector = $selectorStart === false || $selectorEnd === false ? '' : substr($entitlementProjection, $selectorStart, $selectorEnd - $selectorStart);
$check('entitlement selector is a member-id display read without workspace or version contexts',
    str_contains($selector, '$memberId = $this->positiveId($payload[\'memberId\'] ?? null, \'memberId\');')
    && str_contains($selector, '$this->buildSources($snapshot, [], [], $operatorScope->tenantId(), false)')
    && str_contains($selector, '$this->provider->synchronizeProjectionVersion(')
    && str_contains($selector, "'kind' => 'card_holder'")
    && !str_contains($selector, 'requireSelectedMember(')
    && !str_contains($selector, 'assertSelectedMemberInTx(')
    && !str_contains($selector, 'cashier_workspace'));

echo sprintf('checkout-snapshot-direct-authority-contract: %d passed, %d failed', $passed, $failed) . PHP_EOL;
exit($failed > 0 ? 1 : 0);
