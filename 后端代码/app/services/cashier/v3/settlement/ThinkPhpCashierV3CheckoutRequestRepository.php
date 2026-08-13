<?php

namespace app\services\cashier\v3\settlement;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResourceScope;
use app\services\cashier\v3\CashierV3ResourceVersionServices;
use app\services\cashier\v3\CashierV3TransactionGuard;
use think\facade\Db;

/**
 * ThinkPHP persistence adapter for the eventless checkout request aggregate.
 *
 * The caller owns the final transaction. This repository deliberately writes
 * only the request, its current draft children and its server-verified source
 * references. It never writes sale/payment facts, balances, debt, entitlement,
 * inventory, performance, business events or outbox rows.
 */
final class ThinkPhpCashierV3CheckoutRequestRepository implements CashierV3CheckoutRequestRepository
{
    public const REQUEST_TABLE = 'cashier_v3_checkout_request';
    public const LINE_TABLE = 'cashier_v3_checkout_line_draft';
    public const PAYMENT_TABLE = 'cashier_v3_checkout_payment_draft';
    public const SOURCE_TABLE = 'cashier_v3_checkout_source_reference';

    private const SALES_ORDER_TABLE = 'cashier_v3_sales_order';
    private const ENTITLEMENT_COMPLETION_RECEIPT_TABLE = 'cashier_v3_entitlement_completion_receipt';

    private const REQUEST_PLAN_TABLE = 'eb_cashier_v3_checkout_request';
    private const LINE_PLAN_TABLE = 'eb_cashier_v3_checkout_line_draft';
    private const PAYMENT_PLAN_TABLE = 'eb_cashier_v3_checkout_payment_draft';

    private const REQUEST_MAP = [
        'requestId' => 'request_id',
        'tenantId' => 'tenant_id',
        'organizationId' => 'organization_id',
        'organizationPath' => 'organization_path',
        'organizationNameSnapshot' => 'organization_name_snapshot',
        'workspaceId' => 'workspace_id',
        'stateContextId' => 'state_context_id',
        'storeId' => 'store_id',
        'storeNameSnapshot' => 'store_name_snapshot',
        'memberId' => 'member_id',
        'memberNameSnapshot' => 'member_name_snapshot',
        'operatorId' => 'operator_id',
        'operatorNameSnapshot' => 'operator_name_snapshot',
        'requestVersion' => 'request_version',
        'requestStatus' => 'request_status',
        'composition' => 'composition',
        'businessDate' => 'business_date',
        'businessTimezone' => 'business_timezone',
        'operationOccurredAt' => 'operation_occurred_at',
        'recordedAt' => 'recorded_at',
        'orderNote' => 'order_note',
        'supplementEnabled' => 'supplement_enabled',
        'supplementReason' => 'supplement_reason',
        'supplementOperatorId' => 'supplement_operator_id',
        'supplementOperatorNameSnapshot' => 'supplement_operator_name_snapshot',
        'supplementOperatedAt' => 'supplement_operated_at',
        'sourceDocumentType' => 'source_document_type',
        'sourceDocumentId' => 'source_document_id',
        'sourceDocumentNo' => 'source_document_no',
        'salesAmountCents' => 'sales_amount_cents',
        'receivableAmountCents' => 'receivable_amount_cents',
        'selectedPaymentAmountCents' => 'selected_payment_amount_cents',
        'balanceDeductionAmountCents' => 'balance_deduction_amount_cents',
        'balanceAuthorityKey' => 'balance_authority_key',
        'balanceAccountId' => 'balance_account_id',
        'balanceAccountVersion' => 'balance_account_version',
        'debtAmountCents' => 'debt_amount_cents',
        'debtAuthorityKey' => 'debt_authority_key',
        'debtPolicyVersion' => 'debt_policy_version',
        'cashPerformanceAmountCents' => 'cash_performance_amount_cents',
        'entitlementActualAmountCents' => 'entitlement_actual_amount_cents',
        'authoritySnapshotVersion' => 'authority_snapshot_version',
        'authorityFingerprint' => 'authority_fingerprint',
        'aggregateFingerprint' => 'aggregate_fingerprint',
        'creationIdempotencyKey' => 'creation_idempotency_key',
        'lastIdempotencyKey' => 'last_idempotency_key',
        'lastOperationFingerprint' => 'last_operation_fingerprint',
        'lastOperation' => 'last_operation',
    ];

    private const LINE_MAP = [
        'lineId' => 'line_id',
        'requestId' => 'request_id',
        'draftVersion' => 'draft_version',
        'draftStatus' => 'draft_status',
        'tenantId' => 'tenant_id',
        'storeId' => 'store_id',
        'memberId' => 'member_id',
        'lineRole' => 'line_role',
        'authorityKey' => 'authority_key',
        'sourceKind' => 'source_kind',
        'sourceType' => 'source_type',
        'sourceId' => 'source_id',
        'catalogSkuId' => 'catalog_sku_id',
        'entitlementSourceDetailId' => 'entitlement_source_detail_id',
        'sourceVersion' => 'source_version',
        'projectId' => 'project_id',
        'projectVersion' => 'project_version',
        'serviceObject' => 'service_object',
        'isExperience' => 'is_experience',
        'quantity' => 'quantity',
        'originalAmountCents' => 'original_amount_cents',
        'discountAmountCents' => 'discount_amount_cents',
        'saleAmountCents' => 'sale_amount_cents',
        'debtAmountCents' => 'debt_amount_cents',
        'entitlementActualAmountCents' => 'entitlement_actual_amount_cents',
        'sourceNameSnapshot' => 'source_name_snapshot',
        'sourceCodeSnapshot' => 'source_code_snapshot',
        'projectNameSnapshot' => 'project_name_snapshot',
        'categoryIdSnapshot' => 'category_id_snapshot',
        'categoryNameSnapshot' => 'category_name_snapshot',
        'configuredCostCents' => 'configured_cost_cents',
        'priceChangeReason' => 'price_change_reason',
        'priceChangedBy' => 'price_changed_by',
        'priceChangedByNameSnapshot' => 'price_changed_by_name_snapshot',
        'priceChangedAt' => 'price_changed_at',
        // Coupon identity and discount are part of the immutable checkout
        // line snapshot. Omitting these mappings silently downgraded a valid
        // workspace coupon to coupon_user_id=0 during prepare-checkout.
        'couponUserId' => 'coupon_user_id',
        'couponNameSnapshot' => 'coupon_name_snapshot',
        'couponDiscountCents' => 'coupon_discount_cents',
        'craftsmenSnapshotJson' => 'craftsmen_snapshot_json',
        'guideSelectionsJson' => 'guide_selections_json',
        'salesManagerSelectionsJson' => 'sales_manager_selections_json',
        'manualLaborFeeCents' => 'manual_labor_fee_cents',
        'lineFingerprint' => 'line_fingerprint',
        'sortNo' => 'sort_no',
    ];

    private const PAYMENT_MAP = [
        'paymentDraftId' => 'payment_draft_id',
        'requestId' => 'request_id',
        'draftVersion' => 'draft_version',
        'tenantId' => 'tenant_id',
        'storeId' => 'store_id',
        'memberId' => 'member_id',
        'operatorId' => 'operator_id',
        'paymentAuthorityKey' => 'payment_authority_key',
        'paymentMethod' => 'payment_method',
        'amountCents' => 'amount_cents',
        'externalTransactionNo' => 'external_transaction_no',
        'remark' => 'remark',
        'businessDate' => 'business_date',
        'businessTimezone' => 'business_timezone',
        'operationOccurredAt' => 'operation_occurred_at',
        'recordedAt' => 'recorded_at',
        'operatorNameSnapshot' => 'operator_name_snapshot',
        'sourceDocumentType' => 'source_document_type',
        'sourceDocumentId' => 'source_document_id',
        'sourceDocumentNo' => 'source_document_no',
        'paymentFingerprint' => 'payment_fingerprint',
        'draftStatus' => 'draft_status',
        'sortNo' => 'sort_no',
    ];

    /** @var CashierV3CheckoutResourcePlanRepository|null */
    private $resourcePlanRepository;

    public function __construct(CashierV3CheckoutResourcePlanRepository $resourcePlanRepository = null)
    {
        $this->resourcePlanRepository = $resourcePlanRepository;
    }

