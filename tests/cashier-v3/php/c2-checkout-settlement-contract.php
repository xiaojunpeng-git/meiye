<?php
/**
 * Isolated C2 checkout request and settlement-draft contract.
 * Database and ThinkPHP are intentionally not bootstrapped here.
 */

$backendRoot = getenv('C2_CHECKOUT_BACKEND_ROOT');
$backendRoot = is_string($backendRoot) && $backendRoot !== ''
    ? rtrim($backendRoot, '/')
    : __DIR__ . '/../../../后端代码';

require_once $backendRoot . '/app/services/cashier/v3/settlement/CashierV3CheckoutSettlementContractException.php';
require_once $backendRoot . '/app/services/cashier/v3/settlement/CashierV3CheckoutSettlementCanonicalizer.php';
require_once $backendRoot . '/app/services/cashier/v3/settlement/CashierV3CheckoutSettlementIdFactory.php';
require_once $backendRoot . '/app/services/cashier/v3/settlement/CashierV3CheckoutSettlementStateMachine.php';
require_once $backendRoot . '/app/services/cashier/v3/settlement/CashierV3CheckoutCraftsmenSnapshot.php';
require_once $backendRoot . '/app/services/cashier/v3/settlement/CashierV3CheckoutSettlementKernel.php';
require_once $backendRoot . '/app/services/cashier/v3/settlement/CashierV3CheckoutRequestRepository.php';
require_once $backendRoot . '/app/services/cashier/v3/settlement/CashierV3CheckoutVerifiedSourceSet.php';
require_once $backendRoot . '/app/services/cashier/v3/settlement/CashierV3CheckoutProjectionServices.php';

use app\services\cashier\v3\settlement\CashierV3CheckoutSettlementCanonicalizer;
use app\services\cashier\v3\settlement\CashierV3CheckoutSettlementContractException;
use app\services\cashier\v3\settlement\CashierV3CheckoutSettlementIdFactory;
use app\services\cashier\v3\settlement\CashierV3CheckoutSettlementKernel;
use app\services\cashier\v3\settlement\CashierV3CheckoutSettlementStateMachine;
use app\services\cashier\v3\settlement\CashierV3CheckoutProjectionServices;

$passed = 0;
$failed = 0;
$secret = 'checkout-settlement-test-secret-32-bytes-minimum';

function checkoutAssert(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    echo "FAIL {$name}" . ($detail === '' ? '' : ': ' . $detail) . "\n";
}

function checkoutReason(callable $callable): string
{
    try {
        $callable();
    } catch (CashierV3CheckoutSettlementContractException $exception) {
        return $exception->reason();
    } catch (\Throwable $throwable) {
        return 'UNEXPECTED:' . get_class($throwable) . ':' . $throwable->getMessage();
    }
    return '';
}

function checkoutFailure(callable $callable): array
{
    try {
        $callable();
    } catch (CashierV3CheckoutSettlementContractException $exception) {
        return ['reason' => $exception->reason(), 'detail' => $exception->detail()];
    } catch (\Throwable $throwable) {
        return [
            'reason' => 'UNEXPECTED:' . get_class($throwable),
            'detail' => ['message' => $throwable->getMessage()],
        ];
    }
    return ['reason' => '', 'detail' => []];
}

function checkoutIdem(int $number, string $prefix = 'CHECKOUT'): string
{
    return sprintf('%s-00000000-0000-4000-8000-%012d', $prefix, $number);
}

function checkoutCommand(string $operation, int $idemNumber = 1): array
{
    return [
        'contractVersion' => CashierV3CheckoutSettlementKernel::CONTRACT_VERSION,
        'operation' => $operation,
        'idempotencyKey' => checkoutIdem(
            $idemNumber,
            $operation === CashierV3CheckoutSettlementKernel::OPERATION_PREPARE_SUBMISSION
                ? 'CHECKOUT_PREPARE'
                : 'CHECKOUT'
        ),
        'workspaceId' => 'workspace-001',
        'stateContextId' => 'state-context-001',
        'permissionSnapshotFingerprint' => 'roles:' . str_repeat('a', 32),
    ];
}

function checkoutSnapshot(): array
{
    $snapshot = [
        'contractVersion' => CashierV3CheckoutSettlementKernel::AUTHORITY_CONTRACT_VERSION,
        'authorityOrigin' => 'server_final_lock_snapshot',
        'authoritySnapshotVersion' => 7,
        'authoritySnapshotFingerprint' => '',
        'tenantId' => '0',
        'organizationId' => '3',
        'organizationPath' => '/1/3/',
        'organizationName' => '华东区域',
        'storeId' => 7,
        'storeName' => '魔核旗舰店',
        'workspaceId' => 'workspace-001',
        'stateContextId' => 'state-context-001',
        'permissionSnapshotFingerprint' => 'roles:' . str_repeat('a', 32),
        'memberId' => 1001,
        'memberName' => '肖君鹏',
        'operatorId' => 21,
        'operatorName' => '收银员甲',
        // Historical business date is independent from the real operation time.
        'businessDate' => '2026-07-20',
        'businessTimezone' => 'Asia/Shanghai',
        'occurredAt' => 1785258000,
        'recordedAt' => 1785258002,
        'orderNote' => '结账备注',
        'supplement' => [
            'enabled' => true,
            'reason' => '补录历史经营日',
            'operatorId' => 21,
            'operatorNameSnapshot' => '收银员甲',
            'operatedAt' => 1785258001,
        ],
        'sourceDocument' => [
            'type' => 'cashier_workspace',
            'id' => 'workspace-001',
            'no' => 'CASHIER-DRAFT-001',
        ],
        'saleLines' => [[
            'authorityKey' => 'sale:cart:501',
            'saleClassification' => 'formal_sale',
            'sourceType' => 'project',
            'sourceId' => 501,
            'sourceVersion' => 9,
            'quantity' => 1,
            'originalAmountCents' => 10000,
            'discountAmountCents' => 1000,
            'couponUserId' => 0,
            'couponNameSnapshot' => '',
            'couponDiscountCents' => 0,
            'saleAmountCents' => 9000,
            'debtAmountCents' => 3000,
            'sourceNameSnapshot' => '深层护理',
            'sourceCodeSnapshot' => 'PROJECT-501',
            'categoryIdSnapshot' => 51,
            'categoryNameSnapshot' => '面部护理',
            'configuredCostCents' => 5000,
            'priceChangeReason' => '会员折扣',
            'priceChangedBy' => 21,
            'priceChangedByNameSnapshot' => '收银员甲',
            'priceChangedAt' => 1785258001,
            'serviceObject' => 'self',
            'craftsmen' => [],
            'isExperience' => 0,
            'guideSelections' => [],
            'salesManagerSelections' => [],
        ]],
        'entitlementLines' => [[
            'authorityKey' => 'entitlement:opaque-cart-row-alpha',
            'sourceKind' => 'count_card',
            'holderId' => 701,
            'entitlementSourceDetailId' => 1701,
            'sourceVersion' => 11,
            'projectId' => 502,
            'projectVersion' => 6,
            'quantity' => 1,
            'actualEntitlementAmountCents' => 2000,
            'sourceNameSnapshot' => '护理次卡',
            'sourceCodeSnapshot' => 'CARD-701',
            'projectNameSnapshot' => '补水护理',
            'projectCategoryIdSnapshot' => 51,
            'projectCategoryNameSnapshot' => '面部护理',
        ]],
        'paymentDetails' => [
            [
                'paymentAuthorityKey' => 'payment:unionpay',
                'method' => 'unionpay',
                'amountCents' => 3000,
                'businessTime' => 1785258000,
                'externalTransactionNo' => 'UNIONPAY-TRACE-001',
                'remark' => '前台记账说明',
            ],
            [
                'paymentAuthorityKey' => 'payment:wechat',
                'method' => 'wechat',
                'amountCents' => 1000,
                'businessTime' => 1785258000,
            ],
        ],
        'balanceDeduction' => [
            'authorityKey' => 'balance:member:1001',
            'accountId' => 'member-balance-1001',
            'accountVersion' => 5,
            'amountCents' => 2000,
        ],
        'debt' => [
            'authorityKey' => 'debt-policy:store:7',
            'policyVersion' => 3,
            'amountCents' => 3000,
        ],
    ];
    checkoutResign($snapshot);
    return $snapshot;
}

