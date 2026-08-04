<?php

namespace app\services\cashier\v3\settlement;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3BusinessDocumentNumberServices;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\cashier\CashierV3CashierWorkspaceServices;
use app\services\cashier\v3\checkout\CashierV3EntitlementCompletionAuthorityAdapter;
use app\services\cashier\v3\checkout\CashierV3EntitlementCompletionAuthorityException;
use app\services\cashier\v3\checkout\CashierV3EntitlementCompletionContractException;
use app\services\cashier\v3\checkout\persistence\CashierV3EntitlementCompletionPersistenceException;
use app\services\cashier\v3\checkout\persistence\CashierV3EntitlementCompletionPlanV1;
use app\services\cashier\v3\checkout\persistence\ThinkPhpCashierV3EntitlementCompletionWriter;
use app\services\cashier\v3\checkout\provider\CashierV3EntitlementProviderContractException;
use app\services\cashier\v3\checkout\provider\CashierV3InventoryCompletionGatewayAdapter;
use app\services\cashier\v3\checkout\provider\CashierV3MemberBalanceWriterAdapter;
use app\services\cashier\v3\event\CashierV3BusinessEventExecution;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\fact\CashierV3CheckoutFactContractException;
use app\services\cashier\v3\fact\CashierV3SaleOnlyFactAssembler;
use app\services\cashier\v3\fact\ThinkPhpCashierV3CheckoutFactRepository;
use app\services\cashier\v3\order\settlement\CashierV3SalesOrderAuthorityException;
use app\services\cashier\v3\order\settlement\CashierV3SalesOrderPlanV1;
use app\services\cashier\v3\order\settlement\ThinkPhpCashierV3SalesOrderAuthorityWriter;
use app\services\cashier\v3\service\CashierV3ServiceOrderAuthorityException;
use app\services\cashier\v3\settlement\payment\CashierV3PaymentCollectionAuthorityException;
use app\services\cashier\v3\settlement\payment\CashierV3PaymentCollectionPlanV1;
use app\services\cashier\v3\settlement\payment\ThinkPhpCashierV3PaymentCollectionAuthorityWriter;
use app\services\product\inventory\completion\InventoryCompletionContractException;
use app\services\product\inventory\completion\InventoryEntitlementCompletionContract;

/**
 * Stateless production implementation of the final checkout execution port.
 *
 * Gateway owns the only transaction. Every request-scoped object is carried by
 * CashierV3CheckoutSubmissionExecutionContext; this service keeps dependencies
 * only and therefore cannot leak one checkout's authority into another.
 */
