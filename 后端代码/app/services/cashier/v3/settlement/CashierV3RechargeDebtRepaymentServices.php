<?php
declare(strict_types=1);

namespace app\services\cashier\v3\settlement;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3ActionDispatcher;
use app\services\cashier\v3\CashierV3BusinessDocumentNumberServices;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\checkout\provider\CashierV3MemberBalanceProvider;
use app\services\cashier\v3\event\CashierV3BusinessEventExecution;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\fact\CashierV3CheckoutFactIdFactory;
use app\services\cashier\v3\fact\CashierV3CheckoutFactPlanV1;
use app\services\cashier\v3\fact\ThinkPhpCashierV3CheckoutFactRepository;
use app\services\cashier\v3\registry\CashierV3ContextPolicy;
use app\services\cashier\v3\settlement\CashierV3CheckoutSettlementKernel;
use app\services\cashier\v3\settlement\CashierV3CheckoutVerifiedSourceSet;
use app\services\cashier\v3\settlement\ThinkPhpCashierV3CheckoutRequestRepository;
use app\services\user\UserBalanceAtomicServices;
use think\facade\Db;

/**
 * The repayment authority for debts created by submit-recharge.
 *
 * This intentionally does not call StoreDebtServices: recharge debts use the
 * order_id=0 compatibility sentinel and must never be resolved as sales orders.
 */
final class CashierV3RechargeDebtRepaymentServices
{
    public const ACTION = 'submit-recharge-debt-repayment';
    public const PREPARE_ACTION = 'prepare-recharge-debt-repayment';
    private const PAYMENT_METHODS = ['unionpay','wechat','alipay','dianping_voucher','douyin_voucher','partner_collection','other_collection'];

    public static function install(CashierV3ActionDispatcher $dispatcher): void
    {
        // The unified checkout command is registered by CashierV3CashierModule,
        // where the persisted checkout request can be converted into the
        // dedicated recharge-repayment input before this service executes.
    }