function checkoutResign(array &$snapshot): void
{
    $snapshot['authoritySnapshotFingerprint'] =
        CashierV3CheckoutSettlementKernel::authorityFingerprint($snapshot);
}

function checkoutCurrent(array $result): array
{
    $request = $result['persistencePlan']['request'];
    return [
        'requestId' => $result['requestId'],
        'tenantId' => $request['tenantId'],
        'workspaceId' => $request['workspaceId'],
        'version' => $result['requestVersion'],
        'status' => $result['requestStatus'],
        'creationIdempotencyKey' => $request['creationIdempotencyKey'],
        'lastIdempotencyKey' => $request['lastIdempotencyKey'],
        'lastOperationFingerprint' => $result['operationFingerprint'],
    ];
}

function checkoutProjectionAggregate(array $result): array
{
    $request = $result['persistencePlan']['request'];
    $requestRow = [
        'request_id' => $request['requestId'],
        'tenant_id' => $request['tenantId'],
        'workspace_id' => $request['workspaceId'],
        'state_context_id' => $request['stateContextId'],
        'store_id' => $request['storeId'],
        'member_id' => $request['memberId'],
        'member_name_snapshot' => $request['memberNameSnapshot'],
        'operator_id' => $request['operatorId'],
        'request_version' => $request['requestVersion'],
        'request_status' => $request['requestStatus'],
        'composition' => $request['composition'],
        'business_date' => $request['businessDate'],
        'order_note' => $request['orderNote'],
        'supplement_enabled' => $request['supplementEnabled'],
        'supplement_reason' => $request['supplementReason'],
        'supplement_operator_id' => $request['supplementOperatorId'],
        'supplement_operator_name_snapshot' => $request['supplementOperatorNameSnapshot'],
        'supplement_operated_at' => $request['supplementOperatedAt'],
        'source_document_type' => $request['sourceDocumentType'],
        'source_document_id' => $request['sourceDocumentId'],
        'source_document_no' => $request['sourceDocumentNo'],
        'sales_amount_cents' => $request['salesAmountCents'],
        'receivable_amount_cents' => $request['receivableAmountCents'],
        'selected_payment_amount_cents' => $request['selectedPaymentAmountCents'],
        'balance_deduction_amount_cents' => $request['balanceDeductionAmountCents'],
        'debt_amount_cents' => $request['debtAmountCents'],
        'cash_performance_amount_cents' => $request['cashPerformanceAmountCents'],
        'entitlement_actual_amount_cents' => $request['entitlementActualAmountCents'],
        'authority_snapshot_version' => $request['authoritySnapshotVersion'],
        'authority_fingerprint' => $request['authorityFingerprint'],
        'aggregate_fingerprint' => $request['aggregateFingerprint'],
        'creation_idempotency_key' => $request['creationIdempotencyKey'],
        'last_operation_fingerprint' => $request['lastOperationFingerprint'],
    ];
    $lineRows = [];
    foreach ($result['persistencePlan']['lineDrafts'] as $index => $line) {
        $lineRows[] = [
            'id' => $index + 1,
            'line_id' => $line['lineId'],
            'request_id' => $line['requestId'],
            'draft_version' => $line['draftVersion'],
            'draft_status' => $line['draftStatus'],
            'tenant_id' => $line['tenantId'],
            'store_id' => $line['storeId'],
            'member_id' => $line['memberId'],
            'line_role' => $line['lineRole'],
            'authority_key' => $line['authorityKey'],
            'source_kind' => $line['sourceKind'],
            'source_type' => $line['sourceType'],
            'source_id' => $line['sourceId'],
            'entitlement_source_detail_id' => $line['entitlementSourceDetailId'],
            'source_version' => $line['sourceVersion'],
            'project_id' => $line['projectId'],
            'project_version' => $line['projectVersion'],
            'service_object' => $line['serviceObject'],
            'friend_counts_as_customer' => $line['friendCountsAsCustomer'] ?? 1,
            'is_experience' => $line['isExperience'],
            'catalog_sku_id' => $line['catalogSkuId'],
            'craftsmen_snapshot_json' => $line['craftsmenSnapshotJson'],
            'guide_selections_json' => $line['guideSelectionsJson'],
            'sales_manager_selections_json' => $line['salesManagerSelectionsJson'],
            'manual_labor_fee_cents' => $line['manualLaborFeeCents'],
            'quantity' => $line['quantity'],
            'original_amount_cents' => $line['originalAmountCents'],
            'discount_amount_cents' => $line['discountAmountCents'],
            'coupon_user_id' => $line['couponUserId'],
            'coupon_name_snapshot' => $line['couponNameSnapshot'],
            'coupon_discount_cents' => $line['couponDiscountCents'],
            'sale_amount_cents' => $line['saleAmountCents'],
            'debt_amount_cents' => $line['debtAmountCents'],
            'entitlement_actual_amount_cents' => $line['entitlementActualAmountCents'],
            'source_name_snapshot' => $line['sourceNameSnapshot'],
            'source_code_snapshot' => $line['sourceCodeSnapshot'],
            'project_name_snapshot' => $line['projectNameSnapshot'],
            'category_id_snapshot' => $line['categoryIdSnapshot'],
            'category_name_snapshot' => $line['categoryNameSnapshot'],
            'configured_cost_cents' => $line['configuredCostCents'],
            'price_change_reason' => $line['priceChangeReason'],
            'price_changed_by' => $line['priceChangedBy'],
            'price_changed_by_name_snapshot' => $line['priceChangedByNameSnapshot'],
            'price_changed_at' => $line['priceChangedAt'],
            'line_fingerprint' => $line['lineFingerprint'],
            'sort_no' => $line['sortNo'],
        ];
    }
    $paymentRows = [];
    foreach ($result['persistencePlan']['paymentDrafts'] as $index => $payment) {
        $paymentRows[] = [
            'id' => $index + 1,
            'payment_draft_id' => $payment['paymentDraftId'],
            'request_id' => $payment['requestId'],
            'draft_version' => $payment['draftVersion'],
            'draft_status' => $payment['draftStatus'],
            'tenant_id' => $payment['tenantId'],
            'store_id' => $payment['storeId'],
            'member_id' => $payment['memberId'],
            'operator_id' => $payment['operatorId'],
            'payment_authority_key' => $payment['paymentAuthorityKey'],
            'payment_method' => $payment['paymentMethod'],
            'amount_cents' => $payment['amountCents'],
            'external_transaction_no' => $payment['externalTransactionNo'],
            'remark' => $payment['remark'],
            'operation_occurred_at' => $payment['operationOccurredAt'],
            'payment_fingerprint' => $payment['paymentFingerprint'],
            'sort_no' => $payment['sortNo'],
        ];
    }
    return [
        'request' => $requestRow,
        'lines' => $lineRows,
        'payments' => $paymentRows,
        'sources' => [],
        'workspaceCurrentVersion' => $request['authoritySnapshotVersion'] + 1,
    ];
}

