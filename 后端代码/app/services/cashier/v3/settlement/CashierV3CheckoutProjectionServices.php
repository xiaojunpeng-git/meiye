<?php

namespace app\services\cashier\v3\settlement;

use app\services\cashier\v3\CashierV3BusinessDocumentNumberServices;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\checkout\provider\CashierV3MemberBalanceContractException;
use app\services\cashier\v3\checkout\provider\CashierV3MemberBalanceProvider;
use think\facade\Db;

/** Read-only projection of the current eventless checkout request. */
final class CashierV3CheckoutProjectionServices
{
    public const CONTRACT_VERSION = 'cashier-v3-checkout-projection-v2';

    private const MAX_PAYMENT_DRAFTS = 200;

    private const PAYMENT_NAMES = [
        'unionpay' => '银联',
        'wechat' => '微信',
        'alipay' => '支付宝',
        'dianping_voucher' => '大众验券',
        'douyin_voucher' => '抖音验券',
        'partner_collection' => '合作方收款',
        'other_collection' => '其他收款',
    ];

    /** @var CashierV3CheckoutRequestRepository */
    private $requests;

    /** @var CashierV3CheckoutResultReadRepository|null */
    private $completedResults;

    /** @var CashierV3MemberBalanceProvider */
    private $memberBalances;

    /** @var CashierV3CheckoutBusinessConfigProjectionServices */
    private $businessConfigProjection;

    public function __construct(
        CashierV3CheckoutRequestRepository $requests = null,
        CashierV3CheckoutResultReadRepository $completedResults = null,
        CashierV3MemberBalanceProvider $memberBalances = null,
        CashierV3CheckoutBusinessConfigProjectionServices $businessConfigProjection = null
    ) {
        $this->requests = $requests ?: new ThinkPhpCashierV3CheckoutRequestRepository();
        $this->completedResults = $completedResults;
        $this->memberBalances = $memberBalances ?: new CashierV3MemberBalanceProvider();
        $this->businessConfigProjection = $businessConfigProjection ?: new CashierV3CheckoutBusinessConfigProjectionServices();
    }

    public function readCurrent(
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ) {
        $aggregate = $this->requests->readLatestEditingProjection(
            $workspaceId,
            $stateContextId,
            $operatorScope,
            $dataScope
        );
        if ($aggregate !== null) {
            return $this->withCurrentMemberBalance(
                $this->businessConfigProjection->apply(
                    self::projectPersistedAggregate($aggregate),
                    CashierV3CheckoutBusinessSourceSelectionServices::KIND_SALE,
                    (array)$aggregate['request']
                ),
                $operatorScope,
                $dataScope
            );
        }
        $debtRepayment = $this->readLatestSucceededDebtRepayment(
            $workspaceId,
            $stateContextId,
            $operatorScope,
            $dataScope
        );
        if ($debtRepayment !== null) {
            return $debtRepayment;
        }
        if ($this->completedResults === null) {
            return null;
        }
        $committed = $this->completedResults->findLatestCommittedCheckoutForWorkspace(
            $workspaceId,
            $stateContextId,
            $operatorScope,
            $dataScope
        );
        return $committed === null || $committed === []
            ? null
            : self::projectSucceededResult($committed);
    }

    public function readEditingRequest(
        string $requestId,
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): ?array {
        $aggregate = $this->requests->readEditingProjectionByRequestId(
            $requestId,
            $workspaceId,
            $stateContextId,
            $operatorScope,
            $dataScope
        );
        if ($aggregate === null) {
            return null;
        }
        return $this->withCurrentMemberBalance(
            $this->businessConfigProjection->apply(
                self::projectPersistedAggregate($aggregate),
                CashierV3CheckoutBusinessSourceSelectionServices::KIND_SALE,
                (array)$aggregate['request']
            ),
            $operatorScope,
            $dataScope
        );
    }

    private function readLatestSucceededDebtRepayment(
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operator,
        CashierV3DataScopeContext $scope
    ): ?array {
        $request = (array)Db::name('cashier_v3_checkout_request')
            ->where('tenant_id', $scope->tenantId())
            ->where('store_id', $operator->storeId())
            ->where('workspace_id', $workspaceId)
            ->where('state_context_id', $stateContextId)
            ->order('recorded_at desc,id desc')->find();
        if (!$request) return null;
        // Only the latest request may drive the current overlay. An older debt
        // repayment must never hide a newer normal checkout result.
        if ((string)($request['source_document_type'] ?? '') !== 'debt_repayment'
            || (string)($request['request_status'] ?? '') !== 'succeeded') return null;
        $requestId = (string)($request['request_id'] ?? '');
        $repayment = (array)Db::name('cashier_v3_debt_repayment')
            ->where('tenant_id', $scope->tenantId())
            ->where('store_id', $operator->storeId())
            ->where('natural_key', 'debt_repayment:' . $requestId)
            ->where('status', 'succeeded')->find();
        if (!$repayment) {
            $rechargeRepayment = (array)Db::name('cashier_v3_recharge_debt_repayment')
                ->where('tenant_id', $scope->tenantId())->where('store_id', $operator->storeId())
                ->where('command_idempotency_key', (string)($request['last_idempotency_key'] ?? ''))
                ->where('status', 'succeeded')->find();
            if (!$rechargeRepayment) throw self::failure('debt_repayment_succeeded_projection_missing');
            $collections = Db::name('cashier_v3_recharge_debt_repayment_payment')
                ->where('repayment_id', (string)$rechargeRepayment['repayment_id'])
                ->order('payment_line_no asc,id asc')->select()->toArray();
            $resultLines = [];
            foreach ($collections as $row) {
                $method = (string)$row['payment_method'];
                if (!isset(self::PAYMENT_NAMES[$method])) throw self::failure('debt_repayment_collection_method_invalid');
                $resultLines[] = [
                    'id' => (string)$rechargeRepayment['repayment_id'] . ':' . (int)$row['payment_line_no'],
                    'kind' => 'bookkeeping_collection', 'method' => $method,
                    'name' => self::PAYMENT_NAMES[$method], 'amount' => self::money((int)$row['amount_cents']),
                    'status' => 'succeeded', 'externalTransactionNo' => (string)($row['collection_reference_snapshot'] ?? ''),
                    'remark' => (string)($row['remark_snapshot'] ?? ''), 'canEdit' => false, 'canRemove' => false,
                ];
            }
            $amount = (int)$rechargeRepayment['amount_cents'];
            return [
                'contractVersion' => self::CONTRACT_VERSION, 'businessType' => 'debt_repayment',
                'status' => 'succeeded', 'requestStatus' => 'succeeded', 'checkoutRequestId' => $requestId,
                'requestId' => $requestId, 'requestNo' => (string)$rechargeRepayment['repayment_no'],
                'checkoutRequestVersion' => (int)$request['request_version'], 'revision' => (int)$request['request_version'],
                'originalIdempotencyKey' => (string)$rechargeRepayment['command_idempotency_key'],
                'settledAt' => (int)$rechargeRepayment['settled_at'], 'completionKind' => 'debt_repayment_completed',
                'completionLabel' => '欠款补交成功', 'completionDescription' => '本次充值欠款补交与收款明细已保存。',
                'canClose' => true, 'canRetry' => false, 'resumeOnLoad' => false, 'recoveryReady' => false,
                'preparationReady' => false, 'snapshotReady' => false, 'orderLines' => [],
                'summary' => ['selectedCount'=>0,'originalAmount'=>self::money($amount),'discountAmount'=>0,'receivableAmount'=>self::money($amount),'entitlementActualAmount'=>0],
                'compositionCode' => 'sale_only', 'composition' => ['code'=>'sale_only','lineRoles'=>[],'hasSale'=>false,'hasEntitlement'=>false,'primaryAction'=>'collect_payment','primaryActionLabel'=>'确认补交'],
                'debtAmount' => 0, 'cashPerformanceAmount' => self::money($amount), 'balancePaymentAmount' => 0,
                'payment' => ['methods'=>[],'selectedLines'=>[],'resultLines'=>$resultLines,'summary'=>['receivableAmount'=>self::money($amount),'selectedAmount'=>self::money($amount),'remainingAmount'=>0,'overpaidAmount'=>0]],
                'finalChanges' => [], 'commandContexts' => [],
            ];
        }
        $collections = Db::name('cashier_v3_debt_repayment_collection')
            ->where('repayment_id', (string)$repayment['repayment_id'])
            ->where('collection_status', 'succeeded')->order('payment_line_no asc,id asc')->select()->toArray();
        $resultLines = [];
        foreach ($collections as $row) {
            $method = (string)$row['payment_method'];
            if (!isset(self::PAYMENT_NAMES[$method])) throw self::failure('debt_repayment_collection_method_invalid');
            $resultLines[] = [
                'id' => (string)$row['collection_id'], 'kind' => 'bookkeeping_collection',
                'method' => $method, 'name' => (string)($row['payment_method_name_snapshot'] ?? self::PAYMENT_NAMES[$method]),
                'amount' => self::money((int)$row['amount_cents']), 'status' => 'succeeded',
                'externalTransactionNo' => (string)$row['external_transaction_no_snapshot'],
                'remark' => (string)$row['remark_snapshot'], 'canEdit' => false, 'canRemove' => false,
            ];
        }
        $amount = (int)$repayment['repayment_amount_cents'];
        return [
            'contractVersion' => self::CONTRACT_VERSION, 'businessType' => 'debt_repayment',
            'status' => 'succeeded', 'requestStatus' => 'succeeded',
            'checkoutRequestId' => $requestId, 'requestId' => $requestId,
            'requestNo' => (string)$repayment['repayment_no'],
            'checkoutRequestVersion' => (int)$request['request_version'], 'revision' => (int)$request['request_version'],
            'originalIdempotencyKey' => (string)$repayment['command_idempotency_key'],
            'settledAt' => (int)$repayment['settled_at'], 'completionKind' => 'debt_repayment_completed',
            'completionLabel' => '欠款补交成功', 'completionDescription' => '本次欠款补交与收款明细已保存。',
            'canClose' => true, 'canRetry' => false, 'resumeOnLoad' => false,
            'recoveryReady' => false, 'preparationReady' => false, 'snapshotReady' => false,
            'orderLines' => [],
            'summary' => ['selectedCount'=>0,'originalAmount'=>self::money($amount),'discountAmount'=>0,'receivableAmount'=>self::money($amount),'entitlementActualAmount'=>0],
            'compositionCode' => 'sale_only',
            'composition' => ['code'=>'sale_only','lineRoles'=>[],'hasSale'=>false,'hasEntitlement'=>false,'primaryAction'=>'collect_payment','primaryActionLabel'=>'确认还款'],
            'debtAmount' => 0, 'cashPerformanceAmount' => self::money($amount), 'balancePaymentAmount' => 0,
            'payment' => ['methods'=>[],'selectedLines'=>[],'resultLines'=>$resultLines,'summary'=>['receivableAmount'=>self::money($amount),'selectedAmount'=>self::money($amount),'remainingAmount'=>0,'overpaidAmount'=>0]],
            'finalChanges' => [], 'commandContexts' => [],
        ];
    }