    public function lockAggregateForEditInTx(
        string $requestId,
        int $expectedVersion,
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        CashierV3TransactionGuard::assertInTransaction('checkoutRequestAggregateEdit');
        $this->assertScopeContext($operatorScope, $dataScope);
        $requestId = trim($requestId);
        $workspaceId = trim($workspaceId);
        $stateContextId = trim($stateContextId);
        $expectedWorkspaceId = sprintf(
            'ws:%d:%d:%s',
            $operatorScope->storeId(),
            $operatorScope->operatorId(),
            $stateContextId
        );
        if (!$this->validRequestId($requestId)
            || $expectedVersion <= 0
            || $stateContextId === ''
            || strlen($stateContextId) > 64
            || strlen($workspaceId) > 64
            || !hash_equals($expectedWorkspaceId, $workspaceId)) {
            throw self::failure('checkout_edit_identity_invalid');
        }

        $request = Db::name(self::REQUEST_TABLE)
            ->where('request_id', $requestId)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('store_id', $dataScope->forcedStoreId())
            ->where('operator_id', $operatorScope->operatorId())
            ->where('workspace_id', $workspaceId)
            ->where('state_context_id', $stateContextId)
            ->where('request_status', 'editing')
            ->lock(true)
            ->find();
        if (!$request) {
            throw self::failure('checkout_edit_request_not_found');
        }
        if ((int)($request['request_version'] ?? 0) !== $expectedVersion) {
            throw self::failure('checkout_request_version_conflict', [
                'requestId' => $requestId,
                'expectedVersion' => $expectedVersion,
                'currentVersion' => (int)($request['request_version'] ?? 0),
            ]);
        }

        $lines = $this->rows(Db::name(self::LINE_TABLE)
            ->where('request_id', $requestId)
            ->where('draft_version', $expectedVersion)
            ->where('draft_status', 'draft')
            ->order('sort_no asc,id asc')
            ->lock(true)
            ->select());
        $payments = $this->rows(Db::name(self::PAYMENT_TABLE)
            ->where('request_id', $requestId)
            ->where('draft_version', $expectedVersion)
            ->where('draft_status', 'draft')
            ->order('sort_no asc,id asc')
            ->lock(true)
            ->select());
        $sources = $this->rows(Db::name(self::SOURCE_TABLE)
            ->where('request_id', $requestId)
            ->order('source_kind asc,source_id asc,source_role asc')
            ->lock(true)
            ->select());
        if (!$lines) {
            throw self::failure('checkout_edit_lines_missing');
        }

        $authorityRows = [];
        foreach ($sources as $index => $row) {
            $kind = (string)($row['source_kind'] ?? '');
            $id = (string)($row['source_id'] ?? '');
            $sourceVersion = (int)($row['source_version'] ?? 0);
            $role = (string)($row['source_role'] ?? '');
            if ((string)($row['tenant_id'] ?? '') !== $dataScope->tenantId()
                || (int)($row['store_id'] ?? 0) !== $dataScope->forcedStoreId()
                || (int)($row['bound_request_version'] ?? 0) !== $expectedVersion) {
                throw self::failure('checkout_source_binding_version_drift', ['index' => $index]);
            }
            $expectedFingerprint = CashierV3CheckoutVerifiedSourceSet::referenceFingerprint(
                $kind,
                $id,
                $sourceVersion,
                $role
            );
            if (!hash_equals(
                (string)($row['source_fingerprint'] ?? ''),
                $expectedFingerprint
            )) {
                throw self::failure('checkout_source_reference_fingerprint_drift', [
                    'index' => $index,
                ]);
            }
            $authorityRows[] = [
                'tenantId' => $dataScope->tenantId(),
                'storeId' => $dataScope->forcedStoreId(),
                'kind' => $kind,
                'id' => $id,
                'sourceVersion' => $sourceVersion,
                'role' => $role,
            ];
        }
        $verifiedSources = CashierV3CheckoutVerifiedSourceSet::fromServerVerifiedAuthorityRows(
            $dataScope->tenantId(),
            $dataScope->forcedStoreId(),
            $authorityRows
        );

        return [
            'request' => is_array($request) ? $request : (array)$request,
            'lines' => $lines,
            'payments' => $payments,
            'sources' => $sources,
            'currentRequest' => $this->kernelCurrentFromRow($request),
            'verifiedSources' => $verifiedSources,
        ];
    }

    public function lockAggregateForSubmitInTx(
        string $requestId,
        int $expectedVersion,
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        CashierV3TransactionGuard::assertInTransaction('checkoutRequestAggregateSubmit');
        $this->assertScopeContext($operatorScope, $dataScope);
        $requestId = trim($requestId);
        $workspaceId = trim($workspaceId);
        $stateContextId = trim($stateContextId);
        $expectedWorkspaceId = sprintf(
            'ws:%d:%d:%s',
            $operatorScope->storeId(),
            $operatorScope->operatorId(),
            $stateContextId
        );
        if (!$this->validRequestId($requestId)
            || $expectedVersion <= 0
            || $stateContextId === ''
            || strlen($stateContextId) > 64
            || strlen($workspaceId) > 64
            || !hash_equals($expectedWorkspaceId, $workspaceId)) {
            throw self::failure('checkout_submit_identity_invalid');
        }

        $request = Db::name(self::REQUEST_TABLE)
            ->where('request_id', $requestId)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('store_id', $dataScope->forcedStoreId())
            ->where('operator_id', $operatorScope->operatorId())
            ->where('workspace_id', $workspaceId)
            ->where('state_context_id', $stateContextId)
            ->where('request_status', CashierV3CheckoutSettlementStateMachine::READY_FOR_SUBMIT)
            ->lock(true)
            ->find();
        if (!$request) {
            throw self::failure('checkout_submit_request_not_ready');
        }
        if ((int)($request['request_version'] ?? 0) !== $expectedVersion) {
            throw self::failure('checkout_request_version_conflict', [
                'requestId' => $requestId,
                'expectedVersion' => $expectedVersion,
                'currentVersion' => (int)($request['request_version'] ?? 0),
            ]);
        }

        $lines = $this->rows(Db::name(self::LINE_TABLE)
            ->where('request_id', $requestId)
            ->where('draft_version', $expectedVersion)
            ->where('draft_status', 'draft')
            ->order('sort_no asc,id asc')
            ->lock(true)
            ->select());
        $payments = $this->rows(Db::name(self::PAYMENT_TABLE)
            ->where('request_id', $requestId)
            ->where('draft_version', $expectedVersion)
            ->where('draft_status', 'draft')
            ->order('sort_no asc,id asc')
            ->lock(true)
            ->select());
        $sources = $this->rows(Db::name(self::SOURCE_TABLE)
            ->where('request_id', $requestId)
            ->order('source_kind asc,source_id asc,source_role asc')
            ->lock(true)
            ->select());
        $composition = (string)($request['composition'] ?? '');
        $entitlementOnly = $composition === CashierV3CheckoutSettlementKernel::COMPOSITION_ENTITLEMENT_ONLY;
        if (!$lines
            || ($entitlementOnly && $payments !== [])
            || (!$entitlementOnly && !$this->hasSettlementSource($request, count($payments)))
            || ($entitlementOnly
                && ((int)($request['sales_amount_cents'] ?? -1) !== 0
                    || (int)($request['receivable_amount_cents'] ?? -1) !== 0
                    || (int)($request['selected_payment_amount_cents'] ?? -1) !== 0
                    || (int)($request['balance_deduction_amount_cents'] ?? -1) !== 0
                    || (int)($request['debt_amount_cents'] ?? -1) !== 0
                    || (int)($request['cash_performance_amount_cents'] ?? -1) !== 0))) {
            throw self::failure('checkout_submit_drafts_missing');
        }

        $authorityRows = [];
        foreach ($sources as $index => $row) {
            $kind = (string)($row['source_kind'] ?? '');
            $id = (string)($row['source_id'] ?? '');
            $sourceVersion = (int)($row['source_version'] ?? 0);
            $role = (string)($row['source_role'] ?? '');
            if ((string)($row['tenant_id'] ?? '') !== $dataScope->tenantId()
                || (int)($row['store_id'] ?? 0) !== $dataScope->forcedStoreId()
                || (int)($row['bound_request_version'] ?? 0) !== $expectedVersion) {
                throw self::failure('checkout_source_binding_version_drift', ['index' => $index]);
            }
            $expectedFingerprint = CashierV3CheckoutVerifiedSourceSet::referenceFingerprint(
                $kind,
                $id,
                $sourceVersion,
                $role
            );
            if (!hash_equals((string)($row['source_fingerprint'] ?? ''), $expectedFingerprint)) {
                throw self::failure('checkout_source_reference_fingerprint_drift', ['index' => $index]);
            }
            $authorityRows[] = [
                'tenantId' => $dataScope->tenantId(),
                'storeId' => $dataScope->forcedStoreId(),
                'kind' => $kind,
                'id' => $id,
                'sourceVersion' => $sourceVersion,
                'role' => $role,
            ];
        }
        $verifiedSources = CashierV3CheckoutVerifiedSourceSet::fromServerVerifiedAuthorityRows(
            $dataScope->tenantId(),
            $dataScope->forcedStoreId(),
            $authorityRows
        );

        return [
            'request' => is_array($request) ? $request : (array)$request,
            'lines' => $lines,
            'payments' => $payments,
            'sources' => $sources,
            'currentRequest' => $this->kernelCurrentFromRow($request),
            'verifiedSources' => $verifiedSources,
        ];
    }