$methods = CashierV3CheckoutSettlementKernel::paymentMethods();
checkoutAssert('seven bookkeeping methods are exact', $methods === [
    'unionpay',
    'wechat',
    'alipay',
    'dianping_voucher',
    'douyin_voucher',
    'partner_collection',
    'other_collection',
]);
checkoutAssert('paper cash is absent', !in_array('cash', $methods, true));
checkoutAssert('old card is outside bookkeeping methods', !in_array('old_card_entry', $methods, true));
$legacy = CashierV3CheckoutSettlementKernel::legacyEntryContract();
checkoutAssert(
    'old card entry remains separate and zero cash performance',
    $legacy['separateOperationRequired'] === true
        && $legacy['combinableWithCheckoutSettlement'] === false
        && $legacy['cashPerformanceAmountCents'] === 0
        && $legacy['paymentCollectedFactCount'] === 0
);

$snapshot = checkoutSnapshot();
$saveCommand = checkoutCommand(CashierV3CheckoutSettlementKernel::OPERATION_SAVE_DRAFT, 1);
$draft = CashierV3CheckoutSettlementKernel::saveDraft($saveCommand, $snapshot, null, $secret);
// A guide round is a formal checkout attribution dimension. It must survive
// the kernel's normalized line snapshot instead of degrading to employee-only.
$guideSnapshot = checkoutSnapshot();
$guideSnapshot['saleLines'][0]['guideSelections'] = [[
    'employeeId' => 490,
    'name' => '测试导购',
    'guideRoundNo' => 1,
]];
checkoutResign($guideSnapshot);
$guideDraft = CashierV3CheckoutSettlementKernel::saveDraft(
    checkoutCommand(CashierV3CheckoutSettlementKernel::OPERATION_SAVE_DRAFT, 33),
    $guideSnapshot,
    null,
    $secret
);
$guideDraftRows = (array)($guideDraft['persistencePlan']['lineDrafts'] ?? []);
$guideDraftSelections = json_decode((string)($guideDraftRows[0]['guideSelectionsJson'] ?? ''), true);
checkoutAssert('guide round survives the checkout draft kernel',
    is_array($guideDraftSelections)
    && (int)($guideDraftSelections[0]['employeeId'] ?? 0) === 490
    && (int)($guideDraftSelections[0]['guideRoundNo'] ?? 0) === 1);
checkoutAssert('mixed composition is server classified', $draft['composition'] === 'mixed');
checkoutAssert('draft remains eventless editing state',
    $draft['requestStatus'] === 'editing' && $draft['eventless'] === true);
checkoutAssert('sales amount excludes entitlement actual amount',
    $draft['totals']['salesAmountCents'] === 9000
        && $draft['totals']['receivableAmountCents'] === 6000
        && $draft['totals']['entitlementActualAmountCents'] === 2000);
checkoutAssert('seven-method sum is cash performance',
    $draft['totals']['selectedPaymentAmountCents'] === 4000
        && $draft['totals']['cashPerformanceAmountCents'] === 4000);
checkoutAssert('balance and debt remain independent non-cash components',
    $draft['totals']['balanceDeductionAmountCents'] === 2000
        && $draft['totals']['debtAmountCents'] === 3000
        && $draft['totals']['settlementAmountCents'] === 6000);
checkoutAssert('balanced draft records equality', $draft['totals']['balanced'] === true);
checkoutAssert('request and child ids are server stable ids',
    preg_match('/^CKR-[0-9a-f]{40}$/D', $draft['requestId']) === 1
        && preg_match('/^CKL-[0-9a-f]{40}$/D', $draft['lineDrafts'][0]['lineId']) === 1
        && preg_match('/^CKP-[0-9a-f]{40}$/D', $draft['paymentDrafts'][0]['paymentDraftId']) === 1);
checkoutAssert('sale and entitlement are separate draft grains',
    count($draft['lineDrafts']) === 2
        && $draft['lineDrafts'][0]['lineRole'] === 'sale'
        && $draft['lineDrafts'][0]['entitlementSourceDetailId'] === 0
        && $draft['lineDrafts'][0]['draftVersion'] === 1
        && $draft['lineDrafts'][0]['draftStatus'] === 'draft'
        && $draft['lineDrafts'][0]['serviceObject'] === 'self'
        && $draft['lineDrafts'][0]['isExperience'] === 0
        && $draft['lineDrafts'][1]['lineRole'] === 'entitlement_service'
        && $draft['lineDrafts'][1]['serviceObject'] === ''
        && $draft['lineDrafts'][1]['isExperience'] === 0
        && $draft['lineDrafts'][1]['saleAmountCents'] === 0);
checkoutAssert('line drafts carry immutable service and inventory tags',
    $draft['lineDrafts'][0]['friendCountsAsCustomer'] === 1
        && $draft['lineDrafts'][0]['isPresale'] === 0
        && $draft['lineDrafts'][0]['inventoryOutboundRequired'] === 1
        && $draft['lineDrafts'][1]['friendCountsAsCustomer'] === 1
        && $draft['lineDrafts'][1]['isPresale'] === 0
        && $draft['lineDrafts'][1]['inventoryOutboundRequired'] === 1);
checkoutAssert('entitlement source and project history are frozen separately',
    $draft['lineDrafts'][1]['sourceNameSnapshot'] === '护理次卡'
        && $draft['lineDrafts'][1]['entitlementSourceDetailId'] === 1701
        && $draft['lineDrafts'][1]['sourceVersion'] === 11
        && $draft['lineDrafts'][1]['projectNameSnapshot'] === '补水护理'
        && $draft['lineDrafts'][1]['projectVersion'] === 6);
checkoutAssert('payment detail freezes operator source and historical business date',
    $draft['paymentDrafts'][0]['draftVersion'] === 1
        && $draft['paymentDrafts'][0]['draftStatus'] === 'draft'
        && $draft['paymentDrafts'][0]['operatorId'] === 21
        && $draft['paymentDrafts'][0]['operatorNameSnapshot'] === '收银员甲'
        && $draft['paymentDrafts'][0]['sourceDocumentNo'] === 'CASHIER-DRAFT-001'
        && $draft['paymentDrafts'][0]['businessDate'] === '2026-07-20'
        && $draft['paymentDrafts'][0]['operationOccurredAt'] === 1785258000
        && $draft['paymentDrafts'][0]['externalTransactionNo'] === 'UNIONPAY-TRACE-001'
        && $draft['paymentDrafts'][0]['remark'] === '前台记账说明'
        && $draft['paymentDrafts'][1]['externalTransactionNo'] === ''
        && $draft['paymentDrafts'][1]['remark'] === ''
        && $draft['paymentDrafts'][0]['settledAt'] === null);
checkoutAssert('draft writes no business facts or mutations',
    $draft['businessEffects'] === [
        'checkoutSucceeded' => false,
        'saleFacts' => 0,
        'paymentCollectedFacts' => 0,
        'balanceMutations' => 0,
        'debtMutations' => 0,
        'entitlementMutations' => 0,
        'performanceFacts' => 0,
        'businessEvents' => 0,
        'outboxRows' => 0,
    ]);
checkoutAssert('persistence plan uses insert and CAS version one',
    $draft['persistencePlan']['mode'] === 'insert'
        && $draft['persistencePlan']['cas']['expectedVersion'] === null
        && $draft['persistencePlan']['cas']['nextVersion'] === 1);

