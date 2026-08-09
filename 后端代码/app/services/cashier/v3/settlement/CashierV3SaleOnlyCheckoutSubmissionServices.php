<?php

namespace app\services\cashier\v3\settlement;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3BusinessDocumentNumberServices;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\cashier\CashierV3CashierWorkspaceServices;
use app\services\cashier\v3\card\CashierV3CardPurchaseIssuanceServices;
use app\services\cashier\v3\card\CashierV3CardOperationCheckoutSettlementServices;
use app\services\cashier\v3\checkout\provider\CashierV3MemberBalanceContractException;
use app\services\cashier\v3\checkout\provider\CashierV3MemberBalanceWriterAdapter;
use app\services\cashier\v3\event\CashierV3BusinessEventExecution;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\fact\CashierV3CheckoutFactContractException;
use app\services\cashier\v3\fact\CashierV3SaleOnlyFactAssembler;
use app\services\cashier\v3\fact\ThinkPhpCashierV3CheckoutFactRepository;
use app\services\cashier\v3\hang\CashierV3HangCheckoutBindingServices;
use app\services\cashier\v3\order\settlement\CashierV3SalesOrderAuthorityException;
use app\services\cashier\v3\order\settlement\CashierV3SalesOrderPlanV1;
use app\services\cashier\v3\order\settlement\ThinkPhpCashierV3SalesOrderAuthorityWriter;
use app\services\cashier\v3\settlement\payment\CashierV3PaymentCollectionAuthorityException;
use app\services\cashier\v3\settlement\payment\CashierV3PaymentCollectionPlanV1;
use app\services\cashier\v3\settlement\payment\ThinkPhpCashierV3PaymentCollectionAuthorityWriter;
use think\facade\Db;
use think\facade\Log;

/** Atomic final submission for product, project and card sales. */
final class CashierV3SaleOnlyCheckoutSubmissionServices
{
    public const CONTRACT_VERSION = 'cashier-v3-sale-only-checkout-submission-v1';

    private const ACTION = 'submit-checkout';

    private const REQUIRED_TABLES = [
        'eb_cashier_v3_checkout_request',
        'eb_cashier_v3_checkout_line_draft',
        'eb_cashier_v3_checkout_payment_draft',
        'eb_cashier_v3_checkout_source_reference',
        'eb_cashier_v3_sales_order',
        'eb_cashier_v3_sales_order_line',
        'eb_cashier_v3_payment_collection_batch',
        'eb_cashier_v3_payment_collection',
        'eb_cashier_v3_business_event',
        'eb_cashier_v3_sale_fact',
        'eb_cashier_v3_payment_fact',
        'eb_cashier_v3_balance_fact',
        'eb_cashier_v3_performance_fact',
        'eb_cashier_v3_entitlement_service_fact',
        'eb_store_debt',
        'eb_store_debt_item',
        'eb_cashier_v3_debt_authority',
        'eb_cashier_v3_debt_item_personnel_authority',
        'eb_cashier_v3_workspace_draft',
        'eb_cashier_v3_workspace_line',
        'eb_cashier_v3_sale_inventory_receipt',
        'eb_inventory_location',
        'eb_inventory_stock',
        'eb_inventory_batch',
        'eb_inventory_batch_movement_fact',
        'eb_store_product',
        'eb_user',
        'eb_user_money',
    ];

    private const REQUIRED_COLUMNS = [
        'eb_cashier_v3_checkout_line_draft.catalog_sku_id',
        'eb_cashier_v3_sales_order_line.catalog_sku_id',
    ];

    /** @var CashierV3CashierWorkspaceServices */
    private $workspace;

    /** @var CashierV3CheckoutRequestRepository */
    private $requests;

    /** @var ThinkPhpCashierV3SalesOrderAuthorityWriter */
    private $salesOrders;

    /** @var ThinkPhpCashierV3PaymentCollectionAuthorityWriter */
    private $collections;

    /** @var ThinkPhpCashierV3CheckoutFactRepository */
    private $facts;

    /** @var CashierV3SaleInventorySettlementServices */
    private $saleInventory;

    /** @var CashierV3HangCheckoutBindingServices */
    private $hangBindings;

