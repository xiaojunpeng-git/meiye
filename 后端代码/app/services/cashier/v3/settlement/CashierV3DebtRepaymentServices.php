<?php
declare(strict_types=1);

namespace app\services\cashier\v3\settlement;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\checkout\provider\CashierV3MemberBalanceProvider;
use app\services\cashier\v3\config\CashierV3BusinessConfigServices;
use app\services\cashier\v3\event\CashierV3BusinessEventExecution;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\fact\CashierV3CheckoutFactPlanV1;
use app\services\cashier\v3\fact\ThinkPhpCashierV3CheckoutFactRepository;
use think\facade\Db;

/** Sales-debt repayment authority. Legacy debts are deliberately unsupported. */
final class CashierV3DebtRepaymentServices
{
    public const PREPARE_CONTRACT_VERSION = 'cashier-v3-debt-repayment-prepare-v1';
    public const SUBMIT_CONTRACT_VERSION = 'cashier-v3-debt-repayment-submit-v1';

    private const PAYMENT_METHODS = [
        'unionpay', 'wechat', 'alipay', 'dianping_voucher', 'douyin_voucher',
        'partner_collection', 'other_collection',
    ];

    /** @var CashierV3CheckoutRequestRepository */
    private $requests;

    /** @var string */
    private $serverIdSecret;

    public function __construct(
        ?CashierV3CheckoutRequestRepository $requests = null,
        string $serverIdSecret = ''
    ) {
        $this->requests = $requests ?: new ThinkPhpCashierV3CheckoutRequestRepository();
        $this->serverIdSecret = $serverIdSecret;
    }

    /** Prepare an eventless, versioned payment draft for one new V3 sales debt. */
    public function prepareInTx(array $scope): array
    {
        CashierV3TransactionGuard::assertInTransaction('debtRepayment.prepareInTx');
        [$operator, $dataScope] = $this->scopes($scope);
        $payload = (array)($scope['payload'] ?? []);
        $debtId = self::positiveInt($payload['debtRecordId'] ?? $payload['debtId'] ?? null, 'debt_id');
        $amountCents = self::inputMoneyCents($payload['amount'] ?? null, 'repayment_amount');
        $stateContextId = trim((string)($scope['state_context_id'] ?? ''));
        $workspaceId = self::workspaceId($operator, $stateContextId);
        $idempotencyKey = trim((string)($scope['idempotency_key'] ?? ''));
        if ($idempotencyKey === '') {
            throw self::failure('debt_repayment_prepare_idempotency_missing');
        }

        $locked = $this->lockDebtAuthority($debtId, $operator, $dataScope);
        $debt = $locked['debt'];
        $authority = $locked['authority'];
        $items = $locked['items'];
        $personnel = $locked['personnel'];
        $total = self::storedMoneyCents($debt['total_debt'] ?? null, 'debt_total');
        $repaid = self::storedMoneyCents($debt['repaid_debt'] ?? null, 'debt_repaid');
        if ((int)($debt['status'] ?? -1) !== 0 || $amountCents <= 0 || $amountCents > $total - $repaid) {
            throw self::failure('debt_repayment_amount_outdated');
        }
        $selectedSalespeople = $this->resolveRepaymentSalespeople(
            is_array($payload['salespersonAllocations'] ?? null) ? array_values($payload['salespersonAllocations']) : [],
            $amountCents,
            $operator
        );
        $debtFingerprint = self::debtFingerprint($authority, $debt, $items, $personnel);
        $dimensions = $this->dimensions($operator, $dataScope, (int)$authority['member_id']);
        $workspaceVersion = self::contextVersion((array)($scope['contexts'] ?? []), 'cashier_workspace', $workspaceId);
        $now = time();
        $snapshot = [
            'contractVersion' => CashierV3CheckoutSettlementKernel::AUTHORITY_CONTRACT_VERSION,
            'authorityOrigin' => 'server_final_lock_snapshot',
            'authoritySnapshotVersion' => $workspaceVersion,
            'authoritySnapshotFingerprint' => '',
            'tenantId' => $dataScope->tenantId(),
            'organizationId' => $operator->organizationId(),
            'organizationPath' => $dimensions['organizationPath'],
            'organizationName' => $dimensions['organizationName'],
            'storeId' => $operator->storeId(),
            'storeName' => $dimensions['storeName'],
            'workspaceId' => $workspaceId,
            'stateContextId' => $stateContextId,
            'permissionSnapshotFingerprint' => $dataScope->permissionVersion(),
            'memberId' => (int)$authority['member_id'],
            'memberName' => $dimensions['memberName'],
            'operatorId' => $operator->operatorId(),
            'operatorName' => $dimensions['operatorName'],
            'businessDate' => date('Y-m-d', $now),
            'businessTimezone' => 'Asia/Shanghai',
            'occurredAt' => $now,
            'recordedAt' => $now,
            'orderNote' => '',
            'supplement' => ['enabled' => false, 'reason' => '', 'operatorId' => 0, 'operatorNameSnapshot' => '', 'operatedAt' => 0],
            'sourceDocument' => ['type' => 'debt_repayment', 'id' => (string)$debtId, 'no' => (string)$authority['debt_no']],
            'saleLines' => [[
                'authorityKey' => 'debt-repayment:' . $debtId,
                'saleClassification' => 'formal_sale',
                'sourceType' => 'card',
                'sourceId' => $debtId,
                'sourceVersion' => max(1, (int)($debt['update_time'] ?? 0)),
                'quantity' => 1,
                'originalAmountCents' => $amountCents,
                'discountAmountCents' => 0,
                'saleAmountCents' => $amountCents,
                'debtAmountCents' => 0,
                'configuredCostCents' => 0,
                'priceChangeReason' => '',
                'priceChangedBy' => 0,
                'priceChangedByNameSnapshot' => '',
                'priceChangedAt' => 0,
                'sourceNameSnapshot' => '欠款补交',
                'sourceCodeSnapshot' => (string)$authority['sales_order_no_snapshot'],
                'categoryIdSnapshot' => 0,
                'categoryNameSnapshot' => '',
                'serviceObject' => '',
                'craftsmen' => [],
                'isExperience' => 0,
            ]],
            'entitlementLines' => [],
            'paymentDetails' => [],
            'balanceDeduction' => ['authorityKey' => '', 'accountId' => '', 'accountVersion' => 0, 'amountCents' => 0],
            'debt' => ['authorityKey' => '', 'policyVersion' => 0, 'amountCents' => 0],
        ];
        $snapshot['authoritySnapshotFingerprint'] = CashierV3CheckoutSettlementKernel::authorityFingerprint($snapshot);
        $current = $this->requests->lockCurrentForKernelInTx('', $idempotencyKey, $operator, $dataScope);
        $kernel = CashierV3CheckoutSettlementKernel::saveDraft([
            'contractVersion' => CashierV3CheckoutSettlementKernel::CONTRACT_VERSION,
            'operation' => CashierV3CheckoutSettlementKernel::OPERATION_SAVE_DRAFT,
            'idempotencyKey' => $idempotencyKey,
            'workspaceId' => $workspaceId,
            'stateContextId' => $stateContextId,
            'permissionSnapshotFingerprint' => $dataScope->permissionVersion(),
        ], $snapshot, $current, $this->secret());
        $sources = CashierV3CheckoutVerifiedSourceSet::fromServerVerifiedAuthorityRows(
            $dataScope->tenantId(),
            $operator->storeId(),
            [[
                'tenantId' => $dataScope->tenantId(),
                'storeId' => $operator->storeId(),
                'kind' => 'debt_record',
                'id' => (string)$debtId,
                'sourceVersion' => self::contextVersion((array)($scope['contexts'] ?? []), 'debt_record', (string)$debtId),
                'role' => 'debt_record',
            ]]
        );
        $persisted = $this->requests->persistKernelPlanInTx($kernel, $sources, $operator, $dataScope);
        $this->persistDraftInTx($kernel, $authority, $amountCents, $repaid, $debtFingerprint, $selectedSalespeople, $workspaceId, $stateContextId, $operator, $idempotencyKey, $now);

        return [
            'contractVersion' => self::PREPARE_CONTRACT_VERSION,
            'preparationRequestId' => $idempotencyKey,
            'checkoutRequestId' => (string)$kernel['requestId'],
            'checkoutRequestVersion' => (int)$kernel['requestVersion'],
            'requestStatus' => (string)$kernel['requestStatus'],
            'composition' => (string)$kernel['composition'],
            'replayed' => !empty($kernel['replayed']) || !empty($persisted['replayed']),
            'eventless' => true,
        ];
    }