    public function markSucceededInTx(
        string $requestId,
        int $expectedVersion,
        string $commandIdempotencyKey,
        string $completionReferenceId,
        int $settledAt,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        CashierV3TransactionGuard::assertInTransaction('checkoutRequestMarkSucceeded');
        $this->assertScopeContext($operatorScope, $dataScope);
        $requestId = trim($requestId);
        $commandIdempotencyKey = trim($commandIdempotencyKey);
        $completionReferenceId = trim($completionReferenceId);
        if (!$this->validRequestId($requestId)
            || $expectedVersion <= 0
            || !$this->validIdempotencyKey($commandIdempotencyKey)
            || $completionReferenceId === ''
            || $settledAt <= 0) {
            throw self::failure('checkout_submit_completion_identity_invalid');
        }

        $request = Db::name(self::REQUEST_TABLE)
            ->where('request_id', $requestId)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('organization_id', $dataScope->organizationId())
            ->where('store_id', $dataScope->forcedStoreId())
            ->where('operator_id', $operatorScope->operatorId())
            ->where('request_version', $expectedVersion)
            ->where('request_status', CashierV3CheckoutSettlementStateMachine::READY_FOR_SUBMIT)
            ->lock(true)
            ->find();
        if (!$request) {
            throw self::failure('checkout_submit_request_not_ready');
        }
        $request = is_array($request) ? $request : (array)$request;
        $completionReference = $this->lockCompletionAuthorityInTx(
            $request,
            $commandIdempotencyKey,
            $completionReferenceId,
            $settledAt,
            $operatorScope,
            $dataScope
        );
        $composition = (string)($request['composition'] ?? '');
        $entitlementOnly = $composition === CashierV3CheckoutSettlementKernel::COMPOSITION_ENTITLEMENT_ONLY;

        CashierV3CheckoutSettlementStateMachine::assertTransition(
            CashierV3CheckoutSettlementStateMachine::READY_FOR_SUBMIT,
            CashierV3CheckoutSettlementStateMachine::SUBMITTING
        );
        CashierV3CheckoutSettlementStateMachine::assertTransition(
            CashierV3CheckoutSettlementStateMachine::SUBMITTING,
            CashierV3CheckoutSettlementStateMachine::SUCCEEDED
        );
        $submitting = (int)Db::name(self::REQUEST_TABLE)
            ->where('request_id', $requestId)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('store_id', $dataScope->forcedStoreId())
            ->where('operator_id', $operatorScope->operatorId())
            ->where('request_version', $expectedVersion)
            ->where('request_status', CashierV3CheckoutSettlementStateMachine::READY_FOR_SUBMIT)
            ->update([
                'request_status' => CashierV3CheckoutSettlementStateMachine::SUBMITTING,
                'update_time' => $settledAt,
            ]);
        $this->assertAffected('checkout_request_mark_submitting', $submitting, 1);

        $lineCount = $this->lockedDraftChildCount(self::LINE_TABLE, $requestId, $expectedVersion);
        $paymentCount = $this->lockedDraftChildCount(self::PAYMENT_TABLE, $requestId, $expectedVersion);
        if ($lineCount <= 0
            || ($entitlementOnly && $paymentCount !== 0)
            || (!$entitlementOnly && !$this->hasSettlementSource($request, $paymentCount))) {
            throw self::failure('checkout_submit_drafts_missing');
        }
        $lineAffected = (int)Db::name(self::LINE_TABLE)
            ->where('request_id', $requestId)
            ->where('draft_version', $expectedVersion)
            ->where('draft_status', 'draft')
            ->update(['draft_status' => 'committed', 'update_time' => $settledAt]);
        $paymentAffected = $paymentCount === 0 ? 0 : (int)Db::name(self::PAYMENT_TABLE)
            ->where('request_id', $requestId)
            ->where('draft_version', $expectedVersion)
            ->where('draft_status', 'draft')
            ->update(['draft_status' => 'committed', 'update_time' => $settledAt]);
        $this->assertAffected('checkout_line_drafts_commit', $lineAffected, $lineCount);
        $this->assertAffected('checkout_payment_drafts_commit', $paymentAffected, $paymentCount);

        $operationFingerprint = CashierV3CheckoutSettlementCanonicalizer::fingerprint([
            'contractVersion' => 'cashier-v3-checkout-submit-success-v1',
            'requestId' => $requestId,
            'expectedVersion' => $expectedVersion,
            'nextVersion' => $expectedVersion + 1,
            'commandIdempotencyKey' => $commandIdempotencyKey,
            'composition' => $composition,
            'completionReferenceType' => $completionReference['type'],
            'completionReferenceId' => $completionReference['id'],
            'salesOrderId' => $completionReference['salesOrderId'],
            'entitlementCompletionReceiptId' =>
                $completionReference['entitlementCompletionReceiptId'],
            'settledAt' => $settledAt,
        ]);
        $completed = (int)Db::name(self::REQUEST_TABLE)
            ->where('request_id', $requestId)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('store_id', $dataScope->forcedStoreId())
            ->where('operator_id', $operatorScope->operatorId())
            ->where('request_version', $expectedVersion)
            ->where('request_status', CashierV3CheckoutSettlementStateMachine::SUBMITTING)
            ->update([
                'request_version' => $expectedVersion + 1,
                'request_status' => CashierV3CheckoutSettlementStateMachine::SUCCEEDED,
                'last_idempotency_key' => $commandIdempotencyKey,
                'last_operation_fingerprint' => $operationFingerprint,
                'last_operation' => CashierV3CheckoutSettlementKernel::OPERATION_SUBMIT,
                'update_time' => $settledAt,
            ]);
        $this->assertAffected('checkout_request_mark_succeeded', $completed, 1);

        return [
            'contractVersion' => 'cashier-v3-checkout-submit-success-v1',
            'checkoutRequestId' => $requestId,
            'previousVersion' => $expectedVersion,
            'checkoutRequestVersion' => $expectedVersion + 1,
            'requestStatus' => CashierV3CheckoutSettlementStateMachine::SUCCEEDED,
            'composition' => $composition,
            'completionReference' => $completionReference,
            'salesOrderId' => (string)($completionReference['salesOrderId'] ?? ''),
            'entitlementCompletionReceiptId' => (string)(
                $completionReference['entitlementCompletionReceiptId'] ?? ''
            ),
            'settledAt' => $settledAt,
            'operationFingerprint' => $operationFingerprint,
        ];
    }

    public function readLatestEditingProjection(
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ) {
        $this->assertScopeContext($operatorScope, $dataScope);
        $workspaceId = trim($workspaceId);
        $stateContextId = trim($stateContextId);
        $expectedWorkspaceId = sprintf(
            'ws:%d:%d:%s',
            $operatorScope->storeId(),
            $operatorScope->operatorId(),
            $stateContextId
        );
        if ($stateContextId === ''
            || strlen($workspaceId) > 64
            || strlen($stateContextId) > 64
            || !hash_equals($expectedWorkspaceId, $workspaceId)) {
            throw self::failure('checkout_projection_workspace_invalid');
        }

        $workspaceVersion = (int)Db::name(CashierV3ResourceVersionServices::TABLE)
            ->where('scope_type', CashierV3ResourceScope::TYPE_STORE)
            ->where('scope_id', (string)$operatorScope->storeId())
            ->where('resource_kind', 'cashier_workspace')
            ->where('resource_id', $workspaceId)
            ->value('current_version');
        if ($workspaceVersion <= 1) {
            return null;
        }
        $preparedWorkspaceVersion = $workspaceVersion - 1;

        $request = Db::name(self::REQUEST_TABLE)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('store_id', $dataScope->forcedStoreId())
            ->where('operator_id', $operatorScope->operatorId())
            ->where('workspace_id', $workspaceId)
            ->where('state_context_id', $stateContextId)
            ->whereIn('request_status', ['editing', 'ready_for_submit'])
            ->where('authority_snapshot_version', $preparedWorkspaceVersion)
            ->order('update_time desc,id desc')
            ->find();
        if (!$request) {
            return null;
        }

        $requestId = (string)($request['request_id'] ?? '');
        $requestVersion = (int)($request['request_version'] ?? 0);
        $requestStatus = (string)($request['request_status'] ?? '');
        if (!$this->validRequestId($requestId) || $requestVersion <= 0) {
            throw self::failure('checkout_projection_request_invalid');
        }
        $lines = $this->rows(Db::name(self::LINE_TABLE)
            ->where('request_id', $requestId)
            ->where('draft_version', $requestVersion)
            ->where('draft_status', 'draft')
            ->order('sort_no asc,id asc')
            ->select());
        $payments = $this->rows(Db::name(self::PAYMENT_TABLE)
            ->where('request_id', $requestId)
            ->where('draft_version', $requestVersion)
            ->where('draft_status', 'draft')
            ->order('sort_no asc,id asc')
            ->select());
        $sources = $this->rows(Db::name(self::SOURCE_TABLE)
            ->where('request_id', $requestId)
            ->order('source_kind asc,source_id asc,source_role asc')
            ->select());

        // This projection is deliberately lock free. Re-read both authorities
        // after the child collections so a concurrent cart or checkout CAS is
        // returned as no current projection instead of a mixed snapshot.
        $workspaceVersionAfter = (int)Db::name(CashierV3ResourceVersionServices::TABLE)
            ->where('scope_type', CashierV3ResourceScope::TYPE_STORE)
            ->where('scope_id', (string)$operatorScope->storeId())
            ->where('resource_kind', 'cashier_workspace')
            ->where('resource_id', $workspaceId)
            ->value('current_version');
        $requestAfter = Db::name(self::REQUEST_TABLE)
            ->where('request_id', $requestId)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('store_id', $dataScope->forcedStoreId())
            ->field(
                'request_version,request_status,authority_snapshot_version,'
                . 'aggregate_fingerprint,last_operation_fingerprint'
            )
            ->find();
        if ($workspaceVersionAfter !== $workspaceVersion
            || !$requestAfter
            || (int)($requestAfter['request_version'] ?? 0) !== $requestVersion
            || (string)($requestAfter['request_status'] ?? '') !== $requestStatus
            || (int)($requestAfter['authority_snapshot_version'] ?? 0) !== $preparedWorkspaceVersion
            || !hash_equals(
                (string)($request['aggregate_fingerprint'] ?? ''),
                (string)($requestAfter['aggregate_fingerprint'] ?? '')
            )
            || !hash_equals(
                (string)($request['last_operation_fingerprint'] ?? ''),
                (string)($requestAfter['last_operation_fingerprint'] ?? '')
            )) {
            return null;
        }

        return [
            'request' => is_array($request) ? $request : (array)$request,
            'lines' => $lines,
            'payments' => $payments,
            'sources' => $sources,
            'workspaceCurrentVersion' => $workspaceVersion,
        ];
    }