$checkoutProjectionAggregate = checkoutProjectionAggregate($draft);
$checkoutProjection = CashierV3CheckoutProjectionServices::projectPersistedAggregate(
    $checkoutProjectionAggregate
);
checkoutAssert('current editing checkout projection freezes request identity and correlation token',
    $checkoutProjection['contractVersion'] === 'cashier-v3-checkout-projection-v2'
        && $checkoutProjection['checkoutRequestId'] === $draft['requestId']
        && $checkoutProjection['checkoutRequestVersion'] === 1
        && $checkoutProjection['status'] === 'editing'
        && $checkoutProjection['preparationTokenRole'] === 'projection_correlation_only'
        && preg_match('/^CKPT-[0-9a-f]{64}$/D', $checkoutProjection['preparationToken']) === 1
        && $checkoutProjection['preparationReady'] === true
        && $checkoutProjection['snapshotReady'] === true);
checkoutAssert('checkout projection carries exact workspace and request command contexts',
    $checkoutProjection['commandContexts'] === [
        [
            'kind' => 'cashier_workspace',
            'id' => 'workspace-001',
            'expectedVersion' => 8,
        ],
        [
            'kind' => 'checkout_request',
            'id' => $draft['requestId'],
            'expectedVersion' => 1,
        ],
    ]);
$projectionWithSource = $checkoutProjectionAggregate;
$projectionWithSource['sources'] = [[
    'request_id' => $draft['requestId'],
    'tenant_id' => '0',
    'store_id' => 7,
    'bound_request_version' => 1,
    'source_kind' => 'service_order',
    'source_id' => 'SO-501',
    'source_version' => 13,
    'source_role' => 'service_origin',
    'source_fingerprint' => \app\services\cashier\v3\settlement\CashierV3CheckoutVerifiedSourceSet::referenceFingerprint(
        'service_order',
        'SO-501',
        13,
        'service_origin'
    ),
]];
$sourceProjection = CashierV3CheckoutProjectionServices::projectPersistedAggregate(
    $projectionWithSource
);
checkoutAssert('checkout projection restores the persisted source version into command contexts',
    $sourceProjection['commandContexts'][2] === [
        'kind' => 'service_order',
        'id' => 'SO-501',
        'expectedVersion' => 13,
    ]);
$projectionSourceVersionDrift = $projectionWithSource;
$projectionSourceVersionDrift['sources'][0]['source_version'] = 14;
checkoutAssert('checkout projection rejects source-version fingerprint drift',
    checkoutReason(static function () use ($projectionSourceVersionDrift): void {
        CashierV3CheckoutProjectionServices::projectPersistedAggregate(
            $projectionSourceVersionDrift
        );
    }) === 'checkout_projection_source_fingerprint_drift');
checkoutAssert('checkout projection lines summary and composition use frozen request rows',
    count($checkoutProjection['orderLines']) === 2
        && $checkoutProjection['orderLines'][0]['lineRole'] === 'sale'
        && $checkoutProjection['orderLines'][1]['lineRole'] === 'entitlement_service'
        && $checkoutProjection['orderLines'][1]['entitlementSourceDetailId'] === 1701
        && $checkoutProjection['orderLines'][1]['actualAmount'] === '20.00'
        && $checkoutProjection['summary'] === [
            'selectedCount' => 2,
            'originalAmount' => '100.00',
            'discountAmount' => '10.00',
            'receivableAmount' => '60.00',
            'entitlementActualAmount' => '20.00',
        ]
        && $checkoutProjection['compositionCode'] === 'mixed'
        && $checkoutProjection['composition']['primaryAction'] === 'collect_and_complete'
        && $checkoutProjection['composition']['primaryActionLabel'] === '收款并完成服务');
checkoutAssert('projection exposes seven bookkeeping methods and independently disables old card entry',
    array_column($checkoutProjection['payment']['methods'], 'id') === [
        'unionpay', 'wechat', 'alipay', 'dianping_voucher', 'douyin_voucher',
        'partner_collection', 'other_collection', 'old_card_entry',
    ]
        && $checkoutProjection['payment']['methods'][0]['canAdd'] === true
        && $checkoutProjection['payment']['methods'][7]['canAdd'] === false
        && $checkoutProjection['payment']['methods'][7]['cashPerformanceEligible'] === false
        && $checkoutProjection['payment']['methods'][7]['disabledReason'] !== '');
checkoutAssert('projection payment lines and summary remain exact cents-derived values',
    count($checkoutProjection['payment']['selectedLines']) === 3
        && $checkoutProjection['payment']['selectedLines'][0]['amount'] === '30.00'
        && $checkoutProjection['payment']['selectedLines'][0]['externalTransactionNo']
            === 'UNIONPAY-TRACE-001'
        && $checkoutProjection['payment']['selectedLines'][0]['remark'] === '前台记账说明'
        && $checkoutProjection['payment']['selectedLines'][2]['kind'] === 'balance_deduction'
        && $checkoutProjection['payment']['selectedLines'][2]['amount'] === '20.00'
        && $checkoutProjection['payment']['summary']['receivableAmount'] === '60.00'
        && $checkoutProjection['payment']['summary']['selectedAmount'] === '60.00'
        && $checkoutProjection['payment']['summary']['remainingAmount'] === '0.00'
        && $checkoutProjection['debtAmount'] === '30.00'
        && $checkoutProjection['balancePaymentAmount'] === '20.00'
        && $checkoutProjection['cashPerformanceAmount'] === '40.00');

$projectionDetailDrift = $checkoutProjectionAggregate;
$projectionDetailDrift['lines'][1]['entitlement_source_detail_id'] = 1702;
checkoutAssert('checkout projection rejects persisted entitlement detail drift',
    checkoutReason(static function () use ($projectionDetailDrift): void {
        CashierV3CheckoutProjectionServices::projectPersistedAggregate($projectionDetailDrift);
    }) === 'checkout_projection_line_fingerprint_drift');
$projectionPaymentDrift = $checkoutProjectionAggregate;
$projectionPaymentDrift['payments'][0]['amount_cents']++;
checkoutAssert('checkout projection rejects persisted payment drift',
    checkoutReason(static function () use ($projectionPaymentDrift): void {
        CashierV3CheckoutProjectionServices::projectPersistedAggregate($projectionPaymentDrift);
    }) === 'checkout_projection_payment_fingerprint_drift');

$replay = CashierV3CheckoutSettlementKernel::saveDraft(
    $saveCommand,
    $snapshot,
    checkoutCurrent($draft),
    $secret
);
checkoutAssert('same key same fingerprint replays without persistence',
    $replay['replayed'] === true
        && $replay['requestId'] === $draft['requestId']
        && $replay['requestVersion'] === 1
        && $replay['persistencePlan']['mode'] === 'none');
checkoutAssert('stable child ids survive replay',
    $replay['lineDrafts'][0]['lineId'] === $draft['lineDrafts'][0]['lineId']
        && $replay['paymentDrafts'][0]['paymentDraftId'] === $draft['paymentDrafts'][0]['paymentDraftId']);
checkoutAssert('idempotent replay returns the frozen business result',
    $replay['authoritySnapshotFingerprint'] === $draft['authoritySnapshotFingerprint']
        && $replay['aggregateFingerprint'] === $draft['aggregateFingerprint']
        && $replay['totals'] === $draft['totals']
        && $replay['lineDrafts'] === $draft['lineDrafts']
        && $replay['paymentDrafts'] === $draft['paymentDrafts']
        && $replay['balanceDeductionDraft'] === $draft['balanceDeductionDraft']
        && $replay['debtDraft'] === $draft['debtDraft']);

$changedSameKey = $snapshot;
$changedSameKey['saleLines'][0]['sourceNameSnapshot'] = '修改名称';
checkoutResign($changedSameKey);
checkoutAssert('same idempotency key with changed snapshot conflicts',
    checkoutReason(static function () use ($saveCommand, $changedSameKey, $draft, $secret): void {
        CashierV3CheckoutSettlementKernel::saveDraft(
            $saveCommand,
            $changedSameKey,
            checkoutCurrent($draft),
            $secret
        );
    }) === 'checkout_idempotency_key_conflict');