final class ThinkPhpCashierV3CheckoutSubmissionExecutionPort
    implements CashierV3CheckoutSubmissionExecutionPort
{
    public const CONTRACT_VERSION = 'thinkphp-cashier-v3-checkout-submission-execution-port-v1';

    private const ACTION = 'submit-checkout';

    /** @var CashierV3CashierWorkspaceServices */
    private $workspace;

    /** @var CashierV3CheckoutRequestRepository */
    private $requests;

    /** @var CashierV3SaleOnlyCheckoutSubmissionServices */
    private $saleOnly;

    /** @var CashierV3EntitlementCompletionAuthorityAdapter */
    private $entitlementAuthority;

    /** @var CashierV3InventoryCompletionGatewayAdapter */
    private $inventory;

    /** @var ThinkPhpCashierV3SalesOrderAuthorityWriter */
    private $salesOrders;

    /** @var ThinkPhpCashierV3PaymentCollectionAuthorityWriter */
    private $collections;

    /** @var ThinkPhpCashierV3CheckoutFactRepository */
    private $facts;

    /** @var ThinkPhpCashierV3EntitlementCompletionWriter */
    private $entitlements;

    /** @var CashierV3CheckoutDebtAuthorityServices */
    private $debts;

    /** @var CashierV3MemberBalanceWriterAdapter */
    private $balances;

    /** @var string */
    private $serverNamespaceSecret;

    public function __construct(
        CashierV3CashierWorkspaceServices $workspace,
        CashierV3EntitlementCompletionAuthorityAdapter $entitlementAuthority,
        ?CashierV3SaleOnlyCheckoutSubmissionServices $saleOnly = null,
        ?CashierV3CheckoutRequestRepository $requests = null,
        ?ThinkPhpCashierV3SalesOrderAuthorityWriter $salesOrders = null,
        ?ThinkPhpCashierV3PaymentCollectionAuthorityWriter $collections = null,
        ?ThinkPhpCashierV3CheckoutFactRepository $facts = null,
        ?CashierV3InventoryCompletionGatewayAdapter $inventory = null,
        ?ThinkPhpCashierV3EntitlementCompletionWriter $entitlements = null,
        string $serverNamespaceSecret = '',
        ?CashierV3CheckoutDebtAuthorityServices $debts = null,
        ?CashierV3MemberBalanceWriterAdapter $balances = null
    ) {
        $this->workspace = $workspace;
        $this->requests = $requests ?: new ThinkPhpCashierV3CheckoutRequestRepository();
        $this->salesOrders = $salesOrders ?: new ThinkPhpCashierV3SalesOrderAuthorityWriter();
        $this->collections = $collections ?: new ThinkPhpCashierV3PaymentCollectionAuthorityWriter();
        $this->facts = $facts ?: new ThinkPhpCashierV3CheckoutFactRepository();
        $this->inventory = $inventory ?: new CashierV3InventoryCompletionGatewayAdapter();
        $this->entitlements = $entitlements ?: new ThinkPhpCashierV3EntitlementCompletionWriter();
        $this->debts = $debts ?: new CashierV3CheckoutDebtAuthorityServices();
        $this->balances = $balances ?: new CashierV3MemberBalanceWriterAdapter();
        $this->entitlementAuthority = $entitlementAuthority;
        $this->serverNamespaceSecret = $serverNamespaceSecret;
        $this->saleOnly = $saleOnly ?: new CashierV3SaleOnlyCheckoutSubmissionServices(
            $workspace,
            $this->requests,
            $this->salesOrders,
            $this->collections,
            $this->facts,
            $serverNamespaceSecret
        );
    }

    public function lockSubmissionAuthorityInTx(array $scope): array
    {
        return $this->domainCall(function () use ($scope): array {
            CashierV3TransactionGuard::assertInTransaction('checkoutSubmissionExecution.lockAuthority');
            if (($scope['action'] ?? null) !== self::ACTION) {
                throw self::failure('checkout_submit_action_invalid');
            }
            $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
            self::assertPayload($payload);
            $operatorScope = $scope['operator_scope'] ?? null;
            $dataScope = $scope['data_scope'] ?? null;
            $eventRecorder = $scope['event_recorder'] ?? null;
            $eventExecution = $scope['event_execution'] ?? null;
            $eventContract = is_array($scope['event_contract'] ?? null)
                ? $scope['event_contract']
                : [];
            if (!($operatorScope instanceof CashierV3OperatorScope)
                || !($dataScope instanceof CashierV3DataScopeContext)
                || !($eventRecorder instanceof CashierV3BusinessEventRecorder)
                || !($eventExecution instanceof CashierV3BusinessEventExecution)) {
                throw self::failure('checkout_submit_execution_scope_missing');
            }
            self::assertDataScope($operatorScope, $dataScope);
            $stateContextId = self::stateContextId($scope['state_context_id'] ?? null);
            $workspaceId = sprintf(
                'ws:%d:%d:%s',
                $operatorScope->storeId(),
                $operatorScope->operatorId(),
                $stateContextId
            );
            $requestId = (string)$payload['checkoutRequestId'];
            $requestVersion = (int)$payload['checkoutRequestVersion'];
            $aggregate = $this->requests->lockAggregateForSubmitInTx(
                $requestId,
                $requestVersion,
                $workspaceId,
                $stateContextId,
                $operatorScope,
                $dataScope
            );
            self::assertPreparationIdentity($payload, (array)$aggregate['request']);
            $request = (array)$aggregate['request'];
            $composition = (string)($request['composition'] ?? '');
            if (!in_array($composition, ['sale_only', 'entitlement_only', 'mixed'], true)) {
                throw self::failure('checkout_submit_composition_invalid');
            }
            $lineIds = self::lineIdsByRole((array)$aggregate['lines']);
            self::assertComposition($composition, $lineIds);
            $commandKey = self::commandIdempotencyKey($scope['idempotency_key'] ?? null);
            $settledAt = max(
                time(),
                (int)($request['operation_occurred_at'] ?? 0),
                (int)($request['recorded_at'] ?? 0)
            );

            $entitlementBundle = null;
            $entitlementAuthority = null;
            if ($composition !== 'sale_only') {
                $authorityScope = $scope;
                $authorityScope['server_time'] = $settledAt;
                $entitlementBundle = $this->entitlementAuthority->buildAfterGatewayLocks(
                    $aggregate,
                    $authorityScope
                );
                self::assertEntitlementBundle($entitlementBundle, $requestId, $requestVersion);
                $entitlementAuthority = [
                    'command' => $entitlementBundle['command'],
                    'lockedSnapshot' => $entitlementBundle['lockedSnapshot'],
                    'lockedResourcePlan' => $entitlementBundle['lockedResourcePlan'],
                    'staffSnapshots' => $entitlementBundle['staffSnapshots'],
                    'inventoryProviderSnapshot' => $entitlementBundle['inventoryProviderSnapshot'],
                    'inventoryDataScope' => $entitlementBundle['inventoryDataScope'],
                ];
            }

            $executionContext = new CashierV3CheckoutSubmissionExecutionContext(
                $aggregate,
                $operatorScope,
                $dataScope,
                $eventRecorder,
                $eventExecution,
                $eventContract,
                $entitlementBundle
            );
            $resourcePlan = is_array($scope['checkout_resource_plan'] ?? null)
                ? $scope['checkout_resource_plan']
                : [];
            $authority = [
                'contractVersion' => CashierV3CheckoutSubmissionOrchestrator::AUTHORITY_CONTRACT_VERSION,
                'checkoutRequestId' => $requestId,
                'checkoutRequestVersion' => $requestVersion,
                'commandIdempotencyKey' => $commandKey,
                'requestStatus' => 'ready_for_submit',
                'composition' => $composition,
                'workspaceId' => $workspaceId,
                'stateContextId' => $stateContextId,
                'tenantId' => (string)$request['tenant_id'],
                'storeId' => (int)$request['store_id'],
                'memberId' => (int)$request['member_id'],
                'settledAt' => $settledAt,
                'lockedAggregateFingerprint' => self::sha256(
                    $request['aggregate_fingerprint'] ?? null,
                    'checkout_locked_aggregate_fingerprint_invalid'
                ),
                'lockedResourcePlanFingerprint' => self::sha256(
                    $resourcePlan['resourcePlanFingerprint'] ?? null,
                    'checkout_locked_resource_plan_fingerprint_invalid'
                ),
                'saleLineIds' => $lineIds['sale'],
                'entitlementLineIds' => $lineIds['entitlement'],
                'entitlementAuthority' => $entitlementAuthority,
                'replayResult' => null,
                'executionContext' => $executionContext,
            ];
            $executionContext->assertMatchesAuthority($authority);
            return $authority;
        });
    }

    public function submitSaleOnlyInTx(array $scope, array $authority): array
    {
        $this->context($authority);
        return $this->saleOnly->submitInTx($scope);
    }

    public function persistSalesOrderInTx(array $authority): array
    {
        return $this->domainCall(function () use ($authority): array {
            $context = $this->context($authority);
            $plan = $this->salesPlan($context, $authority);
            return $this->salesOrders->persistInTx(
                $plan,
                $context->operatorScope(),
                $context->dataScope()
            );
        });
    }

    public function persistPaymentCollectionInTx(
        array $authority,
        array $salesOrder
    ): array {
        return $this->domainCall(function () use ($authority, $salesOrder): array {
            $context = $this->context($authority);
            $salesPlan = $this->salesPlan($context, $authority);
            if (($salesOrder['orderId'] ?? null) !== $salesPlan->header()['order_id']) {
                throw self::failure('checkout_payment_sales_order_mismatch');
            }
            $plan = $this->paymentPlan($context, $authority, $salesPlan);
            return $this->collections->persistInTx(
                $plan,
                $context->operatorScope(),
                $context->dataScope()
            );
        });
    }

    public function persistDebtInTx(array $authority, array $salesOrder): array
    {
        return $this->domainCall(function () use ($authority, $salesOrder): array {
            $context = $this->context($authority);
            $salesPlan = $this->salesPlan($context, $authority);
            $result = $this->debts->persistInTx(
                (array)$context->aggregate()['request'],
                $salesPlan,
                $salesOrder,
                $authority['commandIdempotencyKey'],
                $authority['settledAt'],
                $context->operatorScope(),
                $context->dataScope()
            );
            $result['checkoutRequestId'] = $authority['checkoutRequestId'];
            $result['salesOrderId'] = $salesOrder['orderId'];
            return $result;
        });
    }

    public function persistBalancePaymentInTx(
        array $authority,
        array $salesOrder,
        array $paymentCollection
    ): ?array {
        return $this->domainCall(function () use ($authority, $salesOrder, $paymentCollection): ?array {
            $context = $this->context($authority);
            $request = (array)$context->aggregate()['request'];
            $amountCents = (int)($request['balance_deduction_amount_cents'] ?? 0);
            if ($amountCents === 0) {
                return null;
            }
            if ($amountCents < 0
                || (int)($request['member_id'] ?? 0) <= 0
                || (int)($request['balance_account_version'] ?? 0) <= 0
                || (string)($request['balance_account_id'] ?? '') === '') {
                throw self::failure('checkout_balance_authority_invalid');
            }
            $orderId = (string)($salesOrder['orderId'] ?? '');
            if ($orderId === '') {
                throw self::failure('checkout_balance_sales_order_missing');
            }
            $sourceOrderId = $this->salesOrders->internalRecordIdInTx(
                (string)($request['tenant_id'] ?? ''),
                $orderId
            );
            $collectedAmountCents = (int)($paymentCollection['collectedAmountCents'] ?? 0);
            return $this->balances->deductCheckoutInTx([
                'memberId' => (int)$request['member_id'],
                'expectedVersion' => (int)$request['balance_account_version'],
                'amountCents' => $amountCents,
                'sourceOrderId' => $sourceOrderId,
                'commandIdempotencyKey' => (string)$authority['commandIdempotencyKey'],
                'paymentMode' => $collectedAmountCents > 0
                    ? CashierV3MemberBalanceWriterAdapter::PAYMENT_MODE_COMBINED
                    : CashierV3MemberBalanceWriterAdapter::PAYMENT_MODE_BALANCE_ONLY,
            ], $context->operatorScope(), $context->dataScope());
        });
    }

    public function planEntitlementCompletionInTx(array $authority): array
    {
        return $this->domainCall(function () use ($authority): array {
            $bundle = $this->context($authority)->entitlementBundle();
            $kernelPlan = (array)$bundle['kernelPlan'];
            return array_merge($kernelPlan, [
                'checkoutRequestId' => $authority['checkoutRequestId'],
                'receiptId' => $this->entitlementReceiptId($authority),
                'kernelPlanFingerprint' => CashierV3CheckoutSettlementCanonicalizer::fingerprint(
                    $kernelPlan
                ),
            ]);
        });
    }

    public function persistInventoryCompletionInTx(
        array $authority,
        array $entitlementPlan
    ): array {
        return $this->domainCall(function () use ($authority, $entitlementPlan): array {
            $context = $this->context($authority);
            $bundle = $context->entitlementBundle();
            $this->assertKernelPlan($entitlementPlan, $bundle, $authority);
            $kernelPlan = (array)$bundle['kernelPlan'];
            $dimension = (array)$kernelPlan['dimensionSnapshot'];
            $receiptId = $this->entitlementReceiptId($authority);
            $receiptKey = 'ICR-' . substr(hash(
                'sha256',
                $authority['tenantId'] . "\0" . $authority['checkoutRequestId']
                    . "\0" . $authority['commandIdempotencyKey']
            ), 0, 40);
            $result = $this->inventory->persistCompletionInTx([
                'contractVersion' => InventoryEntitlementCompletionContract::CONTRACT_VERSION,
                'receiptKey' => $receiptKey,
                'idempotencyKey' => $authority['commandIdempotencyKey'],
                'tenantId' => $authority['tenantId'],
                'storeId' => $authority['storeId'],
                'organizationNameSnapshot' => (string)$dimension['organizationName'],
                'storeNameSnapshot' => (string)$dimension['storeName'],
                'sourceType' => 'entitlement_completion',
                'sourceId' => $receiptId,
                'sourceDetailId' => $authority['checkoutRequestId'],
                'businessDate' => (string)$kernelPlan['businessDate'],
                'occurredAt' => (int)$kernelPlan['occurredAt'],
                'settledAt' => (int)$kernelPlan['settledAt'],
                'recordedAt' => (int)$kernelPlan['recordedAt'],
            ], $kernelPlan, (array)$bundle['inventoryProviderSnapshot'], $bundle['inventoryDataScope']);
            $result['checkoutRequestId'] = $authority['checkoutRequestId'];
            return $result;
        });
    }

    public function recordCompletionEventsInTx(
        array $authority,
        array $salesOrder,
        array $paymentCollection,
        array $debt,
        array $entitlementPlan,
        array $inventoryCompletion
    ): array {
        return $this->domainCall(function () use (
            $authority,
            $salesOrder,
            $paymentCollection,
            $debt,
            $entitlementPlan,
            $inventoryCompletion
        ): array {
            $context = $this->context($authority);
            $bundle = $context->entitlementBundle();
            $this->assertKernelPlan($entitlementPlan, $bundle, $authority);
            $kernelPlan = (array)$bundle['kernelPlan'];
            $eventInputs = $this->completionEventInputs(
                $authority,
                $kernelPlan,
                $salesOrder,
                $paymentCollection,
                $debt,
                $inventoryCompletion
            );
            $this->assertKernelEventCardinality($kernelPlan, $eventInputs);
            $recorded = [];
            $checkoutCompleted = null;
            foreach ($eventInputs as $input) {
                $result = $context->eventRecorder()->recordInTx(
                    $context->eventExecution(),
                    $context->eventContract(),
                    $input
                );
                $recorded[] = [
                    'eventNo' => (string)$result['event_no'],
                    'eventKey' => (string)$result['event_key'],
                    'eventType' => (string)$result['event_type'],
                ];
                if ($input['event_type'] === 'checkout.completed') {
                    $checkoutCompleted = self::completedEventAuthority(
                        $result,
                        $input,
                        $authority
                    );
                }
            }
            if ($checkoutCompleted === null) {
                throw self::failure('checkout_completed_event_missing');
            }
            return [
                'businessEventNos' => array_column($recorded, 'eventNo'),
                'eventSetFingerprint' => CashierV3CheckoutSettlementCanonicalizer::fingerprint(
                    $recorded
                ),
                'checkoutCompleted' => $checkoutCompleted,
            ];
        });
    }

    public function persistEntitlementCompletionInTx(
        array $authority,
        array $entitlementPlan,
        array $inventoryCompletion,
        array $businessEvents
    ): array {
        return $this->domainCall(function () use (
            $authority,
            $entitlementPlan,
            $inventoryCompletion,
            $businessEvents
        ): array {
            $context = $this->context($authority);
            $bundle = $context->entitlementBundle();
            $this->assertKernelPlan($entitlementPlan, $bundle, $authority);
            if (($inventoryCompletion['receiptKey'] ?? null) !== $this->inventoryReceiptKey($authority)
                || ($inventoryCompletion['checkoutRequestId'] ?? null)
                    !== $authority['checkoutRequestId']) {
                throw self::failure('checkout_inventory_authority_mismatch');
            }
            $event = is_array($businessEvents['checkoutCompleted'] ?? null)
                ? $businessEvents['checkoutCompleted']
                : [];
            $documentId = $authority['composition'] === 'mixed'
                ? (string)($event['aggregate_id'] ?? '')
                : $this->entitlementReceiptId($authority);
            $documentNo = $authority['composition'] === 'mixed'
                ? (string)($event['aggregate_name_snapshot'] ?? '')
                : $this->entitlementReceiptId($authority);
            $request = (array)$context->aggregate()['request'];
            $tenantNameSnapshot = trim((string)($request['organization_name_snapshot'] ?? ''));
            if ($tenantNameSnapshot === ''
                || $tenantNameSnapshot !== (string)(
                    $bundle['kernelPlan']['dimensionSnapshot']['organizationName'] ?? ''
                )) {
                throw self::failure('checkout_tenant_name_snapshot_mismatch');
            }
            $plan = $this->entitlementAuthority->buildPersistencePlanAfterEvents(
                (array)$bundle['kernelPlan'],
                (array)$bundle['staffSnapshots'],
                [
                    'event_id' => $event['event_id'] ?? null,
                    'event_no' => $event['event_no'] ?? null,
                    'event_key' => $event['event_key'] ?? null,
                    'event_type' => $event['event_type'] ?? null,
                    'business_date' => $event['business_date'] ?? null,
                    'occurred_at' => $event['occurred_at'] ?? null,
                    'settled_at' => $event['settled_at'] ?? null,
                    'recorded_at' => $event['recorded_at'] ?? null,
                ],
                $context,
                [
                    'documentId' => $documentId,
                    'documentNo' => $documentNo,
                    'tenantNameSnapshot' => $tenantNameSnapshot,
                ]
            );
            $result = $this->entitlements->persistInTx($plan);
            if (($result['receiptId'] ?? null) !== $this->entitlementReceiptId($authority)
                || ($result['planFingerprint'] ?? null) !== $plan->fingerprint()) {
                throw self::failure('checkout_entitlement_persistence_authority_mismatch');
            }
            return $result;
        });
    }

    public function persistSaleFactsInTx(
        array $authority,
        array $salesOrder,
        array $paymentCollection,
        array $businessEvents,
        ?array $balancePayment
    ): array {
        return $this->domainCall(function () use (
            $authority,
            $salesOrder,
            $paymentCollection,
            $businessEvents,
            $balancePayment
        ): array {
            $context = $this->context($authority);
            $salesPlan = $this->salesPlan($context, $authority);
            $paymentPlan = $this->paymentPlan($context, $authority, $salesPlan);
            $event = is_array($businessEvents['checkoutCompleted'] ?? null)
                ? $businessEvents['checkoutCompleted']
                : [];
            $plan = CashierV3SaleOnlyFactAssembler::assemble(
                $context->aggregate(),
                $salesPlan,
                $salesOrder,
                $paymentPlan,
                $paymentCollection,
                $event,
                $authority['commandIdempotencyKey'],
                $this->serverNamespaceSecret(),
                $balancePayment
            );
            return $this->facts->persistInTx(
                $plan,
                $context->operatorScope(),
                $context->dataScope()
            );
        });
    }

    public function markSucceededInTx(
        array $authority,
        string $completionReferenceId,
        array $resultSlices
    ): array {
        return $this->domainCall(function () use (
            $authority,
            $completionReferenceId,
            $resultSlices
        ): array {
            $context = $this->context($authority);
            $result = $this->requests->markSucceededInTx(
                $authority['checkoutRequestId'],
                $authority['checkoutRequestVersion'],
                $authority['commandIdempotencyKey'],
                $completionReferenceId,
                $authority['settledAt'],
                $context->operatorScope(),
                $context->dataScope()
            );
            $reference = is_array($result['completionReference'] ?? null)
                ? $result['completionReference']
                : [];
            $type = (string)($reference['type'] ?? '');
            $id = (string)($reference['id'] ?? '');
            $salesOrderId = (string)($reference['salesOrderId'] ?? '');
            $entitlementReceiptId = (string)(
                $reference['entitlementCompletionReceiptId'] ?? ''
            );
            $expectedEntitlementReceiptId = (string)(
                $resultSlices['entitlementCompletion']['receiptId'] ?? ''
            );
            $valid = $id !== ''
                && hash_equals($completionReferenceId, $id)
                && (string)($result['checkoutRequestId'] ?? '')
                    === $authority['checkoutRequestId']
                && (string)($result['composition'] ?? '') === $authority['composition'];
            if ($authority['composition'] === 'mixed') {
                $valid = $valid
                    && $type === 'mixed_checkout'
                    && hash_equals($id, $salesOrderId)
                    && $expectedEntitlementReceiptId !== ''
                    && hash_equals($expectedEntitlementReceiptId, $entitlementReceiptId);
            } elseif ($authority['composition'] === 'entitlement_only') {
                $valid = $valid
                    && $type === 'entitlement_completion_receipt'
                    && $salesOrderId === ''
                    && hash_equals($id, $entitlementReceiptId)
                    && hash_equals($id, $expectedEntitlementReceiptId);
            } else {
                $valid = false;
            }
            if (!$valid) {
                throw self::failure('checkout_success_reference_authority_mismatch');
            }
            $result['completionReferenceId'] = $id;
            return $result;
        });
    }

    public function completeWorkspaceInTx(array $authority): array
    {
        return $this->domainCall(function () use ($authority): array {
            $context = $this->context($authority);
            $draft = $this->workspace->completeCheckoutInTx(
                $authority['workspaceId'],
                $authority['stateContextId'],
                $context->operatorScope(),
                (array)$context->aggregate()['lines']
            );
            return [
                'workspaceId' => $authority['workspaceId'],
                'cleared' => true,
                'cashierDraft' => $draft,
            ];
        });
    }

    private function salesPlan(
        CashierV3CheckoutSubmissionExecutionContext $context,
        array $authority
    ): CashierV3SalesOrderPlanV1 {
        $request = (array)($context->aggregate()['request'] ?? []);
        $documentNo = (new CashierV3BusinessDocumentNumberServices())->salesOrderNoForCheckoutInTx(
            $context->operatorScope()->tenantId(),
            (string)($request['request_id'] ?? ''),
            (string)($request['business_date'] ?? ''),
            (int)$authority['settledAt']
        );
        return CashierV3SalesOrderPlanV1::fromLockedCheckoutAggregate(
            $context->aggregate(),
            $authority['commandIdempotencyKey'],
            $authority['settledAt'],
            $authority['settledAt'],
            $authority['settledAt'],
            $this->serverNamespaceSecret(),
            $documentNo
        );
    }

    private function paymentPlan(
        CashierV3CheckoutSubmissionExecutionContext $context,
        array $authority,
        CashierV3SalesOrderPlanV1 $salesPlan
    ): CashierV3PaymentCollectionPlanV1 {
        return CashierV3PaymentCollectionPlanV1::fromLockedCheckoutAggregate(
            $context->aggregate(),
            $salesPlan,
            $authority['commandIdempotencyKey'],
            $authority['settledAt'],
            $authority['settledAt'],
            $authority['settledAt'],
            $this->serverNamespaceSecret()
        );
    }

    private function completionEventInputs(
        array $authority,
        array $kernelPlan,
        array $salesOrder,
        array $paymentCollection,
        array $debt,
        array $inventoryCompletion
    ): array {
        $receiptId = $this->entitlementReceiptId($authority);
        $mixed = $authority['composition'] === 'mixed';
        $checkoutAggregateId = $mixed ? (string)($salesOrder['orderId'] ?? '') : $receiptId;
        $checkoutAggregateName = $mixed ? (string)($salesOrder['orderNo'] ?? '') : $receiptId;
        if ($mixed && ($checkoutAggregateId === '' || $checkoutAggregateName === '')) {
            throw self::failure('checkout_completed_sales_authority_missing');
        }
        $common = [
            'aggregate_version' => 1,
            'event_version' => 1,
            'source_type' => self::ACTION,
            'source_id' => $authority['checkoutRequestId'],
            'member_id' => $authority['memberId'],
            'business_date' => $kernelPlan['businessDate'],
            'occurred_at' => $kernelPlan['occurredAt'],
            'settled_at' => $kernelPlan['settledAt'],
            'recorded_at' => $kernelPlan['recordedAt'],
            'store_name_snapshot' => $kernelPlan['dimensionSnapshot']['storeName'],
        ];
        $events = [array_merge($common, [
            'event_type' => 'checkout.completed',
            'aggregate_type' => $mixed ? 'sales_order' : 'entitlement_completion',
            'aggregate_id' => $checkoutAggregateId,
            'aggregate_name_snapshot' => $checkoutAggregateName,
            'payload' => [
                'contractVersion' => self::CONTRACT_VERSION,
                'checkoutRequestId' => $authority['checkoutRequestId'],
                'checkoutRequestVersion' => $authority['checkoutRequestVersion'],
                'composition' => $authority['composition'],
                'salesOrderId' => $mixed ? $checkoutAggregateId : null,
                'paymentBatchId' => $mixed ? (string)($paymentCollection['batchId'] ?? '') : null,
                'entitlementCompletionReceiptId' => $receiptId,
                'inventoryReceiptKey' => (string)($inventoryCompletion['receiptKey'] ?? ''),
            ],
        ])];

        if ((int)($debt['amountCents'] ?? 0) > 0) {
            $events[] = array_merge($common, [
                'event_type' => 'debt.recorded',
                'aggregate_type' => 'store_debt',
                'aggregate_id' => (string)$debt['debtNo'],
                'aggregate_name_snapshot' => (string)$debt['debtNo'],
                'payload' => [
                    'contractVersion' => CashierV3CheckoutDebtAuthorityServices::CONTRACT_VERSION,
                    'checkoutRequestId' => $authority['checkoutRequestId'],
                    'salesOrderId' => (string)$salesOrder['orderId'],
                    'salesOrderNo' => (string)$salesOrder['orderNo'],
                    'debtNo' => (string)$debt['debtNo'],
                    'debtAmountCents' => (int)$debt['amountCents'],
                    'policyVersion' => (int)$debt['policyVersion'],
                    'lineAllocations' => (array)$debt['orderLineAllocations'],
                ],
            ]);
        }

        foreach ((array)$kernelPlan['linePlans'] as $line) {
            $lineId = (string)$line['lineId'];
            $source = (array)$line['source'];
            $projectName = (string)$source['projectNameSnapshot'];
            $linePayload = [
                'lineId' => $lineId,
                'quantity' => (int)$line['quantity'],
                'projectId' => (int)$source['projectId'],
                'projectNameSnapshot' => $projectName,
                'entitlementInstanceType' => (string)$source['entitlementInstanceType'],
                'entitlementInstanceId' => (int)$source['entitlementInstanceId'],
                'sourceDetailId' => (int)$source['sourceDetailId'],
            ];
            $events[] = array_merge($common, [
                'event_type' => 'entitlement.writeoff.completed',
                'aggregate_type' => 'entitlement_source_detail',
                'aggregate_id' => (string)$source['sourceDetailId'],
                'detail_id' => $lineId,
                'aggregate_name_snapshot' => (string)$source['sourceNameSnapshot'],
                'payload' => array_merge($linePayload, [
                    'sourceVersion' => (int)$source['sourceVersion'],
                    'detailVersion' => (int)$source['detailVersion'],
                    'actualEntitlementAmountCents' => (int)$line['actualEntitlementAmountCents'],
                ]),
            ]);
            $events[] = array_merge($common, [
                'event_type' => 'service.completed',
                'aggregate_type' => 'service_line',
                'aggregate_id' => $lineId,
                'aggregate_name_snapshot' => $projectName,
                'payload' => array_merge($linePayload, [
                    'serviceObject' => (string)$line['serviceSnapshot']['serviceObject'],
                    'isExperience' => (bool)$line['serviceSnapshot']['isExperience'],
                    'primaryCraftsmanId' => (int)$line['serviceSnapshot']['primaryCraftsmanId'],
                ]),
            ]);
            $events[] = array_merge($common, [
                'event_type' => 'performance.consumption.recorded',
                'aggregate_type' => 'service_line',
                'aggregate_id' => $lineId,
                'aggregate_name_snapshot' => $projectName,
                'payload' => array_merge($linePayload, [
                    'mode' => (string)$line['consumptionPerformance']['mode'],
                    'amountCents' => (int)$line['consumptionPerformance']['amountCents'],
                ]),
            ]);
            foreach ((array)$line['laborPerformance']['allocations'] as $allocation) {
                $staffId = (int)$allocation['staffId'];
                $events[] = array_merge($common, [
                    'event_type' => 'performance.labor.allocated',
                    'aggregate_type' => 'service_line_staff',
                    'aggregate_id' => self::derivedAggregateId('LS', [$lineId, $staffId]),
                    'aggregate_name_snapshot' => (string)$allocation['staffName'],
                    'payload' => array_merge($linePayload, [
                        'staffId' => $staffId,
                        'staffNameSnapshot' => (string)$allocation['staffName'],
                        'amountCents' => (int)$allocation['amountCents'],
                    ]),
                ]);
            }
            if (!empty($source['isGift'])) {
                $events[] = array_merge($common, [
                    'event_type' => 'gift.consumed',
                    'aggregate_type' => 'entitlement_source_detail',
                    'aggregate_id' => (string)$source['sourceDetailId'],
                    'detail_id' => $lineId,
                    'aggregate_name_snapshot' => (string)$source['sourceNameSnapshot'],
                    'payload' => array_merge($linePayload, [
                        'giftSourceType' => (string)$source['giftSourceType'],
                        'giftId' => (int)$source['giftId'],
                        'giftVersion' => (int)$source['giftVersion'],
                    ]),
                ]);
            }
            foreach ((array)$line['inventory']['consumables'] as $consumable) {
                $stockId = (string)$consumable['stockId'];
                foreach ((array)$consumable['batchAllocations'] as $allocation) {
                    $events[] = array_merge($common, [
                        'event_type' => 'inventory.batch.consumed',
                        'aggregate_type' => 'service_line_inventory_batch',
                        'aggregate_id' => self::derivedAggregateId(
                            'IB',
                            [$lineId, $stockId, (string)$allocation['batchId']]
                        ),
                        'aggregate_name_snapshot' => $projectName,
                        'payload' => array_merge($linePayload, [
                            'stockId' => $stockId,
                            'batchId' => (int)$allocation['batchId'],
                            'quantityUnits' => (int)$allocation['quantityUnits'],
                            'actualCostCents' => (int)$allocation['actualCostCents'],
                        ]),
                    ]);
                }
                if ((int)$consumable['shortageQuantityUnits'] > 0) {
                    $events[] = array_merge($common, [
                        'event_type' => 'inventory.shortage.recorded',
                        'aggregate_type' => 'service_line_inventory_shortage',
                        'aggregate_id' => self::derivedAggregateId('IS', [$lineId, $stockId]),
                        'aggregate_name_snapshot' => $projectName,
                        'payload' => array_merge($linePayload, [
                            'stockId' => $stockId,
                            'shortageQuantityUnits' => (int)$consumable['shortageQuantityUnits'],
                            'estimatedShortageCostCents' => (int)$consumable['estimatedShortageCostCents'],
                        ]),
                    ]);
                }
            }
        }
        $events[] = array_merge($common, [
            'event_type' => 'inventory.service_consumption.resolved',
            'aggregate_type' => 'entitlement_completion',
            'aggregate_id' => $receiptId,
            'aggregate_name_snapshot' => $receiptId,
            'payload' => [
                'checkoutRequestId' => $authority['checkoutRequestId'],
                'entitlementCompletionReceiptId' => $receiptId,
                'inventoryReceiptKey' => (string)($inventoryCompletion['receiptKey'] ?? ''),
                'lineCount' => (int)$kernelPlan['totals']['lineCount'],
                'actualCostCents' => (int)($inventoryCompletion['actualCostCents'] ?? 0),
                'estimatedShortageCostCents' => (int)($inventoryCompletion['estimatedShortageCostCents'] ?? 0),
                'costComplete' => (bool)($inventoryCompletion['costComplete'] ?? false),
            ],
        ]);
        return $events;
    }

    private function assertKernelEventCardinality(array $kernelPlan, array $events): void
    {
        $counts = [];
        foreach ($events as $event) {
            $type = (string)($event['event_type'] ?? '');
            $counts[$type] = ($counts[$type] ?? 0) + 1;
            if (($event['source_type'] ?? null) !== self::ACTION) {
                throw self::failure('checkout_event_source_type_invalid');
            }
        }
        if (($counts['checkout.completed'] ?? 0) !== 1) {
            throw self::failure('checkout_completed_event_cardinality_invalid');
        }
        foreach ((array)$kernelPlan['requiredEventContract'] as $type => $rule) {
            $expected = (int)($rule['minCount'] ?? -1);
            if ($expected < 0
                || $expected !== (int)($rule['maxCount'] ?? -2)
                || ($counts[$type] ?? 0) !== $expected) {
                throw self::failure('checkout_kernel_event_cardinality_invalid', [
                    'eventType' => $type,
                    'expected' => $expected,
                    'actual' => (int)($counts[$type] ?? 0),
                ]);
            }
        }
    }

    private function assertKernelPlan(
        array $plan,
        array $bundle,
        array $authority
    ): void {
        $kernel = (array)($bundle['kernelPlan'] ?? []);
        $fingerprint = CashierV3CheckoutSettlementCanonicalizer::fingerprint($kernel);
        if (($plan['checkoutRequestId'] ?? null) !== $authority['checkoutRequestId']
            || ($plan['receiptId'] ?? null) !== $this->entitlementReceiptId($authority)
            || ($plan['kernelPlanFingerprint'] ?? null) !== $fingerprint
            || ($plan['contractVersion'] ?? null) !== 'c2-entitlement-completion-v3') {
            throw self::failure('checkout_entitlement_kernel_plan_mismatch');
        }
    }

    private function context(array $authority): CashierV3CheckoutSubmissionExecutionContext
    {
        CashierV3TransactionGuard::assertInTransaction('checkoutSubmissionExecution.context');
        $context = $authority['executionContext'] ?? null;
        if (!($context instanceof CashierV3CheckoutSubmissionExecutionContext)) {
            throw self::failure('checkout_execution_context_invalid');
        }
        try {
            $context->assertMatchesAuthority($authority);
        } catch (\LogicException $exception) {
            throw self::failure('checkout_execution_context_mismatch');
        }
        return $context;
    }

    private function entitlementReceiptId(array $authority): string
    {
        return CashierV3EntitlementCompletionPlanV1::receiptId(
            $authority['tenantId'],
            $authority['checkoutRequestId'],
            $authority['commandIdempotencyKey']
        );
    }

    private function inventoryReceiptKey(array $authority): string
    {
        return 'ICR-' . substr(hash(
            'sha256',
            $authority['tenantId'] . "\0" . $authority['checkoutRequestId']
                . "\0" . $authority['commandIdempotencyKey']
        ), 0, 40);
    }

    private static function completedEventAuthority(
        array $result,
        array $input,
        array $authority
    ): array {
        return [
            'event_id' => (int)$result['event_id'],
            'event_no' => (string)$result['event_no'],
            'event_key' => (string)$result['event_key'],
            'event_type' => (string)$result['event_type'],
            'aggregate_type' => (string)$input['aggregate_type'],
            'aggregate_id' => (string)$input['aggregate_id'],
            'aggregate_version' => (int)$input['aggregate_version'],
            'source_type' => (string)$input['source_type'],
            'source_id' => (string)$input['source_id'],
            'command_idempotency_key' => (string)$authority['commandIdempotencyKey'],
            'business_date' => (string)$result['business_date'],
            'occurred_at' => (int)$result['occurred_at'],
            'settled_at' => (int)$result['settled_at'],
            'recorded_at' => (int)$result['recorded_at'],
            'aggregate_name_snapshot' => (string)$input['aggregate_name_snapshot'],
        ];
    }

    private static function assertEntitlementBundle(
        array $bundle,
        string $requestId,
        int $requestVersion
    ): void {
        foreach ([
            'contractVersion', 'checkoutRequestId', 'checkoutRequestVersion',
            'command', 'lockedSnapshot', 'lockedResourcePlan', 'kernelPlan',
            'staffSnapshots', 'inventoryProviderSnapshot', 'inventoryDataScope',
        ] as $key) {
            if (!array_key_exists($key, $bundle)) {
                throw self::failure('checkout_entitlement_authority_incomplete', ['field' => $key]);
            }
        }
        if ($bundle['contractVersion'] !== CashierV3EntitlementCompletionAuthorityAdapter::CONTRACT_VERSION
            || $bundle['checkoutRequestId'] !== $requestId
            || $bundle['checkoutRequestVersion'] !== $requestVersion
            || !is_object($bundle['inventoryDataScope'])) {
            throw self::failure('checkout_entitlement_authority_invalid');
        }
    }

    private static function assertPayload(array $payload): void
    {
        $expected = [
            'checkoutRequestId', 'checkoutRequestVersion',
            'preparationRequestId', 'preparationToken',
        ];
        $actual = array_keys($payload);
        sort($expected, SORT_STRING);
        sort($actual, SORT_STRING);
        if ($actual !== $expected
            || !is_string($payload['checkoutRequestId'])
            || preg_match('/^CKR-[0-9a-f]{40}$/D', $payload['checkoutRequestId']) !== 1
            || !is_int($payload['checkoutRequestVersion'])
            || $payload['checkoutRequestVersion'] <= 0
            || !is_string($payload['preparationRequestId'])
            || preg_match('/^CHECKOUT_PREPARE-[0-9a-f-]{36}$/D', $payload['preparationRequestId']) !== 1
            || !is_string($payload['preparationToken'])
            || preg_match('/^CKPT-[0-9a-f]{64}$/D', $payload['preparationToken']) !== 1) {
            throw self::failure('checkout_submit_payload_invalid');
        }
    }

    private static function assertPreparationIdentity(array $payload, array $request): void
    {
        if (!hash_equals(
            (string)($request['creation_idempotency_key'] ?? ''),
            (string)$payload['preparationRequestId']
        )) {
            throw self::failure('checkout_submit_preparation_mismatch');
        }
        $expectedToken = 'CKPT-' . hash('sha256', implode('|', [
            CashierV3CheckoutProjectionServices::CONTRACT_VERSION,
            (string)$request['request_id'],
            (string)$request['request_version'],
            (string)$request['aggregate_fingerprint'],
            (string)$request['last_operation_fingerprint'],
        ]));
        if (!hash_equals($expectedToken, (string)$payload['preparationToken'])) {
            throw CashierV3CommandException::versionConflict(
                '结账资料已经变化，请关闭后重新进入。',
                ['reason' => 'checkout_submit_preparation_token_stale']
            );
        }
    }

    private static function lineIdsByRole(array $lines): array
    {
        $result = ['sale' => [], 'entitlement' => []];
        $seen = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                throw self::failure('checkout_submit_line_shape_invalid');
            }
            $lineId = self::token($line['line_id'] ?? null, 128, 'checkout_submit_line_id_invalid');
            if (isset($seen[$lineId])) {
                throw self::failure('checkout_submit_line_id_duplicate');
            }
            $seen[$lineId] = true;
            $role = (string)($line['line_role'] ?? '');
            if ($role === 'sale') {
                $result['sale'][] = $lineId;
            } elseif ($role === 'entitlement_service') {
                $result['entitlement'][] = $lineId;
            } else {
                throw self::failure('checkout_submit_line_role_invalid');
            }
        }
        return $result;
    }

    private static function assertComposition(string $composition, array $lineIds): void
    {
        $sale = $lineIds['sale'] !== [];
        $entitlement = $lineIds['entitlement'] !== [];
        $valid = $composition === 'sale_only' && $sale && !$entitlement
            || $composition === 'entitlement_only' && !$sale && $entitlement
            || $composition === 'mixed' && $sale && $entitlement;
        if (!$valid) {
            throw self::failure('checkout_submit_composition_line_mismatch');
        }
    }

    private static function assertDataScope(
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): void {
        $storeAllowed = $dataScope->authorizationMode() === CashierV3DataScopeContext::MODE_SELF_PARTICIPANT
            || $dataScope->allowsStore($operatorScope->storeId());
        if ($operatorScope->tenantId() === ''
            || !hash_equals($operatorScope->tenantId(), $dataScope->tenantId())
            || !hash_equals($operatorScope->organizationId(), $dataScope->organizationId())
            || $operatorScope->storeId() !== $dataScope->forcedStoreId()
            || $operatorScope->operatorId() !== $dataScope->operatorId()
            || !$storeAllowed) {
            throw self::failure('checkout_submit_data_scope_denied');
        }
    }

    private function serverNamespaceSecret(): string
    {
        $secret = trim($this->serverNamespaceSecret);
        if ($secret === '' && function_exists('config')) {
            $secret = trim((string)config('cashier_v3.checkout_namespace_secret'));
        }
        if (strlen($secret) < 32) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
                '结账签名服务尚未配置，请联系管理员。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'checkout_namespace_secret_missing']
            );
        }
        return $secret;
    }

    private static function stateContextId($value): string
    {
        return self::token($value, 64, 'checkout_submit_state_context_invalid');
    }

    private static function commandIdempotencyKey($value): string
    {
        $key = is_string($value) ? trim($value) : '';
        if (preg_match('/^CHECKOUT-[0-9a-f-]{36}$/D', $key) !== 1) {
            throw self::failure('checkout_submit_idempotency_key_invalid');
        }
        return $key;
    }

    private static function derivedAggregateId(string $prefix, array $components): string
    {
        return $prefix . '-' . substr(hash('sha256', implode("\0", array_map('strval', $components))), 0, 40);
    }

    private static function token($value, int $maxLength, string $reason): string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '' || strlen($value) > $maxLength
            || preg_match('/^[A-Za-z0-9_.:-]+$/D', $value) !== 1) {
            throw self::failure($reason);
        }
        return $value;
    }

    private static function sha256($value, string $reason): string
    {
        if (!is_string($value) || preg_match('/^[0-9a-f]{64}$/D', $value) !== 1) {
            throw self::failure($reason);
        }
        return $value;
    }

    private function domainCall(callable $operation): array
    {
        try {
            return $operation();
        } catch (CashierV3CommandException $exception) {
            throw $exception;
        } catch (CashierV3CheckoutSettlementContractException
            | CashierV3SalesOrderAuthorityException
            | CashierV3PaymentCollectionAuthorityException
            | CashierV3CheckoutFactContractException
            | CashierV3EntitlementCompletionAuthorityException
            | CashierV3EntitlementCompletionContractException
            | CashierV3EntitlementCompletionPersistenceException
            | CashierV3EntitlementProviderContractException
            | CashierV3ServiceOrderAuthorityException
            | InventoryCompletionContractException $exception) {
            $reason = method_exists($exception, 'reason')
                ? (string)$exception->reason()
                : 'checkout_domain_failure';
            $detail = method_exists($exception, 'detail')
                ? (array)$exception->detail()
                : [];
            $detail['reason'] = $reason;
            if (preg_match('/(?:conflict|changed|stale|version)/', $reason) === 1) {
                throw CashierV3CommandException::versionConflict(
                    '结账资料刚刚发生变化，本次操作已回滚，请刷新后重试。',
                    $detail
                );
            }
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '正式结账未能完整落账，本次操作已全部回滚，请重试。',
                CashierV3ResultCode::STATUS_FAILED,
                $detail
            );
        }
    }

    private static function failure(
        string $reason,
        array $detail = []
    ): CashierV3CommandException {
        $detail['reason'] = $reason;
        return new CashierV3CommandException(
            CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
            '结账资料校验失败，本次操作已回滚，请刷新后重试。',
            CashierV3ResultCode::STATUS_FAILED,
            $detail
        );
    }
}