    public function readEditingProjectionByRequestId(
        string $requestId,
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ) {
        $this->assertScopeContext($operatorScope, $dataScope);
        $requestId = trim($requestId);
        $workspaceId = trim($workspaceId);
        $stateContextId = trim($stateContextId);
        $expectedWorkspaceId = sprintf(
            'ws:%d:%d:%s',
            $operatorScope->storeId(),
            $operatorScope->operatorId(),
            $stateContextId
        );
        if (!$this->validRequestId($requestId)
            || $stateContextId === ''
            || strlen($workspaceId) > 64
            || strlen($stateContextId) > 64
            || !hash_equals($expectedWorkspaceId, $workspaceId)) {
            throw self::failure('checkout_projection_request_invalid');
        }

        $workspaceVersion = (int)Db::name(CashierV3ResourceVersionServices::TABLE)
            ->where('scope_type', CashierV3ResourceScope::TYPE_STORE)
            ->where('scope_id', (string)$operatorScope->storeId())
            ->where('resource_kind', 'cashier_workspace')
            ->where('resource_id', $workspaceId)
            ->value('current_version');
        if ($workspaceVersion <= 1) {
            return null;
        }

        $request = Db::name(self::REQUEST_TABLE)
            ->where('request_id', $requestId)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('store_id', $dataScope->forcedStoreId())
            ->where('operator_id', $operatorScope->operatorId())
            ->where('workspace_id', $workspaceId)
            ->where('state_context_id', $stateContextId)
            ->whereIn('request_status', ['editing', 'ready_for_submit'])
            ->find();
        if (!$request) {
            return null;
        }

        $requestVersion = (int)($request['request_version'] ?? 0);
        $requestStatus = (string)($request['request_status'] ?? '');
        $authoritySnapshotVersion = (int)($request['authority_snapshot_version'] ?? 0);
        if ($requestVersion <= 0
            || $authoritySnapshotVersion <= 0
            || $authoritySnapshotVersion >= $workspaceVersion) {
            return null;
        }
        $lines = $this->rows(Db::name(self::LINE_TABLE)
            ->where('request_id', $requestId)
            ->where('draft_version', $requestVersion)
            ->where('draft_status', 'draft')
            ->order('sort_no asc,id asc')
            ->select());
        $payments = $this->rows(Db::name(self::PAYMENT_TABLE)
            ->where('request_id', $requestId)
            ->where('draft_version', $requestVersion)
            ->where('draft_status', 'draft')
            ->order('sort_no asc,id asc')
            ->select());
        $sources = $this->rows(Db::name(self::SOURCE_TABLE)
            ->where('request_id', $requestId)
            ->order('source_kind asc,source_id asc,source_role asc')
            ->select());

        $workspaceVersionAfter = (int)Db::name(CashierV3ResourceVersionServices::TABLE)
            ->where('scope_type', CashierV3ResourceScope::TYPE_STORE)
            ->where('scope_id', (string)$operatorScope->storeId())
            ->where('resource_kind', 'cashier_workspace')
            ->where('resource_id', $workspaceId)
            ->value('current_version');
        $requestAfter = Db::name(self::REQUEST_TABLE)
            ->where('request_id', $requestId)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('store_id', $dataScope->forcedStoreId())
            ->where('operator_id', $operatorScope->operatorId())
            ->where('workspace_id', $workspaceId)
            ->where('state_context_id', $stateContextId)
            ->field(
                'request_version,request_status,authority_snapshot_version,'
                . 'aggregate_fingerprint,last_operation_fingerprint'
            )
            ->find();
        if ($workspaceVersionAfter !== $workspaceVersion
            || !$requestAfter
            || (int)($requestAfter['request_version'] ?? 0) !== $requestVersion
            || (string)($requestAfter['request_status'] ?? '') !== $requestStatus
            || (int)($requestAfter['authority_snapshot_version'] ?? 0) !== $authoritySnapshotVersion
            || !hash_equals(
                (string)($request['aggregate_fingerprint'] ?? ''),
                (string)($requestAfter['aggregate_fingerprint'] ?? '')
            )
            || !hash_equals(
                (string)($request['last_operation_fingerprint'] ?? ''),
                (string)($requestAfter['last_operation_fingerprint'] ?? '')
            )) {
            return null;
        }

        return [
            'request' => is_array($request) ? $request : (array)$request,
            'lines' => $lines,
            'payments' => $payments,
            'sources' => $sources,
            'workspaceCurrentVersion' => $workspaceVersion,
            // Only a committed command receipt may select this exact request.
            // Workspace-only draft settings can legitimately advance beyond
            // the original checkout authority snapshot without rebuilding it.
            'exactRequestProjection' => true,
        ];
    }

    public function lockCurrentForKernelInTx(
        string $requestId,
        string $creationIdempotencyKey,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ) {
        CashierV3TransactionGuard::assertInTransaction('checkoutRequestCurrent');
        $this->assertScopeContext($operatorScope, $dataScope);
        $requestId = trim($requestId);
        $creationIdempotencyKey = trim($creationIdempotencyKey);
        if ($requestId !== '' && !$this->validRequestId($requestId)) {
            throw self::failure('checkout_request_id_invalid');
        }
        if (!$this->validIdempotencyKey($creationIdempotencyKey)) {
            throw self::failure('checkout_creation_idempotency_key_invalid');
        }

        if ($requestId !== '') {
            $row = Db::name(self::REQUEST_TABLE)
                ->where('tenant_id', $dataScope->tenantId())
                ->where('store_id', $dataScope->forcedStoreId())
                ->where('request_id', $requestId)
                ->lock(true)
                ->find();
            return $row ? $this->kernelCurrentFromRow($row) : null;
        }

        // Do not SELECT a missing unique creation key FOR UPDATE. Two creators
        // would hold compatible gap locks and can deadlock while upgrading to
        // inserts. The unique insert serializes creation; only an existing row
        // is re-read and locked by its stable request ID.
        $discovered = Db::name(self::REQUEST_TABLE)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('store_id', $dataScope->forcedStoreId())
            ->where('creation_idempotency_key', $creationIdempotencyKey)
            ->field('request_id')
            ->find();
        if (!$discovered || !$this->validRequestId((string)($discovered['request_id'] ?? ''))) {
            return null;
        }
        $row = $this->lockRequestById(
            (string)$discovered['request_id'],
            $dataScope->tenantId(),
            $dataScope->forcedStoreId()
        );
        if (!$row
            || !hash_equals(
                (string)($row['creation_idempotency_key'] ?? ''),
                $creationIdempotencyKey
            )) {
            return null;
        }
        return $this->kernelCurrentFromRow($row);
    }