$prepareCommand = checkoutCommand(CashierV3CheckoutSettlementKernel::OPERATION_PREPARE_SUBMISSION, 2);
$prepareCommand['requestId'] = $draft['requestId'];
$prepareCommand['expectedVersion'] = 1;
$ready = CashierV3CheckoutSettlementKernel::prepareSubmission(
    $prepareCommand,
    $snapshot,
    checkoutCurrent($draft),
    $secret
);
checkoutAssert('balanced prepare advances by CAS to ready',
    $ready['requestStatus'] === 'ready_for_submit'
        && $ready['requestVersion'] === 2
        && $ready['persistencePlan']['mode'] === 'cas_replace_children'
        && $ready['persistencePlan']['cas']['expectedVersion'] === 1);
checkoutAssert('child identities survive versioned edit',
    $ready['lineDrafts'][0]['lineId'] === $draft['lineDrafts'][0]['lineId']
        && $ready['lineDrafts'][0]['draftVersion'] === 2
        && $ready['paymentDrafts'][0]['paymentDraftId'] === $draft['paymentDrafts'][0]['paymentDraftId']
        && $ready['paymentDrafts'][0]['draftVersion'] === 2);

$legacySupplement = $snapshot;
$legacySupplement['supplement'] = [
    'enabled' => false,
    'reason' => '历史草稿残留',
    'operatorId' => 21,
    'operatorNameSnapshot' => '旧收银员',
    'operatedAt' => 1785258001,
    'legacyField' => 'ignored',
];
checkoutResign($legacySupplement);
$legacySupplementResult = CashierV3CheckoutSettlementKernel::prepareSubmission(
    checkoutCommand(CashierV3CheckoutSettlementKernel::OPERATION_PREPARE_SUBMISSION, 31),
    $legacySupplement,
    null,
    $secret
);
checkoutAssert('disabled legacy supplement audit is normalized without blocking checkout',
    $legacySupplementResult['requestStatus'] === 'ready_for_submit'
        && $legacySupplementResult['persistencePlan']['request']['supplementEnabled'] === 0
        && $legacySupplementResult['persistencePlan']['request']['supplementReason'] === ''
        && $legacySupplementResult['persistencePlan']['request']['supplementOperatorId'] === 0
        && $legacySupplementResult['persistencePlan']['request']['supplementOperatedAt'] === 0);

$missingSupplement = $snapshot;
unset($missingSupplement['supplement']);
checkoutResign($missingSupplement);
$missingSupplementResult = CashierV3CheckoutSettlementKernel::prepareSubmission(
    checkoutCommand(CashierV3CheckoutSettlementKernel::OPERATION_PREPARE_SUBMISSION, 32),
    $missingSupplement,
    null,
    $secret
);
checkoutAssert('missing legacy supplement block defaults to disabled',
    $missingSupplementResult['requestStatus'] === 'ready_for_submit'
        && $missingSupplementResult['persistencePlan']['request']['supplementEnabled'] === 0);

$staleCommand = $prepareCommand;
$staleCommand['idempotencyKey'] = checkoutIdem(3, 'CHECKOUT_PREPARE');
checkoutAssert('stale expected version is rejected',
    checkoutReason(static function () use ($staleCommand, $snapshot, $ready, $secret): void {
        CashierV3CheckoutSettlementKernel::prepareSubmission(
            $staleCommand,
            $snapshot,
            checkoutCurrent($ready),
            $secret
        );
    }) === 'checkout_request_version_conflict');

$unbalanced = $snapshot;
$unbalanced['debt']['amountCents'] = 2000;
$unbalanced['saleLines'][0]['debtAmountCents'] = 2000;
checkoutResign($unbalanced);
$unbalancedDraft = CashierV3CheckoutSettlementKernel::saveDraft(
    checkoutCommand(CashierV3CheckoutSettlementKernel::OPERATION_SAVE_DRAFT, 4),
    $unbalanced,
    null,
    $secret
);
checkoutAssert('editing may retain an explicitly unbalanced draft without facts',
    $unbalancedDraft['requestStatus'] === 'editing'
        && $unbalancedDraft['totals']['balanced'] === false
        && $unbalancedDraft['businessEffects']['paymentCollectedFacts'] === 0);
checkoutAssert('submission preparation requires exact receivable equality',
    checkoutReason(static function () use ($unbalanced, $secret): void {
        CashierV3CheckoutSettlementKernel::prepareSubmission(
            checkoutCommand(CashierV3CheckoutSettlementKernel::OPERATION_PREPARE_SUBMISSION, 5),
            $unbalanced,
            null,
            $secret
        );
    }) === 'checkout_receivable_not_balanced');

$saleOnly = $snapshot;
$saleOnly['entitlementLines'] = [];
checkoutResign($saleOnly);
$saleOnlyResult = CashierV3CheckoutSettlementKernel::prepareSubmission(
    checkoutCommand(CashierV3CheckoutSettlementKernel::OPERATION_PREPARE_SUBMISSION, 6),
    $saleOnly,
    null,
    $secret
);
checkoutAssert('sale-only composition is supported',
    $saleOnlyResult['composition'] === 'sale_only' && count($saleOnlyResult['lineDrafts']) === 1);

$entitlementOnly = $snapshot;
$entitlementOnly['saleLines'] = [];
$entitlementOnly['paymentDetails'] = [];
$secondEntitlementLine = $entitlementOnly['entitlementLines'][0];
$secondEntitlementLine['authorityKey'] = 'entitlement:opaque-cart-row-beta';
$secondEntitlementLine['sourceKind'] = 'unknown';
$secondEntitlementLine['holderId'] = 702;
$secondEntitlementLine['entitlementSourceDetailId'] = 1702;
$secondEntitlementLine['sourceVersion'] = 12;
$secondEntitlementLine['projectId'] = 503;
$secondEntitlementLine['projectVersion'] = 7;
$secondEntitlementLine['sourceNameSnapshot'] = '历史定制卡';
$secondEntitlementLine['sourceCodeSnapshot'] = 'CARD-702';
$secondEntitlementLine['projectNameSnapshot'] = '清润护理';
$entitlementOnly['entitlementLines'][] = $secondEntitlementLine;
$entitlementOnly['balanceDeduction'] = [
    'authorityKey' => '', 'accountId' => '', 'accountVersion' => 0, 'amountCents' => 0,
];
$entitlementOnly['debt'] = ['authorityKey' => '', 'policyVersion' => 0, 'amountCents' => 0];
checkoutResign($entitlementOnly);
checkoutResign($entitlementOnly);
$entitlementOnlyResult = CashierV3CheckoutSettlementKernel::prepareSubmission(
    checkoutCommand(CashierV3CheckoutSettlementKernel::OPERATION_PREPARE_SUBMISSION, 7),
    $entitlementOnly,
    null,
    $secret
);
checkoutAssert('entitlement-only composition has zero receivable and cash performance',
    $entitlementOnlyResult['composition'] === 'entitlement_only'
        && count($entitlementOnlyResult['lineDrafts']) === 2
        && count($entitlementOnlyResult['paymentDrafts']) === 0
        && $entitlementOnlyResult['totals']['receivableAmountCents'] === 0
        && $entitlementOnlyResult['totals']['cashPerformanceAmountCents'] === 0
        && $entitlementOnlyResult['totals']['entitlementActualAmountCents'] === 4000);