    /**
     * Create the same eventless checkout draft used by sales debt repayment.
     * The final settlement still delegates to this service so recharge debts
     * keep their dedicated balance-credit and projection semantics.
     */
    public function prepareCheckoutInTx(array $scope): array
    {
        CashierV3TransactionGuard::assertInTransaction('rechargeDebtRepayment.prepareCheckoutInTx');
        $operator = $scope['operator_scope'] ?? null;
        $dataScope = $scope['data_scope'] ?? null;
        if (!$operator instanceof CashierV3OperatorScope || !$dataScope instanceof CashierV3DataScopeContext
            || !$dataScope->allowsStore($operator->storeId())) {
            throw self::failure('recharge_repayment_scope_incomplete');
        }
        $payload = (array)($scope['payload'] ?? []);
        $memberId = (int)($payload['memberId'] ?? 0);
        $debtId = (int)($payload['debtRecordId'] ?? $payload['debtId'] ?? 0);
        $amountCents = self::inputCents($payload['amount'] ?? null);
        $stateContextId = trim((string)($scope['state_context_id'] ?? ''));
        $idempotencyKey = trim((string)($scope['idempotency_key'] ?? $payload['preparationRequestId'] ?? ''));
        if ($memberId <= 0 || $debtId <= 0 || $amountCents <= 0 || $stateContextId === '' || $idempotencyKey === '') {
            throw self::failure('recharge_repayment_prepare_payload_invalid');
        }
        $debt = (array)Db::name('store_debt')->where('id', $debtId)->lock(true)->find();
        $map = (array)Db::name('cashier_v3_recharge_debt_authority')->where('debt_id', $debtId)->lock(true)->find();
        if (!$debt || !$map || (int)($debt['order_id'] ?? -1) !== 0
            || (int)($debt['uid'] ?? 0) !== $memberId || (int)($debt['store_id'] ?? 0) !== $operator->storeId()
            || (int)($map['member_id'] ?? 0) !== $memberId || (int)($map['store_id'] ?? 0) !== $operator->storeId()
            || (string)($map['tenant_id'] ?? '') !== $dataScope->tenantId()) {
            throw self::failure('recharge_repayment_source_mismatch');
        }
        $total = self::cents((string)($debt['total_debt'] ?? ''));
        $repaid = self::cents((string)($debt['repaid_debt'] ?? ''));
        if ((int)($debt['status'] ?? -1) !== 0 || $amountCents > $total - $repaid || $amountCents <= 0) {
            throw self::failure('recharge_repayment_amount_outdated');
        }
        $rechargeId = (int)($map['recharge_id'] ?? 0);
        $recharge = (array)Db::name('user_recharge')->where('id', $rechargeId)->lock(true)->find();
        if (!$recharge || (int)($recharge['uid'] ?? 0) !== $memberId || (int)($recharge['store_id'] ?? 0) !== $operator->storeId()) {
            throw self::failure('recharge_repayment_recharge_missing');
        }
        $workspaceId = \app\services\cashier\v3\CashierV3CheckoutWorkspaceIdentity::id($operator->storeId(), $stateContextId);
        $workspaceVersion = 0;
        foreach ((array)($scope['contexts'] ?? []) as $context) {
            if ((string)($context['kind'] ?? '') === 'cashier_workspace' && (int)($context['expected_version'] ?? 0) > 0) {
                $workspaceVersion = (int)$context['expected_version'];
                break;
            }
        }
        if ($workspaceVersion <= 0) throw self::failure('recharge_repayment_workspace_version_missing');
        $storeName = (string)Db::name('system_store')->where('id', $operator->storeId())->value('name');
        $organization = (array)Db::name('organization')->where('id', $operator->organizationId())->where('is_del', 0)->field('id,pid,name')->find();
        if (!$organization) throw self::failure('recharge_repayment_organization_missing');
        $organizationIds = [];
        $seenOrganizations = [];
        $cursor = $organization;
        while ($cursor) {
            $organizationId = (int)($cursor['id'] ?? 0);
            if ($organizationId <= 0 || isset($seenOrganizations[$organizationId])) throw self::failure('recharge_repayment_organization_path_invalid');
            $seenOrganizations[$organizationId] = true;
            $organizationIds[] = $organizationId;
            $parentId = (int)($cursor['pid'] ?? 0);
            if ($parentId <= 0) break;
            $cursor = (array)Db::name('organization')->where('id', $parentId)->where('is_del', 0)->field('id,pid,name')->find();
            if (!$cursor) throw self::failure('recharge_repayment_organization_path_invalid');
        }
        $member = (array)Db::name('user')->where('uid', $memberId)->field('real_name,nickname,phone')->find();
        $memberName = trim((string)($member['real_name'] ?? '')) ?: trim((string)($member['nickname'] ?? '')) ?: trim((string)($member['phone'] ?? ''));
        $operatorProfile = $dataScope->operatorProfile();
        $operatorName = trim((string)($operatorProfile['staff_name'] ?? $operatorProfile['real_name'] ?? $operatorProfile['name'] ?? ''));
        if ($storeName === '' || $memberName === '' || $operatorName === '') throw self::failure('recharge_repayment_dimension_missing');
        $now = time();
        $snapshot = [
            'contractVersion' => CashierV3CheckoutSettlementKernel::AUTHORITY_CONTRACT_VERSION,
            'authorityOrigin' => 'server_final_lock_snapshot', 'authoritySnapshotVersion' => $workspaceVersion,
            'authoritySnapshotFingerprint' => '', 'tenantId' => $dataScope->tenantId(), 'organizationId' => $operator->organizationId(),
            'organizationPath' => '/' . implode('/', array_reverse($organizationIds)) . '/', 'organizationName' => (string)$organization['name'], 'storeId' => $operator->storeId(),
            'storeName' => $storeName, 'workspaceId' => $workspaceId, 'stateContextId' => $stateContextId,
            'permissionSnapshotFingerprint' => $dataScope->permissionVersion(), 'memberId' => $memberId, 'memberName' => $memberName,
            'operatorId' => $operator->operatorId(), 'operatorName' => $operatorName, 'businessDate' => date('Y-m-d', $now),
            'businessTimezone' => 'Asia/Shanghai', 'occurredAt' => $now, 'recordedAt' => $now, 'orderNote' => '',
            'supplement' => ['enabled' => false, 'reason' => '', 'operatorId' => 0, 'operatorNameSnapshot' => '', 'operatedAt' => 0],
            'sourceDocument' => ['type' => 'debt_repayment', 'id' => (string)$debtId, 'no' => (string)($debt['debt_no'] ?? $map['debt_no'] ?? ('QK' . $debtId))],
            'saleLines' => [[
                'authorityKey' => 'recharge-debt-repayment:' . $debtId, 'saleClassification' => 'formal_sale', 'sourceType' => 'card',
                'sourceId' => $debtId, 'sourceVersion' => max(1, (int)($debt['update_time'] ?? 0)), 'quantity' => 1,
                'originalAmountCents' => $amountCents, 'discountAmountCents' => 0,
                'couponUserId' => 0, 'couponNameSnapshot' => '', 'couponDiscountCents' => 0,
                'saleAmountCents' => $amountCents,
                'debtAmountCents' => 0, 'configuredCostCents' => 0, 'priceChangeReason' => '', 'priceChangedBy' => 0,
                'priceChangedByNameSnapshot' => '', 'priceChangedAt' => 0, 'sourceNameSnapshot' => '充值欠款补交',
                'sourceCodeSnapshot' => (string)($map['debt_no'] ?? ''), 'categoryIdSnapshot' => 0, 'categoryNameSnapshot' => '',
                'serviceObject' => '', 'craftsmen' => [], 'isExperience' => 0,
            ]],
            'entitlementLines' => [], 'paymentDetails' => [],
            'balanceDeduction' => ['authorityKey' => '', 'accountId' => '', 'accountVersion' => 0, 'amountCents' => 0],
            'debt' => ['authorityKey' => '', 'policyVersion' => 0, 'amountCents' => 0],
        ];
        $snapshot['authoritySnapshotFingerprint'] = CashierV3CheckoutSettlementKernel::authorityFingerprint($snapshot);
        $current = (new ThinkPhpCashierV3CheckoutRequestRepository())->lockCurrentForKernelInTx('', $idempotencyKey, $operator, $dataScope);
        $kernel = CashierV3CheckoutSettlementKernel::saveDraft([
            'contractVersion' => CashierV3CheckoutSettlementKernel::CONTRACT_VERSION,
            'operation' => CashierV3CheckoutSettlementKernel::OPERATION_SAVE_DRAFT, 'idempotencyKey' => $idempotencyKey,
            'workspaceId' => $workspaceId, 'stateContextId' => $stateContextId, 'permissionSnapshotFingerprint' => $dataScope->permissionVersion(),
        ], $snapshot, $current, $this->secret());
        $repository = new ThinkPhpCashierV3CheckoutRequestRepository();
        $sources = CashierV3CheckoutVerifiedSourceSet::fromServerVerifiedAuthorityRows($dataScope->tenantId(), $operator->storeId(), []);
        $persisted = $repository->persistKernelPlanInTx($kernel, $sources, $operator, $dataScope);
        return ['contractVersion' => 'cashier-v3-recharge-debt-repayment-prepare-v1', 'preparationRequestId' => $idempotencyKey,
            'checkoutRequestId' => (string)$kernel['requestId'], 'checkoutRequestVersion' => (int)$kernel['requestVersion'],
            'requestStatus' => (string)$kernel['requestStatus'], 'composition' => (string)$kernel['composition'],
            'replayed' => !empty($kernel['replayed']) || !empty($persisted['replayed']), 'eventless' => true];
    }