    public function persistKernelPlanInTx(
        array $kernelResult,
        CashierV3CheckoutVerifiedSourceSet $verifiedSources,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        CashierV3TransactionGuard::assertInTransaction('checkoutRequestPersistence');
        $this->assertScopeContext($operatorScope, $dataScope);
        $plan = $this->validateKernelResult($kernelResult, $verifiedSources, $operatorScope, $dataScope);
        $mode = $plan['mode'];
        $request = $plan['request'];

        if ($mode === 'none') {
            $existing = $this->lockRequestById(
                (string)$request['requestId'],
                (string)$request['tenantId'],
                (int)$request['storeId']
            );
            if (!$existing) {
                throw self::failure('checkout_request_not_found');
            }
            $this->assertReplayMatches($existing, $plan, $verifiedSources);
            return $this->persistenceResult($existing, true, 'none', [
                'requestRows' => 0,
                'lineRowsDeleted' => 0,
                'lineRowsInserted' => 0,
                'paymentRowsDeleted' => 0,
                'paymentRowsInserted' => 0,
                'sourceRowsDeleted' => 0,
                'sourceRowsInserted' => 0,
            ]);
        }

        if ($mode === 'insert') {
            return $this->insertAggregate($plan, $verifiedSources);
        }

        return $this->casReplaceAggregate($plan, $verifiedSources);
    }

    public function bindResumedHangOrderInTx(
        string $requestId,
        int $requestVersion,
        string $hangOrderId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): void {
        CashierV3TransactionGuard::assertInTransaction('checkoutRequestResumedHangBinding');
        $this->assertScopeContext($operatorScope, $dataScope);
        $requestId = trim($requestId);
        $hangOrderId = trim($hangOrderId);
        if (!$this->validRequestId($requestId) || $requestVersion <= 0
            || ($hangOrderId !== '' && preg_match('/^HGO[0-9a-f]{40}$/D', $hangOrderId) !== 1)) {
            throw self::failure('checkout_resumed_hang_reference_invalid');
        }

        $query = Db::name(self::REQUEST_TABLE)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('store_id', $dataScope->forcedStoreId())
            ->where('operator_id', $dataScope->operatorId())
            ->where('request_id', $requestId)
            ->where('request_version', $requestVersion)
            ->where('request_status', 'editing');
        $current = $query->lock(true)->find();
        if (!$current) {
            throw self::failure('checkout_resumed_hang_reference_cas_conflict');
        }
        if (hash_equals((string)($current['resumed_hang_order_id'] ?? ''), $hangOrderId)) {
            return;
        }
        $affected = (int)Db::name(self::REQUEST_TABLE)
            ->where('id', (int)$current['id'])
            ->update([
                'resumed_hang_order_id' => $hangOrderId,
                'update_time' => time(),
            ]);
        $this->assertAffected('checkout_resumed_hang_reference_bind', $affected, 1);
    }

    private function insertAggregate(
        array $plan,
        CashierV3CheckoutVerifiedSourceSet $verifiedSources
    ): array {
        $request = $plan['request'];
        $now = time();
        $requestRow = $this->mapRow($request, self::REQUEST_MAP, 'request');
        $requestRow['add_time'] = $now;
        $requestRow['update_time'] = $now;
        try {
            $affected = (int)Db::name(self::REQUEST_TABLE)->insert($requestRow);
        } catch (\Throwable $exception) {
            if (!$this->isDuplicateKey($exception)) {
                throw $exception;
            }
            $existing = Db::name(self::REQUEST_TABLE)
                ->where('tenant_id', $request['tenantId'])
                ->where('store_id', $request['storeId'])
                ->where('creation_idempotency_key', $request['creationIdempotencyKey'])
                ->lock(true)
                ->find();
            if (!$existing) {
                throw $exception;
            }
            $this->assertReplayMatches($existing, $plan, $verifiedSources);
            return $this->persistenceResult($existing, true, 'insert_race_replay', [
                'requestRows' => 0,
                'lineRowsDeleted' => 0,
                'lineRowsInserted' => 0,
                'paymentRowsDeleted' => 0,
                'paymentRowsInserted' => 0,
                'sourceRowsDeleted' => 0,
                'sourceRowsInserted' => 0,
            ]);
        }
        $this->assertAffected('checkout_request_insert', $affected, 1);

        $lineInserted = $this->insertMappedRows(
            self::LINE_TABLE,
            $plan['lineDrafts'],
            self::LINE_MAP,
            'line_draft',
            $now
        );
        $paymentInserted = $this->insertPaymentRows($plan['paymentDrafts'], $now);
        $sourceInserted = $this->insertSourceRows(
            $verifiedSources,
            (string)$request['requestId'],
            (int)$request['requestVersion'],
            $now
        );

        $persisted = $this->lockRequestById(
            (string)$request['requestId'],
            (string)$request['tenantId'],
            (int)$request['storeId']
        );
        if (!$persisted) {
            throw self::failure('checkout_request_insert_readback_missing');
        }
        return $this->persistenceResult($persisted, false, 'insert', [
            'requestRows' => 1,
            'lineRowsDeleted' => 0,
            'lineRowsInserted' => $lineInserted,
            'paymentRowsDeleted' => 0,
            'paymentRowsInserted' => $paymentInserted,
            'sourceRowsDeleted' => 0,
            'sourceRowsInserted' => $sourceInserted,
        ]);
    }

    private function casReplaceAggregate(
        array $plan,
        CashierV3CheckoutVerifiedSourceSet $verifiedSources
    ): array {
        $request = $plan['request'];
        $cas = $plan['cas'];
        $existing = $this->lockRequestById(
            (string)$request['requestId'],
            (string)$request['tenantId'],
            (int)$request['storeId']
        );
        if (!$existing) {
            throw self::failure('checkout_request_not_found');
        }

        $currentVersion = (int)($existing['request_version'] ?? 0);
        $expectedVersion = (int)$cas['expectedVersion'];
        $nextVersion = (int)$cas['nextVersion'];
        if ($currentVersion === $nextVersion
            && hash_equals((string)$existing['last_idempotency_key'], (string)$request['lastIdempotencyKey'])
            && hash_equals(
                (string)$existing['last_operation_fingerprint'],
                (string)$request['lastOperationFingerprint']
            )) {
            $this->assertReplayMatches($existing, $plan, $verifiedSources);
            return $this->persistenceResult($existing, true, 'cas_race_replay', [
                'requestRows' => 0,
                'lineRowsDeleted' => 0,
                'lineRowsInserted' => 0,
                'paymentRowsDeleted' => 0,
                'paymentRowsInserted' => 0,
                'sourceRowsDeleted' => 0,
                'sourceRowsInserted' => 0,
            ]);
        }
        if ($currentVersion !== $expectedVersion) {
            throw self::failure('checkout_request_version_conflict', [
                'requestId' => $request['requestId'],
                'expectedVersion' => $expectedVersion,
                'currentVersion' => $currentVersion,
            ]);
        }

        $requestId = (string)$request['requestId'];
        $lineBefore = $this->lockedChildCount(self::LINE_TABLE, $requestId);
        $paymentBefore = $this->lockedChildCount(self::PAYMENT_TABLE, $requestId);
        $sourceBefore = $this->lockedChildCount(self::SOURCE_TABLE, $requestId);

        $now = time();
        $requestUpdate = $this->mapRow($request, self::REQUEST_MAP, 'request');
        unset($requestUpdate['request_id'], $requestUpdate['tenant_id'], $requestUpdate['store_id']);
        $requestUpdate['update_time'] = $now;
        $affected = (int)Db::name(self::REQUEST_TABLE)
            ->where('request_id', $requestId)
            ->where('tenant_id', $request['tenantId'])
            ->where('store_id', $request['storeId'])
            ->where('request_version', $expectedVersion)
            ->update($requestUpdate);
        $this->assertAffected('checkout_request_cas', $affected, 1);
        if ($this->resourcePlanRepository instanceof CashierV3CheckoutResourcePlanRepository) {
            $this->resourcePlanRepository->supersedeActiveBeforeVersionInTx(
                $requestId,
                (string)$request['tenantId'],
                (int)$request['storeId'],
                $nextVersion,
                'checkout_request_version_advanced'
            );
        }

        $lineDeleted = (int)Db::name(self::LINE_TABLE)->where('request_id', $requestId)->delete();
        $paymentDeleted = (int)Db::name(self::PAYMENT_TABLE)->where('request_id', $requestId)->delete();
        $sourceDeleted = (int)Db::name(self::SOURCE_TABLE)->where('request_id', $requestId)->delete();
        $this->assertAffected('checkout_line_delete', $lineDeleted, $lineBefore);
        $this->assertAffected('checkout_payment_delete', $paymentDeleted, $paymentBefore);
        $this->assertAffected('checkout_source_delete', $sourceDeleted, $sourceBefore);

        $lineInserted = $this->insertMappedRows(
            self::LINE_TABLE,
            $plan['lineDrafts'],
            self::LINE_MAP,
            'line_draft',
            $now
        );
        $paymentInserted = $this->insertPaymentRows($plan['paymentDrafts'], $now);
        $sourceInserted = $this->insertSourceRows(
            $verifiedSources,
            $requestId,
            $nextVersion,
            $now
        );

        $persisted = $this->lockRequestById(
            $requestId,
            (string)$request['tenantId'],
            (int)$request['storeId']
        );
        if (!$persisted || (int)$persisted['request_version'] !== $nextVersion) {
            throw self::failure('checkout_request_cas_readback_invalid');
        }
        return $this->persistenceResult($persisted, false, 'cas_replace_children', [
            'requestRows' => 1,
            'lineRowsDeleted' => $lineDeleted,
            'lineRowsInserted' => $lineInserted,
            'paymentRowsDeleted' => $paymentDeleted,
            'paymentRowsInserted' => $paymentInserted,
            'sourceRowsDeleted' => $sourceDeleted,
            'sourceRowsInserted' => $sourceInserted,
        ]);
    }