$fractionalEntitlement = $entitlementOnly;
$fractionalEntitlement['entitlementLines'][0]['actualEntitlementAmountCents'] = 155333;
$fractionalEntitlement['entitlementLines'][1]['actualEntitlementAmountCents'] = 4667;
checkoutResign($fractionalEntitlement);
$fractionalEntitlementResult = CashierV3CheckoutSettlementKernel::prepareSubmission(
    checkoutCommand(CashierV3CheckoutSettlementKernel::OPERATION_PREPARE_SUBMISSION, 71),
    $fractionalEntitlement,
    null,
    $secret
);
checkoutAssert('entitlement actual amounts may preserve fractional yuan allocation',
    $fractionalEntitlementResult['composition'] === 'entitlement_only'
        && $fractionalEntitlementResult['totals']['receivableAmountCents'] === 0
        && $fractionalEntitlementResult['totals']['entitlementActualAmountCents'] === 160000);

$legacyUnknownEntitlement = $entitlementOnly;
$legacyUnknownEntitlement['entitlementLines'][0]['sourceKind'] = 'unknown';
checkoutResign($legacyUnknownEntitlement);
$legacyUnknownEntitlementResult = CashierV3CheckoutSettlementKernel::prepareSubmission(
    checkoutCommand(CashierV3CheckoutSettlementKernel::OPERATION_PREPARE_SUBMISSION, 27),
    $legacyUnknownEntitlement,
    null,
    $secret
);
checkoutAssert('legacy entitlement source kind remains traceable without being recast',
    $legacyUnknownEntitlementResult['lineDrafts'][0]['sourceKind'] === 'unknown'
        && $legacyUnknownEntitlementResult['composition'] === 'entitlement_only');

$differentEntitlementDetail = $snapshot;
$differentEntitlementDetail['entitlementLines'][0]['entitlementSourceDetailId'] = 1702;
checkoutResign($differentEntitlementDetail);
$differentEntitlementDetailResult = CashierV3CheckoutSettlementKernel::saveDraft(
    checkoutCommand(CashierV3CheckoutSettlementKernel::OPERATION_SAVE_DRAFT, 24),
    $differentEntitlementDetail,
    null,
    $secret
);
checkoutAssert('explicit entitlement source detail identity participates in every fingerprint',
    $differentEntitlementDetailResult['lineDrafts'][1]['authorityKey']
        === $draft['lineDrafts'][1]['authorityKey']
        && $differentEntitlementDetailResult['lineDrafts'][1]['entitlementSourceDetailId'] === 1702
        && $differentEntitlementDetailResult['lineDrafts'][1]['lineFingerprint']
            !== $draft['lineDrafts'][1]['lineFingerprint']
        && $differentEntitlementDetailResult['aggregateFingerprint']
            !== $draft['aggregateFingerprint']);

$missingEntitlementDetail = $snapshot;
unset($missingEntitlementDetail['entitlementLines'][0]['entitlementSourceDetailId']);
checkoutResign($missingEntitlementDetail);
checkoutAssert('entitlement source detail identity is mandatory and never parsed from authority key',
    checkoutReason(static function () use ($missingEntitlementDetail, $secret): void {
        CashierV3CheckoutSettlementKernel::saveDraft(
            checkoutCommand(CashierV3CheckoutSettlementKernel::OPERATION_SAVE_DRAFT, 25),
            $missingEntitlementDetail,
            null,
            $secret
        );
    }) === 'object_shape_invalid');

$zeroEntitlementDetail = $snapshot;
$zeroEntitlementDetail['entitlementLines'][0]['entitlementSourceDetailId'] = 0;
checkoutResign($zeroEntitlementDetail);
checkoutAssert('entitlement source detail identity must be a positive integer',
    checkoutReason(static function () use ($zeroEntitlementDetail, $secret): void {
        CashierV3CheckoutSettlementKernel::saveDraft(
            checkoutCommand(CashierV3CheckoutSettlementKernel::OPERATION_SAVE_DRAFT, 26),
            $zeroEntitlementDetail,
            null,
            $secret
        );
    }) === 'positive_integer_required');

$badEntitlementOnly = $entitlementOnly;
$badEntitlementOnly['paymentDetails'] = [[
    'paymentAuthorityKey' => 'payment:wechat',
    'method' => 'wechat',
    'amountCents' => 100,
    'businessTime' => $badEntitlementOnly['occurredAt'],
]];
checkoutResign($badEntitlementOnly);
checkoutAssert('entitlement-only cannot smuggle a settlement amount',
    checkoutReason(static function () use ($badEntitlementOnly, $secret): void {
        CashierV3CheckoutSettlementKernel::saveDraft(
            checkoutCommand(CashierV3CheckoutSettlementKernel::OPERATION_SAVE_DRAFT, 8),
            $badEntitlementOnly,
            null,
            $secret
        );
    }) === 'entitlement_only_settlement_must_be_zero');

$oldCard = $snapshot;
$oldCard['paymentDetails'][0]['method'] = 'old_card_entry';
checkoutResign($oldCard);
checkoutAssert('old card entry is rejected from combined checkout',
    checkoutReason(static function () use ($oldCard, $secret): void {
        CashierV3CheckoutSettlementKernel::saveDraft(
            checkoutCommand(CashierV3CheckoutSettlementKernel::OPERATION_SAVE_DRAFT, 9),
            $oldCard,
            null,
            $secret
        );
    }) === 'old_card_entry_separate_flow_required');

$paperCash = $snapshot;
$paperCash['paymentDetails'][0]['method'] = 'cash';
checkoutResign($paperCash);
checkoutAssert('paper cash method is rejected',
    checkoutReason(static function () use ($paperCash, $secret): void {
        CashierV3CheckoutSettlementKernel::saveDraft(
            checkoutCommand(CashierV3CheckoutSettlementKernel::OPERATION_SAVE_DRAFT, 10),
            $paperCash,
            null,
            $secret
        );
    }) === 'payment_method_invalid');

$duplicatePayment = $snapshot;
$duplicatePayment['paymentDetails'][1]['method'] = 'unionpay';
checkoutResign($duplicatePayment);
checkoutAssert('one request can contain multiple independent rows of one bookkeeping method',
    CashierV3CheckoutSettlementKernel::saveDraft(
        checkoutCommand(CashierV3CheckoutSettlementKernel::OPERATION_SAVE_DRAFT, 11),
        $duplicatePayment,
        null,
        $secret
    )['totals']['selectedPaymentAmountCents'] > 0);

$zeroPaymentDraft = $snapshot;
$zeroPaymentDraft['paymentDetails'][0]['amountCents'] = 0;
checkoutResign($zeroPaymentDraft);
checkoutAssert('editing draft can retain a selected payment method at zero pending amount entry',
    CashierV3CheckoutSettlementKernel::saveDraft(
        checkoutCommand(CashierV3CheckoutSettlementKernel::OPERATION_SAVE_DRAFT, 29),
        $zeroPaymentDraft,
        null,
        $secret
    )['paymentDrafts'][0]['amountCents'] === 0);
checkoutAssert('positive receivable still rejects a zero-valued payment draft as unbalanced',
    checkoutReason(static function () use ($zeroPaymentDraft, $secret): void {
        CashierV3CheckoutSettlementKernel::prepareSubmission(
            checkoutCommand(CashierV3CheckoutSettlementKernel::OPERATION_PREPARE_SUBMISSION, 30),
            $zeroPaymentDraft,
            null,
            $secret
        );
    }) === 'checkout_receivable_not_balanced');