    /** @var CashierV3CardPurchaseIssuanceServices */
    private $cardPurchases;

    /** @var CashierV3MemberBalanceWriterAdapter */
    private $balances;

    /** @var CashierV3CheckoutDebtAuthorityServices */
    private $debts;

    /** @var CashierV3CardOperationCheckoutSettlementServices */
    private $cardOperationSettlements;

    /** @var CashierV3CheckoutBusinessSourceSelectionServices */
    private $businessSources;

    /** @var CashierV3SaleProjectServiceCompletionServices */
    private $saleProjectServices;

    /** @var string */
    private $serverNamespaceSecret;

    public function __construct(
        CashierV3CashierWorkspaceServices $workspace,
        CashierV3CheckoutRequestRepository $requests = null,
        ThinkPhpCashierV3SalesOrderAuthorityWriter $salesOrders = null,
        ThinkPhpCashierV3PaymentCollectionAuthorityWriter $collections = null,
        ThinkPhpCashierV3CheckoutFactRepository $facts = null,
        string $serverNamespaceSecret = '',
        CashierV3SaleInventorySettlementServices $saleInventory = null,
        CashierV3HangCheckoutBindingServices $hangBindings = null,
        CashierV3CardPurchaseIssuanceServices $cardPurchases = null,
        CashierV3MemberBalanceWriterAdapter $balances = null,
        CashierV3CheckoutDebtAuthorityServices $debts = null,
        ?CashierV3CardOperationCheckoutSettlementServices $cardOperationSettlements = null,
        ?CashierV3CheckoutBusinessSourceSelectionServices $businessSources = null,
        ?CashierV3SaleProjectServiceCompletionServices $saleProjectServices = null
    ) {
        $this->workspace = $workspace;
        $this->requests = $requests ?: new ThinkPhpCashierV3CheckoutRequestRepository();
        $this->salesOrders = $salesOrders ?: new ThinkPhpCashierV3SalesOrderAuthorityWriter();
        $this->collections = $collections ?: new ThinkPhpCashierV3PaymentCollectionAuthorityWriter();
        $this->facts = $facts ?: new ThinkPhpCashierV3CheckoutFactRepository();
        $this->serverNamespaceSecret = $serverNamespaceSecret;
        $this->saleInventory = $saleInventory ?: new CashierV3SaleInventorySettlementServices();
        $this->hangBindings = $hangBindings ?: new CashierV3HangCheckoutBindingServices();
        $this->cardPurchases = $cardPurchases ?: new CashierV3CardPurchaseIssuanceServices();
        $this->balances = $balances ?: new CashierV3MemberBalanceWriterAdapter();
        $this->debts = $debts ?: new CashierV3CheckoutDebtAuthorityServices();
        $this->cardOperationSettlements = $cardOperationSettlements
            ?: new CashierV3CardOperationCheckoutSettlementServices();
        $this->businessSources = $businessSources
            ?: new CashierV3CheckoutBusinessSourceSelectionServices();
        $this->saleProjectServices = $saleProjectServices
            ?: new CashierV3SaleProjectServiceCompletionServices($this->salesOrders);
    }