    private function validateKernelResult(
        array $kernelResult,
        CashierV3CheckoutVerifiedSourceSet $verifiedSources,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        foreach ([
            'contractVersion', 'requestId', 'requestVersion', 'requestStatus',
            'replayed', 'eventless', 'persistencePlan', 'businessEffects', 'submitAvailable',
        ] as $key) {
            if (!array_key_exists($key, $kernelResult)) {
                throw self::failure('checkout_kernel_result_incomplete', ['missing' => $key]);
            }
        }
        if ($kernelResult['contractVersion'] !== CashierV3CheckoutSettlementKernel::CONTRACT_VERSION
            || $kernelResult['eventless'] !== true
            || $kernelResult['submitAvailable'] !== false
            || $kernelResult['businessEffects'] !== $this->emptyBusinessEffects()) {
            throw self::failure('checkout_kernel_result_not_eventless');
        }
        if (!is_array($kernelResult['persistencePlan'])) {
            throw self::failure('checkout_persistence_plan_invalid');
        }
        $plan = $kernelResult['persistencePlan'];
        $this->assertExactKeys($plan, [
            'mode', 'requestTable', 'lineTable', 'paymentTable', 'request',
            'lineDrafts', 'paymentDrafts', 'cas',
            'replaceChildrenOnlyUnderLockedRequest', 'affectedRequestRowsMustEqual',
        ], 'persistence_plan');
        if (!in_array($plan['mode'], ['insert', 'cas_replace_children', 'none'], true)
            || $plan['requestTable'] !== self::REQUEST_PLAN_TABLE
            || $plan['lineTable'] !== self::LINE_PLAN_TABLE
            || $plan['paymentTable'] !== self::PAYMENT_PLAN_TABLE
            || $plan['replaceChildrenOnlyUnderLockedRequest'] !== true
            || !is_array($plan['request'])
            || !is_array($plan['lineDrafts'])
            || !is_array($plan['paymentDrafts'])
            || !is_array($plan['cas'])) {
            throw self::failure('checkout_persistence_plan_invalid');
        }
        $this->assertExactKeys($plan['request'], array_keys(self::REQUEST_MAP), 'request_row');
        $this->assertExactKeys($plan['cas'], ['requestId', 'expectedVersion', 'nextVersion'], 'request_cas');
        foreach ($plan['lineDrafts'] as $row) {
            if (!is_array($row)) {
                throw self::failure('checkout_line_plan_invalid');
            }
            $this->assertExactKeys($row, array_keys(self::LINE_MAP), 'line_row');
            if (!in_array($row['lineRole'], ['sale', 'entitlement_service'], true)
                || !is_int($row['entitlementSourceDetailId'])
                || ($row['lineRole'] === 'sale' && $row['entitlementSourceDetailId'] !== 0)
                || ($row['lineRole'] === 'entitlement_service'
                    && $row['entitlementSourceDetailId'] <= 0)) {
                throw self::failure('checkout_line_entitlement_source_detail_invalid', [
                    'lineRole' => $row['lineRole'],
                ]);
            }
        }
        foreach ($plan['paymentDrafts'] as $row) {
            if (!is_array($row)) {
                throw self::failure('checkout_payment_plan_invalid');
            }
            $this->assertExactKeys(
                $row,
                array_merge(array_keys(self::PAYMENT_MAP), ['settledAt']),
                'payment_row'
            );
            if ($row['settledAt'] !== null) {
                throw self::failure('checkout_payment_draft_settled_time_forbidden');
            }
        }

        $request = $plan['request'];
        if (!is_string($request['requestId'])
            || !$this->validRequestId($request['requestId'])
            || $kernelResult['requestId'] !== $request['requestId']
            || (int)$kernelResult['requestVersion'] !== (int)$request['requestVersion']
            || $kernelResult['requestStatus'] !== $request['requestStatus']
            || $plan['cas']['requestId'] !== $request['requestId']
            || (int)$plan['cas']['nextVersion'] !== (int)$request['requestVersion']) {
            throw self::failure('checkout_request_plan_identity_invalid');
        }
        if (!hash_equals((string)$request['tenantId'], $dataScope->tenantId())
            || (int)$request['storeId'] !== $dataScope->forcedStoreId()
            || (int)$request['operatorId'] !== $operatorScope->operatorId()
            || !hash_equals((string)$request['organizationId'], $operatorScope->organizationId())
            || !hash_equals($verifiedSources->tenantId(), (string)$request['tenantId'])
            || $verifiedSources->storeId() !== (int)$request['storeId']) {
            throw self::failure('checkout_request_scope_mismatch');
        }

        $mode = $plan['mode'];
        $expectedVersion = $plan['cas']['expectedVersion'];
        $nextVersion = $plan['cas']['nextVersion'];
        if (($mode === 'insert'
                && ($expectedVersion !== null || $nextVersion !== 1
                    || $plan['affectedRequestRowsMustEqual'] !== null))
            || ($mode === 'cas_replace_children'
                && (!is_int($expectedVersion) || $expectedVersion <= 0
                    || !is_int($nextVersion) || $nextVersion !== $expectedVersion + 1
                    || $plan['affectedRequestRowsMustEqual'] !== 1))
            || ($mode === 'none'
                && ($kernelResult['replayed'] !== true
                    || $plan['affectedRequestRowsMustEqual'] !== null))) {
            throw self::failure('checkout_request_cas_plan_invalid');
        }
        foreach (array_merge($plan['lineDrafts'], $plan['paymentDrafts']) as $child) {
            if (!hash_equals((string)$child['requestId'], (string)$request['requestId'])
                || !hash_equals((string)$child['tenantId'], (string)$request['tenantId'])
                || (int)$child['storeId'] !== (int)$request['storeId']
                || (int)$child['draftVersion'] !== (int)$request['requestVersion']) {
                throw self::failure('checkout_child_scope_or_version_mismatch');
            }
        }
        return $plan;
    }

    private function assertReplayMatches(
        array $existing,
        array $plan,
        CashierV3CheckoutVerifiedSourceSet $verifiedSources
    ): void {
        $request = $plan['request'];
        $checks = [
            'request_id' => 'requestId',
            'tenant_id' => 'tenantId',
            'store_id' => 'storeId',
            'request_version' => 'requestVersion',
            'request_status' => 'requestStatus',
            'creation_idempotency_key' => 'creationIdempotencyKey',
            'last_idempotency_key' => 'lastIdempotencyKey',
            'last_operation_fingerprint' => 'lastOperationFingerprint',
            'aggregate_fingerprint' => 'aggregateFingerprint',
        ];
        foreach ($checks as $column => $field) {
            if ((string)($existing[$column] ?? '') !== (string)$request[$field]) {
                throw self::failure('checkout_idempotency_key_conflict', [
                    'requestId' => $request['requestId'],
                    'field' => $field,
                ]);
            }
        }
        $this->assertPersistedChildrenMatchPlan($plan);
        $this->assertPersistedSourcesMatch(
            (string)$request['requestId'],
            (int)$request['requestVersion'],
            $verifiedSources
        );
    }