    /**
     * Payment drafts carry only a frozen balance authority after the member
     * chooses balance payment. The editable checkout projection must expose
     * the current read-only available amount so that choice is reachable.
     */
    private function withCurrentMemberBalance(
        array $projection,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        $memberId = (int)($projection['member']['id'] ?? 0);
        if (!isset($projection['payment']) || !is_array($projection['payment'])) {
            throw self::failure('checkout_projection_payment_missing');
        }
        // Sales-debt repayment accepts only the seven bookkeeping collection
        // methods. It must not silently turn an old receivable into a balance
        // deduction, for which the repayment authority has no ledger fields.
        if ((string)($projection['businessType'] ?? '') === 'debt_repayment') {
            $projection['payment']['balanceAvailable'] = false;
            $projection['payment']['availableBalance'] = 0;
            return $projection;
        }
        if ($memberId <= 0) {
            $projection['payment']['balanceAvailable'] = false;
            $projection['payment']['availableBalance'] = 0;
            return $projection;
        }
        try {
            $balance = $this->memberBalances->readSnapshot($memberId, $operatorScope, $dataScope);
            $projection['payment']['balanceAvailable'] = true;
            $projection['payment']['availableBalance'] = self::money((int)$balance['totalCents']);
            return $projection;
        } catch (CashierV3MemberBalanceContractException $exception) {
            // The amount is display-only. A missing authority must not be
            // represented as usable; final submission still remains locked.
            $projection['payment']['balanceAvailable'] = false;
            $projection['payment']['availableBalance'] = 0;
            return $projection;
        }
    }