    public function submitInTx(array $scope): array
    {
        CashierV3TransactionGuard::assertInTransaction('saleOnlyCheckoutSubmission');
        try {
            $this->assertTablesReady();
            if (($scope['action'] ?? null) !== self::ACTION) {
                throw self::failure('checkout_submit_action_invalid');
            }
            $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
            self::assertPayload($payload);
            $operatorScope = $scope['operator_scope'] ?? null;
            $dataScope = $scope['data_scope'] ?? null;
            if (!($operatorScope instanceof CashierV3OperatorScope)
                || !($dataScope instanceof CashierV3DataScopeContext)) {
                throw self::failure('checkout_submit_scope_missing');
            }
            self::assertDataScope($operatorScope, $dataScope);

            $requestId = (string)$payload['checkoutRequestId'];
            $requestVersion = (int)$payload['checkoutRequestVersion'];
            $stateContextId = self::stateContextId($scope['state_context_id'] ?? null);
            $workspaceId = sprintf(
                'ws:%d:%d:%s',
                $operatorScope->storeId(),
                $operatorScope->operatorId(),
                $stateContextId
            );
            self::assertLockedPublicContexts(
                (array)($scope['contexts'] ?? []),
                (array)($scope['locked_versions'] ?? []),
                $workspaceId,
                $requestId,
                $requestVersion
            );
            self::assertResourcePlan(
                $scope['checkout_resource_plan'] ?? null,
                $requestId,
                $requestVersion,
                $dataScope
            );

            $aggregate = $this->requests->lockAggregateForSubmitInTx(
                $requestId,
                $requestVersion,
                $workspaceId,
                $stateContextId,
                $operatorScope,
                $dataScope
            );
            self::assertPreparationIdentity($payload, $aggregate['request']);
            $this->assertSupportedSaleLines($aggregate['lines'], $dataScope);
            $salespeopleByCheckoutLine = $this->workspace->lockedSalespeopleByCheckoutLineInTx(
                $workspaceId,
                (array)$aggregate['lines'],
                $operatorScope
            );

            $commandKey = self::commandIdempotencyKey($scope['idempotency_key'] ?? null);
            $now = time();
            $secret = $this->serverNamespaceSecret();
            $salesOrderNo = (new CashierV3BusinessDocumentNumberServices())->salesOrderNoForCheckoutInTx(
                $dataScope->tenantId(),
                (string)$aggregate['request']['request_id'],
                (string)$aggregate['request']['business_date'],
                $now
            );
            $businessSource = $this->businessSources->lockResolvedForSettlementInTx(
                CashierV3CheckoutBusinessSourceSelectionServices::KIND_SALE,
                (string)$aggregate['request']['request_id'],
                (string)$aggregate['request']['tenant_id'],
                (int)$aggregate['request']['store_id']
            );
            $salesPlan = CashierV3SalesOrderPlanV1::fromLockedCheckoutAggregate(
                $aggregate,
                $commandKey,
                $now,
                $now,
                $now,
                $secret,
                $salesOrderNo,
                $businessSource['primarySourceId'] > 0 ? $businessSource : []
            );
            $inventoryPlan = $this->saleInventory->planInTx(
                (array)$aggregate['request'],
                $salesPlan->header(),
                $salesPlan->lines(),
                $operatorScope,
                $dataScope
            );
            $salesResult = $this->salesOrders->persistInTx(
                $salesPlan,
                $operatorScope,
                $dataScope
            );
            $paymentPlan = CashierV3PaymentCollectionPlanV1::fromLockedCheckoutAggregate(
                $aggregate,
                $salesPlan,
                $commandKey,
                $now,
                $now,
                $now,
                $secret
            );
            $paymentResult = $this->collections->persistInTx(
                $paymentPlan,
                $operatorScope,
                $dataScope
            );
            $inventoryResult = $this->saleInventory->persistInTx($inventoryPlan);
            // Card sale has no inventory movement.  Its current holder and
            // benefit pools are signed only after sales/payment persistence,
            // but before the outer transaction exposes any terminal fact.
            $cardPurchaseResult = $this->cardPurchases->issueInTx(
                $aggregate,
                $salesPlan,
                $salesResult,
                $commandKey,
                $now,
                $operatorScope,
                $dataScope
            );
            $debtResult = $this->debts->persistInTx(
                (array)$aggregate['request'],
                $salesPlan,
                $salesResult,
                $salespeopleByCheckoutLine,
                $commandKey,
                $now,
                $operatorScope,
                $dataScope
            );
            $balanceMutation = null;
            $balanceAmount = (int)($aggregate['request']['balance_deduction_amount_cents'] ?? 0);
            if ($balanceAmount > 0) {
                $sourceOrderId = $this->salesOrders->internalRecordIdInTx(
                    (string)$salesPlan->header()['tenant_id'],
                    (string)$salesResult['orderId']
                );
                $balanceMutation = $this->balances->deductCheckoutInTx([
                    'memberId' => (int)$aggregate['request']['member_id'],
                    'expectedVersion' => (int)$aggregate['request']['balance_account_version'],
                    'amountCents' => $balanceAmount,
                    'sourceOrderId' => $sourceOrderId,
                    'commandIdempotencyKey' => $commandKey,
                    'paymentMode' => (int)$paymentResult['collectedAmountCents'] > 0
                        ? CashierV3MemberBalanceWriterAdapter::PAYMENT_MODE_COMBINED
                        : CashierV3MemberBalanceWriterAdapter::PAYMENT_MODE_BALANCE_ONLY,
                ], $operatorScope, $dataScope);
            }

            $eventRecorder = $scope['event_recorder'] ?? null;
            $eventExecution = $scope['event_execution'] ?? null;
            $eventContract = is_array($scope['event_contract'] ?? null)
                ? $scope['event_contract']
                : [];
            if (!($eventRecorder instanceof CashierV3BusinessEventRecorder)
                || !($eventExecution instanceof CashierV3BusinessEventExecution)) {
                throw self::failure('checkout_submit_event_services_missing');
            }
            $cardOperationSettlement = $this->cardOperationSettlements->settleFromCheckoutInTx(
                $aggregate,
                $salesPlan,
                $salesResult,
                $cardPurchaseResult,
                $operatorScope,
                $dataScope,
                $eventRecorder,
                $eventExecution,
                $eventContract,
                $now
            );
            $order = $salesPlan->header();
            $batch = $paymentPlan->batch();
            $inventoryEventCommon = [
                'source_type' => self::ACTION,
                'source_id' => $requestId,
                'member_id' => (int)$order['member_id'],
                'business_date' => (string)$order['business_date'],
                'occurred_at' => $now,
                'settled_at' => $now,
                'recorded_at' => $now,
                'store_name_snapshot' => (string)$order['store_name_snapshot'],
            ];
            foreach ($this->saleInventory->eventInputs($inventoryResult, $inventoryEventCommon) as $input) {
                $eventRecorder->recordInTx($eventExecution, $eventContract, $input);
            }
            $debtEvent = null;
            if ((int)$debtResult['amountCents'] > 0) {
                $debtEvent = $eventRecorder->recordInTx($eventExecution, $eventContract, [
                    'event_type' => 'debt.recorded',
                    'aggregate_type' => 'store_debt',
                    'aggregate_id' => (string)$debtResult['debtNo'],
                    'aggregate_version' => 1,
                    'event_version' => 1,
                    'source_type' => self::ACTION,
                    'source_id' => $requestId,
                    'member_id' => (int)$order['member_id'],
                    'business_date' => (string)$order['business_date'],
                    'occurred_at' => $now,
                    'settled_at' => $now,
                    'recorded_at' => $now,
                    'aggregate_name_snapshot' => (string)$debtResult['debtNo'],
                    'store_name_snapshot' => (string)$order['store_name_snapshot'],
                    'payload' => [
                        'contractVersion' => CashierV3CheckoutDebtAuthorityServices::CONTRACT_VERSION,
                        'checkoutRequestId' => $requestId,
                        'salesOrderId' => (string)$order['order_id'],
                        'salesOrderNo' => (string)$order['order_no'],
                        'debtNo' => (string)$debtResult['debtNo'],
                        'debtAmountCents' => (int)$debtResult['amountCents'],
                        'policyVersion' => (int)$debtResult['policyVersion'],
                        'lineAllocations' => (array)$debtResult['orderLineAllocations'],
                    ],
                ]);
            }
            $eventResult = $eventRecorder->recordInTx($eventExecution, $eventContract, [
                'event_type' => CashierV3SaleOnlyFactAssembler::EVENT_TYPE,
                'aggregate_type' => 'sales_order',
                'aggregate_id' => (string)$order['order_id'],
                'aggregate_version' => 1,
                'event_version' => 1,
                'source_type' => self::ACTION,
                'source_id' => $requestId,
                'member_id' => (int)$order['member_id'],
                'business_date' => (string)$order['business_date'],
                'occurred_at' => $now,
                'settled_at' => $now,
                'recorded_at' => $now,
                'aggregate_name_snapshot' => (string)$order['order_no'],
                'store_name_snapshot' => (string)$order['store_name_snapshot'],
                'payload' => [
                    'contractVersion' => self::CONTRACT_VERSION,
                    'checkoutRequestId' => $requestId,
                    'checkoutRequestVersion' => $requestVersion,
                    'salesOrderId' => (string)$order['order_id'],
                    'salesOrderNo' => (string)$order['order_no'],
                    'paymentBatchId' => (string)$batch['batch_id'],
                    'salesAmountCents' => (int)$order['sale_amount_cents'],
                    'cashPerformanceAmountCents' => (int)$batch['cash_performance_amount_cents'],
                    'balanceDeductionAmountCents' => $balanceAmount,
                    'debtAmountCents' => (int)$debtResult['amountCents'],
                    'inventorySale' => [
                        'receiptId' => (string)$inventoryResult['receiptId'],
                        'inventoryLineCount' => (int)$inventoryResult['inventoryLineCount'],
                        'batchAllocationCount' => (int)$inventoryResult['batchAllocationCount'],
                        'actualCostCents' => (int)$inventoryResult['actualCostCents'],
                    ],
                    'cardPurchase' => [
                        'issuedCardCount' => (int)$cardPurchaseResult['issuedCardCount'],
                        'receiptIds' => array_values(array_map(static function (array $receipt): string {
                            return (string)($receipt['receiptId'] ?? '');
                        }, (array)$cardPurchaseResult['receipts'])),
                    ],
                ],
            ]);
            $eventAuthority = array_merge($eventResult, [
                'aggregate_type' => 'sales_order',
                'aggregate_id' => (string)$order['order_id'],
                'aggregate_name_snapshot' => (string)$order['order_no'],
                'aggregate_version' => 1,
                'source_type' => self::ACTION,
                'source_id' => $requestId,
                'command_idempotency_key' => $commandKey,
            ]);
            // Product decision: a cash-purchased project (member or guest)
            // completes service at successful settlement.  The service fact
            // references the transaction's committed checkout event; a later
            // failure rolls both facts back together.
            $saleProjectServiceResult = $this->saleProjectServices->completeInTx(
                $salesPlan,
                $salesResult,
                $now,
                (string)$eventAuthority['event_no']
            );
            foreach ((array)$saleProjectServiceResult['services'] as $service) {
                $eventRecorder->recordInTx($eventExecution, $eventContract, [
                    'event_type' => 'service.completed',
                    'aggregate_type' => 'service_line',
                    'aggregate_id' => (string)$service['sourceLineId'],
                    'aggregate_version' => 1,
                    'event_version' => 1,
                    'source_type' => self::ACTION,
                    'source_id' => $requestId,
                    'member_id' => (int)$order['member_id'],
                    'business_date' => (string)$order['business_date'],
                    'occurred_at' => $now,
                    'settled_at' => $now,
                    'recorded_at' => $now,
                    'aggregate_name_snapshot' => (string)$service['projectNameSnapshot'],
                    'store_name_snapshot' => (string)$order['store_name_snapshot'],
                    'payload' => [
                        'contractVersion' => 'cashier-v3-sale-project-service-v1',
                        'checkoutRequestId' => $requestId,
                        'salesOrderId' => (string)$order['order_id'],
                        'salesOrderNo' => (string)$order['order_no'],
                        'serviceFactId' => (string)$service['serviceFactId'],
                        'serviceRecordNo' => (string)$service['serviceRecordNo'],
                        'sourceLineId' => (string)$service['sourceLineId'],
                        'projectId' => (int)$service['projectId'],
                        'quantity' => (int)$service['quantity'],
                        'serviceObject' => (string)$service['serviceObject'],
                        'isExperience' => (bool)$service['isExperience'],
                        'source' => 'cash_project_sale',
                    ],
                ]);
            }

            $factPlan = CashierV3SaleOnlyFactAssembler::assemble(
                $aggregate,
                $salesPlan,
                $salesResult,
                $paymentPlan,
                $paymentResult,
                $eventAuthority,
                $commandKey,
                $secret,
                $balanceMutation,
                $salespeopleByCheckoutLine
            );
            $factResult = $this->facts->persistInTx($factPlan, $operatorScope, $dataScope);
            $requestResult = $this->requests->markSucceededInTx(
                $requestId,
                $requestVersion,
                $commandKey,
                (string)$order['order_id'],
                $now,
                $operatorScope,
                $dataScope
            );
            $hangResult = $this->hangBindings->completeFromCheckoutInTx(
                $aggregate,
                $requestId,
                $salesResult,
                $operatorScope,
                $dataScope,
                $eventRecorder,
                $eventExecution,
                $eventContract
            );
            $cashierDraft = $this->workspace->completeSaleOnlyCheckoutInTx(
                $workspaceId,
                $stateContextId,
                $operatorScope,
                $aggregate['lines']
            );

            return [
                'contractVersion' => self::CONTRACT_VERSION,
                'checkoutRequestId' => $requestId,
                'checkoutRequestVersion' => (int)$requestResult['checkoutRequestVersion'],
                'requestStatus' => (string)$requestResult['requestStatus'],
                'salesOrder' => [
                    'orderId' => (string)$salesResult['orderId'],
                    'orderNo' => (string)$salesResult['orderNo'],
                    'orderStatus' => (string)$salesResult['orderStatus'],
                    'orderVersion' => (int)$salesResult['orderVersion'],
                ],
                'paymentCollection' => [
                    'batchId' => (string)$paymentResult['batchId'],
                    'collectionCount' => (int)$paymentResult['collectionCount'],
                    'collectedAmountCents' => (int)$paymentResult['collectedAmountCents'],
                    'cashPerformanceAmountCents' => (int)$paymentResult['cashPerformanceAmountCents'],
                ],
                'balancePayment' => $balanceMutation,
                'debt' => [
                    'debtId' => (int)$debtResult['debtId'],
                    'debtNo' => (string)$debtResult['debtNo'],
                    'amountCents' => (int)$debtResult['amountCents'],
                    'eventNo' => (string)($debtEvent['event_no'] ?? ''),
                ],
                'businessEventNo' => (string)$eventAuthority['event_no'],
                'factFingerprint' => (string)$factResult['planFingerprint'],
                'settledAt' => $now,
                'cardPurchase' => [
                    'issuedCardCount' => (int)$cardPurchaseResult['issuedCardCount'],
                    'receipts' => (array)$cardPurchaseResult['receipts'],
                ],
                'cardOperation' => $cardOperationSettlement,
                'saleProjectService' => $saleProjectServiceResult,
                'hangOrder' => $hangResult,
                'cashierDraft' => $cashierDraft,
                'replayed' => !empty($salesResult['replayed'])
                    || !empty($paymentResult['replayed'])
                    || !empty($inventoryResult['replayed'])
                    || !empty($saleProjectServiceResult['replayed'])
                    || !empty($cardPurchaseResult['replayed'])
                    || !empty($debtResult['replayed'])
                    || array_sum((array)$factResult['replayed']) > 0,
            ];
        } catch (CashierV3CommandException $exception) {
            throw $exception;
        } catch (CashierV3CheckoutSettlementContractException $exception) {
            throw self::translatedFailure($exception->reason(), $exception->detail());
        } catch (CashierV3SalesOrderAuthorityException $exception) {
            throw self::translatedFailure($exception->reason(), $exception->detail());
        } catch (CashierV3PaymentCollectionAuthorityException $exception) {
            throw self::translatedFailure($exception->reason(), $exception->detail());
        } catch (CashierV3MemberBalanceContractException $exception) {
            throw self::translatedFailure($exception->reason(), $exception->detail());
        } catch (CashierV3CheckoutFactContractException $exception) {
            throw self::translatedFailure($exception->reason(), $exception->detail());
        }
    }