    private function assertPersistedChildrenMatchPlan(array $plan): void
    {
        $requestId = (string)$plan['request']['requestId'];
        $actualLines = $this->rows(Db::name(self::LINE_TABLE)
            ->where('request_id', $requestId)
            ->field(
                'line_id,draft_version,line_role,entitlement_source_detail_id,craftsmen_snapshot_json,guide_selections_json,sales_manager_selections_json,manual_labor_fee_cents,line_fingerprint'
            )
            ->order('line_id asc')
            ->lock(true)
            ->select());
        $expectedLines = array_map(static function (array $row): array {
            return [
                'line_id' => (string)$row['lineId'],
                'draft_version' => (string)$row['draftVersion'],
                'line_role' => (string)$row['lineRole'],
                'entitlement_source_detail_id' => (string)$row['entitlementSourceDetailId'],
                'craftsmen_snapshot_json' => (string)$row['craftsmenSnapshotJson'],
                'guide_selections_json' => (string)($row['guideSelectionsJson'] ?? ''),
                'sales_manager_selections_json' => (string)($row['salesManagerSelectionsJson'] ?? ''),
                'manual_labor_fee_cents' => $row['manualLaborFeeCents'] === null ? null : (int)$row['manualLaborFeeCents'],
                'line_fingerprint' => (string)$row['lineFingerprint'],
            ];
        }, $plan['lineDrafts']);
        usort($expectedLines, static function (array $left, array $right): int {
            return strcmp($left['line_id'], $right['line_id']);
        });
        $actualLines = array_map(static function (array $row): array {
            return [
                'line_id' => (string)$row['line_id'],
                'draft_version' => (string)$row['draft_version'],
                'line_role' => (string)$row['line_role'],
                'entitlement_source_detail_id' => (string)$row['entitlement_source_detail_id'],
                'craftsmen_snapshot_json' => (string)$row['craftsmen_snapshot_json'],
                'guide_selections_json' => (string)($row['guide_selections_json'] ?? ''),
                'sales_manager_selections_json' => (string)($row['sales_manager_selections_json'] ?? ''),
                'manual_labor_fee_cents' => $row['manual_labor_fee_cents'] === null ? null : (int)$row['manual_labor_fee_cents'],
                'line_fingerprint' => (string)$row['line_fingerprint'],
            ];
        }, $actualLines);
        if ($actualLines !== $expectedLines) {
            throw self::failure('checkout_idempotent_line_drift');
        }

        $actualPayments = $this->rows(Db::name(self::PAYMENT_TABLE)
            ->where('request_id', $requestId)
            ->field('payment_draft_id,draft_version,payment_fingerprint')
            ->order('payment_draft_id asc')
            ->lock(true)
            ->select());
        $expectedPayments = array_map(static function (array $row): array {
            return [
                'payment_draft_id' => (string)$row['paymentDraftId'],
                'draft_version' => (string)$row['draftVersion'],
                'payment_fingerprint' => (string)$row['paymentFingerprint'],
            ];
        }, $plan['paymentDrafts']);
        usort($expectedPayments, static function (array $left, array $right): int {
            return strcmp($left['payment_draft_id'], $right['payment_draft_id']);
        });
        $actualPayments = array_map(static function (array $row): array {
            return [
                'payment_draft_id' => (string)$row['payment_draft_id'],
                'draft_version' => (string)$row['draft_version'],
                'payment_fingerprint' => (string)$row['payment_fingerprint'],
            ];
        }, $actualPayments);
        if ($actualPayments !== $expectedPayments) {
            throw self::failure('checkout_idempotent_payment_drift');
        }
    }

    private function assertPersistedSourcesMatch(
        string $requestId,
        int $requestVersion,
        CashierV3CheckoutVerifiedSourceSet $verifiedSources
    ): void {
        $rows = $this->rows(Db::name(self::SOURCE_TABLE)
            ->where('request_id', $requestId)
            ->field(
                'tenant_id,store_id,bound_request_version,source_kind,source_id,'
                . 'source_version,source_role,source_fingerprint'
            )
            ->order('source_kind asc,source_id asc,source_role asc')
            ->lock(true)
            ->select());
        $authorityRows = [];
        $bindingVersion = null;
        foreach ($rows as $row) {
            $rowBindingVersion = (int)($row['bound_request_version'] ?? 0);
            if ($rowBindingVersion <= 0
                || $rowBindingVersion > $requestVersion
                || ($bindingVersion !== null && $bindingVersion !== $rowBindingVersion)) {
                throw self::failure('checkout_source_binding_version_drift');
            }
            $bindingVersion = $rowBindingVersion;
            $kind = (string)($row['source_kind'] ?? '');
            $id = (string)($row['source_id'] ?? '');
            $sourceVersion = (int)($row['source_version'] ?? 0);
            $role = (string)($row['source_role'] ?? '');
            $fingerprint = CashierV3CheckoutVerifiedSourceSet::referenceFingerprint(
                $kind,
                $id,
                $sourceVersion,
                $role
            );
            if (!hash_equals((string)($row['source_fingerprint'] ?? ''), $fingerprint)) {
                throw self::failure('checkout_source_reference_fingerprint_drift');
            }
            $authorityRows[] = [
                'tenantId' => (string)$row['tenant_id'],
                'storeId' => (int)$row['store_id'],
                'kind' => $kind,
                'id' => $id,
                'sourceVersion' => $sourceVersion,
                'role' => $role,
            ];
        }
        try {
            $persisted = CashierV3CheckoutVerifiedSourceSet::fromServerVerifiedAuthorityRows(
                $verifiedSources->tenantId(),
                $verifiedSources->storeId(),
                $authorityRows
            );
        } catch (CashierV3CheckoutSettlementContractException $exception) {
            throw self::failure('checkout_source_reference_drift', [
                'cause' => $exception->reason(),
            ]);
        }
        if (!hash_equals($persisted->fingerprint(), $verifiedSources->fingerprint())) {
            throw self::failure('checkout_source_reference_drift');
        }
    }

    private function insertPaymentRows(array $rows, int $now): int
    {
        $mapped = [];
        foreach ($rows as $row) {
            $copy = $row;
            unset($copy['settledAt']);
            $mapped[] = $this->mapRow($copy, self::PAYMENT_MAP, 'payment_draft');
        }
        return $this->insertPreparedRows(self::PAYMENT_TABLE, $mapped, $now, count($rows));
    }

    private function insertMappedRows(
        string $table,
        array $rows,
        array $map,
        string $path,
        int $now
    ): int {
        $mapped = [];
        foreach ($rows as $row) {
            $mapped[] = $this->mapRow($row, $map, $path);
        }
        return $this->insertPreparedRows($table, $mapped, $now, count($rows));
    }

    private function insertPreparedRows(string $table, array $rows, int $now, int $expected): int
    {
        if (!$rows) {
            return 0;
        }
        foreach ($rows as &$row) {
            $row['add_time'] = $now;
            $row['update_time'] = $now;
        }
        unset($row);
        $affected = (int)Db::name($table)->insertAll($rows);
        $this->assertAffected($table . '_insert_all', $affected, $expected);
        return $affected;
    }

    private function insertSourceRows(
        CashierV3CheckoutVerifiedSourceSet $verifiedSources,
        string $requestId,
        int $requestVersion,
        int $now
    ): int {
        $rows = [];
        foreach ($verifiedSources->references() as $reference) {
            $rows[] = [
                'request_id' => $requestId,
                'tenant_id' => $verifiedSources->tenantId(),
                'store_id' => $verifiedSources->storeId(),
                'bound_request_version' => $requestVersion,
                'source_kind' => $reference['kind'],
                'source_id' => $reference['id'],
                'source_version' => $reference['sourceVersion'],
                'source_role' => $reference['role'],
                'source_fingerprint' => CashierV3CheckoutVerifiedSourceSet::referenceFingerprint(
                    $reference['kind'],
                    $reference['id'],
                    $reference['sourceVersion'],
                    $reference['role']
                ),
                'add_time' => $now,
                'update_time' => $now,
            ];
        }
        $affected = (int)Db::name(self::SOURCE_TABLE)->insertAll($rows);
        $this->assertAffected('checkout_source_insert_all', $affected, count($rows));
        return $affected;
    }

    private function mapRow(array $row, array $map, string $path): array
    {
        $this->assertExactKeys($row, array_keys($map), $path);
        $mapped = [];
        foreach ($map as $field => $column) {
            $mapped[$column] = $row[$field];
        }
        return $mapped;
    }

    private function lockRequestById(string $requestId, string $tenantId, int $storeId)
    {
        return Db::name(self::REQUEST_TABLE)
            ->where('request_id', $requestId)
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->lock(true)
            ->find();
    }