$floatMoney = $snapshot;
$floatMoney['saleLines'][0]['saleAmountCents'] = '9000';
checkoutResign($floatMoney);
checkoutAssert('non-integer money is rejected',
    checkoutReason(static function () use ($floatMoney, $secret): void {
        CashierV3CheckoutSettlementKernel::saveDraft(
            checkoutCommand(CashierV3CheckoutSettlementKernel::OPERATION_SAVE_DRAFT, 12),
            $floatMoney,
            null,
            $secret
        );
    }) === 'money_cents_invalid');

$clientAmount = $saveCommand;
$clientAmount['receivableAmountCents'] = 1;
checkoutAssert('client amount field is outside command contract',
    checkoutReason(static function () use ($clientAmount, $snapshot, $secret): void {
        CashierV3CheckoutSettlementKernel::saveDraft($clientAmount, $snapshot, null, $secret);
    }) === 'object_shape_invalid');

$tampered = $snapshot;
$tampered['saleLines'][0]['saleAmountCents'] = 8999;
checkoutAssert('tampered authority snapshot fingerprint is rejected first',
    checkoutReason(static function () use ($tampered, $secret): void {
        CashierV3CheckoutSettlementKernel::saveDraft(
            checkoutCommand(CashierV3CheckoutSettlementKernel::OPERATION_SAVE_DRAFT, 13),
            $tampered,
            null,
            $secret
        );
    }) === 'authority_snapshot_fingerprint_mismatch');

$badEquation = $snapshot;
$badEquation['saleLines'][0]['discountAmountCents'] = 1100;
checkoutResign($badEquation);
checkoutAssert('formal sale amount equation is enforced',
    checkoutReason(static function () use ($badEquation, $secret): void {
        CashierV3CheckoutSettlementKernel::saveDraft(
            checkoutCommand(CashierV3CheckoutSettlementKernel::OPERATION_SAVE_DRAFT, 14),
            $badEquation,
            null,
            $secret
        );
    }) === 'sale_line_amount_equation_invalid');

$belowCost = $snapshot;
$belowCost['saleLines'][0]['discountAmountCents'] = 6000;
$belowCost['saleLines'][0]['saleAmountCents'] = 4000;
checkoutResign($belowCost);
checkoutAssert('audited price change cannot settle below configured cost',
    checkoutReason(static function () use ($belowCost, $secret): void {
        CashierV3CheckoutSettlementKernel::saveDraft(
            checkoutCommand(CashierV3CheckoutSettlementKernel::OPERATION_SAVE_DRAFT, 31),
            $belowCost,
            null,
            $secret
        );
    }) === 'sale_line_price_audit_invalid');

$atCost = $snapshot;
$atCost['saleLines'][0]['discountAmountCents'] = 5000;
$atCost['saleLines'][0]['saleAmountCents'] = 5000;
$atCost['paymentDetails'][0]['amountCents'] = 0;
$atCost['paymentDetails'][1]['amountCents'] = 0;
$atCost['balanceDeduction']['amountCents'] = 2000;
$atCost['debt']['amountCents'] = 3000;
checkoutResign($atCost);
checkoutAssert('audited price change equal to configured cost is accepted',
    checkoutReason(static function () use ($atCost, $secret): void {
        CashierV3CheckoutSettlementKernel::saveDraft(
            checkoutCommand(CashierV3CheckoutSettlementKernel::OPERATION_SAVE_DRAFT, 32),
            $atCost,
            null,
            $secret
        );
    }) === '');

$fractionalCents = $snapshot;
$fractionalCents['saleLines'][0]['saleAmountCents'] = 8901;
checkoutResign($fractionalCents);
checkoutAssert('new checkout money must be whole yuan',
    checkoutReason(static function () use ($fractionalCents, $secret): void {
        CashierV3CheckoutSettlementKernel::saveDraft(
            checkoutCommand(CashierV3CheckoutSettlementKernel::OPERATION_SAVE_DRAFT, 27),
            $fractionalCents,
            null,
            $secret
        );
    }) === 'money_whole_yuan_required');

$giftShell = $snapshot;
$giftShell['saleLines'][0]['saleClassification'] = 'gift_shell';
checkoutResign($giftShell);
checkoutAssert('gift shell cannot be classified as formal sale',
    checkoutReason(static function () use ($giftShell, $secret): void {
        CashierV3CheckoutSettlementKernel::saveDraft(
            checkoutCommand(CashierV3CheckoutSettlementKernel::OPERATION_SAVE_DRAFT, 15),
            $giftShell,
            null,
            $secret
        );
    }) === 'sale_line_not_formal');

$empty = $entitlementOnly;
$empty['entitlementLines'] = [];
checkoutResign($empty);
checkoutAssert('empty composition is rejected',
    checkoutReason(static function () use ($empty, $secret): void {
        CashierV3CheckoutSettlementKernel::saveDraft(
            checkoutCommand(CashierV3CheckoutSettlementKernel::OPERATION_SAVE_DRAFT, 16),
            $empty,
            null,
            $secret
        );
    }) === 'checkout_composition_empty');

$guestBalance = $saleOnly;
$guestBalance['memberId'] = 0;
$guestBalance['memberName'] = '';
checkoutResign($guestBalance);
checkoutAssert('guest cannot consume member balance',
    checkoutReason(static function () use ($guestBalance, $secret): void {
        CashierV3CheckoutSettlementKernel::saveDraft(
            checkoutCommand(CashierV3CheckoutSettlementKernel::OPERATION_SAVE_DRAFT, 17),
            $guestBalance,
            null,
            $secret
        );
    }) === 'balance_deduction_member_required');

$badPaymentTime = $snapshot;
$badPaymentTime['paymentDetails'][0]['businessTime']++;
checkoutResign($badPaymentTime);
checkoutAssert('payment business time cannot drift from server operation time',
    checkoutReason(static function () use ($badPaymentTime, $secret): void {
        CashierV3CheckoutSettlementKernel::saveDraft(
            checkoutCommand(CashierV3CheckoutSettlementKernel::OPERATION_SAVE_DRAFT, 18),
            $badPaymentTime,
            null,
            $secret
        );
    }) === 'payment_business_time_must_match_authority_operation_time');

$badTimezone = $snapshot;
$badTimezone['businessTimezone'] = 'UTC';
checkoutResign($badTimezone);
checkoutAssert('unsupported business timezone is rejected',
    checkoutReason(static function () use ($badTimezone, $secret): void {
        CashierV3CheckoutSettlementKernel::saveDraft(
            checkoutCommand(CashierV3CheckoutSettlementKernel::OPERATION_SAVE_DRAFT, 19),
            $badTimezone,
            null,
            $secret
        );
    }) === 'business_timezone_not_supported');

$badDate = $snapshot;
$badDate['businessDate'] = '2026-02-31';
checkoutResign($badDate);
checkoutAssert('invalid historical business date is rejected',
    checkoutReason(static function () use ($badDate, $secret): void {
        CashierV3CheckoutSettlementKernel::saveDraft(
            checkoutCommand(CashierV3CheckoutSettlementKernel::OPERATION_SAVE_DRAFT, 20),
            $badDate,
            null,
            $secret
        );
    }) === 'business_date_invalid');

$badRecordedTime = $snapshot;
$badRecordedTime['recordedAt'] = $badRecordedTime['occurredAt'] - 1;
checkoutResign($badRecordedTime);
checkoutAssert('recorded time cannot precede real operation time',
    checkoutReason(static function () use ($badRecordedTime, $secret): void {
        CashierV3CheckoutSettlementKernel::saveDraft(
            checkoutCommand(CashierV3CheckoutSettlementKernel::OPERATION_SAVE_DRAFT, 21),
            $badRecordedTime,
            null,
            $secret
        );
    }) === 'recorded_at_before_occurred_at');