    private function assertSupportedSaleLines(array $lines, CashierV3DataScopeContext $dataScope): void
    {
        $productIds = [];
        foreach ($lines as $line) {
            if (!is_array($line)
                || (string)($line['line_role'] ?? '') !== 'sale'
                || (int)($line['source_id'] ?? 0) <= 0) {
                throw self::unsupportedFulfillment();
            }
            $sourceType = (string)($line['source_type'] ?? '');
            if (!in_array($sourceType, ['product', 'card', 'project'], true)) {
                throw self::unsupportedFulfillment();
            }
            $productIds[(int)$line['source_id']] = $sourceType;
        }
        foreach ($productIds as $productId => $sourceType) {
            $product = Db::name('store_product')
                ->where('id', $productId)
                ->where('relation_id', $dataScope->forcedStoreId())
                ->where('type', 1)
                ->where('is_del', 0)
                ->field('id,pid,product_type,is_inventory')
                ->find();
            if (!$product || !self::isSupportedSaleProduct($sourceType, $product)) {
                throw self::unsupportedFulfillment();
            }
        }
    }

    /**
     * Final checkout can sell ordinary products and card purchase definitions.
     * Legacy custom-card shells are project copies, identified only by id/pid 8154.
     */
    private static function isSupportedSaleProduct(string $sourceType, array $product): bool
    {
        if ($sourceType === 'product') {
            return (int)($product['product_type'] ?? -1) !== 0 ? false : true;
        }
        $productType = (int)($product['product_type'] ?? -1);
        if ($sourceType === 'project') {
            return $productType === 6;
        }
        if ($sourceType !== 'card') {
            return false;
        }
        if ($productType === 5) {
            return true;
        }
        return $productType === 6
            && ((int)($product['id'] ?? 0) === 8154 || (int)($product['pid'] ?? 0) === 8154);
    }