    /** Final settlement. The gateway owns the surrounding transaction. */
    public function submitInTx(array $scope): array
    {
        CashierV3TransactionGuard::assertInTransaction('debtRepayment.submitInTx');
        [$operator, $dataScope] = $this->scopes($scope);
        $recorder = $scope['event_recorder'] ?? null;
        $execution = $scope['event_execution'] ?? null;
        if (!$recorder instanceof CashierV3BusinessEventRecorder || !$execution instanceof CashierV3BusinessEventExecution) {
            throw self::failure('debt_repayment_event_scope_missing');
        }
        $payload = (array)($scope['payload'] ?? []);
        $requestId = trim((string)($payload['checkoutRequestId'] ?? ''));
        $requestVersion = self::positiveInt($payload['checkoutRequestVersion'] ?? null, 'checkout_request_version');
        $commandKey = trim((string)($scope['idempotency_key'] ?? ''));
        if ($requestId === '' || $commandKey === '') throw self::failure('debt_repayment_submit_identity_missing');

        $existing = (array)Db::name('cashier_v3_debt_repayment')
            ->where('tenant_id', $dataScope->tenantId())
            ->where('command_idempotency_key', $commandKey)->lock(true)->find();
        if ($existing) return $this->replayedResult($existing, $requestId, $operator, $dataScope);

        $workspaceId = self::workspaceId($operator, trim((string)($scope['state_context_id'] ?? '')));
        $aggregate = $this->requests->lockAggregateForEditInTx(
            $requestId,
            $requestVersion,
            $workspaceId,
            trim((string)($scope['state_context_id'] ?? '')),
            $operator,
            $dataScope
        );
        $request = (array)$aggregate['request'];
        if ((string)($request['source_document_type'] ?? '') === 'debt_repayment') {
            $rechargeDebt = (array)Db::name('cashier_v3_recharge_debt_authority')
                ->where('tenant_id', $dataScope->tenantId())
                ->where('store_id', $operator->storeId())
                ->where('debt_id', (int)($request['source_document_id'] ?? 0))
                ->lock(true)->find();
            if ($rechargeDebt) {
                return $this->submitRechargeDebtThroughCheckout($scope, $aggregate, $request, $workspaceId, $requestId, $requestVersion, $commandKey, $operator, $dataScope);
            }
        }
        if ((string)($request['source_document_type'] ?? '') !== 'debt_repayment'
            || (int)($request['balance_deduction_amount_cents'] ?? 0) !== 0) {
            throw self::failure('debt_repayment_checkout_source_invalid');
        }
        $debtId = self::positiveInt($request['source_document_id'] ?? null, 'debt_id');
        $locked = $this->lockDebtAuthority($debtId, $operator, $dataScope);
        $debt = $locked['debt'];
        $authority = $locked['authority'];
        $items = $locked['items'];
        $personnel = $locked['personnel'];
        $draft = (array)Db::name('cashier_v3_debt_repayment_draft')
            ->where('tenant_id', $dataScope->tenantId())->where('workspace_id', $workspaceId)
            ->where('debt_id', $debtId)->lock(true)->find();
        if (!$draft || (string)($draft['draft_status'] ?? '') !== 'editing') throw self::failure('debt_repayment_draft_missing');
        $selectedSalespeople = $this->verifyRepaymentSalespeopleSnapshot(
            (string)($draft['salespeople_snapshot_json'] ?? ''),
            (int)($draft['repayment_amount_cents'] ?? 0),
            $operator
        );
        $amount = (int)($request['receivable_amount_cents'] ?? 0);
        $total = self::storedMoneyCents($debt['total_debt'] ?? null, 'debt_total');
        $repaidBefore = self::storedMoneyCents($debt['repaid_debt'] ?? null, 'debt_repaid');
        if ($amount <= 0 || $amount !== (int)$draft['repayment_amount_cents']
            || $repaidBefore !== (int)$draft['debt_repaid_snapshot_cents']
            || $amount > $total - $repaidBefore
            || (int)($debt['status'] ?? -1) !== 0
            || !hash_equals((string)$draft['debt_version_fingerprint'], self::debtFingerprint($authority, $debt, $items, $personnel))) {
            throw self::failure('debt_repayment_authority_outdated');
        }
        $payments = $this->paymentLines((array)$aggregate['payments'], $amount);
        $repaymentId = (new CashierV3DebtRepaymentIdFactory($this->secret()))->repaymentId($dataScope->tenantId(), $debtId, $commandKey);
        $repaymentNo = (new \app\services\cashier\v3\CashierV3BusinessDocumentNumberServices())->allocateForSourceInTx(
            $dataScope->tenantId(),
            \app\services\cashier\v3\CashierV3BusinessDocumentNumberServices::DEBT_REPAYMENT,
            'debt_repayment',
            $repaymentId,
            date('Y-m-d'),
            time()
        );
        $now = time();
        $repaidAfter = $repaidBefore + $amount;
        $itemTargets = self::proportionalCumulativeAllocation($repaidAfter, $items);
        $dimensions = $this->dimensions($operator, $dataScope, (int)$authority['member_id']);
        $fingerprint = hash('sha256', json_encode([$requestId, $debtId, $amount, $repaidBefore, $payments, $selectedSalespeople], JSON_UNESCAPED_SLASHES));
        $recordId = (int)Db::name('cashier_v3_debt_repayment')->insertGetId([
            'repayment_id' => $repaymentId, 'repayment_no' => $repaymentNo,
            'natural_key' => 'debt_repayment:' . $requestId, 'command_idempotency_key' => $commandKey,
            'immutable_fingerprint' => $fingerprint, 'tenant_id' => $dataScope->tenantId(),
            'organization_id' => (string)$operator->organizationId(),
            'organization_path_snapshot' => $dimensions['organizationPath'], 'organization_name_snapshot' => $dimensions['organizationName'],
            'store_id' => $operator->storeId(), 'store_name_snapshot' => $dimensions['storeName'],
            'member_id' => (int)$authority['member_id'], 'member_name_snapshot' => $dimensions['memberName'],
            'operator_id' => $operator->operatorId(), 'operator_name_snapshot' => $dimensions['operatorName'],
            'debt_id' => $debtId, 'debt_no' => (string)$authority['debt_no'],
            'sales_order_id' => (string)$authority['sales_order_id'], 'sales_order_no_snapshot' => (string)$authority['sales_order_no_snapshot'],
            'salespeople_snapshot_json' => json_encode($selectedSalespeople, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'repayment_amount_cents' => $amount, 'debt_repaid_before_cents' => $repaidBefore,
            'debt_repaid_after_cents' => $repaidAfter, 'debt_status_after' => $repaidAfter === $total ? 'settled' : 'partial',
            'business_date' => date('Y-m-d', $now), 'business_timezone' => 'Asia/Shanghai',
            'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now,
            'status' => 'processing', 'version' => 1, 'add_time' => $now, 'update_time' => $now,
        ]);
        if ($recordId <= 0) throw self::failure('debt_repayment_insert_failed');
        $collectionRows = [];
        $ids = new CashierV3DebtRepaymentIdFactory($this->secret());
        foreach ($payments as $index => $payment) {
            $lineNo = $index + 1;
            $collectionId = $ids->collectionId($repaymentId, $lineNo);
            $natural = 'debt_repayment_collection:' . $repaymentId . ':' . $lineNo;
            $collectionRows[] = [
                'collection_id' => $collectionId, 'repayment_id' => $repaymentId, 'natural_key' => $natural,
                'command_idempotency_key' => $commandKey,
                'immutable_fingerprint' => hash('sha256', json_encode($payment, JSON_UNESCAPED_SLASHES)),
                'payment_line_no' => $lineNo, 'payment_method' => $payment['paymentMethod'],
                'payment_method_name_snapshot' => $payment['paymentMethodName'], 'amount_cents' => $payment['amountCents'],
                'external_transaction_no_snapshot' => $payment['externalTransactionNo'], 'remark_snapshot' => $payment['remark'],
                'collection_status' => 'succeeded', 'occurred_at' => $now, 'settled_at' => $now,
                'recorded_at' => $now, 'add_time' => $now, 'update_time' => $now,
            ];
        }
        if ((int)Db::name('cashier_v3_debt_repayment_collection')->insertAll($collectionRows) !== count($collectionRows)) {
            throw self::failure('debt_repayment_collection_insert_failed');
        }
        foreach ($items as $item) {
            $itemId = (int)$item['id'];
            $targetMoney = self::money((int)$itemTargets[$itemId]);
            if ((string)$item['repaid_debt'] === $targetMoney) continue;
            if ((int)Db::name('store_debt_item')->where('id', $itemId)->where('debt_id', $debtId)
                ->where('repaid_debt', (string)$item['repaid_debt'])
                ->update(['repaid_debt' => $targetMoney, 'update_time' => $now]) !== 1) {
                throw self::failure('debt_repayment_item_update_failed');
            }
        }
        if ((int)Db::name('store_debt')->where('id', $debtId)->where('repaid_debt', (string)$debt['repaid_debt'])
            ->update(['repaid_debt' => self::money($repaidAfter), 'status' => $repaidAfter === $total ? 1 : 0, 'update_time' => $now]) !== 1) {
            throw self::failure('debt_repayment_debt_update_failed');
        }
        $event = $recorder->recordInTx($execution, (array)($scope['event_contract'] ?? []), [
            'event_type' => 'debt.repaid', 'aggregate_type' => 'debt_repayment', 'aggregate_id' => $repaymentId,
            'aggregate_version' => 1, 'event_version' => 1, 'source_type' => 'submit-debt-repayment',
            'source_id' => $requestId, 'member_id' => (int)$authority['member_id'],
            'business_date' => date('Y-m-d', $now), 'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now,
            'aggregate_name_snapshot' => $repaymentNo, 'store_name_snapshot' => $dimensions['storeName'],
            'payload' => ['contractVersion' => self::SUBMIT_CONTRACT_VERSION, 'debtId' => $debtId, 'amountCents' => $amount, 'salesOrderId' => (string)$authority['sales_order_id'], 'salespeopleSnapshotFingerprint' => hash('sha256', json_encode($selectedSalespeople, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))],
        ]);
        $this->persistFactsInTx($payments, $authority, $selectedSalespeople, $repaymentId, $repaymentNo, $requestId, $amount, $commandKey, $event, $dimensions, $operator, $dataScope, $now);
        $lineDraftCount = (int)Db::name('cashier_v3_checkout_line_draft')
            ->where('request_id', $requestId)->where('draft_version', $requestVersion)
            ->where('draft_status', 'draft')->lock(true)->count();
        $paymentDraftCount = (int)Db::name('cashier_v3_checkout_payment_draft')
            ->where('request_id', $requestId)->where('draft_version', $requestVersion)
            ->where('draft_status', 'draft')->lock(true)->count();
        $committedLines = (int)Db::name('cashier_v3_checkout_line_draft')
            ->where('request_id', $requestId)->where('draft_version', $requestVersion)
            ->where('draft_status', 'draft')->update(['draft_status' => 'committed', 'update_time' => $now]);
        $committedPayments = (int)Db::name('cashier_v3_checkout_payment_draft')
            ->where('request_id', $requestId)->where('draft_version', $requestVersion)
            ->where('draft_status', 'draft')->update(['draft_status' => 'committed', 'update_time' => $now]);
        if ((int)Db::name('cashier_v3_debt_repayment')->where('id', $recordId)->update(['status' => 'succeeded', 'update_time' => $now]) !== 1
            || (int)Db::name('cashier_v3_debt_repayment_draft')->where('id', (int)$draft['id'])->update([
                'payment_lines_json' => json_encode($payments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'draft_status' => 'succeeded', 'draft_version' => (int)$draft['draft_version'] + 1,
                'last_idempotency_key' => $commandKey, 'update_time' => $now,
            ]) !== 1
            || (int)Db::name('cashier_v3_checkout_request')->where('request_id', $requestId)->where('request_version', $requestVersion)
                ->where('request_status', 'editing')->update([
                    'request_status' => 'succeeded', 'request_version' => $requestVersion + 1,
                    'last_idempotency_key' => $commandKey, 'last_operation' => 'submit-debt-repayment',
                    'recorded_at' => $now, 'update_time' => $now,
                ]) !== 1
            || $lineDraftCount <= 0 || $paymentDraftCount !== count($payments)
            || $committedLines !== $lineDraftCount || $committedPayments !== $paymentDraftCount) {
            throw self::failure('debt_repayment_terminal_update_failed');
        }
        return $this->successResult($repaymentId, $repaymentNo, $requestId, $requestVersion + 1, $amount, false);
    }

    /**
     * Finalize a recharge-debt repayment from the unified checkout request.
     * This keeps the dedicated recharge event contract instead of routing the
     * domain event through the sales-debt command contract.
     */
    public function submitRechargeDebtCheckoutInTx(array $scope): array
    {
        CashierV3TransactionGuard::assertInTransaction('debtRepayment.submitRechargeDebtCheckoutInTx');
        $operator = $scope['operator_scope'] ?? null;
        $dataScope = $scope['data_scope'] ?? null;
        if (!$operator instanceof CashierV3OperatorScope || !$dataScope instanceof CashierV3DataScopeContext
            || !$dataScope->allowsStore($operator->storeId())) {
            throw self::failure('debt_repayment_scope_incomplete');
        }
        $payload = (array)($scope['payload'] ?? []);
        $requestId = trim((string)($payload['checkoutRequestId'] ?? ''));
        $requestVersion = self::positiveInt($payload['checkoutRequestVersion'] ?? null, 'checkout_request_version');
        $commandKey = trim((string)($scope['idempotency_key'] ?? ''));
        if ($requestId === '' || $commandKey === '') throw self::failure('debt_repayment_submit_identity_missing');
        $workspaceId = self::workspaceId($operator, trim((string)($scope['state_context_id'] ?? '')));
        $aggregate = $this->requests->lockAggregateForEditInTx(
            $requestId, $requestVersion, $workspaceId,
            trim((string)($scope['state_context_id'] ?? '')),
            $operator, $dataScope
        );
        $request = (array)$aggregate['request'];
        if ((string)($request['source_document_type'] ?? '') !== 'debt_repayment') {
            throw self::failure('debt_repayment_checkout_source_invalid');
        }
        $rechargeDebt = (array)Db::name('cashier_v3_recharge_debt_authority')
            ->where('tenant_id', $dataScope->tenantId())
            ->where('store_id', $operator->storeId())
            ->where('debt_id', (int)($request['source_document_id'] ?? 0))
            ->lock(true)->find();
        if (!$rechargeDebt) throw self::failure('recharge_repayment_source_mismatch');
        return $this->submitRechargeDebtThroughCheckout(
            $scope, $aggregate, $request, $workspaceId, $requestId,
            $requestVersion, $commandKey, $operator, $dataScope
        );
    }

    private function submitRechargeDebtThroughCheckout(array $scope, array $aggregate, array $request, string $workspaceId, string $requestId, int $requestVersion, string $commandKey, CashierV3OperatorScope $operator, CashierV3DataScopeContext $dataScope): array
    {
        $amountCents = (int)($request['receivable_amount_cents'] ?? 0);
        $payments = $this->paymentLines((array)$aggregate['payments'], $amountCents);
        $balance = (new CashierV3MemberBalanceProvider())->lockSnapshotInTx((int)$request['member_id'], $operator, $dataScope);
        $payload = [
            'memberId' => (int)$request['member_id'],
            'debtId' => (int)$request['source_document_id'],
            'amount' => (string)intdiv($amountCents, 100),
            'balanceVersion' => (int)$balance['accountVersion'],
            'paymentLines' => array_map(static function (array $line): array {
                return [
                    'paymentMethod' => $line['paymentMethod'],
                    'amount' => (string)intdiv((int)$line['amountCents'], 100),
                    'collectionReference' => $line['externalTransactionNo'],
                    'remark' => $line['remark'],
                ];
            }, $payments),
            'salespersonAllocations' => [],
        ];
        $rechargeScope = $scope;
        $rechargeScope['payload'] = $payload;
        $rechargeScope['idempotency_key'] = $commandKey;
        $result = (new CashierV3RechargeDebtRepaymentServices())->submitInTx($rechargeScope);
        $now = time();
        $lineCount = (int)Db::name('cashier_v3_checkout_line_draft')->where('request_id', $requestId)->where('draft_version', $requestVersion)->where('draft_status', 'draft')->lock(true)->count();
        $paymentCount = (int)Db::name('cashier_v3_checkout_payment_draft')->where('request_id', $requestId)->where('draft_version', $requestVersion)->where('draft_status', 'draft')->lock(true)->count();
        $committedLines = (int)Db::name('cashier_v3_checkout_line_draft')->where('request_id', $requestId)->where('draft_version', $requestVersion)->where('draft_status', 'draft')->update(['draft_status' => 'committed', 'update_time' => $now]);
        $committedPayments = (int)Db::name('cashier_v3_checkout_payment_draft')->where('request_id', $requestId)->where('draft_version', $requestVersion)->where('draft_status', 'draft')->update(['draft_status' => 'committed', 'update_time' => $now]);
        if ($lineCount <= 0 || $paymentCount !== count($payments) || $committedLines !== $lineCount || $committedPayments !== $paymentCount
            || (int)Db::name('cashier_v3_checkout_request')->where('request_id', $requestId)->where('request_version', $requestVersion)->where('request_status', 'editing')->update([
                'request_status' => 'succeeded', 'request_version' => $requestVersion + 1, 'last_idempotency_key' => $commandKey,
                'last_operation' => 'submit-recharge-debt-repayment', 'recorded_at' => $now, 'update_time' => $now,
            ]) !== 1) {
            throw self::failure('recharge_repayment_checkout_terminal_update_failed');
        }
        // The recharge-debt domain service returns its own `repayment` payload.
        // Normalize it at this adapter boundary to the same dedicated result
        // shape used by sales-debt repayment.  The cashier must be able to
        // render a terminal success directly; it must never fall through to
        // the generic checkout result-query state for this one-record action.
        $repayment = is_array($result['data']['repayment'] ?? null)
            ? $result['data']['repayment']
            : [];
        $result['data']['debtRepayment'] = array_merge([
            'contractVersion' => self::SUBMIT_CONTRACT_VERSION,
            'checkoutRequestId' => $requestId,
            'checkoutRequestVersion' => $requestVersion + 1,
            'status' => 'succeeded',
        ], $repayment);
        $result['touched'] = ['cashier_workspace', 'checkout_request', 'member_balance'];
        $result['return_root_state'] = true;
        return $result;
    }

    public function query(array $payload, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): array
    {
        $key = trim((string)($payload['originalIdempotencyKey'] ?? $payload['idempotencyKey'] ?? ''));
        $requestId = trim((string)($payload['checkoutRequestId'] ?? ''));
        if ($key === '' || $requestId === '') throw self::failure('debt_repayment_query_identity_missing');
        $row = (array)Db::name('cashier_v3_debt_repayment')->where('tenant_id', $scope->tenantId())
            ->where('store_id', $operator->storeId())->where('command_idempotency_key', $key)->find();
        if (!$row) {
            $rechargeRow = (array)Db::name('cashier_v3_recharge_debt_repayment')->where('tenant_id', $scope->tenantId())
                ->where('store_id', $operator->storeId())->where('command_idempotency_key', $key)->find();
            if ($rechargeRow && (string)($rechargeRow['status'] ?? '') === 'succeeded') {
                $requestVersion = (int)Db::name('cashier_v3_checkout_request')->where('request_id', $requestId)
                    ->where('tenant_id', $scope->tenantId())->where('store_id', $operator->storeId())->value('request_version');
                if ($requestVersion <= 0) throw self::failure('debt_repayment_query_request_missing');
                return [
                    'data' => ['debtRepayment' => ['contractVersion' => self::SUBMIT_CONTRACT_VERSION,
                        'repaymentId' => (string)$rechargeRow['repayment_id'], 'repaymentNo' => (string)$rechargeRow['repayment_no'],
                        'checkoutRequestId' => $requestId, 'checkoutRequestVersion' => $requestVersion,
                        'amount' => self::money((int)$rechargeRow['amount_cents']), 'status' => 'succeeded', 'replayed' => true]],
                    'business_no' => (string)$rechargeRow['repayment_no'],
                    'touched' => ['cashier_workspace', 'checkout_request', 'member_balance'],
                    'return_root_state' => true, 'message' => '充值欠款补交已完成。',
                ];
            }
        }
        if (!$row || (string)($row['status'] ?? '') !== 'succeeded'
            || (string)($row['natural_key'] ?? '') !== 'debt_repayment:' . $requestId) {
            throw self::failure('debt_repayment_query_not_found');
        }
        $requestVersion = (int)Db::name('cashier_v3_checkout_request')->where('request_id', $requestId)
            ->where('tenant_id', $scope->tenantId())->where('store_id', $operator->storeId())->value('request_version');
        if ($requestVersion <= 0) throw self::failure('debt_repayment_query_request_missing');
        return $this->successResult((string)$row['repayment_id'], (string)$row['repayment_no'], $requestId, $requestVersion, (int)$row['repayment_amount_cents'], true);
    }

    /** Deterministic cumulative proportional allocation with stable largest-remainder ties. */
    public static function proportionalCumulativeAllocation(int $repaidCents, array $items): array
    {
        if ($repaidCents < 0 || !$items) throw new \InvalidArgumentException('debt_repayment_allocation_input_invalid');
        $total = 0; $normalized = [];
        foreach ($items as $item) {
            $id = (int)($item['id'] ?? 0);
            $amount = self::storedMoneyCents($item['debt_amount'] ?? null, 'item_debt');
            if ($id <= 0 || $amount <= 0 || isset($normalized[$id])) throw new \InvalidArgumentException('debt_repayment_allocation_item_invalid');
            $normalized[$id] = $amount; $total += $amount;
        }
        if ($repaidCents > $total) throw new \InvalidArgumentException('debt_repayment_allocation_exceeds_total');
        ksort($normalized, SORT_NUMERIC); $out = []; $remainders = []; $allocated = 0;
        foreach ($normalized as $id => $amount) {
            $product = $repaidCents * $amount;
            $base = intdiv($product, $total);
            $out[$id] = $base; $allocated += $base; $remainders[$id] = $product % $total;
        }
        $ids = array_keys($normalized);
        usort($ids, static function (int $a, int $b) use ($remainders): int {
            $cmp = $remainders[$b] <=> $remainders[$a]; return $cmp !== 0 ? $cmp : $a <=> $b;
        });
        for ($i = 0; $i < $repaidCents - $allocated; $i++) $out[$ids[$i]]++;
        ksort($out, SORT_NUMERIC); return $out;
    }

    private function persistDraftInTx(array $kernel, array $authority, int $amount, int $repaid, string $fingerprint, array $selectedSalespeople, string $workspaceId, string $stateContextId, CashierV3OperatorScope $operator, string $key, int $now): void
    {
        $draftId = (new CashierV3DebtRepaymentIdFactory($this->secret()))->draftId((string)$authority['tenant_id'], (int)$authority['debt_id'], $workspaceId);
        $stored = (array)Db::name('cashier_v3_debt_repayment_draft')->where('tenant_id', (string)$authority['tenant_id'])
            ->where('workspace_id', $workspaceId)->where('debt_id', (int)$authority['debt_id'])->lock(true)->find();
        $row = [
            'draft_id' => $draftId, 'tenant_id' => (string)$authority['tenant_id'], 'workspace_id' => $workspaceId,
            'state_context_id' => $stateContextId, 'store_id' => $operator->storeId(), 'member_id' => (int)$authority['member_id'],
            'operator_id' => $operator->operatorId(), 'debt_id' => (int)$authority['debt_id'], 'debt_no' => (string)$authority['debt_no'],
            'repayment_amount_cents' => $amount, 'debt_repaid_snapshot_cents' => $repaid,
            'debt_version_fingerprint' => $fingerprint,
            'salespeople_snapshot_json' => json_encode($selectedSalespeople, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'payment_lines_json' => '[]', 'draft_status' => 'editing',
            'draft_version' => (int)$kernel['requestVersion'], 'prepare_idempotency_key' => $key,
            'last_idempotency_key' => $key, 'update_time' => $now,
        ];
        if ($stored) {
            $same = (string)$stored['prepare_idempotency_key'] === $key;
            if (!$same && (string)$stored['draft_status'] === 'editing') throw self::failure('debt_repayment_draft_already_editing');
            Db::name('cashier_v3_debt_repayment_draft')->where('id', (int)$stored['id'])->update($row);
            $verified = (array)Db::name('cashier_v3_debt_repayment_draft')->where('id', (int)$stored['id'])->lock(true)->find();
            foreach ($row as $column => $value) {
                if ((string)($verified[$column] ?? '') !== (string)$value) throw self::failure('debt_repayment_draft_update_failed');
            }
            return;
        }
        $row['add_time'] = $now;
        if ((int)Db::name('cashier_v3_debt_repayment_draft')->insert($row) !== 1) throw self::failure('debt_repayment_draft_insert_failed');
    }

    private function lockDebtAuthority(int $debtId, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): array
    {
        $debt = (array)Db::name('store_debt')->where('id', $debtId)->lock(true)->find();
        $authority = (array)Db::name('cashier_v3_debt_authority')->where('debt_id', $debtId)->lock(true)->find();
        if (!$authority || !$debt || (string)$authority['tenant_id'] !== $scope->tenantId()
            || (int)$authority['store_id'] !== $operator->storeId() || (int)$debt['store_id'] !== $operator->storeId()
            || (int)$authority['member_id'] !== (int)$debt['uid'] || (int)$debt['order_id'] !== (int)$authority['sales_order_record_id']) {
            throw self::failure('debt_repayment_v3_authority_missing');
        }
        $order = (array)Db::name('cashier_v3_sales_order')->where('id', (int)$authority['sales_order_record_id'])
            ->where('order_id', (string)$authority['sales_order_id'])->where('tenant_id', $scope->tenantId())->lock(true)->find();
        $items = Db::name('store_debt_item')->where('debt_id', $debtId)->order('id asc')->lock(true)->select()->toArray();
        if (!$order || !$items || (int)$order['member_id'] !== (int)$authority['member_id'] || (int)$order['store_id'] !== $operator->storeId()) {
            throw self::failure('debt_repayment_sales_order_mismatch');
        }
        $itemTotal = 0;
        $itemRepaid = 0;
        foreach ($items as $item) {
            $lineTotal = self::storedMoneyCents($item['debt_amount'] ?? null, 'item_debt');
            $lineRepaid = self::storedMoneyCents($item['repaid_debt'] ?? null, 'item_repaid');
            if ($lineTotal <= 0 || $lineRepaid < 0 || $lineRepaid > $lineTotal
                || (int)($item['order_id'] ?? 0) !== (int)($debt['order_id'] ?? 0)) {
                throw self::failure('debt_repayment_item_authority_invalid');
            }
            $itemTotal += $lineTotal;
            $itemRepaid += $lineRepaid;
        }
        if ($itemTotal !== self::storedMoneyCents($debt['total_debt'] ?? null, 'debt_total')
            || $itemRepaid !== self::storedMoneyCents($debt['repaid_debt'] ?? null, 'debt_repaid')) {
            throw self::failure('debt_repayment_header_item_amount_drift');
        }
        $personnelRows = Db::name('cashier_v3_debt_item_personnel_authority')
            ->where('debt_id', $debtId)->order('debt_item_id asc')->lock(true)->select()->toArray();
        if (!$personnelRows) {
            $personnel = $this->readLegacyV3PersonnelSnapshots($authority, $items, $scope);
            return ['authority' => $authority, 'debt' => $debt, 'items' => $items, 'personnel' => $personnel, 'order' => $order];
        }
        if (count($personnelRows) !== count($items)) {
            throw self::failure('debt_repayment_personnel_authority_missing');
        }
        $personnel = [];
        foreach ($personnelRows as $row) {
            $itemId = (int)($row['debt_item_id'] ?? 0);
            $item = null;
            foreach ($items as $candidate) {
                if ((int)$candidate['id'] === $itemId) {
                    $item = $candidate;
                    break;
                }
            }
            $fingerprintInput = [
                'order_line_id' => (string)($row['order_line_id'] ?? ''),
                'checkout_line_id' => (string)($row['checkout_line_id'] ?? ''),
                'line_debt_amount_cents' => (int)($row['line_debt_amount_cents'] ?? 0),
                'salespeople_snapshot_json' => (string)($row['salespeople_snapshot_json'] ?? ''),
                'debt_item_id' => $itemId,
                'debt_id' => (int)($row['debt_id'] ?? 0),
                'tenant_id' => (string)($row['tenant_id'] ?? ''),
                'store_id' => (int)($row['store_id'] ?? 0),
                'member_id' => (int)($row['member_id'] ?? 0),
            ];
            if (!$item || $itemId <= 0 || isset($personnel[$itemId])
                || (int)$row['debt_id'] !== $debtId
                || (string)$row['tenant_id'] !== $scope->tenantId()
                || (int)$row['store_id'] !== $operator->storeId()
                || (int)$row['member_id'] !== (int)$authority['member_id']
                || (int)$row['line_debt_amount_cents'] !== self::storedMoneyCents($item['debt_amount'], 'item_debt')
                || !hash_equals((string)($row['snapshot_fingerprint'] ?? ''), hash('sha256', json_encode($fingerprintInput, JSON_UNESCAPED_SLASHES)))) {
                throw self::failure('debt_repayment_personnel_authority_invalid');
            }
            $decoded = json_decode((string)$row['salespeople_snapshot_json'], true);
            if (!is_array($decoded)) {
                throw self::failure('debt_repayment_personnel_snapshot_invalid');
            }
            $personnel[$itemId] = ['authority' => $row, 'salespeople' => self::normalizedSalespeople($decoded)];
        }
        return ['authority' => $authority, 'debt' => $debt, 'items' => $items, 'personnel' => $personnel, 'order' => $order];
    }

    /**
     * Early V3 sales debts may have a debt authority but no frozen personnel rows.
     * Recover only from immutable forward performance facts; never write or infer a
     * salesperson. Partial personnel authority remains an invalid data condition.
     *
     * @return array<int,array<string,mixed>>
     */
    private function readLegacyV3PersonnelSnapshots(array $authority, array $items, CashierV3DataScopeContext $scope): array
    {
        $lines = Db::name('cashier_v3_sales_order_line')
            ->where('tenant_id', $scope->tenantId())
            ->where('order_id', (string)$authority['sales_order_id'])
            ->where('line_direction', 'forward')->where('line_status', 'settled')
            ->field('order_line_id,checkout_line_id,line_no,item_name_snapshot,debt_amount_cents')->order('line_no asc')->lock(true)->select()->toArray();
        $facts = Db::name('cashier_v3_performance_fact')
            ->where('tenant_id', $scope->tenantId())
            ->where('order_id', (string)$authority['sales_order_id'])
            ->where('performance_type', 'sales_performance_allocated')
            ->where('fact_direction', 'forward')->where('status', 'effective')
            ->field('source_line_id,employee_id,employee_name_snapshot,employee_type_snapshot,employee_type_authority_version,allocation_weight_numerator')
            ->order('source_line_id asc,id asc')->lock(true)->select()->toArray();
        $factsByLine = [];
        foreach ($facts as $fact) $factsByLine[(string)$fact['source_line_id']][] = $fact;

        $personnel = [];
        $usedLines = [];
        foreach ($items as $item) {
            $itemDebtCents = self::storedMoneyCents($item['debt_amount'] ?? null, 'item_debt');
            $itemName = trim((string)($item['product_name'] ?? ''));
            $matches = [];
            foreach ($lines as $line) {
                $lineId = (string)($line['order_line_id'] ?? '');
                if ($lineId === '' || isset($usedLines[$lineId])
                    || (int)($line['debt_amount_cents'] ?? -1) !== $itemDebtCents) {
                    continue;
                }
                if ($itemName !== '' && trim((string)($line['item_name_snapshot'] ?? '')) === $itemName) {
                    $matches[] = $line;
                }
            }
            if (!$matches) {
                foreach ($lines as $line) {
                    $lineId = (string)($line['order_line_id'] ?? '');
                    if ($lineId !== '' && !isset($usedLines[$lineId])
                        && (int)($line['debt_amount_cents'] ?? -1) === $itemDebtCents) {
                        $matches[] = $line;
                    }
                }
            }
            if (count($matches) !== 1) {
                throw self::failure('debt_repayment_personnel_legacy_line_mismatch');
            }
            $line = (array)$matches[0];
            $usedLines[(string)$line['order_line_id']] = true;
            $salespeople = [];
            foreach ((array)($factsByLine[(string)($line['order_line_id'] ?? '')] ?? []) as $sequence => $fact) {
                $salespeople[] = [
                    'employeeId' => (int)$fact['employee_id'],
                    'name' => (string)$fact['employee_name_snapshot'],
                    'employeeTypeCodeSnapshot' => (string)$fact['employee_type_snapshot'],
                    'employeeTypeAuthorityVersion' => (int)$fact['employee_type_authority_version'],
                    'allocationWeight' => (int)$fact['allocation_weight_numerator'],
                    'sequence' => $sequence + 1,
                ];
            }
            $salespeople = self::normalizedSalespeople($salespeople);
            $fingerprint = hash('sha256', json_encode([
                'legacyV3FactRecovery', (int)$item['id'], (string)($line['order_line_id'] ?? ''), $salespeople,
            ], JSON_UNESCAPED_SLASHES));
            $personnel[(int)$item['id']] = [
                'authority' => [
                    'order_line_id' => (string)($line['order_line_id'] ?? ''),
                    'checkout_line_id' => (string)($line['checkout_line_id'] ?? ''),
                    'snapshot_fingerprint' => $fingerprint,
                ],
                'salespeople' => $salespeople,
            ];
        }
        return $personnel;
    }

    private function paymentLines(array $rows, int $expected): array
    {
        if (!$rows) throw self::failure('debt_repayment_payment_missing');
        $out = []; $sum = 0; $businessConfig = new CashierV3BusinessConfigServices();
        foreach ($rows as $row) {
            $method = (string)($row['payment_method'] ?? ''); $amount = (int)($row['amount_cents'] ?? 0);
            if (!in_array($method, self::PAYMENT_METHODS, true) || $amount <= 0 || (string)($row['draft_status'] ?? '') !== 'draft') throw self::failure('debt_repayment_payment_invalid');
            $snapshot = $businessConfig->resolveAccountingMethodSnapshot($method, true);
            $sum += $amount; $out[] = ['paymentMethod'=>$method,'paymentMethodName'=>$snapshot['displayNameSnapshot'], 'amountCents'=>$amount,'externalTransactionNo'=>(string)($row['external_transaction_no']??''),'remark'=>(string)($row['remark']??'')];
        }
        if ($sum !== $expected) throw self::failure('debt_repayment_payment_total_invalid');
        return $out;
    }

    private function persistFactsInTx(array $payments, array $authority, array $selectedSalespeople, string $repaymentId, string $repaymentNo, string $requestId, int $amount, string $commandKey, array $event, array $dimensions, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope, int $now): void
    {
        $ids = new CashierV3DebtRepaymentIdFactory($this->secret()); $paymentFacts = [];
        foreach ($payments as $index => $payment) {
            $line = $repaymentId . ':payment:' . ($index + 1);
            $paymentFacts[] = ['factId'=>$ids->factId('payment',$repaymentId,$line),'naturalKey'=>'debt_repayment:payment:'.$repaymentId.':'.($index+1),'factVersion'=>1,'reversalOf'=>'','status'=>'effective','sourceLineId'=>$line,'paymentMethod'=>$payment['paymentMethod'],'paymentAuthorityKey'=>$repaymentId.':'.($index+1),'collectionReference'=>$payment['externalTransactionNo'],'amountCents'=>$payment['amountCents']];
        }
        $performanceFacts = $this->performanceFacts($selectedSalespeople, $repaymentId, $amount, $ids);
        $plan = CashierV3CheckoutFactPlanV1::fromInternalAuthority([
            'contractVersion'=>CashierV3CheckoutFactPlanV1::CONTRACT_VERSION,'commandIdempotencyKey'=>$commandKey,
            'context'=>['tenantId'=>$scope->tenantId(),'tenantNameSnapshot'=>'','organizationId'=>$operator->organizationId(),'organizationNameSnapshot'=>$dimensions['organizationName'],'organizationPathSnapshot'=>$dimensions['organizationPath'],'storeId'=>$operator->storeId(),'storeNameSnapshot'=>$dimensions['storeName'],'memberId'=>(int)$authority['member_id'],'memberNameSnapshot'=>$dimensions['memberName'],'operatorId'=>$operator->operatorId(),'operatorNameSnapshot'=>$dimensions['operatorName'],'businessDate'=>date('Y-m-d',$now),'businessTimezone'=>'Asia/Shanghai','occurredAt'=>$now,'settledAt'=>$now,'recordedAt'=>$now,'checkoutRequestId'=>$requestId,'orderId'=>$repaymentId,'orderNoSnapshot'=>$repaymentNo,'sourceDocumentType'=>'debt_repayment','businessEventNo'=>(string)$event['event_no']],
            'saleFacts'=>[],'paymentFacts'=>$paymentFacts,'balanceFacts'=>[],'performanceFacts'=>$performanceFacts,
        ]);
        try { (new ThinkPhpCashierV3CheckoutFactRepository())->persistInTx($plan,$operator,$scope); }
        catch (\Throwable $e) { throw self::failure('debt_repayment_fact_write_failed',['cause'=>get_class($e)]); }
    }

    private function performanceFacts(array $selectedSalespeople, string $repaymentId, int $amount, CashierV3DebtRepaymentIdFactory $ids): array
    {
        $facts=[]; $external=0;
        $sourceLine=$repaymentId.':performance';
        foreach($selectedSalespeople as $person){
            $share=(int)$person['amountCents'];
            if(in_array((string)$person['employeeTypeCodeSnapshot'],['partner','outsourced'],true))$external+=$share;
            $line=$sourceLine.':salesperson:'.(int)$person['employeeId'].':'.(int)$person['sequence'];
            $facts[]=['factId'=>$ids->factId('performance',$repaymentId,$line),'naturalKey'=>'debt_repayment:performance:'.$line,'factVersion'=>1,'reversalOf'=>'','status'=>'effective','sourceLineId'=>$sourceLine,'performanceType'=>'sales_performance_allocated','employeeId'=>(int)$person['employeeId'],'employeeNameSnapshot'=>(string)$person['name'],'employeeTypeSnapshot'=>(string)$person['employeeTypeCodeSnapshot'],'employeeTypeAuthorityVersion'=>(int)$person['employeeTypeAuthorityVersion'],'roleSnapshot'=>'salesperson','allocationWeightNumerator'=>(int)$person['allocationWeight'],'allocationWeightDenominator'=>100,'allocationBaseAmountCents'=>$amount,'amountCents'=>$share,'ruleCodeSnapshot'=>'debt_repayment_selected_salesperson_allocation','ruleNameSnapshot'=>'销售欠款补交所选销售人分配','ruleVersionSnapshot'=>'v1'];
        }
        $line=$repaymentId.':actual';
        $facts[]=['factId'=>$ids->factId('performance',$repaymentId,$line),'naturalKey'=>'debt_repayment:actual:'.$repaymentId,'factVersion'=>1,'reversalOf'=>'','status'=>'effective','sourceLineId'=>$line,'performanceType'=>'actual_performance_recorded','employeeId'=>0,'employeeNameSnapshot'=>'','employeeTypeSnapshot'=>'','employeeTypeAuthorityVersion'=>0,'roleSnapshot'=>'','allocationWeightNumerator'=>0,'allocationWeightDenominator'=>1,'allocationBaseAmountCents'=>$amount,'amountCents'=>$amount-$external,'ruleCodeSnapshot'=>'debt_repayment_cash_performance','ruleNameSnapshot'=>'销售欠款补交实际业绩','ruleVersionSnapshot'=>'v1'];
        return $facts;
    }

    private function resolveRepaymentSalespeople(array $allocations, int $amountCents, CashierV3OperatorScope $operator): array
    {
        if (!$allocations) return [];
        if ($amountCents <= 0 || $amountCents % 100 !== 0 || count($allocations) > 20) {
            throw self::failure('debt_repayment_salespeople_invalid');
        }
        $requested=[];$weightTotal=0;
        foreach($allocations as $index=>$row){
            if(!is_array($row))throw self::failure('debt_repayment_salespeople_invalid');
            $staffId=(int)($row['staffId']??0);$weight=(int)($row['allocationWeight']??0);
            if($staffId<=0||$weight<=0||isset($requested[$staffId]))throw self::failure('debt_repayment_salespeople_invalid');
            $requested[$staffId]=['allocationWeight'=>$weight,'isPreSale'=>!empty($row['isPreSale'])||!empty($row['marked']),'sequence'=>$index+1];
            $weightTotal+=$weight;
        }
        if($weightTotal!==100)throw self::failure('debt_repayment_salespeople_weight_invalid');
        $staffIds=array_keys($requested);sort($staffIds,SORT_NUMERIC);
        $rows=Db::name('system_store_staff')->alias('ss')->join('employee e','e.id=ss.employee_id')
            ->whereIn('ss.id',$staffIds)->where('ss.store_id',$operator->storeId())->where('ss.status',1)->where('ss.is_del',0)
            ->where('ss.cashier_salesperson_enabled',1)->where('e.status',1)->where('e.is_del',0)
            ->field('ss.id,ss.staff_name,ss.employee_id,e.name,e.employment_type_code,e.employment_type_version')->lock(true)->select()->toArray();
        if(count($rows)!==count($requested))throw self::failure('debt_repayment_salesperson_ineligible');
        $by=[];foreach($rows as $row)$by[(int)$row['id']]=$row;
        $result=[];$allocated=0;$count=count($requested);$amountYuan=intdiv($amountCents,100);
        foreach($requested as $staffId=>$selection){
            $row=$by[$staffId]??null;if(!$row)throw self::failure('debt_repayment_salesperson_ineligible');
            $type=(string)($row['employment_type_code']??'');$typeVersion=(int)($row['employment_type_version']??0);
            $name=trim((string)($row['name']??''))?:trim((string)($row['staff_name']??''));
            if($name===''||!in_array($type,['internal','partner','outsourced'],true)||$typeVersion<=0)throw self::failure('debt_repayment_salesperson_profile_invalid');
            $sequence=(int)$selection['sequence'];
            $share=$sequence===$count?$amountCents-$allocated:intdiv($amountYuan*(int)$selection['allocationWeight'],100)*100;
            if($share<=0||$share%100!==0)throw self::failure('debt_repayment_salespeople_amount_invalid');
            $allocated+=$share;
            $result[]=['staffId'=>(int)$staffId,'employeeId'=>(int)$row['employee_id'],'name'=>$name,'employeeTypeCodeSnapshot'=>$type,'employeeTypeAuthorityVersion'=>$typeVersion,'allocationWeight'=>(int)$selection['allocationWeight'],'amountCents'=>$share,'isPreSale'=>(bool)$selection['isPreSale'],'sequence'=>$sequence];
        }
        if($allocated!==$amountCents)throw self::failure('debt_repayment_salespeople_amount_invalid');
        return $result;
    }

    private function verifyRepaymentSalespeopleSnapshot(string $json, int $amountCents, CashierV3OperatorScope $operator): array
    {
        $stored=json_decode($json,true);
        if(!is_array($stored))throw self::failure('debt_repayment_salespeople_snapshot_invalid');
        $allocations=[];
        foreach($stored as $person){
            if(!is_array($person))throw self::failure('debt_repayment_salespeople_snapshot_invalid');
            $allocations[]=['staffId'=>(int)($person['staffId']??0),'allocationWeight'=>(int)($person['allocationWeight']??0),'isPreSale'=>!empty($person['isPreSale'])];
        }
        $current=$this->resolveRepaymentSalespeople($allocations,$amountCents,$operator);
        if(!hash_equals(hash('sha256',json_encode($stored,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),hash('sha256',json_encode($current,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)))){
            throw self::failure('debt_repayment_salespeople_snapshot_outdated');
        }
        return $stored;
    }

    private static function normalizedSalespeople(array $rows):array
    {
        $out=[];$weight=0;$sequences=[];
        foreach(array_values($rows) as $index=>$row){
            $person=['employeeId'=>(int)($row['employeeId']??0),'name'=>trim((string)($row['name']??'')),'employeeTypeCodeSnapshot'=>trim((string)($row['employeeTypeCodeSnapshot']??'')),'employeeTypeAuthorityVersion'=>(int)($row['employeeTypeAuthorityVersion']??0),'allocationWeight'=>(int)($row['allocationWeight']??0),'sequence'=>(int)($row['sequence']??($index+1))];
            if($person['employeeId']<=0||$person['name']===''||!in_array($person['employeeTypeCodeSnapshot'],['internal','partner','outsourced'],true)||$person['employeeTypeAuthorityVersion']<=0||$person['allocationWeight']<=0||$person['sequence']<=0||isset($sequences[$person['sequence']]))throw self::failure('debt_repayment_personnel_snapshot_invalid');
            $sequences[$person['sequence']]=true;$weight+=$person['allocationWeight'];$out[]=$person;
        }
        if($out&&$weight!==100)throw self::failure('debt_repayment_personnel_weight_invalid');
        usort($out,static function(array $a,array $b):int{return $a['sequence']<=>$b['sequence'];});
        return $out;
    }

    private function dimensions(CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope, int $memberId): array
    {
        $store=(string)Db::name('system_store')->where('id',$operator->storeId())->value('name');
        $member=(array)Db::name('user')->where('uid',$memberId)->field('real_name,nickname,phone')->find();
        $profile=$scope->operatorProfile(); $operatorName=''; foreach(['staff_name','real_name','name','account'] as $key){$operatorName=trim((string)($profile[$key]??''));if($operatorName!=='')break;}
        $organization=(array)Db::name('organization')->where('id',(int)$operator->organizationId())->where('is_del',0)->field('id,pid,name')->find();
        $memberName=trim((string)($member['real_name']??''))
            ?:trim((string)($member['nickname']??''))
            ?:trim((string)($member['phone']??''))
            ?:('会员#'.$memberId);
        if(trim($store)===''||$operatorName===''||!$organization||$memberName==='')throw self::failure('debt_repayment_dimension_missing');
        $ids=[];$seen=[];$cursor=$organization;
        while($cursor){$id=(int)$cursor['id'];if($id<=0||isset($seen[$id]))throw self::failure('debt_repayment_organization_path_invalid');$seen[$id]=true;$ids[]=$id;$pid=(int)($cursor['pid']??0);if($pid<=0)break;$cursor=(array)Db::name('organization')->where('id',$pid)->where('is_del',0)->field('id,pid,name')->find();if(!$cursor)throw self::failure('debt_repayment_organization_path_invalid');}
        return ['storeName'=>trim($store),'operatorName'=>$operatorName,'memberName'=>$memberName,'organizationName'=>(string)$organization['name'],'organizationPath'=>'/'.implode('/',array_reverse($ids)).'/'];
    }

    private function scopes(array $scope): array
    {
        $operator=$scope['operator_scope']??null;$data=$scope['data_scope']??null;
        if(!$operator instanceof CashierV3OperatorScope||!$data instanceof CashierV3DataScopeContext||!$data->allowsStore($operator->storeId()))throw self::failure('debt_repayment_scope_invalid');
        return [$operator,$data];
    }
    private function replayedResult(array $row,string $requestId,CashierV3OperatorScope $operator,CashierV3DataScopeContext $scope):array
    {
        if((string)$row['status']!=='succeeded'||(string)$row['natural_key']!=='debt_repayment:'.$requestId
            ||(int)$row['store_id']!==$operator->storeId())throw self::failure('debt_repayment_idempotency_conflict');
        $version=(int)Db::name('cashier_v3_checkout_request')->where('request_id',$requestId)
            ->where('tenant_id',$scope->tenantId())->where('store_id',$operator->storeId())->lock(true)->value('request_version');
        if($version<=0)throw self::failure('debt_repayment_query_request_missing');
        return $this->successResult((string)$row['repayment_id'],(string)$row['repayment_no'],$requestId,$version,(int)$row['repayment_amount_cents'],true);
    }
    private function successResult(string $id,string $no,string $requestId,int $version,int $amount,bool $replayed):array{return ['data'=>['debtRepayment'=>['contractVersion'=>self::SUBMIT_CONTRACT_VERSION,'repaymentId'=>$id,'repaymentNo'=>$no,'checkoutRequestId'=>$requestId,'checkoutRequestVersion'=>$version,'amount'=>self::money($amount),'status'=>'succeeded','replayed'=>$replayed]],'business_no'=>$no,'touched'=>['cashier_workspace','checkout_request','debt_record'],'return_root_state'=>true,'message'=>'欠款补交成功。'];}
    private static function debtFingerprint(array $authority,array $debt,array $items,array $personnel):string{$snapshot=[$authority['authority_fingerprint']??'',(int)($debt['id']??0),(string)($debt['total_debt']??''),(string)($debt['repaid_debt']??''),(int)($debt['status']??-1),(int)($debt['update_time']??0)];foreach($items as $item){$itemId=(int)$item['id'];$snapshot[]=[(int)$item['id'],(string)$item['debt_amount'],(string)$item['repaid_debt'],(int)$item['update_time'],(string)($personnel[$itemId]['authority']['snapshot_fingerprint']??'')];}return hash('sha256',json_encode($snapshot,JSON_UNESCAPED_SLASHES));}
    private static function workspaceId(CashierV3OperatorScope $operator,string $state):string{if($state===''||strlen($state)>64)throw self::failure('debt_repayment_state_context_invalid');return sprintf('ws:%d:%d:%s',$operator->storeId(),$operator->operatorId(),$state);}
    private static function contextVersion(array $contexts,string $kind,string $id):int{foreach($contexts as $context){if((string)($context['kind']??'')===$kind&&(string)($context['id']??'')===$id){$v=(int)($context['expected_version']??$context['expectedVersion']??0);if($v>0)return $v;}}throw self::failure('debt_repayment_context_version_missing',['kind'=>$kind,'id'=>$id]);}
    private static function positiveInt($value,string $field):int{if(is_bool($value)||!is_numeric($value)||(int)$value<=0)throw self::failure('debt_repayment_positive_int_invalid',['field'=>$field]);return (int)$value;}
    private static function inputMoneyCents($value,string $field):int{$raw=trim((string)$value);if(preg_match('/^[1-9][0-9]*$/D',$raw)!==1||strlen($raw)>10)throw self::failure('debt_repayment_money_invalid',['field'=>$field]);return (int)$raw*100;}
    private static function storedMoneyCents($value,string $field):int{$raw=trim((string)$value);if(preg_match('/^(?:0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?$/D',$raw,$m)!==1)throw self::failure('debt_repayment_stored_money_invalid',['field'=>$field]);[$whole,$fraction]=array_pad(explode('.',$raw,2),2,'');return (int)$whole*100+(int)str_pad($fraction,2,'0');}
    private static function money(int $cents):string{if($cents<0)throw self::failure('debt_repayment_negative_money');return number_format($cents/100,2,'.','');}
    private function secret():string{$secret=$this->serverIdSecret!==''?$this->serverIdSecret:trim((string)config('cashier_v3.checkout_namespace_secret'));if(strlen($secret)<32)throw self::failure('debt_repayment_secret_missing');return $secret;}
    private static function failure(string $reason,array $detail=[]):CashierV3CommandException{return new CashierV3CommandException(CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,'欠款补交资料不完整，请刷新后重试。',CashierV3ResultCode::STATUS_FAILED,array_merge(['reason'=>$reason],$detail));}
}