$duplicateLine = $snapshot;
$duplicateLine['saleLines'][] = $duplicateLine['saleLines'][0];
checkoutResign($duplicateLine);
checkoutAssert('duplicate authoritative sale line is rejected',
    checkoutReason(static function () use ($duplicateLine, $secret): void {
        CashierV3CheckoutSettlementKernel::saveDraft(
            checkoutCommand(CashierV3CheckoutSettlementKernel::OPERATION_SAVE_DRAFT, 22),
            $duplicateLine,
            null,
            $secret
        );
    }) === 'sale_authority_key_duplicate');

checkoutAssert('short server ID secret is rejected',
    checkoutReason(static function (): void {
        new CashierV3CheckoutSettlementIdFactory('short');
    }) === 'server_id_secret_invalid');
checkoutAssert('canonicalizer rejects floats independently',
    checkoutReason(static function (): void {
        CashierV3CheckoutSettlementCanonicalizer::fingerprint(['amount' => 1.5]);
    }) === 'canonical_float_forbidden');

$performance = CashierV3CheckoutSettlementKernel::performanceDefinitions();
checkoutAssert('actual performance contract deducts only frozen external allocations',
    $performance['actualPerformance']['formula']
        === 'cashPerformanceCents - externalSalespersonAllocatedCashPerformanceCents'
        && $performance['actualPerformance']['deductedSalespersonTypes'] === ['partner', 'outsourced']
        && $performance['actualPerformance']['employeeTypeSnapshotAtBusinessTimeRequired'] === true
        && $performance['actualPerformance']['implementationStatus'] === 'not_integrated');

$definitions = CashierV3CheckoutSettlementKernel::metricDefinitions();
checkoutAssert('approved sales and cash performance wording is frozen verbatim',
    $definitions['销售额'] === '正式成交的商品、卡项、项目金额，包含现金业绩、余额扣款和欠款覆盖的部分。'
        && $definitions['现金业绩'] === '银联、微信、支付宝、大众验券、抖音验券、合作方收款、其他收款。');
checkoutAssert('approved payment detail and debt wording is frozen verbatim',
    $definitions['收款明细'] === '每一种记账收款分别保存的金额、方式、业务时间、操作人和来源单据。'
        && $definitions['欠款'] === '应收金额中本次未收、允许以后补交的金额；使用七种记账方式补交产生现金业绩。');
checkoutAssert('approved remaining business glossary is complete',
    array_keys($definitions) === [
        '销售额', '现金业绩', '收款明细', '欠款', '储值金额', '余额扣款',
        '销售人业绩', '实际业绩', '消耗业绩', '劳动业绩', '实际提成',
    ]);

$submitFailure = checkoutFailure(static function () use ($ready): void {
    CashierV3CheckoutSettlementKernel::submit(['operation' => 'submit'], checkoutCurrent($ready));
});
checkoutAssert('submit remains fail-closed and status preserving',
    $submitFailure['reason'] === 'checkout_submit_integration_not_ready'
        && $submitFailure['detail']['statusUnchanged'] === true
        && $submitFailure['detail']['businessEffectsWritten'] === false
        && $submitFailure['detail']['businessEffects'] === [
            'checkoutSucceeded' => false,
            'saleFacts' => 0,
            'paymentCollectedFacts' => 0,
            'balanceMutations' => 0,
            'debtMutations' => 0,
            'entitlementMutations' => 0,
            'performanceFacts' => 0,
            'businessEvents' => 0,
            'outboxRows' => 0,
        ]);
$requirements = $submitFailure['detail']['integrationRequirements'] ?? [];
checkoutAssert('submit reports payment balance debt event and inventory gates',
    in_array('seven_method_payment_collection_writer', $requirements, true)
        && in_array('member_balance_atomic_writer', $requirements, true)
        && in_array('debt_atomic_writer', $requirements, true)
        && in_array('cashier_v3_business_event_and_outbox', $requirements, true)
        && in_array('inventory-entitlement-completion-provider-v1', $requirements, true));

$inventoryContract = CashierV3CheckoutSettlementKernel::inventoryIntegrationContract();
checkoutAssert('inventory provider and lock contract is frozen without activation',
    $inventoryContract['providerContractVersion'] === 'inventory-entitlement-completion-provider-v1'
        && $inventoryContract['consumerContractVersion'] === 'c2-entitlement-completion-v3'
        && $inventoryContract['gatewayActivationAllowed'] === false
        && $inventoryContract['finalBusinessTransactionOwnsProviderCall'] === true
        && $inventoryContract['dataScope'] === ['tenant', 'store']
        && $inventoryContract['lockOrder'] === [
            'inventory_policy' => 46,
            'inventory_recipe' => 47,
            'inventory_stock' => 50,
            'inventory_batch' => 55,
            'inventory_shortage_cursor' => 56,
        ]);
checkoutAssert('inventory provider DTO names remain byte-for-byte compatible',
    $inventoryContract['policyAndRecipeFields'] === [
        'merchantDefaultPolicy', 'merchantDefaultPolicyVersion',
        'productPolicyOverride', 'productPolicyVersion', 'policy', 'policyVersion',
        'recipeId', 'recipeVersion', 'recipeFormulaHash',
    ]
        && $inventoryContract['consumableFields'] === [
            'stockId', 'quantityUnitsPerService', 'shortageEstimatedUnitCostCents',
            'shortageCostAllocatedQuantityUnitsBefore', 'shortageCursorId',
            'shortageCursorVersion',
        ]
        && $inventoryContract['stockFields'] === ['stockVersion', 'stockUnitScale', 'batches']
        && $inventoryContract['batchFields'] === [
            'batchId', 'batchVersion', 'availableQuantityUnits', 'unitCostCents',
            'costAllocatedQuantityUnitsBefore', 'allocationOrder',
        ]);
checkoutAssert('inventory shortage and history semantics remain provider-owned',
    $inventoryContract['strictShortageBlocksAll'] === true
        && $inventoryContract['allowShortageConsumesRealBatchesOnly'] === true
        && $inventoryContract['negativeOrSyntheticBatchForbidden'] === true
        && $inventoryContract['shortageCursorLockGate'] === 'explicit_resource_plan_locked_v1'
        && $inventoryContract['shortageCursorStableResourceId']
            === 'stockId:recipeId:estimatedUnitCostCents'
        && $inventoryContract['lockedInventoryResourcesMustCoverEveryShortageCursor'] === true
        && $inventoryContract['providerOwnsNaturalKeyIdempotencyReversalAndCostAdjustment'] === true);

$terminal = checkoutCurrent($ready);
$terminal['status'] = CashierV3CheckoutSettlementStateMachine::SUCCEEDED;
$terminalCommand = checkoutCommand(CashierV3CheckoutSettlementKernel::OPERATION_SAVE_DRAFT, 23);
$terminalCommand['requestId'] = $ready['requestId'];
$terminalCommand['expectedVersion'] = $ready['requestVersion'];
checkoutAssert('successful request is terminal and not editable',
    checkoutReason(static function () use ($terminalCommand, $snapshot, $terminal, $secret): void {
        CashierV3CheckoutSettlementKernel::saveDraft($terminalCommand, $snapshot, $terminal, $secret);
    }) === 'checkout_request_not_editable');
checkoutAssert('state machine rejects direct editing to succeeded transition',
    checkoutReason(static function (): void {
        CashierV3CheckoutSettlementStateMachine::assertTransition('editing', 'succeeded');
    }) === 'checkout_request_transition_invalid');

echo "CHECKOUT_SETTLEMENT_ASSERTIONS={$passed} failed={$failed}\n";
if ($failed > 0) {
    exit(1);
}
echo "CHECKOUT_SETTLEMENT_CONTRACT=PASS\n";