    public function submitInTx(array $scope): array
    {
        CashierV3TransactionGuard::assertInTransaction('rechargeDebtRepayment.submitInTx');
        $operator = $scope['operator_scope'] ?? null;
        $dataScope = $scope['data_scope'] ?? null;
        $recorder = $scope['event_recorder'] ?? null;
        $execution = $scope['event_execution'] ?? null;
        if (!$operator instanceof CashierV3OperatorScope || !$dataScope instanceof CashierV3DataScopeContext
            || !$recorder instanceof CashierV3BusinessEventRecorder || !$execution instanceof CashierV3BusinessEventExecution) {
            throw self::failure('recharge_repayment_scope_incomplete');
        }
        $input = $this->input((array)($scope['payload'] ?? []));
        if (!$dataScope->allowsStore($operator->storeId())) {
            throw self::failure('recharge_repayment_scope_denied');
        }

        $debt = (array)Db::name('store_debt')->where('id', $input['debtId'])->lock(true)->find();
        $map = (array)Db::name('cashier_v3_recharge_debt_authority')->where('debt_id', $input['debtId'])->lock(true)->find();
        if (!$debt || !$map || (int)($debt['order_id'] ?? -1) !== 0
            || (int)($debt['uid'] ?? 0) !== $input['memberId']
            || (int)($debt['store_id'] ?? 0) !== $operator->storeId()
            || (int)($map['member_id'] ?? 0) !== $input['memberId']
            || (int)($map['store_id'] ?? 0) !== $operator->storeId()
            || (string)($map['tenant_id'] ?? '') !== $dataScope->tenantId()) {
            throw self::failure('recharge_repayment_source_mismatch');
        }
        $rechargeId = (int)($map['recharge_id'] ?? 0);
        $recharge = (array)Db::name('user_recharge')->where('id', $rechargeId)->lock(true)->find();
        if (!$recharge || (int)($recharge['uid'] ?? 0) !== $input['memberId']
            || (int)($recharge['store_id'] ?? 0) !== $operator->storeId()) {
            throw self::failure('recharge_repayment_recharge_missing');
        }
        $total = self::cents((string)($debt['total_debt'] ?? ''));
        $repaidBefore = self::cents((string)($debt['repaid_debt'] ?? ''));
        $pending = $total - $repaidBefore;
        if ((int)($debt['status'] ?? -1) !== 0 || $pending <= 0 || $input['amountCents'] > $pending) {
            throw self::failure('recharge_repayment_amount_outdated');
        }
        if (self::cents((string)($recharge['debt_amount'] ?? '')) !== $total
            || self::cents((string)($recharge['repaid_debt_amount'] ?? '0.00')) !== $repaidBefore) {
            throw self::failure('recharge_repayment_projection_mismatch');
        }
        $this->assertPayments($input['paymentLines'], $input['amountCents']);
        $input['salespeople'] = $this->resolveSalespeople($input['salespersonAllocations'], $input['amountCents'], $operator);
        $now = time();
        $commandKey = (string)($scope['idempotency_key'] ?? '');
        if ($commandKey === '') {
            throw self::failure('recharge_repayment_idempotency_missing');
        }
        $repaymentId = 'RRP-' . substr(hash_hmac('sha256', $dataScope->tenantId() . "\0" . $input['debtId'] . "\0" . $commandKey, $this->secret()), 0, 40);
        $repaymentNo = (new CashierV3BusinessDocumentNumberServices())->allocateForSourceInTx(
            $dataScope->tenantId(),
            CashierV3BusinessDocumentNumberServices::DEBT_REPAYMENT,
            'recharge_debt_repayment',
            $repaymentId,
            date('Y-m-d', $now),
            $now
        );
        $repaidAfter = $repaidBefore + $input['amountCents'];
        $debtStatus = $repaidAfter === $total ? 1 : 0;
        $fingerprint = hash('sha256', json_encode([$input, $dataScope->tenantId(), $operator->storeId(), $rechargeId, $repaidBefore], JSON_UNESCAPED_SLASHES));
        $existing = Db::name('cashier_v3_recharge_debt_repayment')->where('tenant_id', $dataScope->tenantId())->where('command_idempotency_key', $commandKey)->lock(true)->find();
        if ($existing) {
            if ((string)($existing['immutable_fingerprint'] ?? '') !== $fingerprint) {
                throw self::failure('recharge_repayment_idempotency_conflict');
            }
            return $this->replayedResult((array)$existing);
        }
        $recordId = (int)Db::name('cashier_v3_recharge_debt_repayment')->insertGetId([
            'repayment_id' => $repaymentId, 'repayment_no' => $repaymentNo,
            'tenant_id' => $dataScope->tenantId(), 'store_id' => $operator->storeId(), 'member_id' => $input['memberId'], 'operator_id' => $operator->operatorId(),
            'debt_id' => $input['debtId'], 'recharge_id' => $rechargeId, 'command_idempotency_key' => $commandKey, 'immutable_fingerprint' => $fingerprint,
            'salespeople_snapshot_json' => json_encode($input['salespeople'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'amount_cents' => $input['amountCents'], 'debt_repaid_before_cents' => $repaidBefore, 'debt_repaid_after_cents' => $repaidAfter, 'debt_status_after' => $debtStatus,
            'balance_ledger_id' => 0, 'business_date' => date('Y-m-d', $now), 'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now,
            'status' => 'processing', 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        if ($recordId <= 0) throw self::failure('recharge_repayment_insert_failed');

        $balanceProvider = new CashierV3MemberBalanceProvider();
        $beforeBalance = $balanceProvider->lockSnapshotInTx($input['memberId'], $operator, $dataScope);
        if ((int)$beforeBalance['accountVersion'] !== $input['balanceVersion']) throw self::failure('recharge_repayment_balance_version_conflict');
        try {
            $atomic = app()->make(UserBalanceAtomicServices::class)->creditBenGive(
                $input['memberId'], self::money($input['amountCents']), '0.00', 'recharge_debt_repayment', $recordId,
                '收银V3充值欠款补交', 'cv3-recharge-debt-repayment-' . hash('sha256', $dataScope->tenantId() . '|' . $commandKey)
            );
        } catch (\Throwable $exception) {
            throw self::failure('recharge_repayment_balance_credit_failed', ['cause' => get_class($exception)]);
        }
        $afterBalance = $balanceProvider->lockSnapshotInTx($input['memberId'], $operator, $dataScope);
        $ledgerId = (int)($atomic['money_id'] ?? 0);
        if ($ledgerId <= 0 || (int)$afterBalance['principalCents'] !== (int)$beforeBalance['principalCents'] + $input['amountCents']) {
            throw self::failure('recharge_repayment_balance_result_invalid');
        }
        $payments = [];
        foreach ($input['paymentLines'] as $index => $line) {
            $payments[] = [
                'repayment_id' => $repaymentId, 'payment_line_no' => $index + 1, 'payment_method' => $line['paymentMethod'], 'amount_cents' => $line['amountCents'],
                'collection_reference_snapshot' => $line['collectionReference'], 'remark_snapshot' => $line['remark'],
                'immutable_fingerprint' => hash('sha256', json_encode($line, JSON_UNESCAPED_SLASHES)),
                'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        if ((int)Db::name('cashier_v3_recharge_debt_repayment_payment')->insertAll($payments) !== count($payments)) throw self::failure('recharge_repayment_payment_insert_failed');
        if ((int)Db::name('store_debt')->where('id', $input['debtId'])->update(['repaid_debt' => self::money($repaidAfter), 'status' => $debtStatus, 'update_time' => $now]) !== 1
            || (int)Db::name('store_debt_item')->where('debt_id', $input['debtId'])->update(['repaid_debt' => self::money($repaidAfter), 'update_time' => $now]) < 1
            || (int)Db::name('user_recharge')->where('id', $rechargeId)->update(['repaid_debt_amount' => self::money($repaidAfter)]) !== 1
            || (int)Db::name('cashier_v3_recharge_debt_repayment')->where('id', $recordId)->update(['balance_ledger_id' => $ledgerId, 'status' => 'succeeded', 'updated_at' => $now]) !== 1) {
            throw self::failure('recharge_repayment_projection_write_failed');
        }
        $event = $recorder->recordInTx($execution, (array)($scope['event_contract'] ?? []), [
            'event_type' => 'debt.repaid', 'aggregate_type' => 'recharge_debt_repayment', 'aggregate_id' => $repaymentId, 'aggregate_version' => 1, 'event_version' => 1,
            'source_type' => self::ACTION, 'source_id' => $repaymentId, 'member_id' => $input['memberId'], 'business_date' => date('Y-m-d', $now),
            'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now, 'aggregate_name_snapshot' => $repaymentNo,
            'store_name_snapshot' => (string)Db::name('system_store')->where('id', $operator->storeId())->value('name'),
            'payload' => ['contractVersion' => 'cashier-v3-recharge-debt-repayment-v1','rechargeId' => $rechargeId,'debtId' => $input['debtId'],'amountCents' => $input['amountCents'],'balanceLedgerId' => $ledgerId,'salespeopleSnapshotFingerprint' => hash('sha256', json_encode($input['salespeople'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))],
        ]);
        $this->persistFactsInTx($input, $operator, $dataScope, $rechargeId, $repaymentId, $repaymentNo, $now, $event, $ledgerId, $afterBalance, $commandKey);
        return ['data' => ['repayment' => ['repaymentId' => $repaymentId,'repaymentNo' => $repaymentNo,'amount' => self::money($input['amountCents']),'balanceVersion' => (int)$afterBalance['accountVersion'],'businessEventNo' => (string)$event['event_no']]], 'business_no' => $repaymentNo, 'touched' => ['member_balance'], 'message' => '充值欠款补交成功，已补足会员储值本金。'];
    }

    private function persistFactsInTx(array $input, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope, int $rechargeId, string $repaymentId, string $repaymentNo, int $now, array $event, int $ledgerId, array $balance, string $commandKey): void
    {
        $ids = new CashierV3CheckoutFactIdFactory($this->secret());
        $member = (array)Db::name('user')->where('uid', $input['memberId'])->field('real_name,nickname')->find();
        $store = (string)Db::name('system_store')->where('id', $operator->storeId())->value('name');
        $operatorName = (string)Db::name('system_store_staff')->where('id', $operator->operatorId())->value('staff_name');
        $paymentFacts = [];
        foreach ($input['paymentLines'] as $index => $line) {
            $lineId = $repaymentId . ':payment:' . ($index + 1);
            $paymentFacts[] = ['factId' => $ids->paymentFactId($operator->tenantId(), $repaymentId, $lineId), 'naturalKey' => $ids->paymentNaturalKey($operator->tenantId(), $repaymentId, $lineId), 'factVersion' => 1, 'reversalOf' => '', 'status' => 'effective', 'sourceLineId' => $lineId, 'paymentMethod' => $line['paymentMethod'], 'paymentAuthorityKey' => $repaymentId . ':' . ($index + 1), 'collectionReference' => $line['collectionReference'], 'amountCents' => $line['amountCents']];
        }
        $performanceLine = $repaymentId . ':performance'; $external = 0; $performanceFacts = [];
        foreach ($input['salespeople'] as $person) {
            $amount = (int)$person['amountCents'];
            if (in_array((string)$person['employeeTypeCodeSnapshot'], ['partner', 'outsourced'], true)) $external += $amount;
            $performanceFacts[] = ['factId' => $ids->salesPerformanceFactId($operator->tenantId(), $repaymentId, $performanceLine, (string)$person['employeeId'], (string)$person['sequence']), 'naturalKey' => $ids->salesPerformanceNaturalKey($operator->tenantId(), $repaymentId, $performanceLine, (string)$person['employeeId'], (string)$person['sequence']), 'factVersion' => 1, 'reversalOf' => '', 'status' => 'effective', 'sourceLineId' => $performanceLine, 'performanceType' => 'sales_performance_allocated', 'employeeId' => (int)$person['employeeId'], 'employeeNameSnapshot' => (string)$person['name'], 'employeeTypeSnapshot' => (string)$person['employeeTypeCodeSnapshot'], 'employeeTypeAuthorityVersion' => (int)$person['employeeTypeAuthorityVersion'], 'roleSnapshot' => 'salesperson', 'allocationWeightNumerator' => (int)$person['allocationWeight'], 'allocationWeightDenominator' => 100, 'allocationBaseAmountCents' => $input['amountCents'], 'amountCents' => $amount, 'ruleCodeSnapshot' => 'recharge_debt_repayment_salesperson_allocation', 'ruleNameSnapshot' => '充值欠款补交销售人分配', 'ruleVersionSnapshot' => 'v1'];
        }
        $performanceFacts[] = ['factId' => $ids->actualPerformanceFactId($operator->tenantId(), $repaymentId, $performanceLine), 'naturalKey' => $ids->actualPerformanceNaturalKey($operator->tenantId(), $repaymentId, $performanceLine), 'factVersion' => 1, 'reversalOf' => '', 'status' => 'effective', 'sourceLineId' => $performanceLine, 'performanceType' => 'actual_performance_recorded', 'employeeId' => 0, 'employeeNameSnapshot' => '', 'employeeTypeSnapshot' => '', 'employeeTypeAuthorityVersion' => 0, 'roleSnapshot' => '', 'allocationWeightNumerator' => 0, 'allocationWeightDenominator' => 1, 'allocationBaseAmountCents' => $input['amountCents'], 'amountCents' => $input['amountCents'] - $external, 'ruleCodeSnapshot' => 'recharge_debt_repayment_cash_performance', 'ruleNameSnapshot' => '充值欠款补交现金业绩', 'ruleVersionSnapshot' => 'v1'];
        $plan = CashierV3CheckoutFactPlanV1::fromInternalAuthority(['contractVersion' => CashierV3CheckoutFactPlanV1::CONTRACT_VERSION, 'commandIdempotencyKey' => $commandKey, 'context' => ['tenantId' => $operator->tenantId(), 'tenantNameSnapshot' => '', 'organizationId' => $operator->organizationId(), 'organizationNameSnapshot' => '', 'organizationPathSnapshot' => $operator->organizationId(), 'storeId' => $operator->storeId(), 'storeNameSnapshot' => $store, 'memberId' => $input['memberId'], 'memberNameSnapshot' => trim((string)($member['real_name'] ?? '')) ?: trim((string)($member['nickname'] ?? '')), 'operatorId' => $operator->operatorId(), 'operatorNameSnapshot' => $operatorName, 'businessDate' => date('Y-m-d', $now), 'businessTimezone' => 'Asia/Shanghai', 'occurredAt' => $now, 'settledAt' => $now, 'recordedAt' => $now, 'checkoutRequestId' => $repaymentId, 'orderId' => $repaymentId, 'orderNoSnapshot' => $repaymentNo, 'sourceDocumentType' => 'recharge_debt_repayment', 'businessEventNo' => (string)$event['event_no'], 'businessSourcePrimaryId' => 0, 'businessSourcePrimaryNameSnapshot' => '', 'businessSourceSecondaryId' => 0, 'businessSourceSecondaryNameSnapshot' => '', 'businessSourceLabelSnapshot' => ''], 'saleFacts' => [], 'paymentFacts' => $paymentFacts, 'balanceFacts' => [['factId' => $ids->balanceFactId($operator->tenantId(), $repaymentId, (string)$ledgerId), 'naturalKey' => $ids->balanceNaturalKey($operator->tenantId(), $repaymentId, (string)$ledgerId), 'factVersion' => 1, 'reversalOf' => '', 'status' => 'effective', 'sourceLineId' => $repaymentId . ':balance', 'balanceChangeType' => 'recharge_debt_repayment_credit', 'balanceAccountId' => (string)$balance['accountId'], 'accountVersion' => (int)$balance['accountVersion'], 'principalDeltaCents' => $input['amountCents'], 'bonusDeltaCents' => 0, 'principalAfterCents' => (int)$balance['principalCents'], 'bonusAfterCents' => (int)$balance['giftCents']]], 'performanceFacts' => $performanceFacts]);
        try { (new ThinkPhpCashierV3CheckoutFactRepository())->persistInTx($plan, $operator, $scope); } catch (\Throwable $e) { throw self::failure('recharge_repayment_fact_write_failed', ['cause' => get_class($e)]); }
    }

    private function input(array $payload): array
    {
        $memberId=(int)($payload['memberId']??0); $debtId=(int)($payload['debtId']??$payload['debtRecordId']??0); $amount=self::inputCents($payload['amount']??null); $version=(int)($payload['balanceVersion']??0);
        $lines=is_array($payload['paymentLines']??null)?array_values($payload['paymentLines']):[]; $salespeople=is_array($payload['salespersonAllocations']??null)?array_values($payload['salespersonAllocations']):[];
        if($memberId<=0||$debtId<=0||$amount<=0||$version<=0||!$lines) throw self::failure('recharge_repayment_payload_invalid');
        $out=[]; foreach($lines as $line){$method=trim((string)($line['paymentMethod']??''));$cents=self::inputCents(is_array($line)?($line['amount']??null):null);$reference=trim((string)($line['collectionReference']??''));$remark=trim((string)($line['remark']??''));if(!in_array($method,self::PAYMENT_METHODS,true)||$cents<=0||strlen($reference)>128||strlen($remark)>255)throw self::failure('recharge_repayment_payment_invalid');$out[]=['paymentMethod'=>$method,'amountCents'=>$cents,'collectionReference'=>$reference,'remark'=>$remark];}
        return ['memberId'=>$memberId,'debtId'=>$debtId,'amountCents'=>$amount,'balanceVersion'=>$version,'paymentLines'=>$out,'salespersonAllocations'=>$salespeople];
    }
    private function assertPayments(array $lines,int $amount):void { $sum=0; foreach($lines as $line)$sum+=(int)$line['amountCents']; if($sum!==$amount)throw self::failure('recharge_repayment_payment_total_invalid'); }
    private function resolveSalespeople(array $allocations, int $amount, CashierV3OperatorScope $operator): array { if (!$allocations) return []; if ($amount<=0||$amount%100!==0||count($allocations)>20) throw self::failure('recharge_repayment_salespeople_invalid'); $requested=[];$total=0;foreach($allocations as $index=>$row){$staffId=(int)($row['staffId']??0);$weight=(int)($row['allocationWeight']??0);if($staffId<=0||$weight<=0||isset($requested[$staffId]))throw self::failure('recharge_repayment_salespeople_invalid');$requested[$staffId]=['allocationWeight'=>$weight,'isPreSale'=>!empty($row['isPreSale'])||!empty($row['marked']),'sequence'=>$index+1];$total+=$weight;}if($total!==100)throw self::failure('recharge_repayment_salespeople_weight_invalid');$ids=array_keys($requested);sort($ids,SORT_NUMERIC);$rows=Db::name('system_store_staff')->alias('ss')->join('employee e','e.id=ss.employee_id')->whereIn('ss.id',$ids)->where('ss.store_id',$operator->storeId())->where('ss.status',1)->where('ss.is_del',0)->where('ss.cashier_salesperson_enabled',1)->where('e.status',1)->where('e.is_del',0)->field('ss.id,ss.staff_name,ss.employee_id,e.name,e.employment_type_code,e.employment_type_version')->lock(true)->select()->toArray();if(count($rows)!==count($requested))throw self::failure('recharge_repayment_salesperson_ineligible');$by=[];foreach($rows as $row)$by[(int)$row['id']]=$row;$out=[];$allocated=0;$count=count($requested);$yuan=intdiv($amount,100);foreach($requested as $staffId=>$selection){$row=$by[$staffId]??null;if(!$row)throw self::failure('recharge_repayment_salesperson_ineligible');$type=(string)$row['employment_type_code'];$version=(int)$row['employment_type_version'];$name=trim((string)$row['name'])?:trim((string)$row['staff_name']);$sequence=(int)$selection['sequence'];$share=$sequence===$count?$amount-$allocated:intdiv($yuan*(int)$selection['allocationWeight'],100)*100;if($name===''||!in_array($type,['internal','partner','outsourced'],true)||$version<=0||$share<=0||$share%100!==0)throw self::failure('recharge_repayment_salesperson_type_invalid');$allocated+=$share;$out[]=['staffId'=>(int)$staffId,'employeeId'=>(int)$row['employee_id'],'name'=>$name,'employeeTypeCodeSnapshot'=>$type,'employeeTypeAuthorityVersion'=>$version,'allocationWeight'=>(int)$selection['allocationWeight'],'amountCents'=>$share,'isPreSale'=>(bool)$selection['isPreSale'],'sequence'=>$sequence];}if($allocated!==$amount)throw self::failure('recharge_repayment_salespeople_amount_invalid');return $out; }
    private function replayedResult(array $row):array { if((string)($row['status']??'')!=='succeeded')throw self::failure('recharge_repayment_prior_result_unknown'); return ['data'=>['repayment'=>['repaymentId'=>(string)$row['repayment_id'],'repaymentNo'=>(string)$row['repayment_no'],'amount'=>self::money((int)$row['amount_cents'])]],'business_no'=>(string)$row['repayment_no'],'touched'=>['member_balance'],'message'=>'充值欠款补交已完成。']; }
    private function secret():string { $secret=trim((string)config('cashier_v3.checkout_namespace_secret')); if(strlen($secret)<32)throw self::failure('recharge_repayment_secret_missing'); return $secret; }
    private static function inputCents($value):int { if(!is_int($value)&&!is_string($value))return -1;$raw=trim((string)$value);if(preg_match('/^(?:0|[1-9][0-9]*)$/D',$raw)!==1)return -1;return (int)$raw*100; }
    private static function cents(string $money):int { if(preg_match('/^(?:0|[1-9][0-9]*)(?:\.00)?$/D',$money)!==1)throw self::failure('recharge_repayment_money_invalid');$yuan=explode('.',$money,2)[0];return (int)$yuan*100; }
    private static function money(int $cents):string { if($cents<0||$cents%100!==0)throw self::failure('recharge_repayment_whole_yuan_required');return (string)intdiv($cents,100); }
    private static function failure(string $reason,array $detail=[]):CashierV3CommandException { return new CashierV3CommandException(CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,'充值欠款补交资料不完整，请刷新后重试。',CashierV3ResultCode::STATUS_FAILED,array_merge(['reason'=>$reason],$detail)); }
}