    /** Minimal immutable result state; it must never reopen an old checkout. */
    public static function projectSucceededResult(array $committed): array
    {
        $request = is_array($committed['checkoutRequest'] ?? null)
            ? $committed['checkoutRequest']
            : [];
        $order = is_array($committed['salesOrder'] ?? null)
            ? $committed['salesOrder']
            : [];
        $entitlement = is_array($committed['entitlementCompletion'] ?? null)
            ? $committed['entitlementCompletion']
            : [];
        $compositionCode = (string)($committed['composition'] ?? '');
        $requestId = (string)($request['requestId'] ?? '');
        $requestVersion = (int)($request['requestVersion'] ?? 0);
        $orderId = (string)($order['orderId'] ?? '');
        $orderNo = (string)($order['orderNo'] ?? '');
        $orderVersion = (int)($order['orderVersion'] ?? 0);
        $checkoutSourceVersion = (int)($order['checkoutRequestVersion'] ?? 0);
        $entitlementReceiptId = (string)($entitlement['receiptId'] ?? '');
        $idempotencyKey = (string)($committed['originalIdempotencyKey'] ?? '');
        $settledAt = (int)($committed['settledAt'] ?? 0);
        if (preg_match('/^CKR-[0-9a-f]{40}$/D', $requestId) !== 1
            || $requestVersion <= 1
            || (string)($request['requestStatus'] ?? '') !== CashierV3CheckoutSettlementStateMachine::SUCCEEDED
            || !in_array($compositionCode, ['sale_only', 'mixed', 'entitlement_only'], true)
            || preg_match('/^CHECKOUT-[0-9a-f-]{36}$/D', $idempotencyKey) !== 1
            || $settledAt <= 0) {
            throw self::failure('checkout_succeeded_projection_invalid');
        }
        $hasSale = in_array($compositionCode, ['sale_only', 'mixed'], true);
        $hasEntitlement = in_array($compositionCode, ['mixed', 'entitlement_only'], true);
        if (($hasSale
                && (preg_match('/^CSO-[0-9a-f]{40}$/D', $orderId) !== 1
                    || !CashierV3BusinessDocumentNumberServices::isSalesOrderNo($orderNo)
                    || (string)($order['orderStatus'] ?? '') !== 'settled'
                    || $orderVersion <= 0
                    || $checkoutSourceVersion <= 0
                    || $requestVersion !== $checkoutSourceVersion + 1))
            || (!$hasSale && $order !== [])
            || ($hasEntitlement
                && (preg_match('/^ECR-[0-9a-f]{40}$/D', $entitlementReceiptId) !== 1
                    || (string)($entitlement['receiptStatus'] ?? '') !== 'completed'
                    || preg_match(
                        '/^[0-9a-f]{64}$/D',
                        (string)($entitlement['planFingerprint'] ?? '')
                    ) !== 1))) {
            throw self::failure('checkout_succeeded_projection_invalid');
        }

        $lineRoles = $compositionCode === 'mixed'
            ? ['sale', 'entitlement_service']
            : ($hasSale ? ['sale'] : ['entitlement_service']);
        $completionKind = $compositionCode === 'mixed'
            ? 'sale_and_entitlement_service_completed'
            : ($compositionCode === 'entitlement_only'
                ? 'entitlement_service_completed'
                : 'sale_completed');
        $completionLabel = $compositionCode === 'mixed'
            ? '收款并完成服务'
            : ($compositionCode === 'entitlement_only' ? '服务完成' : '收款成功');
        $completionDescription = $compositionCode === 'mixed'
            ? '本单已正式完成，销售、收款、权益核销和服务记录已经保存。'
            : ($compositionCode === 'entitlement_only'
                ? '本次权益服务已完成，核销记录已经保存。'
                : '本单已正式完成，销售订单和收款记录已经保存。');

        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'businessType' => 'checkout',
            'status' => CashierV3CheckoutSettlementStateMachine::SUCCEEDED,
            'requestStatus' => CashierV3CheckoutSettlementStateMachine::SUCCEEDED,
            'checkoutRequestId' => $requestId,
            'requestId' => $requestId,
            'requestNo' => $requestId,
            'checkoutRequestVersion' => $requestVersion,
            'revision' => $requestVersion,
            'originalIdempotencyKey' => $idempotencyKey,
            'salesOrderId' => $orderId,
            'salesOrderNo' => $orderNo,
            'salesOrderVersion' => $orderVersion,
            'entitlementCompletionReceiptId' => $entitlementReceiptId,
            'settledAt' => $settledAt,
            'completionKind' => $completionKind,
            'completionLabel' => $completionLabel,
            'completionDescription' => $completionDescription,
            'canClose' => true,
            'canRetry' => false,
            'resumeOnLoad' => false,
            'recoveryReady' => false,
            'preparationReady' => false,
            'snapshotReady' => false,
            'orderLines' => [],
            'summary' => [],
            'compositionCode' => $compositionCode,
            'composition' => [
                'code' => $compositionCode,
                'lineRoles' => $lineRoles,
                'hasSale' => $hasSale,
                'hasEntitlement' => $hasEntitlement,
                'primaryAction' => $hasSale ? 'collect_payment' : 'complete_entitlement_service',
                'primaryActionLabel' => $hasSale ? '确认收款' : '完成服务',
            ],
            'payment' => [
                'methods' => [],
                'selectedLines' => [],
                'resultLines' => [],
                'summary' => [],
            ],
            'finalChanges' => [],
            'commandContexts' => [],
        ];
    }

    /**
     * Pure projection boundary used by the permanent contract suite.
     * The preparation token is a correlation token, not an authentication or
     * authorization credential. Writes remain protected by Gateway contexts,
     * checkout_request version CAS and the final resource lock set.
     */
    public static function projectPersistedAggregate(array $aggregate): array
    {
        if (!is_array($aggregate['request'] ?? null)
            || !is_array($aggregate['lines'] ?? null)
            || !is_array($aggregate['payments'] ?? null)
            || !is_array($aggregate['sources'] ?? null)) {
            throw self::failure('checkout_projection_shape_invalid');
        }
        $request = $aggregate['request'];
        $requestId = self::requestId($request['request_id'] ?? null);
        $requestVersion = self::positiveInt(
            $request['request_version'] ?? null,
            'request.request_version'
        );
        $requestStatus = (string)($request['request_status'] ?? '');
        if (!in_array($requestStatus, ['editing', 'ready_for_submit'], true)) {
            throw self::failure('checkout_projection_request_not_displayable');
        }
        $tenantId = self::token($request['tenant_id'] ?? null, 32, 'request.tenant_id');
        $storeId = self::positiveInt($request['store_id'] ?? null, 'request.store_id');
        $operatorId = self::positiveInt($request['operator_id'] ?? null, 'request.operator_id');
        $memberId = self::nonNegativeInt($request['member_id'] ?? null, 'request.member_id');
        $workspaceId = self::token($request['workspace_id'] ?? null, 64, 'request.workspace_id');
        $stateContextId = self::token(
            $request['state_context_id'] ?? null,
            64,
            'request.state_context_id'
        );
        $workspaceVersion = self::positiveInt(
            $request['authority_snapshot_version'] ?? null,
            'request.authority_snapshot_version'
        );
        $workspaceCurrentVersion = self::positiveInt(
            $aggregate['workspaceCurrentVersion'] ?? null,
            'workspaceCurrentVersion'
        );
        $exactRequestProjection = ($aggregate['exactRequestProjection'] ?? false) === true;
        if ($workspaceVersion >= PHP_INT_MAX
            || $workspaceCurrentVersion <= $workspaceVersion
            || (!$exactRequestProjection
                && $workspaceCurrentVersion !== $workspaceVersion + 1)) {
            throw self::failure('checkout_projection_workspace_version_drift');
        }
        $authorityFingerprint = self::fingerprint(
            $request['authority_fingerprint'] ?? null,
            'request.authority_fingerprint'
        );
        $aggregateFingerprint = self::fingerprint(
            $request['aggregate_fingerprint'] ?? null,
            'request.aggregate_fingerprint'
        );
        $lastOperationFingerprint = self::fingerprint(
            $request['last_operation_fingerprint'] ?? null,
            'request.last_operation_fingerprint'
        );
        $preparationRequestId = self::token(
            $request['creation_idempotency_key'] ?? null,
            128,
            'request.creation_idempotency_key'
        );
        $originalIdempotencyKey = self::recoveredOriginalIdempotencyKey(
            $requestStatus,
            $request['last_idempotency_key'] ?? null
        );
        $memberName = self::text(
            $request['member_name_snapshot'] ?? null,
            128,
            'request.member_name_snapshot'
        );
        if ($memberId > 0 && trim($memberName) === '') {
            throw self::failure('checkout_projection_member_snapshot_incomplete');
        }
        $sourceContexts = self::sourceContexts(
            array_values($aggregate['sources']),
            $requestId,
            $requestVersion,
            $tenantId,
            $storeId
        );

        $rows = array_values($aggregate['lines']);
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw self::failure('checkout_projection_line_shape_invalid');
            }
        }
        usort($rows, static function ($left, $right): int {
            $sort = ((int)($left['sort_no'] ?? 0)) <=> ((int)($right['sort_no'] ?? 0));
            return $sort !== 0 ? $sort : ((int)($left['id'] ?? 0)) <=> ((int)($right['id'] ?? 0));
        });
        if (!$rows || count($rows) > 200) {
            throw self::failure('checkout_projection_line_count_invalid');
        }

        $lines = [];
        $lineIds = [];
        $saleOriginal = 0;
        $saleDiscount = 0;
        $saleAmount = 0;
        $saleDebt = 0;
        $entitlementActual = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw self::failure('checkout_projection_line_shape_invalid');
            }
            self::assertChildScope(
                $row,
                $requestId,
                $requestVersion,
                $tenantId,
                $storeId,
                $memberId,
                'line'
            );
            $lineId = self::lineId($row['line_id'] ?? null);
            if (isset($lineIds[$lineId])) {
                throw self::failure('checkout_projection_line_duplicate');
            }
            $lineIds[$lineId] = true;
            $role = (string)($row['line_role'] ?? '');
            $authorityKey = self::token(
                $row['authority_key'] ?? null,
                96,
                'line.authority_key'
            );
            $sourceId = self::positiveInt($row['source_id'] ?? null, 'line.source_id');
            $sourceVersion = self::positiveInt(
                $row['source_version'] ?? null,
                'line.source_version'
            );
            $quantity = self::positiveInt($row['quantity'] ?? null, 'line.quantity');
            $sourceName = self::nonEmptyText(
                $row['source_name_snapshot'] ?? null,
                255,
                'line.source_name_snapshot'
            );
                $sourceCode = self::text(
                    $row['source_code_snapshot'] ?? null,
                    64,
                    'line.source_code_snapshot'
                );
                $catalogSkuId = self::nonNegativeInt(
                    $row['catalog_sku_id'] ?? 0,
                    'line.catalog_sku_id'
                );
            $categoryId = self::nonNegativeInt(
                $row['category_id_snapshot'] ?? null,
                'line.category_id_snapshot'
            );
            $categoryName = self::text(
                $row['category_name_snapshot'] ?? null,
                128,
                'line.category_name_snapshot'
            );
            $storedFingerprint = self::fingerprint(
                $row['line_fingerprint'] ?? null,
                'line.line_fingerprint'
            );

            if ($role === 'sale') {
                $sourceType = (string)($row['source_type'] ?? '');
                if (!in_array($sourceType, ['product', 'card', 'project'], true)
                    || self::nonNegativeInt(
                        $row['entitlement_source_detail_id'] ?? null,
                        'line.entitlement_source_detail_id'
                    ) !== 0) {
                    throw self::failure('checkout_projection_sale_identity_invalid');
                }
                $original = self::nonNegativeInt(
                    $row['original_amount_cents'] ?? null,
                    'line.original_amount_cents'
                );
                $discount = self::nonNegativeInt(
                    $row['discount_amount_cents'] ?? null,
                    'line.discount_amount_cents'
                );
                $couponUserId = self::nonNegativeInt(
                    $row['coupon_user_id'] ?? 0,
                    'line.coupon_user_id'
                );
                $couponName = self::text(
                    $row['coupon_name_snapshot'] ?? '',
                    128,
                    'line.coupon_name_snapshot'
                );
                $couponDiscount = self::nonNegativeInt(
                    $row['coupon_discount_cents'] ?? 0,
                    'line.coupon_discount_cents'
                );
                $amount = self::nonNegativeInt(
                    $row['sale_amount_cents'] ?? null,
                    'line.sale_amount_cents'
                );
                $lineDebt = self::nonNegativeInt(
                    $row['debt_amount_cents'] ?? null,
                    'line.debt_amount_cents'
                );
                $configuredCost = self::nonNegativeInt(
                    $row['configured_cost_cents'] ?? 0,
                    'line.configured_cost_cents'
                );
                $priceChangeReason = self::text(
                    $row['price_change_reason'] ?? '',
                    255,
                    'line.price_change_reason'
                );
                $priceChangedBy = self::nonNegativeInt(
                    $row['price_changed_by'] ?? 0,
                    'line.price_changed_by'
                );
                $priceChangedByName = self::text(
                    $row['price_changed_by_name_snapshot'] ?? '',
                    128,
                    'line.price_changed_by_name_snapshot'
                );
                $priceChangedAt = self::nonNegativeInt(
                    $row['price_changed_at'] ?? 0,
                    'line.price_changed_at'
                );
                if ($original !== self::safeAdd($discount, $amount, 'sale_line_amount')
                    || $lineDebt > $amount
                    || self::nonNegativeInt(
                        $row['entitlement_actual_amount_cents'] ?? null,
                        'line.entitlement_actual_amount_cents'
                    ) !== 0) {
                    throw self::failure('checkout_projection_sale_amount_invalid');
                }
                $serviceObject = self::text(
                    $row['service_object'] ?? null,
                    16,
                    'line.service_object'
                );
                $isExperience = self::nonNegativeInt(
                    $row['is_experience'] ?? null,
                    'line.is_experience'
                );
                $friendCountsAsCustomer = self::nonNegativeInt(
                    $row['friend_counts_as_customer'] ?? 1,
                    'line.friend_counts_as_customer'
                );
                $isPresale = self::nonNegativeInt($row['is_presale'] ?? 0, 'line.is_presale');
                $inventoryOutboundRequired = self::nonNegativeInt($row['inventory_outbound_required'] ?? 1, 'line.inventory_outbound_required');
                if ($isPresale > 1 || $inventoryOutboundRequired > 1 || ($isPresale === 1 && $inventoryOutboundRequired === 1)) {
                    throw self::failure('checkout_projection_inventory_rule_invalid');
                }
                $manualLaborFeeCents = ($row['manual_labor_fee_cents'] ?? null) === null
                    ? null
                    : self::nonNegativeInt($row['manual_labor_fee_cents'], 'line.manual_labor_fee_cents');
                $craftsmenJson = $row['craftsmen_snapshot_json'] ?? null;
                $craftsmen = self::craftsmenSnapshot($craftsmenJson);
                $guideSelections = self::attributionSnapshot($row['guide_selections_json'] ?? null);
                $salesManagerSelections = self::attributionSnapshot($row['sales_manager_selections_json'] ?? null);
                if (!in_array($serviceObject, ['', 'self', 'friend'], true)
                    || $isExperience > 1 || $friendCountsAsCustomer > 1
                    || ($sourceType === 'project'
                        && !in_array($serviceObject, ['self', 'friend'], true))
                    || ($sourceType !== 'project'
                        && ($serviceObject !== '' || $friendCountsAsCustomer !== 1 || $isExperience !== 0 || $craftsmen !== []))) {
                    throw self::failure('checkout_projection_sale_service_tags_invalid');
                }
                $fingerprintInput = [
                    'authorityKey' => $authorityKey,
                    'saleClassification' => 'formal_sale',
                    'sourceType' => $sourceType,
                    'sourceId' => $sourceId,
                    'catalogSkuId' => $catalogSkuId,
                    'sourceVersion' => $sourceVersion,
                    'quantity' => $quantity,
                    'originalAmountCents' => $original,
                    'discountAmountCents' => $discount,
                    'couponUserId' => $couponUserId,
                    'couponNameSnapshot' => $couponName,
                    'couponDiscountCents' => $couponDiscount,
                    'saleAmountCents' => $amount,
                    'debtAmountCents' => $lineDebt,
                    'sourceNameSnapshot' => $sourceName,
                    'sourceCodeSnapshot' => $sourceCode,
                    'categoryIdSnapshot' => $categoryId,
                    'categoryNameSnapshot' => $categoryName,
                    'configuredCostCents' => $configuredCost,
                    'priceChangeReason' => $priceChangeReason,
                    'priceChangedBy' => $priceChangedBy,
                    'priceChangedByNameSnapshot' => $priceChangedByName,
                    'priceChangedAt' => $priceChangedAt,
                    'serviceObject' => $serviceObject,
                    'friendCountsAsCustomer' => $friendCountsAsCustomer,
                    'isExperience' => $isExperience,
                    'isPresale' => $isPresale,
                    'inventoryOutboundRequired' => $inventoryOutboundRequired,
                    'guideSelections' => $guideSelections,
                    'salesManagerSelections' => $salesManagerSelections,
                ];
                if ($isPresale === 0) {
                    unset($fingerprintInput['isPresale']);
                }
                if ($inventoryOutboundRequired === 1) {
                    unset($fingerprintInput['inventoryOutboundRequired']);
                }
                if ($isPresale !== 0) {
                    $fingerprintInput['isPresale'] = 1;
                }
                if ($inventoryOutboundRequired !== 1) {
                    $fingerprintInput['inventoryOutboundRequired'] = 0;
                }
                if ($manualLaborFeeCents !== null) {
                    $fingerprintInput['manualLaborFeeCents'] = $manualLaborFeeCents;
                }
                // Existing drafts predate this column and their fingerprint did
                // not contain craftsmen. Preserve that immutable contract until
                // a user action rewrites the draft with an explicit [] value.
                if (!CashierV3CheckoutCraftsmenSnapshot::isLegacyEmpty($craftsmenJson)) {
                    $fingerprintInput['craftsmen'] = $craftsmen;
                }
                if ($catalogSkuId <= 0) {
                    unset($fingerprintInput['catalogSkuId']);
                }
                $expectedFingerprint = CashierV3CheckoutSettlementCanonicalizer::fingerprint($fingerprintInput);
                if (!hash_equals($storedFingerprint, $expectedFingerprint)
                    && !self::matchesLegacyUnchangedPriceFingerprint(
                        $storedFingerprint,
                        $fingerprintInput,
                        $configuredCost,
                        $priceChangeReason,
                        $priceChangedBy,
                        $priceChangedByName,
                        $priceChangedAt,
                        $lineDebt
                    )) {
                    throw self::failure('checkout_projection_line_fingerprint_drift');
                }
                $saleOriginal = self::safeAdd($saleOriginal, $original, 'sale_original_total');
                $saleDiscount = self::safeAdd($saleDiscount, $discount, 'sale_discount_total');
                $saleAmount = self::safeAdd($saleAmount, $amount, 'sale_total');
                $saleDebt = self::safeAdd($saleDebt, $lineDebt, 'sale_debt_total');
                $lines[] = [
                    'id' => $lineId,
                    'lineRole' => 'sale',
                    'authorityKey' => $authorityKey,
                    'name' => $sourceName,
                    'kind' => self::saleKindLabel($sourceType),
                    'quantity' => $quantity,
                    'sourceType' => $sourceType,
                    'productId' => $sourceId,
                    'catalogSkuId' => $catalogSkuId,
                    'sourceVersion' => $sourceVersion,
                    'originalAmount' => self::money($original),
                    'finalAmount' => self::money($amount),
                    'amount' => self::money($amount),
                    'couponUserId' => $couponUserId,
                    'couponNameSnapshot' => $couponName,
                    'couponDiscountAmount' => self::money($couponDiscount),
                    'amountRole' => 'sale_receivable',
                    'debtAmountCents' => $lineDebt,
                    'serviceSource' => '本次购买',
                    'sourceCode' => $sourceCode,
                    'categoryId' => $categoryId,
                    'categoryName' => $categoryName,
                    'serviceObject' => $serviceObject,
                    'friendCountsAsCustomer' => $friendCountsAsCustomer === 1,
                    'craftsmen' => $craftsmen,
                    'guideSelections' => $guideSelections,
                    'salesManagerSelections' => $salesManagerSelections,
                    'laborManualFeeCents' => $manualLaborFeeCents,
                    'isExperience' => $isExperience === 1,
                    'isPresale' => $isPresale === 1,
                ];
                if ($inventoryOutboundRequired !== 1) {
                    $fingerprintInput['inventoryOutboundRequired'] = 0;
                }
                continue;
            }

            if ($role !== 'entitlement_service' || $memberId <= 0) {
                throw self::failure('checkout_projection_line_role_invalid');
            }
            $sourceKind = (string)($row['source_kind'] ?? '');
            if (!in_array($sourceKind, ['count_card', 'time_card', 'custom_card', 'gift', 'unknown'], true)) {
                throw self::failure('checkout_projection_entitlement_kind_invalid');
            }
            $detailId = self::positiveInt(
                $row['entitlement_source_detail_id'] ?? null,
                'line.entitlement_source_detail_id'
            );
            $projectId = self::positiveInt($row['project_id'] ?? null, 'line.project_id');
            $projectVersion = self::positiveInt(
                $row['project_version'] ?? null,
                'line.project_version'
            );
            $projectName = self::nonEmptyText(
                $row['project_name_snapshot'] ?? null,
                128,
                'line.project_name_snapshot'
            );
            $actual = self::nonNegativeInt(
                $row['entitlement_actual_amount_cents'] ?? null,
                'line.entitlement_actual_amount_cents'
            );
            foreach (['original_amount_cents', 'discount_amount_cents', 'sale_amount_cents'] as $field) {
                if (self::nonNegativeInt($row[$field] ?? null, 'line.' . $field) !== 0) {
                    throw self::failure('checkout_projection_entitlement_sale_amount_forbidden');
                }
            }
            $expectedFingerprint = CashierV3CheckoutSettlementCanonicalizer::fingerprint([
                'authorityKey' => $authorityKey,
                'sourceKind' => $sourceKind,
                'holderId' => $sourceId,
                'entitlementSourceDetailId' => $detailId,
                'sourceVersion' => $sourceVersion,
                'projectId' => $projectId,
                'projectVersion' => $projectVersion,
                'quantity' => $quantity,
                'actualEntitlementAmountCents' => $actual,
                'sourceNameSnapshot' => $sourceName,
                'sourceCodeSnapshot' => $sourceCode,
                'projectNameSnapshot' => $projectName,
                'projectCategoryIdSnapshot' => $categoryId,
                'projectCategoryNameSnapshot' => $categoryName,
            ]);
            if (!hash_equals($storedFingerprint, $expectedFingerprint)) {
                throw self::failure('checkout_projection_line_fingerprint_drift');
            }
            $entitlementActual = self::safeAdd(
                $entitlementActual,
                $actual,
                'entitlement_actual_total'
            );
            $lines[] = [
                'id' => $lineId,
                'lineRole' => 'entitlement_service',
                'authorityKey' => $authorityKey,
                'name' => $projectName,
                'kind' => '项目',
                'quantity' => $quantity,
                'memberId' => $memberId,
                'entitlementInstanceId' => $sourceId,
                'cardHolderId' => $sourceId,
                'entitlementSourceDetailId' => $detailId,
                'entitlementSourceVersion' => $sourceVersion,
                'cardHolderVersion' => $sourceVersion,
                'memberBenefitPoolVersion' => $projectVersion,
                'projectId' => $projectId,
                'projectVersion' => $projectVersion,
                'entitlementSourceKind' => $sourceKind,
                'entitlementSourceName' => $sourceName,
                'fullCardNo' => $sourceCode,
                'actualAmount' => self::money($actual),
                'finalAmount' => self::money($actual),
                'amount' => self::money($actual),
                'amountRole' => 'entitlement_actual',
                'serviceSource' => '卡内项目',
                'categoryId' => $categoryId,
                'categoryName' => $categoryName,
            ];
        }

        $composition = self::composition($lines);
        if ((string)($request['composition'] ?? '') !== $composition['code']) {
            throw self::failure('checkout_projection_composition_drift');
        }
        $salesAmount = self::nonNegativeInt(
            $request['sales_amount_cents'] ?? null,
            'request.sales_amount_cents'
        );
        $receivable = self::nonNegativeInt(
            $request['receivable_amount_cents'] ?? null,
            'request.receivable_amount_cents'
        );
        $requestEntitlementActual = self::nonNegativeInt(
            $request['entitlement_actual_amount_cents'] ?? null,
            'request.entitlement_actual_amount_cents'
        );
        if ($saleAmount !== $salesAmount
            || $receivable !== $salesAmount - $saleDebt
            || $entitlementActual !== $requestEntitlementActual) {
            throw self::failure('checkout_projection_line_total_drift');
        }

        $paymentRows = array_values($aggregate['payments']);
        foreach ($paymentRows as $row) {
            if (!is_array($row)) {
                throw self::failure('checkout_projection_payment_shape_invalid');
            }
        }
        usort($paymentRows, static function ($left, $right): int {
            $sort = ((int)($left['sort_no'] ?? 0)) <=> ((int)($right['sort_no'] ?? 0));
            return $sort !== 0 ? $sort : ((int)($left['id'] ?? 0)) <=> ((int)($right['id'] ?? 0));
        });
        if (count($paymentRows) > self::MAX_PAYMENT_DRAFTS) {
            throw self::failure('checkout_projection_payment_count_invalid');
        }
        $selectedLines = [];
        $paymentAuthorityKeys = [];
        $selectedPayment = 0;
        foreach ($paymentRows as $row) {
            if (!is_array($row)) {
                throw self::failure('checkout_projection_payment_shape_invalid');
            }
            self::assertChildScope(
                $row,
                $requestId,
                $requestVersion,
                $tenantId,
                $storeId,
                $memberId,
                'payment'
            );
            self::positiveInt($row['operator_id'] ?? null, 'payment.operator_id');
            $method = (string)($row['payment_method'] ?? '');
            if (!isset(self::PAYMENT_NAMES[$method])) {
                throw self::failure('checkout_projection_payment_method_invalid');
            }
            $paymentAuthorityKey = self::token(
                $row['payment_authority_key'] ?? null,
                96,
                'payment.payment_authority_key'
            );
            if (isset($paymentAuthorityKeys[$paymentAuthorityKey])) {
                throw self::failure('checkout_projection_payment_authority_key_duplicate');
            }
            $paymentAuthorityKeys[$paymentAuthorityKey] = true;
            $amount = self::nonNegativeInt($row['amount_cents'] ?? null, 'payment.amount_cents');
            $businessTime = self::positiveInt(
                $row['operation_occurred_at'] ?? null,
                'payment.operation_occurred_at'
            );
            $externalTransactionNo = self::text(
                $row['external_transaction_no'] ?? null,
                64,
                'payment.external_transaction_no'
            );
            $remark = self::text($row['remark'] ?? null, 255, 'payment.remark');
            $storedFingerprint = self::fingerprint(
                $row['payment_fingerprint'] ?? null,
                'payment.payment_fingerprint'
            );
            $expectedFingerprint = CashierV3CheckoutSettlementCanonicalizer::fingerprint([
                'paymentAuthorityKey' => $paymentAuthorityKey,
                'method' => $method,
                'amountCents' => $amount,
                'businessTime' => $businessTime,
                'externalTransactionNo' => $externalTransactionNo,
                'remark' => $remark,
            ]);
            if (!hash_equals($storedFingerprint, $expectedFingerprint)) {
                throw self::failure('checkout_projection_payment_fingerprint_drift');
            }
            $selectedPayment = self::safeAdd($selectedPayment, $amount, 'selected_payment_total');
            $selectedLines[] = [
                'id' => self::paymentId($row['payment_draft_id'] ?? null),
                'paymentAuthorityKey' => $paymentAuthorityKey,
                'method' => $method,
                'name' => self::PAYMENT_NAMES[$method],
                'amount' => self::money($amount),
                'status' => '待收款',
                'canEdit' => true,
                'canRemove' => true,
                'externalTransactionNo' => $externalTransactionNo,
                'remark' => $remark,
                'noteSummary' => $remark === '' ? '未填写备注' : $remark,
            ];
        }

        $selectedPaymentExpected = self::nonNegativeInt(
            $request['selected_payment_amount_cents'] ?? null,
            'request.selected_payment_amount_cents'
        );
        $cashPerformance = self::nonNegativeInt(
            $request['cash_performance_amount_cents'] ?? null,
            'request.cash_performance_amount_cents'
        );
        $balance = self::nonNegativeInt(
            $request['balance_deduction_amount_cents'] ?? null,
            'request.balance_deduction_amount_cents'
        );
        $debt = self::nonNegativeInt($request['debt_amount_cents'] ?? null, 'request.debt_amount_cents');
        if ($selectedPayment !== $selectedPaymentExpected
            || $cashPerformance !== $selectedPayment
            || $saleDebt !== $debt) {
            throw self::failure('checkout_projection_payment_total_drift');
        }
        // 余额扣款也是本单的已选结算方式。它没有独立的 payment_draft
        // 持久化行，但必须进入同一份“本次收款”投影，否则页面会同时
        // 显示待收为零、右侧却缺少余额收款行。
        if ($balance > 0) {
            $selectedLines[] = [
                'id' => 'balance-deduction',
                'kind' => 'balance_deduction',
                'name' => '余额支付',
                'amount' => self::money($balance),
                'status' => '待收款',
                'canEdit' => true,
                'editAction' => 'update-balance-payment',
                'canRemove' => true,
                'removalAction' => 'remove-balance-payment',
            ];
        }
        $settlement = self::safeAdd($selectedPayment, $balance, 'settlement_total');
        $remaining = $receivable > $settlement ? $receivable - $settlement : 0;
        $overpaid = $settlement > $receivable ? $settlement - $receivable : 0;

        $paymentMethods = [];
        foreach (CashierV3CheckoutSettlementKernel::paymentMethods() as $method) {
            if (!isset(self::PAYMENT_NAMES[$method])) {
                throw self::failure('checkout_projection_payment_dictionary_drift');
            }
            $paymentMethods[] = [
                'id' => $method,
                'name' => self::PAYMENT_NAMES[$method],
                // Selecting a bookkeeping method is a draft-editing action,
                // not proof of settlement. Keep it available after the
                // current total reaches zero so cashiers can add and edit
                // multiple independent collection lines before final check.
                'canAdd' => count($paymentRows) < self::MAX_PAYMENT_DRAFTS,
                'disabledReason' => count($paymentRows) < self::MAX_PAYMENT_DRAFTS
                    ? '' : '本单收款明细已达到数量上限。',
                'cashPerformanceEligible' => true,
            ];
        }
        $legacy = CashierV3CheckoutSettlementKernel::legacyEntryContract();
        $paymentMethods[] = [
            'id' => (string)$legacy['methodCode'],
            'name' => '旧卡录入',
            'canAdd' => false,
            'disabledReason' => '旧卡录入需单独办理，不能与其他收款方式组合。',
            'cashPerformanceEligible' => false,
        ];

        $preparationToken = 'CKPT-' . hash('sha256', implode('|', [
            self::CONTRACT_VERSION,
            $requestId,
            (string)$requestVersion,
            $aggregateFingerprint,
            $lastOperationFingerprint,
        ]));
        $sourceDocumentType = self::token(
            $request['source_document_type'] ?? null,
            32,
            'request.source_document_type'
        );
        $sourceDocumentId = self::token(
            $request['source_document_id'] ?? null,
            64,
            'request.source_document_id'
        );
        $businessType = $sourceDocumentType === 'debt_repayment'
            ? 'debt_repayment'
            : 'checkout';
        $compositionProjection = $composition['projection'];
        if ($businessType === 'debt_repayment') {
            $compositionProjection['primaryAction'] = 'collect_payment';
            $compositionProjection['primaryActionLabel'] = '确认还款';
            foreach ($compositionProjection['steps'] as &$step) {
                if (($step['key'] ?? '') === 'final') {
                    $step['label'] = '确认还款';
                }
            }
            unset($step);
        }
        $projection = [
            'contractVersion' => self::CONTRACT_VERSION,
            'businessType' => $businessType,
            'status' => 'editing',
            'requestStatus' => $requestStatus,
            'checkoutRequestId' => $requestId,
            'requestId' => $requestId,
            'checkoutRequestVersion' => $requestVersion,
            'revision' => $requestVersion,
            'preparationRequestId' => $preparationRequestId,
            'originalIdempotencyKey' => $originalIdempotencyKey,
            'preparationToken' => $preparationToken,
            'preparationTokenRole' => 'projection_correlation_only',
            'preparationReady' => true,
            'snapshotReady' => true,
            'recoveryReady' => true,
            'resumeOnLoad' => true,
            'authoritySnapshotFingerprint' => $authorityFingerprint,
            'aggregateFingerprint' => $aggregateFingerprint,
            'workspaceId' => $workspaceId,
            'stateContextId' => $stateContextId,
            'businessDate' => (string)($request['business_date'] ?? ''),
            'supplement' => [
                'enabled' => (int)($request['supplement_enabled'] ?? 0) === 1,
                'businessDate' => (string)($request['supplement_business_date'] ?? ''),
                'reason' => (string)($request['supplement_reason'] ?? ''),
                'operatorId' => (int)($request['supplement_operator_id'] ?? 0),
                'operatorNameSnapshot' => (string)($request['supplement_operator_name_snapshot'] ?? ''),
                'operatedAt' => (int)($request['supplement_operated_at'] ?? 0),
            ],
            'sourceDocumentType' => $sourceDocumentType,
            'sourceDocumentId' => $sourceDocumentId,
            'sourceDocumentNo' => (string)($request['source_document_no'] ?? ''),
            'member' => $memberId > 0 ? [
                'id' => (string)$memberId,
                'name' => $memberName,
            ] : null,
            'orderLines' => $lines,
            'summary' => [
                'selectedCount' => count($lines),
                'originalAmount' => self::money($saleOriginal),
                'discountAmount' => self::money($saleDiscount),
                'receivableAmount' => self::money($receivable),
                'entitlementActualAmount' => self::money($entitlementActual),
            ],
            'compositionCode' => $composition['code'],
            'composition' => $compositionProjection,
            'debtAmount' => self::money($debt),
            'cashPerformanceAmount' => self::money($cashPerformance),
            'balancePaymentAmount' => self::money($balance),
            'payment' => [
                'methods' => $paymentMethods,
                'selectedLines' => $selectedLines,
                'summary' => [
                    'receivableAmount' => self::money($receivable),
                    // "已选收款" only represents actual payment routes shown
                    // on the right: bookkeeping collection plus balance. Debt
                    // remains part of settlement/remaining validation, but is
                    // an amount receivable rather than collected payment.
                    'selectedAmount' => self::money(
                        self::safeAdd($selectedPayment, $balance, 'selected_collection_total')
                    ),
                    'remainingAmount' => self::money($remaining),
                    'overpaidAmount' => self::money($overpaid),
                    'validationMessage' => '收款金额以提交前后端最终校验为准。',
                ],
            ],
            'finalChanges' => [],
            'commandContexts' => array_merge([
                [
                    'kind' => 'cashier_workspace',
                    'id' => $workspaceId,
                    'expectedVersion' => $workspaceCurrentVersion,
                ],
                [
                    'kind' => 'checkout_request',
                    'id' => $requestId,
                    'expectedVersion' => $requestVersion,
                ],
            ], $sourceContexts),
        ];
        if ($sourceDocumentType === 'service_order') {
            $projection['serviceOrderId'] = $sourceDocumentId;
        }
        return $projection;
    }

    private static function matchesLegacyUnchangedPriceFingerprint(
        string $storedFingerprint,
        array $fingerprintInput,
        int $configuredCost,
        string $priceChangeReason,
        int $priceChangedBy,
        string $priceChangedByName,
        int $priceChangedAt,
        int $lineDebt
    ): bool {
        if ($lineDebt !== 0) {
            return false;
        }
        unset($fingerprintInput['debtAmountCents']);
        if (hash_equals(
            $storedFingerprint,
            CashierV3CheckoutSettlementCanonicalizer::fingerprint($fingerprintInput)
        )) {
            return true;
        }
        if ($configuredCost !== 0
            || $priceChangeReason !== ''
            || $priceChangedBy !== 0
            || $priceChangedByName !== ''
            || $priceChangedAt !== 0) {
            return false;
        }
        unset(
            $fingerprintInput['configuredCostCents'],
            $fingerprintInput['priceChangeReason'],
            $fingerprintInput['priceChangedBy'],
            $fingerprintInput['priceChangedByNameSnapshot'],
            $fingerprintInput['priceChangedAt']
        );
        return hash_equals(
            $storedFingerprint,
            CashierV3CheckoutSettlementCanonicalizer::fingerprint($fingerprintInput)
        );
    }

    /**
     * A prepared checkout must resume with the final key paired to the
     * persisted preparation command. Replacing it would create a second
     * submission identity for the same locked aggregate.
     */
    public static function recoveredOriginalIdempotencyKey(
        string $requestStatus,
        $lastIdempotencyKey
    ): string {
        if ($requestStatus !== 'ready_for_submit') {
            return '';
        }
        $key = trim((string)$lastIdempotencyKey);
        $matched = [];
        if (preg_match(
            '/^CHECKOUT_PREPARE-([0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})$/D',
            $key,
            $matched
        ) !== 1) {
            throw self::failure('checkout_projection_preparation_idempotency_key_invalid');
        }
        return 'CHECKOUT-' . $matched[1];
    }

    private static function sourceContexts(
        array $rows,
        string $requestId,
        int $requestVersion,
        string $tenantId,
        int $storeId
    ): array {
        $authorityRows = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)
                || (string)($row['request_id'] ?? '') !== $requestId
                || (string)($row['tenant_id'] ?? '') !== $tenantId
                || self::positiveInt($row['store_id'] ?? null, 'source.store_id') !== $storeId
                || self::positiveInt(
                    $row['bound_request_version'] ?? null,
                    'source.bound_request_version'
                ) !== $requestVersion) {
                throw self::failure('checkout_projection_source_scope_drift', ['index' => $index]);
            }
            $kind = (string)($row['source_kind'] ?? '');
            $id = (string)($row['source_id'] ?? '');
            $sourceVersion = self::positiveInt(
                $row['source_version'] ?? null,
                'source.source_version'
            );
            $role = (string)($row['source_role'] ?? '');
            $expectedFingerprint = CashierV3CheckoutVerifiedSourceSet::referenceFingerprint(
                $kind,
                $id,
                $sourceVersion,
                $role
            );
            if (!hash_equals(
                self::fingerprint(
                    $row['source_fingerprint'] ?? null,
                    'source.source_fingerprint'
                ),
                $expectedFingerprint
            )) {
                throw self::failure('checkout_projection_source_fingerprint_drift', [
                    'index' => $index,
                ]);
            }
            $authorityRows[] = [
                'tenantId' => $tenantId,
                'storeId' => $storeId,
                'kind' => $kind,
                'id' => $id,
                'sourceVersion' => $sourceVersion,
                'role' => $role,
            ];
        }
        $verified = CashierV3CheckoutVerifiedSourceSet::fromServerVerifiedAuthorityRows(
            $tenantId,
            $storeId,
            $authorityRows
        );
        return array_map(static function (array $reference): array {
            return [
                'kind' => $reference['kind'],
                'id' => $reference['id'],
                'expectedVersion' => $reference['sourceVersion'],
            ];
        }, $verified->references());
    }

    private static function composition(array $lines): array
    {
        $roles = [];
        foreach ($lines as $line) {
            $role = (string)$line['lineRole'];
            if (!in_array($role, $roles, true)) {
                $roles[] = $role;
            }
        }
        $hasSale = in_array('sale', $roles, true);
        $hasEntitlement = in_array('entitlement_service', $roles, true);
        $code = $hasSale && $hasEntitlement
            ? 'mixed'
            : ($hasSale ? 'sale_only' : 'entitlement_only');
        $action = $hasSale && $hasEntitlement
            ? 'collect_and_complete'
            : ($hasEntitlement ? 'complete_service' : 'collect_payment');
        $labels = [
            'collect_payment' => '确认收款',
            'complete_service' => '确认完成服务',
            'collect_and_complete' => '收款并完成服务',
        ];
        $label = $labels[$action];
        $steps = [[
            'key' => 'order',
            'number' => 1,
            'label' => $hasEntitlement ? '确认本次内容' : '确认订单',
        ]];
        if ($hasSale) {
            $steps[] = ['key' => 'payment', 'number' => 2, 'label' => '收款信息'];
        }
        $steps[] = ['key' => 'final', 'number' => 3, 'label' => $label];
        $steps[] = ['key' => 'result', 'number' => 4, 'label' => '处理结果'];
        return [
            'code' => $code,
            'projection' => [
                'lineRoles' => $roles,
                'hasSale' => $hasSale,
                'hasEntitlement' => $hasEntitlement,
                'primaryAction' => $action,
                'primaryActionLabel' => $label,
                'steps' => $steps,
            ],
        ];
    }

    private static function assertChildScope(
        array $row,
        string $requestId,
        int $requestVersion,
        string $tenantId,
        int $storeId,
        int $memberId,
        string $path
    ): void {
        if ((string)($row['request_id'] ?? '') !== $requestId
            || self::positiveInt($row['draft_version'] ?? null, $path . '.draft_version') !== $requestVersion
            || (string)($row['draft_status'] ?? '') !== 'draft'
            || (string)($row['tenant_id'] ?? '') !== $tenantId
            || self::positiveInt($row['store_id'] ?? null, $path . '.store_id') !== $storeId
            || self::nonNegativeInt($row['member_id'] ?? null, $path . '.member_id') !== $memberId) {
            throw self::failure('checkout_projection_child_scope_drift', ['path' => $path]);
        }
    }

    private static function safeAdd(int $left, int $right, string $path): int
    {
        if ($left < 0 || $right < 0 || $left > PHP_INT_MAX - $right) {
            throw self::failure('checkout_projection_integer_overflow', ['path' => $path]);
        }
        return $left + $right;
    }

    private static function positiveInt($value, string $path): int
    {
        $value = self::canonicalInt($value, $path);
        if ($value <= 0) {
            throw self::failure('checkout_projection_positive_integer_required', ['path' => $path]);
        }
        return $value;
    }

    private static function nonNegativeInt($value, string $path): int
    {
        return self::canonicalInt($value, $path);
    }

    private static function canonicalInt($value, string $path): int
    {
        if (is_int($value)) {
            if ($value < 0) {
                throw self::failure('checkout_projection_integer_invalid', ['path' => $path]);
            }
            return $value;
        }
        if (!is_string($value)
            || preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1
            || strlen($value) > strlen((string)PHP_INT_MAX)
            || (strlen($value) === strlen((string)PHP_INT_MAX)
                && strcmp($value, (string)PHP_INT_MAX) > 0)) {
            throw self::failure('checkout_projection_integer_invalid', ['path' => $path]);
        }
        return (int)$value;
    }

    private static function requestId($value): string
    {
        if (!is_string($value) || preg_match('/^CKR-[0-9a-f]{40}$/D', $value) !== 1) {
            throw self::failure('checkout_projection_request_id_invalid');
        }
        return $value;
    }

    private static function lineId($value): string
    {
        if (!is_string($value) || preg_match('/^CKL-[0-9a-f]{40}$/D', $value) !== 1) {
            throw self::failure('checkout_projection_line_id_invalid');
        }
        return $value;
    }

    private static function paymentId($value): string
    {
        if (!is_string($value) || preg_match('/^CKP-[0-9a-f]{40}$/D', $value) !== 1) {
            throw self::failure('checkout_projection_payment_id_invalid');
        }
        return $value;
    }

    private static function fingerprint($value, string $path): string
    {
        if (!is_string($value) || preg_match('/^[0-9a-f]{64}$/D', $value) !== 1) {
            throw self::failure('checkout_projection_fingerprint_invalid', ['path' => $path]);
        }
        return $value;
    }

    private static function token($value, int $maxBytes, string $path): string
    {
        if (!is_string($value)) {
            throw self::failure('checkout_projection_token_invalid', ['path' => $path]);
        }
        $value = trim($value);
        if ($value === '' || strlen($value) > $maxBytes
            || preg_match('/^[A-Za-z0-9:._-]+$/D', $value) !== 1) {
            throw self::failure('checkout_projection_token_invalid', ['path' => $path]);
        }
        return $value;
    }

    private static function text($value, int $maxBytes, string $path): string
    {
        if (!is_string($value) || strlen($value) > $maxBytes
            || json_encode($value, JSON_UNESCAPED_UNICODE) === false) {
            throw self::failure('checkout_projection_text_invalid', ['path' => $path]);
        }
        return $value;
    }

    private static function nonEmptyText($value, int $maxBytes, string $path): string
    {
        $value = self::text($value, $maxBytes, $path);
        if (trim($value) === '') {
            throw self::failure('checkout_projection_text_invalid', ['path' => $path]);
        }
        return $value;
    }

    private static function craftsmenSnapshot($json): array
    {
        try {
            return CashierV3CheckoutCraftsmenSnapshot::decode($json);
        } catch (\Throwable $exception) {
            throw self::failure('checkout_projection_craftsmen_snapshot_invalid');
        }
    }

    private static function attributionSnapshot($json): array
    {
        if ($json === null || trim((string)$json) === '') return [];
        $decoded = is_array($json) ? $json : json_decode((string)$json, true);
        if (!is_array($decoded)) throw self::failure('checkout_projection_attribution_snapshot_invalid');
        $result = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) throw self::failure('checkout_projection_attribution_snapshot_invalid');
            $id = self::positiveInt($row['employeeId'] ?? $row['employee_id'] ?? $row['id'] ?? null, 'line.attribution.employee_id');
            // Attribution names are immutable checkout snapshots and are part
            // of the canonical line fingerprint. Keep the same normalized
            // shape as CheckoutSettlementKernel so payment projection cannot
            // reject a freshly prepared line as fingerprint drift.
            $snapshot = [
                'employeeId' => $id,
                'name' => (string)($row['name'] ?? $row['employeeNameSnapshot'] ?? ''),
            ];
            if (array_key_exists('guideRoundNo', $row) || array_key_exists('guide_round_no', $row)) {
                $roundNo = self::positiveInt(
                    $row['guideRoundNo'] ?? $row['guide_round_no'] ?? null,
                    'line.guide_round_no'
                );
                if ($roundNo > 3) {
                    throw self::failure('checkout_projection_guide_round_invalid');
                }
                $snapshot['guideRoundNo'] = $roundNo;
            }
            $result[] = $snapshot;
        }
        return $result;
    }

    private static function money(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    private static function saleKindLabel(string $sourceType): string
    {
        return [
            'product' => '产品',
            'card' => '卡项',
            'project' => '项目',
        ][$sourceType];
    }

    private static function failure(
        string $reason,
        array $detail = []
    ): CashierV3CheckoutSettlementContractException {
        return new CashierV3CheckoutSettlementContractException($reason, $detail);
    }
}