    private function assertTablesReady(): void
    {
        $placeholders = implode(',', array_fill(0, count(self::REQUIRED_TABLES), '?'));
        $rows = Db::query(
            'SELECT TABLE_NAME AS t FROM information_schema.TABLES'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $placeholders . ')',
            self::REQUIRED_TABLES
        );
        $existing = [];
        foreach ((array)$rows as $row) {
            $existing[] = (string)($row['t'] ?? $row['TABLE_NAME'] ?? '');
        }
        $missing = array_values(array_diff(self::REQUIRED_TABLES, $existing));
        if ($missing) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
                '正式结账底座尚未完成本地升级，请联系管理员。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'checkout_submission_tables_missing', 'missing_tables' => $missing]
            );
        }
        $columnRows = Db::query(
            'SELECT CONCAT(TABLE_NAME, \'\.\', COLUMN_NAME) AS c'
            . ' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()'
            . ' AND ((TABLE_NAME = \'eb_cashier_v3_checkout_line_draft\''
            . ' AND COLUMN_NAME = \'catalog_sku_id\''
            . ' AND COLUMN_TYPE = \'bigint(20) unsigned\''
            . ' AND IS_NULLABLE = \'NO\' AND COLUMN_DEFAULT = \'0\')'
            . ' OR (TABLE_NAME = \'eb_cashier_v3_sales_order_line\''
            . ' AND COLUMN_NAME = \'catalog_sku_id\''
            . ' AND COLUMN_TYPE = \'bigint(20) unsigned\''
            . ' AND IS_NULLABLE = \'NO\' AND COLUMN_DEFAULT = \'0\'))'
        );
        $existingColumns = [];
        foreach ((array)$columnRows as $row) {
            $existingColumns[] = (string)($row['c'] ?? $row['C'] ?? '');
        }
        $missingColumns = array_values(array_diff(self::REQUIRED_COLUMNS, $existingColumns));
        if ($missingColumns) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
                '正式结账 SKU 冻结字段尚未完成本地升级，请联系管理员。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'checkout_submission_sku_columns_missing', 'missing_columns' => $missingColumns]
            );
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
            || preg_match(
                '/^CHECKOUT_PREPARE-[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',
                $payload['preparationRequestId']
            ) !== 1
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

    private static function assertDataScope(
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): void {
        if ($operatorScope->tenantId() === ''
            || !hash_equals($operatorScope->tenantId(), $dataScope->tenantId())
            || !hash_equals($operatorScope->organizationId(), $dataScope->organizationId())
            || $operatorScope->storeId() !== $dataScope->forcedStoreId()
            || $operatorScope->operatorId() !== $dataScope->operatorId()
            || !$dataScope->allowsStore($operatorScope->storeId())) {
            throw self::failure('checkout_submit_data_scope_denied');
        }
    }

    private static function assertLockedPublicContexts(
        array $contexts,
        array $lockedVersions,
        string $workspaceId,
        string $requestId,
        int $requestVersion
    ): void {
        $found = [];
        foreach ($contexts as $context) {
            if (!is_array($context)) {
                continue;
            }
            $kind = (string)($context['kind'] ?? '');
            $id = (string)($context['id'] ?? '');
            if (($kind === 'cashier_workspace' && $id === $workspaceId)
                || ($kind === 'checkout_request' && $id === $requestId)) {
                $version = (int)($context['expected_version'] ?? 0);
                $scope = $context['scope'] ?? null;
                if ($version <= 0 || !is_object($scope)
                    || !method_exists($scope, 'type') || !method_exists($scope, 'id')) {
                    throw self::failure('checkout_submit_public_context_invalid');
                }
                $key = $scope->signature() . ':' . $kind . ':' . $id;
                if ((int)($lockedVersions[$key] ?? 0) !== $version) {
                    throw self::failure('checkout_submit_public_context_not_locked');
                }
                $found[$kind] = $version;
            }
        }
        if (!isset($found['cashier_workspace'], $found['checkout_request'])
            || $found['checkout_request'] !== $requestVersion) {
            throw self::failure('checkout_submit_public_context_missing');
        }
    }

    private static function assertResourcePlan(
        $plan,
        string $requestId,
        int $requestVersion,
        CashierV3DataScopeContext $dataScope
    ): void {
        if (!is_array($plan)
            || (string)($plan['requestId'] ?? '') !== $requestId
            || (int)($plan['boundRequestVersion'] ?? 0) !== $requestVersion
            || (string)($plan['tenantId'] ?? '') !== $dataScope->tenantId()
            || (int)($plan['storeId'] ?? 0) !== $dataScope->forcedStoreId()
            || !is_array($plan['resources'] ?? null)
            || !$plan['resources']
            || preg_match(
                '/^[0-9a-f]{64}$/D',
                (string)($plan['resourcePlanFingerprint'] ?? '')
            ) !== 1) {
            throw self::failure('checkout_submit_resource_plan_invalid');
        }
    }

    private function serverNamespaceSecret(): string
    {
        $secret = $this->serverNamespaceSecret;
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
        $id = is_string($value) ? trim($value) : '';
        if ($id === '' || strlen($id) > 64 || preg_match('/^[A-Za-z0-9_.:-]+$/D', $id) !== 1) {
            throw self::failure('checkout_submit_state_context_invalid');
        }
        return $id;
    }

    private static function commandIdempotencyKey($value): string
    {
        $key = is_string($value) ? trim($value) : '';
        if (preg_match(
            '/^CHECKOUT-[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}'
            . '-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',
            $key
        ) !== 1) {
            throw self::failure('checkout_submit_idempotency_key_invalid');
        }
        return $key;
    }

    private static function unsupportedFulfillment(): CashierV3CommandException
    {
        return new CashierV3CommandException(
            CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
            '当前品项尚未接通正式结账，请联系管理员。',
            CashierV3ResultCode::STATUS_FAILED,
            ['reason' => 'checkout_sale_fulfillment_not_ready']
        );
    }

    private static function failure(string $reason): CashierV3CommandException
    {
        return new CashierV3CommandException(
            CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
            '结账资料校验失败，本次操作已回滚，请刷新后重试。',
            CashierV3ResultCode::STATUS_FAILED,
            ['reason' => $reason]
        );
    }

    private static function translatedFailure(string $reason, array $detail): CashierV3CommandException
    {
        $detail['reason'] = $reason;
        Log::error(sprintf(
            '[cashier_v3_sale_checkout_rollback] reason=%s detail=%s',
            $reason,
            json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ));
        error_log(sprintf(
            '[cashier_v3_sale_checkout_rollback] reason=%s detail=%s',
            $reason,
            json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ));
        $message = in_array($reason, [
            'sale_inventory_default_location_not_ready',
            'sale_inventory_stock_not_ready',
            'sale_inventory_stock_changed',
            'sale_inventory_stock_batch_balance_mismatch',
            'sale_inventory_batch_changed',
            'sale_inventory_shortage_denied',
        ], true)
            ? '当前门店库存已变化或不足，本次结账未提交。请完成入库后重新结账。'
            : '正式结账未能完整落账，本次操作已全部回滚，请重试。';
        return new CashierV3CommandException(
            CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
            $message,
            CashierV3ResultCode::STATUS_FAILED,
            $detail
        );
    }
}