    /**
     * The completion ID is only a caller-supplied locator. Success is allowed
     * only after the matching domain authority is re-read under the outer
     * checkout transaction and proven to belong to this exact request.
     *
     * @return array{type:string,id:string,salesOrderId:string,entitlementCompletionReceiptId:string}
     */
    private function lockCompletionAuthorityInTx(
        array $request,
        string $commandIdempotencyKey,
        string $completionReferenceId,
        int $settledAt,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        $requestId = (string)($request['request_id'] ?? '');
        $requestVersion = (int)($request['request_version'] ?? 0);
        $composition = (string)($request['composition'] ?? '');
        if (in_array($composition, [
            CashierV3CheckoutSettlementKernel::COMPOSITION_SALE_ONLY,
            CashierV3CheckoutSettlementKernel::COMPOSITION_MIXED,
        ], true)) {
            if (preg_match('/^CSO-[0-9a-f]{40}$/D', $completionReferenceId) !== 1) {
                throw self::failure('checkout_sales_order_reference_invalid');
            }
            $order = Db::name(self::SALES_ORDER_TABLE)
                ->where('order_id', $completionReferenceId)
                ->where('tenant_id', $dataScope->tenantId())
                ->where('organization_id', $dataScope->organizationId())
                ->where('store_id', $dataScope->forcedStoreId())
                ->where('operator_id', $operatorScope->operatorId())
                ->where('checkout_request_id', $requestId)
                ->where('checkout_request_version', $requestVersion)
                ->where('command_idempotency_key', $commandIdempotencyKey)
                ->where('composition', $composition)
                ->where('order_status', 'settled')
                ->where('order_direction', 'forward')
                ->lock(true)
                ->find();
            if (!$order
                || (int)($order['order_version'] ?? 0) <= 0
                || (int)($order['settled_at'] ?? 0) !== $settledAt) {
                throw self::failure('checkout_sales_order_authority_missing');
            }
            $entitlementReceiptId = '';
            if ($composition === CashierV3CheckoutSettlementKernel::COMPOSITION_MIXED) {
                $receipt = $this->lockEntitlementCompletionReceiptInTx(
                    $request,
                    $commandIdempotencyKey,
                    $settledAt,
                    $operatorScope,
                    $dataScope
                );
                $entitlementReceiptId = (string)$receipt['receipt_id'];
            }
            return [
                'type' => $composition === CashierV3CheckoutSettlementKernel::COMPOSITION_MIXED
                    ? 'mixed_checkout'
                    : 'sales_order',
                'id' => $completionReferenceId,
                'salesOrderId' => $completionReferenceId,
                'entitlementCompletionReceiptId' => $entitlementReceiptId,
            ];
        }

        if ($composition !== CashierV3CheckoutSettlementKernel::COMPOSITION_ENTITLEMENT_ONLY
            || preg_match('/^ECR-[0-9a-f]{40}$/D', $completionReferenceId) !== 1) {
            throw self::failure('checkout_completion_reference_composition_invalid', [
                'composition' => $composition,
            ]);
        }
        $receipt = $this->lockEntitlementCompletionReceiptInTx(
            $request,
            $commandIdempotencyKey,
            $settledAt,
            $operatorScope,
            $dataScope,
            $completionReferenceId
        );
        return [
            'type' => 'entitlement_completion_receipt',
            'id' => (string)$receipt['receipt_id'],
            'salesOrderId' => '',
            'entitlementCompletionReceiptId' => (string)$receipt['receipt_id'],
        ];
    }

    private function lockEntitlementCompletionReceiptInTx(
        array $request,
        string $commandIdempotencyKey,
        int $settledAt,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        string $expectedReceiptId = ''
    ): array {
        $query = Db::name(self::ENTITLEMENT_COMPLETION_RECEIPT_TABLE)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('organization_id', $dataScope->organizationId())
            ->where('store_id', $dataScope->forcedStoreId())
            ->where('operator_id', $operatorScope->operatorId())
            ->where('member_id', (int)($request['member_id'] ?? 0))
            ->where('workspace_id', (string)($request['workspace_id'] ?? ''))
            ->where('state_context_id', (string)($request['state_context_id'] ?? ''))
            ->where('checkout_request_id', (string)($request['request_id'] ?? ''))
            ->where('command_idempotency_key', $commandIdempotencyKey)
            ->where('status', 'completed')
            ->lock(true);
        if ($expectedReceiptId !== '') {
            $query->where('receipt_id', $expectedReceiptId);
        }
        $receipt = $query->find();
        if (!$receipt
            || preg_match('/^ECR-[0-9a-f]{40}$/D', (string)($receipt['receipt_id'] ?? '')) !== 1
            || preg_match('/^[0-9a-f]{64}$/D', (string)($receipt['plan_fingerprint'] ?? '')) !== 1
            || (int)($receipt['settled_at'] ?? 0) !== $settledAt
            || (int)($receipt['completed_at'] ?? 0) <= 0) {
            throw self::failure('checkout_entitlement_completion_receipt_missing');
        }
        return is_array($receipt) ? $receipt : (array)$receipt;
    }

    /**
     * A full balance or debt settlement has no accounting payment draft.
     */
    private function hasSettlementSource(array $request, int $paymentCount): bool
    {
        return $paymentCount > 0
            || (int)($request['balance_deduction_amount_cents'] ?? 0) > 0
            || (int)($request['debt_amount_cents'] ?? 0) > 0;
    }

    private function lockedDraftChildCount(
        string $table,
        string $requestId,
        int $draftVersion
    ): int {
        return count($this->rows(Db::name($table)
            ->where('request_id', $requestId)
            ->where('draft_version', $draftVersion)
            ->where('draft_status', 'draft')
            ->field('id')
            ->order('id asc')
            ->lock(true)
            ->select()));
    }

    private function lockedChildCount(string $table, string $requestId): int
    {
        return count($this->rows(Db::name($table)
            ->where('request_id', $requestId)
            ->field('id')
            ->order('id asc')
            ->lock(true)
            ->select()));
    }

    private function kernelCurrentFromRow(array $row): array
    {
        $version = (int)($row['request_version'] ?? 0);
        if ($version <= 0
            || !$this->validRequestId((string)($row['request_id'] ?? ''))
            || preg_match('/^[0-9a-f]{64}$/D', (string)($row['last_operation_fingerprint'] ?? '')) !== 1) {
            throw self::failure('checkout_request_row_invalid');
        }
        return [
            'requestId' => (string)$row['request_id'],
            'tenantId' => (string)$row['tenant_id'],
            'workspaceId' => (string)$row['workspace_id'],
            'version' => $version,
            'status' => (string)$row['request_status'],
            'creationIdempotencyKey' => (string)$row['creation_idempotency_key'],
            'lastIdempotencyKey' => (string)$row['last_idempotency_key'],
            'lastOperationFingerprint' => (string)$row['last_operation_fingerprint'],
        ];
    }

    private function assertScopeContext(
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): void {
        if ($operatorScope->storeId() !== $dataScope->forcedStoreId()
            || $operatorScope->operatorId() !== $dataScope->operatorId()
            || $operatorScope->tenantId() === ''
            || !hash_equals($operatorScope->tenantId(), $dataScope->tenantId())
            || !hash_equals($operatorScope->organizationId(), $dataScope->organizationId())
            || !$dataScope->allowsStore($operatorScope->storeId())) {
            throw self::failure('checkout_request_data_scope_denied');
        }
    }

    private function assertExactKeys(array $value, array $required, string $path): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($required, SORT_STRING);
        if ($actual !== $required) {
            throw self::failure('checkout_persistence_shape_invalid', [
                'path' => $path,
                'actualKeys' => $actual,
                'requiredKeys' => $required,
            ]);
        }
    }

    private function assertAffected(string $operation, int $actual, int $expected): void
    {
        if ($actual !== $expected) {
            throw self::failure('checkout_persistence_affected_rows_invalid', [
                'operation' => $operation,
                'expected' => $expected,
                'actual' => $actual,
            ]);
        }
    }

    private function validRequestId(string $requestId): bool
    {
        return preg_match('/^CKR-[0-9a-f]{40}$/D', $requestId) === 1;
    }

    private function validIdempotencyKey(string $key): bool
    {
        return strlen($key) <= 128
            && preg_match('/^(CHECKOUT|CHECKOUT_PREPARE)-[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $key) === 1;
    }

    private function isDuplicateKey(\Throwable $exception): bool
    {
        for ($cursor = $exception; $cursor instanceof \Throwable; $cursor = $cursor->getPrevious()) {
            $code = (string)$cursor->getCode();
            $message = strtolower($cursor->getMessage());
            if ($code === '1062'
                || $code === '23000'
                || strpos($message, 'duplicate entry') !== false
                || strpos($message, 'integrity constraint violation: 1062') !== false) {
                return true;
            }
        }
        return false;
    }

    private function rows($value): array
    {
        if (is_object($value) && method_exists($value, 'toArray')) {
            $value = $value->toArray();
        }
        return is_array($value) ? array_values($value) : [];
    }

    private function persistenceResult(
        array $request,
        bool $replayed,
        string $mode,
        array $affected
    ): array {
        return [
            'contractVersion' => CashierV3CheckoutSettlementKernel::CONTRACT_VERSION,
            'requestId' => (string)$request['request_id'],
            'requestVersion' => (int)$request['request_version'],
            'requestStatus' => (string)$request['request_status'],
            'replayed' => $replayed,
            'persistenceMode' => $mode,
            'eventless' => true,
            'affected' => $affected,
            'businessEffects' => $this->emptyBusinessEffects(),
        ];
    }

    private function emptyBusinessEffects(): array
    {
        return [
            'checkoutSucceeded' => false,
            'saleFacts' => 0,
            'paymentCollectedFacts' => 0,
            'balanceMutations' => 0,
            'debtMutations' => 0,
            'entitlementMutations' => 0,
            'performanceFacts' => 0,
            'businessEvents' => 0,
            'outboxRows' => 0,
        ];
    }

    private static function failure(
        string $reason,
        array $detail = []
    ): CashierV3CheckoutSettlementContractException {
        return new CashierV3CheckoutSettlementContractException($reason, $detail);
    }
}
