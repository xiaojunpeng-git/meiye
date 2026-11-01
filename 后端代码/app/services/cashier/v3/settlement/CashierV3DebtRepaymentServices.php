<?php
declare(strict_types=1);

namespace app\services\cashier\v3\settlement;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3CrossStoreEntitlementPolicy;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3PersonnelIdentity;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\checkout\provider\CashierV3MemberBalanceProvider;
use app\services\cashier\v3\checkout\provider\CashierV3MemberBalanceWriterAdapter;
use app\services\cashier\v3\config\CashierV3BusinessConfigServices;
use app\services\cashier\v3\event\CashierV3BusinessEventExecution;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\fact\CashierV3CheckoutFactPlanV1;
use app\services\cashier\v3\fact\ThinkPhpCashierV3CheckoutFactRepository;
use app\services\cashier\v3\member\CashierV3QueryEntitySelectorServices;
use app\services\cashier\v3\order\CashierV3SupplementAttributionServices;
use app\services\report\CustomerLifecycleFactServices;
use app\services\report\StoreReportPaymentSaleAllocationFactServices;
use think\facade\Db;

/**
 * Sales-debt repayment authority and recharge-debt checkout adapter.
 * Both repayment paths settle only at final confirmation. No editing request
 * or server-side draft is accepted as a payment authority.
 */
final class CashierV3DebtRepaymentServices
{
    public const SUBMIT_CONTRACT_VERSION = 'cashier-v3-debt-repayment-submit-v1';

    // Cash methods stay separate from the dedicated, ledger-backed balance tender.
    private const PAYMENT_METHODS = [
        'cash', 'unionpay', 'wechat', 'alipay', 'dianping_voucher', 'douyin_voucher',
        'bank_transfer', 'other_income', 'partner_collection',
    ];

    /** @var string */
    private $serverIdSecret;

    public function __construct(string $serverIdSecret = '')
    {
        $this->serverIdSecret = $serverIdSecret;
    }

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
        $checkoutSnapshot = is_array($payload['checkoutSnapshot'] ?? null) ? $payload['checkoutSnapshot'] : null;
        // “下一步”只在浏览器冻结快照；最终确认必须直接提交该快照。
        // 旧草稿标识与请求版本一律拒绝，避免意外恢复第二套收款权威。
        if ($checkoutSnapshot === null
            || trim((string)($payload['checkoutRequestId'] ?? '')) !== ''
            || array_key_exists('checkoutRequestVersion', $payload)) {
            throw self::failure('debt_repayment_checkout_snapshot_required');
        }
        $commandKey = trim((string)($scope['idempotency_key'] ?? ''));
        if ($commandKey === '') throw self::failure('debt_repayment_submit_identity_missing');
        $sourceDocument = is_array($checkoutSnapshot['sourceDocument'] ?? null) ? $checkoutSnapshot['sourceDocument'] : [];
        if ((string)($checkoutSnapshot['businessType'] ?? '') !== 'debt_repayment'
            || (string)($sourceDocument['type'] ?? '') !== 'debt_repayment') {
            throw self::failure('debt_repayment_checkout_snapshot_source_invalid');
        }
        $debtId = self::positiveInt($sourceDocument['id'] ?? null, 'debt_id');
        $requestId = (new CashierV3DebtRepaymentIdFactory($this->secret()))
            ->repaymentId($dataScope->tenantId(), $debtId, $commandKey);
        $businessSource = is_array($checkoutSnapshot['source'] ?? null) ? $checkoutSnapshot['source'] : [];
        $rewardAmountCents = CashierV3RepaymentReward::cents($businessSource['rewardAmountCents'] ?? 0);
        $sourceColumns = self::sourceSnapshotColumns($businessSource);

        $existing = (array)Db::name('cashier_v3_debt_repayment')
            ->where('tenant_id', $dataScope->tenantId())
            ->where('command_idempotency_key', $commandKey)->lock(true)->find();
        if ($existing) {
            // 同一幂等键只能重放完全相同的确认快照，不能把另一笔金额
            // 或收款方式误报为已成功，也不能再次触发欠款扣减。
            $replayedAmount = self::inputMoneyCents($checkoutSnapshot['repaymentAmount'] ?? null, 'repayment_amount');
            if ((int)($existing['debt_id'] ?? 0) !== $debtId
                || (int)($existing['reward_amount_cents'] ?? 0) !== $rewardAmountCents
                || (int)($existing['repayment_amount_cents'] ?? 0) !== $replayedAmount
                || (string)($existing['business_date'] ?? '') !== (string)($checkoutSnapshot['businessDate'] ?? '')) {
                throw self::failure('debt_repayment_idempotency_conflict');
            }
            // 来源事后可由订单中心审计更正；重放仍只返回原收款结果，不能把
            // 更正后的当前值误当作当初命令输入冲突。未更正的新单仍核对快照。
            if ((int)($existing['business_source_primary_id'] ?? 0) > 0
                && !$this->repaymentSourceWasCorrected((string)$existing['repayment_id'], $dataScope->tenantId())) {
                foreach ($sourceColumns as $column => $value) {
                    if ((string)($existing[$column] ?? '') !== (string)$value) {
                        throw self::failure('debt_repayment_idempotency_conflict');
                    }
                }
            }
            $submittedPayments = $this->paymentLinesFromCheckoutSnapshot(
                $checkoutSnapshot, $replayedAmount, $debtId, (int)$existing['member_id']);
            $savedPayments = Db::name('cashier_v3_debt_repayment_collection')
                ->where('repayment_id', (string)$existing['repayment_id'])
                ->order('payment_line_no asc')->lock(true)->select()->toArray();
            if (count($savedPayments) !== count($submittedPayments)) {
                throw self::failure('debt_repayment_idempotency_conflict');
            }
            foreach ($submittedPayments as $index => $payment) {
                $saved = (array)$savedPayments[$index];
                if ((string)($saved['payment_method'] ?? '') !== (string)$payment['paymentMethod']
                    || (int)($saved['amount_cents'] ?? 0) !== (int)$payment['amountCents']
                    || (string)($saved['external_transaction_no_snapshot'] ?? '') !== (string)$payment['externalTransactionNo']
                    || (string)($saved['remark_snapshot'] ?? '') !== (string)$payment['remark']) {
                    throw self::failure('debt_repayment_idempotency_conflict');
                }
            }
            return $this->replayedResult($existing, $requestId, $operator, $dataScope);
        }

        $businessDate = self::businessDate($checkoutSnapshot['businessDate'] ?? null, time());
        $locked = $this->lockDebtAuthority($debtId, $operator, $dataScope);
        $debt = $locked['debt'];
        $authority = $locked['authority'];
        $items = $locked['items'];
        $personnel = $locked['personnel'];
        // New repayment attribution is independent of the original sale. The
        // policy validates this confirmed choice, never fills it from that sale.
        $businessSource = (new CashierV3CheckoutBusinessSourceSelectionServices())->validateConfirmedChoice(
            'repayment', $dataScope->tenantId(), $operator->storeId(),
            (int)$authority['member_id'], $businessSource
        );
        $sourceColumns = self::sourceSnapshotColumns($businessSource);
        $amount = self::inputMoneyCents($checkoutSnapshot['repaymentAmount'] ?? null, 'repayment_amount');
        // 奖励只属于本次已确认来源，随补交原子保存，不能抵扣本金或重复继承原销售奖励。
        $rewardAmountCents = CashierV3RepaymentReward::forSource($rewardAmountCents, $businessSource['primarySourceNameSnapshot']);
        // 业务行、页面应收与收款行必须指向同一笔金额。浏览器快照
        // 只表达意图；成员、来源欠款和可还余额仍在下方事务锁内核对。
        $businessLines = array_values(array_filter((array)($checkoutSnapshot['lines'] ?? []),
            static function ($line): bool { return is_array($line) && (string)($line['lineRole'] ?? '') === 'sale'; }));
        if (count($businessLines) !== 1
            || (int)($businessLines[0]['sourceId'] ?? 0) !== $debtId
            || (int)($businessLines[0]['lineAmountCents'] ?? -1) !== $amount
            || (int)($businessLines[0]['debtAmountCents'] ?? -1) !== 0) {
            throw self::failure('debt_repayment_checkout_snapshot_amount_invalid');
        }
        $total = self::storedMoneyCents($debt['total_debt'] ?? null, 'debt_total');
        $repaidBefore = self::storedMoneyCents($debt['repaid_debt'] ?? null, 'debt_repaid');
        if ($amount <= 0 || $amount > $total - $repaidBefore
            || (int)($debt['status'] ?? -1) !== 0) {
            throw self::failure('debt_repayment_authority_outdated');
        }
        $payments = $this->paymentLinesFromCheckoutSnapshot(
            $checkoutSnapshot,
            $amount,
            $debtId,
            (int)$authority['member_id']
        );
        $balanceCents = array_sum(array_column(array_filter($payments, static fn(array $p): bool => $p['paymentMethod'] === CashierV3SalesDebtBalanceServices::METHOD), 'amountCents'));
        $cashCents = $amount - $balanceCents;
        $selections = (array)($checkoutSnapshot['salespersonAllocations'] ?? []);
        if ($balanceCents > 0) {
            // A personnel amount chosen before tender selection is gross. Exclude its non-cash share
            // in whole yuan too; otherwise a manual gross amount could silently restore balance performance.
            foreach ($selections as &$selection) {
                if (!is_array($selection)) throw self::failure('debt_repayment_salespeople_invalid');
                [$manual, $gross] = self::manualPerformanceAmount($selection);
                if ($manual) $selection['performanceAmountCents'] = intdiv(intdiv($gross, 100) * $cashCents, $amount) * 100;
            }
            unset($selection);
        }
        // Personnel cash performance follows new money only; principal/bonus is not a new receipt.
        $selectedSalespeople = $this->resolveRepaymentSalespeople(
            $selections, $cashCents, $operator
        );
        if (!is_array($checkoutSnapshot['source'] ?? null)) {
            throw self::failure('debt_repayment_checkout_snapshot_source_required');
        }
        $repaymentId = (new CashierV3DebtRepaymentIdFactory($this->secret()))->repaymentId($dataScope->tenantId(), $debtId, $commandKey);
        $repaymentNo = (new \app\services\cashier\v3\CashierV3BusinessDocumentNumberServices())->allocateForSourceInTx(
            $dataScope->tenantId(),
            \app\services\cashier\v3\CashierV3BusinessDocumentNumberServices::DEBT_REPAYMENT,
            'debt_repayment',
            $repaymentId,
            $businessDate,
            time()
        );
        $now = time();
        $repaidAfter = $repaidBefore + $amount;
        // All new tenders share one exact allocation. Recomputing cumulative proportions after
        // another repayment/void would otherwise move an older wallet-funded share between items.
        $remainingWeights = []; $itemTargets = [];
        foreach ($items as $item) {
            $remaining = self::storedMoneyCents($item['debt_amount'], 'item_debt') - self::storedMoneyCents($item['repaid_debt'], 'item_repaid');
            if ($remaining % 100 !== 0) throw self::failure('debt_repayment_historical_fraction_requires_reconciliation');
            $remainingWeights[(int)$item['id']] = $remaining;
        }
        $increments = CashierV3SalesDebtBalanceServices::allocate($amount, $remainingWeights);
        foreach ($items as $item) $itemTargets[(int)$item['id']] = self::storedMoneyCents($item['repaid_debt'], 'item_repaid') + $increments[(int)$item['id']];
        $dimensions = $this->dimensions($operator, $dataScope, (int)$authority['member_id']);
        $fingerprint = hash('sha256', json_encode([$requestId, $debtId, $amount, $repaidBefore, $payments, $selectedSalespeople, $sourceColumns, $rewardAmountCents], JSON_UNESCAPED_SLASHES));
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
            // 补交主记录与同事务的收款/业绩事实共用本次已确认来源，不读取收银工具栏。
            'business_source_primary_id' => $sourceColumns['business_source_primary_id'],
            'business_source_primary_name_snapshot' => $sourceColumns['business_source_primary_name_snapshot'],
            'business_source_secondary_id' => $sourceColumns['business_source_secondary_id'],
            'business_source_secondary_name_snapshot' => $sourceColumns['business_source_secondary_name_snapshot'],
            'business_source_label_snapshot' => $sourceColumns['business_source_label_snapshot'],
            'salespeople_snapshot_json' => json_encode($selectedSalespeople, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'repayment_amount_cents' => $amount, 'debt_repaid_before_cents' => $repaidBefore,
            'reward_amount_cents' => $rewardAmountCents,
            'debt_repaid_after_cents' => $repaidAfter, 'debt_status_after' => $repaidAfter === $total ? 'settled' : 'partial',
            'business_date' => $businessDate, 'business_timezone' => 'Asia/Shanghai',
            'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now,
            'status' => 'processing', 'version' => 1, 'add_time' => $now, 'update_time' => $now,
        ]);
        if ($recordId <= 0) throw self::failure('debt_repayment_insert_failed');
        $balanceFacts = [];
        if ($balanceCents > 0) {
            $before = (new CashierV3MemberBalanceProvider())->lockSnapshotInTx((int)$authority['member_id'], $operator, $dataScope);
            $mutation = (new CashierV3MemberBalanceWriterAdapter())->deductSalesDebtInTx([
                'memberId' => (int)$authority['member_id'], 'expectedVersion' => (int)$before['accountVersion'],
                'amountCents' => $balanceCents, 'sourceOrderId' => $recordId,
                'commandIdempotencyKey' => $commandKey,
                'paymentMode' => $cashCents > 0 ? 'combined' : 'balance_only',
            ], $operator, $dataScope);
            // Historical cents may remain in the account, but a new deduction cannot contain fractional yuan.
            if ($mutation['deductedPrincipalCents'] % 100 !== 0 || $mutation['deductedGiftCents'] % 100 !== 0
                || (int)$mutation['deductedTotalCents'] !== $balanceCents) throw self::failure('debt_repayment_balance_split_invalid');
            $balanceFacts[] = [
                'factId' => 'DRB-' . substr(hash('sha256', $repaymentId), 0, 40), 'naturalKey' => 'debt_repayment:balance:' . $repaymentId,
                'factVersion' => 1, 'reversalOf' => '', 'status' => 'effective', 'sourceLineId' => $repaymentId . ':balance',
                'balanceChangeType' => 'debt_repayment', 'balanceAccountId' => (string)$mutation['accountId'],
                'accountVersion' => (int)$mutation['accountVersionAfter'],
                'principalDeltaCents' => -(int)$mutation['deductedPrincipalCents'], 'bonusDeltaCents' => -(int)$mutation['deductedGiftCents'],
                'principalAfterCents' => (int)$mutation['after']['principalCents'], 'bonusAfterCents' => (int)$mutation['after']['giftCents'],
            ];
        }
        $itemCashAmounts = CashierV3SalesDebtBalanceServices::freezeInTx($dataScope->tenantId(), $repaymentId, $debt, $items, $itemTargets, $personnel, $balanceCents);
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
            'business_date' => $businessDate, 'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now,
            'aggregate_name_snapshot' => $repaymentNo, 'store_name_snapshot' => $dimensions['storeName'],
            'payload' => ['contractVersion' => self::SUBMIT_CONTRACT_VERSION, 'debtId' => $debtId, 'amountCents' => $amount, 'salesOrderId' => (string)$authority['sales_order_id'], 'salespeopleSnapshotFingerprint' => hash('sha256', json_encode($selectedSalespeople, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))],
        ]);
        $cashPayments = array_values(array_filter($payments, static fn(array $p): bool => $p['paymentMethod'] !== CashierV3SalesDebtBalanceServices::METHOD));
        $this->persistFactsInTx($cashPayments, $authority, $items, $itemTargets, $personnel, $selectedSalespeople, $repaymentId, $repaymentNo, $requestId, $cashCents, $commandKey, $event, $dimensions, $operator, $dataScope, $now, $businessDate, $businessSource, $balanceFacts, $itemCashAmounts);
        // 人员归属与补交收款同事务生效，失败时整笔补交回滚；轮次不记新到店。
        (new CashierV3SupplementAttributionServices())->persistInitialInTx(
            $repaymentId, $repaymentNo, (int)$authority['member_id'], $businessDate, $cashCents,
            (array)($checkoutSnapshot['guideSelections'] ?? []),
            (array)($checkoutSnapshot['salesManagerSelections'] ?? []),
            $commandKey, (string)$event['event_no'], $operator, $dataScope
        );
        if ($repaidAfter === $total) {
            (new CustomerLifecycleFactServices())->recordDebtCompletionInTx(
                $dataScope->tenantId(), (string)$authority['sales_order_id'], $now
            );
        }
        // 唯一持久化结果是成功的补交事实；不读取或更新旧结账草稿。
        // 事实与欠款更新在同一事务，失败会整体回滚。
        if ((int)Db::name('cashier_v3_debt_repayment')->where('id', $recordId)
            ->update(['status' => 'succeeded', 'update_time' => $now]) !== 1) {
            throw self::failure('debt_repayment_terminal_update_failed');
        }
        return $this->successResult($repaymentId, $repaymentNo, $amount, false);
    }

    /**
     * Finalize a recharge-debt browser snapshot through its dedicated domain
     * contract; no checkout request or draft is a prerequisite.
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
        $checkoutSnapshot = is_array($payload['checkoutSnapshot'] ?? null) ? $payload['checkoutSnapshot'] : null;
        if ($checkoutSnapshot === null
            || trim((string)($payload['checkoutRequestId'] ?? '')) !== ''
            || array_key_exists('checkoutRequestVersion', $payload)) {
            throw self::failure('recharge_repayment_checkout_snapshot_required');
        }
        // 充值欠款也只在最终事务中验证页面快照及当前欠款/余额。
        return $this->submitRechargeDebtSnapshotInTx($scope, $checkoutSnapshot, $operator, $dataScope);
    }

    /**
     * Finalize one recharge-debt browser snapshot without any editing request.
     * The browser supplies intent only; current debt and balance are locked by
     * the existing recharge domain service inside the caller's transaction.
     */
    private function submitRechargeDebtSnapshotInTx(array $scope, array $snapshot, CashierV3OperatorScope $operator, CashierV3DataScopeContext $dataScope): array
    {
        $source = is_array($snapshot['sourceDocument'] ?? null) ? $snapshot['sourceDocument'] : [];
        if ((string)($snapshot['businessType'] ?? '') !== 'debt_repayment'
            || ($snapshot['rechargeDebt'] ?? false) !== true
            || (string)($source['type'] ?? '') !== 'debt_repayment') {
            throw self::failure('recharge_repayment_checkout_snapshot_source_invalid');
        }
        $debtId = self::positiveInt($source['id'] ?? null, 'debt_id');
        $memberId = self::positiveInt($snapshot['memberId'] ?? null, 'member_id');
        $amount = self::inputMoneyCents($snapshot['repaymentAmount'] ?? null, 'repayment_amount');
        $commandKey = trim((string)($scope['idempotency_key'] ?? ''));
        if ($commandKey === '') throw self::failure('recharge_repayment_idempotency_missing');
        $saleLines = array_values(array_filter((array)($snapshot['lines'] ?? []),
            static function ($line): bool { return is_array($line) && (string)($line['lineRole'] ?? '') === 'sale'; }));
        if (count($saleLines) !== 1 || (int)($saleLines[0]['sourceId'] ?? 0) !== $debtId
            || (int)($saleLines[0]['lineAmountCents'] ?? -1) !== $amount
            || (int)($saleLines[0]['debtAmountCents'] ?? -1) !== 0) {
            throw self::failure('recharge_repayment_checkout_snapshot_amount_invalid');
        }
        $payments = $this->paymentLinesFromCheckoutSnapshot($snapshot, $amount, $debtId, $memberId, false);
        // 重放校验奖励本身，不因来源后来审计更正而覆盖已记下的本次奖励。
        $rewardAmountCents = CashierV3RepaymentReward::cents($snapshot['source']['rewardAmountCents'] ?? 0);
        $sourceColumns = self::sourceSnapshotColumns(is_array($snapshot['source'] ?? null) ? $snapshot['source'] : []);
        // A confirmed command can be queried or retried with the same key. Do
        // not re-evaluate its now-changed debt/balance as if it were a new sale.
        $existing = (array)Db::name('cashier_v3_recharge_debt_repayment')
            ->where('tenant_id', $dataScope->tenantId())->where('store_id', $operator->storeId())
            ->where('command_idempotency_key', $commandKey)->lock(true)->find();
        if ($existing) {
            if ((string)($existing['status'] ?? '') !== 'succeeded'
                || (int)($existing['debt_id'] ?? 0) !== $debtId
                || (int)($existing['member_id'] ?? 0) !== $memberId
                || (int)($existing['reward_amount_cents'] ?? 0) !== $rewardAmountCents
                || (int)($existing['amount_cents'] ?? 0) !== $amount
                || (string)($existing['business_date'] ?? '') !== (string)($snapshot['businessDate'] ?? '')) {
                throw self::failure('recharge_repayment_idempotency_conflict');
            }
            // 订单中心允许事后更正来源；已更正单的幂等重放不能用当前来源
            // 否决原收款命令。未更正的新单仍按最初确认快照校验。
            if ((int)($existing['business_source_primary_id'] ?? 0) > 0
                && !$this->repaymentSourceWasCorrected((string)$existing['repayment_id'], $dataScope->tenantId())) {
                foreach ($sourceColumns as $column => $value) {
                    if ((string)($existing[$column] ?? '') !== (string)$value) {
                        throw self::failure('recharge_repayment_idempotency_conflict');
                    }
                }
            }
            $savedPayments = Db::name('cashier_v3_recharge_debt_repayment_payment')
                ->where('repayment_id', (string)$existing['repayment_id'])
                ->order('payment_line_no asc')->lock(true)->select()->toArray();
            if (count($savedPayments) !== count($payments)) throw self::failure('recharge_repayment_idempotency_conflict');
            foreach ($payments as $index => $payment) {
                $saved = (array)$savedPayments[$index];
                if ((string)($saved['payment_method'] ?? '') !== (string)$payment['paymentMethod']
                    || (int)($saved['amount_cents'] ?? 0) !== (int)$payment['amountCents']
                    || (string)($saved['collection_reference_snapshot'] ?? '') !== (string)$payment['externalTransactionNo']
                    || (string)($saved['remark_snapshot'] ?? '') !== (string)$payment['remark']) {
                    throw self::failure('recharge_repayment_idempotency_conflict');
                }
            }
            return $this->rechargeSnapshotResult($existing, true);
        }
        $balance = (new CashierV3MemberBalanceProvider())->lockSnapshotInTx($memberId, $operator, $dataScope);
        $domainScope = $scope;
        $domainScope['payload'] = [
            'memberId' => $memberId, 'debtId' => $debtId,
            'amount' => (string)intdiv($amount, 100),
            'businessDate' => (string)($snapshot['businessDate'] ?? ''),
            'businessSource' => is_array($snapshot['source'] ?? null) ? $snapshot['source'] : [],
            'balanceVersion' => (int)$balance['accountVersion'],
            'salespersonAllocations' => is_array($snapshot['salespersonAllocations'] ?? null)
                ? array_values($snapshot['salespersonAllocations']) : [],
            'guideSelections' => is_array($snapshot['guideSelections'] ?? null)
                ? array_values($snapshot['guideSelections']) : [],
            'salesManagerSelections' => is_array($snapshot['salesManagerSelections'] ?? null)
                ? array_values($snapshot['salesManagerSelections']) : [],
            'paymentLines' => array_map(static function (array $line): array {
                return ['paymentMethod' => $line['paymentMethod'],
                    'amount' => (string)intdiv((int)$line['amountCents'], 100),
                    'collectionReference' => $line['externalTransactionNo'], 'remark' => $line['remark']];
            }, $payments),
        ];
        $result = (new CashierV3RechargeDebtRepaymentServices())->submitInTx($domainScope);
        $repayment = (array)($result['data']['repayment'] ?? []);
        if (trim((string)($repayment['repaymentId'] ?? '')) === '') {
            throw self::failure('recharge_repayment_result_missing');
        }
        return $this->rechargeSnapshotResult([
            'repayment_id' => $repayment['repaymentId'], 'repayment_no' => $repayment['repaymentNo'] ?? '',
            'amount_cents' => $amount,
        ], false);
    }

    /** Normalize direct recharge settlement to the same terminal receipt as sales debt. */
    private function rechargeSnapshotResult(array $row, bool $replayed): array
    {
        $id = (string)($row['repayment_id'] ?? '');
        $no = (string)($row['repayment_no'] ?? '');
        return ['data' => ['debtRepayment' => [
            'contractVersion' => self::SUBMIT_CONTRACT_VERSION,
            'repaymentId' => $id, 'repaymentNo' => $no,
            'amount' => self::money((int)($row['amount_cents'] ?? 0)),
            'status' => 'succeeded', 'replayed' => $replayed,
        ]], 'business_no' => $no, 'touched' => ['member_balance'],
            'return_root_state' => true, 'message' => '充值欠款补交成功。'];
    }

    /** Query a completed repayment by its command key; historical facts remain readable without reopening drafts. */
    public function query(array $payload, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): array
    {
        $key = trim((string)($payload['originalIdempotencyKey'] ?? $payload['idempotencyKey'] ?? ''));
        if ($key === '') throw self::failure('debt_repayment_query_identity_missing');
        $row = (array)Db::name('cashier_v3_debt_repayment')->where('tenant_id', $scope->tenantId())
            ->where('store_id', $operator->storeId())->where('command_idempotency_key', $key)->find();
        if ($row && (string)($row['status'] ?? '') === 'succeeded') {
            return $this->successResult((string)$row['repayment_id'], (string)$row['repayment_no'],
                (int)$row['repayment_amount_cents'], true);
        }
        $rechargeRow = (array)Db::name('cashier_v3_recharge_debt_repayment')->where('tenant_id', $scope->tenantId())
            ->where('store_id', $operator->storeId())->where('command_idempotency_key', $key)->find();
        if ($rechargeRow && (string)($rechargeRow['status'] ?? '') === 'succeeded') {
            return $this->rechargeSnapshotResult($rechargeRow, true);
        }
        throw self::failure('debt_repayment_query_not_found');
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

    private function lockDebtAuthority(int $debtId, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): array
    {
        $debt = (array)Db::name('store_debt')->where('id', $debtId)->lock(true)->find();
        $authority = (array)Db::name('cashier_v3_debt_authority')->where('debt_id', $debtId)->lock(true)->find();
        $sourceStoreId = (int)($authority['store_id'] ?? 0);
        $salesOrderRecordId = (int)($authority['sales_order_record_id'] ?? 0);
        $isMigrationAuthority = $salesOrderRecordId === 0;
        if (!$authority || !$debt || (string)$authority['tenant_id'] !== $scope->tenantId()
            || $sourceStoreId <= 0 || (int)$debt['store_id'] !== $sourceStoreId
            || (!CashierV3CrossStoreEntitlementPolicy::enabled() && $sourceStoreId !== $operator->storeId())
            || (int)$authority['member_id'] !== (int)$debt['uid']
            || trim((string)($authority['sales_order_id'] ?? '')) === ''
            || trim((string)($authority['sales_order_no_snapshot'] ?? '')) === ''
            || (!$isMigrationAuthority && (int)$debt['order_id'] !== $salesOrderRecordId)
            || ($isMigrationAuthority && (int)$debt['order_id'] <= 0)) {
            throw self::failure('debt_repayment_v3_authority_missing');
        }
        $order = $salesOrderRecordId > 0
            ? (array)Db::name('cashier_v3_sales_order')->where('id', $salesOrderRecordId)
                ->where('order_id', (string)$authority['sales_order_id'])->where('tenant_id', $scope->tenantId())->lock(true)->find()
            : [];
        $items = Db::name('store_debt_item')->where('debt_id', $debtId)->order('id asc')->lock(true)->select()->toArray();
        // cashier_v3_debt_authority is the immutable mapping created with the
        // debt. Historical migration rows can legitimately have a zero order
        // record ID and no cashier_v3_sales_order read projection. In that
        // case the locked debt header, authority map and item totals are the
        // sole latest snapshot.
        // When the projection remains available, it is still an extra
        // consistency check and any disagreement blocks the repayment.
        if (!$items || ($order && ((int)$order['member_id'] !== (int)$authority['member_id']
            || (int)$order['store_id'] !== $sourceStoreId))) {
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
                || (int)$row['store_id'] !== $sourceStoreId
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

        // 迁移欠款的权威业务数据是 store_debt + debt_authority + debt_item。
        // 早期迁移不会补造 V3 销售行或销售业绩事实，因此不能把“没有
        // 投影行”误判为“没有欠款”。保留空的原销售行标识，后续只写本次
        // 补交的收款事实，不虚构一条原销售行收款分摊。
        if ((int)($authority['sales_order_record_id'] ?? 0) === 0 && !$lines) {
            $personnel = [];
            foreach ($items as $item) {
                $itemId = (int)($item['id'] ?? 0);
                if ($itemId <= 0) {
                    throw self::failure('debt_repayment_item_authority_invalid');
                }
                $personnel[$itemId] = [
                    'authority' => [
                        'order_line_id' => '',
                        'checkout_line_id' => '',
                        'snapshot_fingerprint' => hash('sha256', json_encode([
                            'migrationDebtWithoutV3SaleLine',
                            $itemId,
                            (int)($item['debt_id'] ?? 0),
                            (string)($item['debt_amount'] ?? ''),
                        ], JSON_UNESCAPED_SLASHES)),
                    ],
                    'salespeople' => [],
                ];
            }
            return $personnel;
        }

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

    /** Keep the five queryable order columns aligned with the checkout source used by payment facts. */
    private static function sourceSnapshotColumns(array $source): array
    {
        return [
            'business_source_primary_id' => (int)($source['primarySourceId'] ?? 0),
            'business_source_primary_name_snapshot' => (string)($source['primarySourceNameSnapshot'] ?? ''),
            'business_source_secondary_id' => (int)($source['secondarySourceId'] ?? 0),
            'business_source_secondary_name_snapshot' => (string)($source['secondarySourceNameSnapshot'] ?? ''),
            'business_source_label_snapshot' => (string)($source['displayNameSnapshot'] ?? ''),
        ];
    }

    /** 来源更正不改变历史收款命令；审计行是识别合法事后更正的唯一依据。 */
    private function repaymentSourceWasCorrected(string $repaymentId, string $tenantId): bool
    {
        return Db::name('cashier_v3_order_attribution_change')->where('tenant_id', $tenantId)
            ->where('record_type', 'supplement')->where('record_id', $repaymentId)
            ->where('field_key', 'source')->count() > 0;
    }

    /** Read collection rows from the one browser checkout snapshot. */
    private function paymentLinesFromCheckoutSnapshot(array $snapshot, int $expected, int $debtId, int $memberId, bool $allowBalance = true): array
    {
        if ((string)($snapshot['businessType'] ?? '') !== 'debt_repayment'
            || (int)($snapshot['memberId'] ?? 0) !== $memberId) {
            throw self::failure('debt_repayment_checkout_snapshot_identity_invalid');
        }
        $sourceDocument = is_array($snapshot['sourceDocument'] ?? null) ? $snapshot['sourceDocument'] : [];
        if ((string)($sourceDocument['type'] ?? '') !== 'debt_repayment'
            || (string)($sourceDocument['id'] ?? '') !== (string)$debtId) {
            throw self::failure('debt_repayment_checkout_snapshot_source_invalid');
        }
        $rows = [];
        $balanceSeen = false;
        foreach ((array)($snapshot['lines'] ?? []) as $line) {
            if (!is_array($line)) continue;
            if ((string)($line['lineRole'] ?? '') === 'balance_payment') {
                if (!$allowBalance || !empty($snapshot['rechargeDebt']) || $balanceSeen) throw self::failure('debt_repayment_balance_not_allowed');
                $balanceSeen = true;
                $rows[] = ['paymentMethod' => CashierV3SalesDebtBalanceServices::METHOD, 'paymentMethodName' => '余额支付',
                    'amountCents' => self::inputMoneyCents($line['amount'] ?? null, 'balance_amount'), 'externalTransactionNo' => '', 'remark' => ''];
                continue;
            }
            if ((string)($line['lineRole'] ?? '') !== 'payment') continue;
            $method = trim((string)($line['method'] ?? $line['paymentMethod'] ?? ''));
            $rawAmount = $line['amount'] ?? null;
            $amount = self::inputMoneyCents($rawAmount, 'checkout_snapshot_payment_amount');
            if (!in_array($method, self::PAYMENT_METHODS, true) || $amount <= 0) {
                throw self::failure('debt_repayment_checkout_snapshot_payment_invalid');
            }
            $rows[] = [
                'paymentMethod' => $method,
                'paymentMethodName' => (new CashierV3BusinessConfigServices())->resolveAccountingMethodSnapshot($method, true)['displayNameSnapshot'],
                'amountCents' => $amount,
                'externalTransactionNo' => trim((string)($line['externalTransactionNo'] ?? $line['externalTradeNo'] ?? '')),
                'remark' => trim((string)($line['remark'] ?? $line['note'] ?? '')),
            ];
        }
        if ($rows === []) throw self::failure('debt_repayment_payment_missing');
        $sum = array_sum(array_map(static function (array $line): int { return (int)$line['amountCents']; }, $rows));
        if ($sum !== $expected) throw self::failure('debt_repayment_payment_total_invalid');
        return $rows;
    }

    private function persistFactsInTx(array $payments, array $authority, array $items, array $itemTargets, array $personnel, array $selectedSalespeople, string $repaymentId, string $repaymentNo, string $requestId, int $amount, string $commandKey, array $event, array $dimensions, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope, int $now, string $businessDate, array $businessSource = [], array $balanceFacts = [], array $itemCashAmounts = []): void
    {
        $ids = new CashierV3DebtRepaymentIdFactory($this->secret()); $paymentFacts = [];
        foreach ($payments as $index => $payment) {
            $line = $repaymentId . ':payment:' . ($index + 1);
            $paymentFacts[] = ['factId'=>$ids->factId('payment',$repaymentId,$line),'naturalKey'=>'debt_repayment:payment:'.$repaymentId.':'.($index+1),'factVersion'=>1,'reversalOf'=>'','status'=>'effective','sourceLineId'=>$line,'paymentMethod'=>$payment['paymentMethod'],'paymentAuthorityKey'=>$repaymentId.':'.($index+1),'collectionReference'=>$payment['externalTransactionNo'],'amountCents'=>$payment['amountCents']];
        }
        $performanceFacts = $this->performanceFacts($selectedSalespeople, $repaymentId, $amount, $ids);
        $plan = CashierV3CheckoutFactPlanV1::fromInternalAuthority([
            'contractVersion'=>CashierV3CheckoutFactPlanV1::CONTRACT_VERSION,'commandIdempotencyKey'=>$commandKey,
            'context'=>['tenantId'=>$scope->tenantId(),'tenantNameSnapshot'=>'','organizationId'=>$operator->organizationId(),'organizationNameSnapshot'=>$dimensions['organizationName'],'organizationPathSnapshot'=>$dimensions['organizationPath'],'storeId'=>$operator->storeId(),'storeNameSnapshot'=>$dimensions['storeName'],'memberId'=>(int)$authority['member_id'],'memberNameSnapshot'=>$dimensions['memberName'],'operatorId'=>$operator->operatorId(),'operatorNameSnapshot'=>$dimensions['operatorName'],'businessDate'=>$businessDate,'businessTimezone'=>'Asia/Shanghai','occurredAt'=>$now,'settledAt'=>$now,'recordedAt'=>$now,'checkoutRequestId'=>$requestId,'orderId'=>$repaymentId,'orderNoSnapshot'=>$repaymentNo,'sourceDocumentType'=>'debt_repayment','businessEventNo'=>(string)$event['event_no'],'businessSourcePrimaryId'=>(int)($businessSource['primarySourceId'] ?? 0),'businessSourcePrimaryNameSnapshot'=>(string)($businessSource['primarySourceNameSnapshot'] ?? ''),'businessSourceSecondaryId'=>(int)($businessSource['secondarySourceId'] ?? 0),'businessSourceSecondaryNameSnapshot'=>(string)($businessSource['secondarySourceNameSnapshot'] ?? ''),'businessSourceLabelSnapshot'=>(string)($businessSource['displayNameSnapshot'] ?? '')],
            'saleFacts'=>[],'paymentFacts'=>$paymentFacts,'balanceFacts'=>$balanceFacts,'performanceFacts'=>$performanceFacts,
        ]);
        try { (new ThinkPhpCashierV3CheckoutFactRepository())->persistInTx($plan,$operator,$scope); }
        catch (\Throwable $e) { throw self::failure('debt_repayment_fact_write_failed',['cause'=>get_class($e)]); }
        try {
            $this->persistOriginalSalePaymentAllocationsInTx(
                $paymentFacts, $authority, $items, $itemTargets, $personnel,
                $commandKey, $event, $requestId, $dimensions, $operator, $scope, $now, $businessDate, $itemCashAmounts
            );
        } catch (\Throwable $e) {
            \think\facade\Log::error('[cashier_v3_debt_repayment_payment_allocation_failed] ' . json_encode([
                'exceptionClass' => get_class($e),
                'exceptionMessage' => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            throw self::failure('debt_repayment_payment_allocation_write_failed', ['cause' => get_class($e)]);
        }
    }

    /**
     * A repayment belongs to the original debt-item lines, not to the synthetic
     * repayment document line.  Preserve that relationship in the same
     * immutable allocation fact used by every item-level cash report.
     */
    private function persistOriginalSalePaymentAllocationsInTx(array $paymentFacts, array $authority, array $items, array $itemTargets, array $personnel, string $commandKey, array $event, string $checkoutRequestId, array $dimensions, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope, int $now, string $businessDate, array $itemCashAmounts): void
    {
        // Balance-only settlements have no cash fact or cash allocation to fabricate.
        if ($paymentFacts === []) return;
        // 历史迁移没有对应的 V3 原销售行时，只能记录本次已经实际成功的
        // 收款事实；禁止凭空创建原销售行／销售分摊事实。若迁移行已有
        // 冻结销售行，仍继续走下方同一分摊校验。
        if ((int)($authority['sales_order_record_id'] ?? 0) === 0) {
            $hasOriginalLine = false;
            foreach ($items as $item) {
                $itemId = (int)($item['id'] ?? 0);
                if (trim((string)($personnel[$itemId]['authority']['order_line_id'] ?? '')) !== '') {
                    $hasOriginalLine = true;
                    break;
                }
            }
            if (!$hasOriginalLine) {
                return;
            }
        }
        $lineBases = [];
        foreach ($items as $item) {
            $itemId = (int)($item['id'] ?? 0);
            $allocated = (int)($itemCashAmounts[$itemId] ?? -1);
            $lineId = trim((string)($personnel[$itemId]['authority']['order_line_id'] ?? ''));
            if ($itemId <= 0 || $allocated < 0 || $lineId === '') {
                throw self::failure('debt_repayment_payment_allocation_authority_invalid');
            }
            if ($allocated > 0) $lineBases[$lineId] = (int)($lineBases[$lineId] ?? 0) + $allocated;
        }
        if (!$lineBases) throw self::failure('debt_repayment_payment_allocation_empty');
        if (array_sum($lineBases) !== array_sum(array_map(static function (array $payment): int { return (int)$payment['amountCents']; }, $paymentFacts))) {
            throw self::failure('debt_repayment_payment_allocation_total_invalid');
        }

        $saleRows = Db::name('cashier_v3_sale_fact')->where('tenant_id', $scope->tenantId())
            // Original sale belongs to its issuing store even when another store takes repayment.
            ->where('store_id', (int)$authority['store_id'])->where('order_id', (string)$authority['sales_order_id'])
            ->whereIn('source_line_id', array_keys($lineBases))->where('fact_direction', 'forward')->where('status', 'effective')
            ->field('fact_id,source_line_id,sale_amount_cents,debt_amount_cents')->order('id asc')->lock(true)->select()->toArray();
        $salesByLine = [];
        foreach ($saleRows as $sale) {
            $lineId = (string)$sale['source_line_id'];
            if ($lineId === '' || isset($salesByLine[$lineId])) throw self::failure('debt_repayment_payment_allocation_sale_ambiguous');
            $salesByLine[$lineId] = $sale;
        }
        if (count($salesByLine) !== count($lineBases)) throw self::failure('debt_repayment_payment_allocation_sale_missing');

        $sales = [];
        foreach ($lineBases as $lineId => $base) {
            $sale = (array)$salesByLine[$lineId];
            $sale['allocation_base_amount_cents'] = $base;
            $sales[] = $sale;
        }
        $normalizedPayments = [];
        foreach ($paymentFacts as $payment) {
            $normalizedPayments[] = [
                'fact_id' => (string)$payment['factId'], 'payment_method' => (string)$payment['paymentMethod'],
                'amount_cents' => (int)$payment['amountCents'], 'fact_direction' => 'forward', 'status' => 'effective',
                'command_idempotency_key' => $commandKey,
            ];
        }
        (new StoreReportPaymentSaleAllocationFactServices())->persistDebtRepaymentInTx([
            'tenant_id' => $scope->tenantId(), 'organization_id' => $operator->organizationId(),
            'store_id' => $operator->storeId(), 'member_id' => (int)$authority['member_id'],
            'order_id' => (string)$authority['sales_order_id'], 'order_no_snapshot' => (string)$authority['sales_order_no_snapshot'],
            'business_date' => $businessDate, 'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now,
            'business_event_no' => (string)$event['event_no'], 'checkout_request_id' => $checkoutRequestId,
        ], $normalizedPayments, $sales);
    }

    private function performanceFacts(array $selectedSalespeople, string $repaymentId, int $amount, CashierV3DebtRepaymentIdFactory $ids): array
    {
        if ($amount === 0) return [];
        $facts=[]; $external=0;
        $sourceLine=$repaymentId.':performance';
        foreach($selectedSalespeople as $person){
            $share=(int)$person['amountCents'];
            if(in_array((string)$person['employeeTypeCodeSnapshot'],['partner','outsourced'],true))$external+=$share;
            $line=$sourceLine.':salesperson:'.(int)$person['employeeId'].':'.(int)$person['sequence'];
            // 补交选人快照已冻结售前标记；业绩事实必须保留它，供编辑回填及审计读取。
            $roleSnapshot = 'salesperson:' . (!empty($person['isPreSale']) ? 'presale' : 'postsale');
            $facts[]=['factId'=>$ids->factId('performance',$repaymentId,$line),'naturalKey'=>'debt_repayment:performance:'.$line,'factVersion'=>1,'reversalOf'=>'','status'=>'effective','sourceLineId'=>$sourceLine,'performanceType'=>'sales_performance_allocated','employeeId'=>(int)$person['employeeId'],'employeeNameSnapshot'=>(string)$person['name'],'employeeTypeSnapshot'=>(string)$person['employeeTypeCodeSnapshot'],'employeeTypeAuthorityVersion'=>(int)$person['employeeTypeAuthorityVersion'],'roleSnapshot'=>$roleSnapshot,'allocationWeightNumerator'=>(int)$person['allocationWeight'],'allocationWeightDenominator'=>100,'allocationBaseAmountCents'=>$amount,'amountCents'=>$share,'ruleCodeSnapshot'=>'debt_repayment_selected_salesperson_allocation','ruleNameSnapshot'=>'销售欠款补交所选销售人分配','ruleVersionSnapshot'=>'v1'];
        }
        $line=$repaymentId.':actual';
        $facts[]=['factId'=>$ids->factId('performance',$repaymentId,$line),'naturalKey'=>'debt_repayment:actual:'.$repaymentId,'factVersion'=>1,'reversalOf'=>'','status'=>'effective','sourceLineId'=>$line,'performanceType'=>'actual_performance_recorded','employeeId'=>0,'employeeNameSnapshot'=>'','employeeTypeSnapshot'=>'','employeeTypeAuthorityVersion'=>0,'roleSnapshot'=>'','allocationWeightNumerator'=>0,'allocationWeightDenominator'=>1,'allocationBaseAmountCents'=>$amount,'amountCents'=>$amount-$external,'ruleCodeSnapshot'=>'debt_repayment_cash_performance','ruleNameSnapshot'=>'销售欠款补交实际业绩','ruleVersionSnapshot'=>'v1'];
        return $facts;
    }

    private function resolveRepaymentSalespeople(array $allocations, int $amountCents, CashierV3OperatorScope $operator): array
    {
        if (!$allocations) return [];
        if ($amountCents < 0 || $amountCents % 100 !== 0 || count($allocations) > 20) {
            throw self::failure('debt_repayment_salespeople_invalid');
        }
        $requested=[];$otherStaffIds=[];
        foreach($allocations as $index=>$row){
            if(!is_array($row))throw self::failure('debt_repayment_salespeople_invalid');
            $staffId=(int)($row['staffId']??0);$weight=(int)($row['allocationWeight']??0);
            if($staffId<=0||$weight<=0||$weight>100||isset($requested[$staffId]))throw self::failure('debt_repayment_salespeople_invalid');
            // 虚拟工号只用于同集团其他销售人；来源标志和任职均在事务中重验。
            $source=trim((string)($row['personnelSource']??'store'));
            if(CashierV3PersonnelIdentity::isOrganizationStaffId($staffId)){
                if($source!=='other')throw self::failure('debt_repayment_salesperson_source_invalid');
                $otherStaffIds[]=$staffId;
            }elseif($source!=='store')throw self::failure('debt_repayment_salesperson_source_invalid');
            [$performanceAmountManual,$performanceAmountCents]=self::manualPerformanceAmount($row);
            $requested[$staffId]=['allocationWeight'=>$weight,'isPreSale'=>!empty($row['isPreSale'])||!empty($row['marked']),'performanceAmountManual'=>$performanceAmountManual,'performanceAmountCents'=>$performanceAmountCents,'sequence'=>$index+1];
        }
        $staffIds=array_values(array_diff(array_keys($requested),$otherStaffIds));sort($staffIds,SORT_NUMERIC);
        $rows=$staffIds===[]?[]:Db::name('system_store_staff')->alias('ss')->join('employee e','e.id=ss.employee_id')
            ->leftJoin('staff_job_position sjp','sjp.staff_id=ss.id AND sjp.status=1 AND sjp.is_del=0 AND sjp.end_time=0')
            ->leftJoin('position p','p.id=sjp.position_id AND p.status=1')
            ->whereIn('ss.id',$staffIds)->where('ss.store_id',$operator->storeId())->where('ss.status',1)->where('ss.is_del',0)
            ->where('ss.cashier_salesperson_enabled',1)->where('e.status',1)->where('e.is_del',0)
            ->field('ss.id,ss.staff_name,ss.employee_id,e.name,e.employment_type_code,e.employment_type_version,sjp.position_id,p.name as position_name,p.performance_independent')->lock(true)->select()->toArray();
        if(count($rows)!==count($staffIds))throw self::failure('debt_repayment_salesperson_ineligible');
        $otherAuthority=new CashierV3QueryEntitySelectorServices();
        foreach($otherStaffIds as $staffId){
            $other=$otherAuthority->lockOrganizationSalesperson($staffId,$operator);
            if($other===[])throw self::failure('debt_repayment_salesperson_ineligible');
            $rows[]=$other;
        }
        $by=[];$seenEmployeeIds=[];
        foreach($rows as $row){
            $employeeId=(int)($row['employee_id']??0);
            if($employeeId<=0||isset($seenEmployeeIds[$employeeId]))throw self::failure('debt_repayment_salesperson_duplicate');
            $seenEmployeeIds[$employeeId]=true;$by[(int)$row['id']]=$row;
        }
        if(count($by)!==count($requested))throw self::failure('debt_repayment_salesperson_ineligible');
        $result=[];$groups=[];$amountYuan=intdiv($amountCents,100);
        foreach($requested as $staffId=>$selection){
            $row=$by[$staffId]??null;if(!$row)throw self::failure('debt_repayment_salesperson_ineligible');
            $type=(string)($row['employment_type_code']??'');$typeVersion=(int)($row['employment_type_version']??0);
            $name=trim((string)($row['name']??''))?:trim((string)($row['staff_name']??''));
            if($name===''||!in_array($type,['internal','partner','outsourced'],true)||$typeVersion<=0)throw self::failure('debt_repayment_salesperson_profile_invalid');
            $sequence=(int)$selection['sequence'];
            $positionId=(int)($row['position_id']??0);
            // 职位“独立核算”是服务端权限资料，不能相信浏览器提交的同名字段。
            $performanceIndependent=(int)($row['performance_independent']??0)===1;
            $groupKey=$performanceIndependent?'independent:'.($positionId>0?$positionId:$staffId):'normal';
            $result[]=['staffId'=>(int)$staffId,'employeeId'=>(int)$row['employee_id'],'name'=>$name,'employeeTypeCodeSnapshot'=>$type,'employeeTypeAuthorityVersion'=>$typeVersion,'allocationWeight'=>(int)$selection['allocationWeight'],'amountCents'=>0,'performanceAmountManual'=>(bool)$selection['performanceAmountManual'],'performanceAmountCents'=>(int)$selection['performanceAmountCents'],'isPreSale'=>(bool)$selection['isPreSale'],'sequence'=>$sequence,'positionId'=>$positionId,'positionName'=>(string)($row['position_name']??''),'performanceIndependent'=>$performanceIndependent,'allocationGroupKey'=>$groupKey];
            $groups[$groupKey][] = count($result)-1;
        }
        foreach($groups as $groupKey=>$indexes){
            $weightTotal=0;foreach($indexes as $index)$weightTotal+=(int)$result[$index]['allocationWeight'];
            // 普通组和每个独立职位组各自按 100% 分配；禁止把独立核算职位
            // 与普通销售人的比例合并校验，否则两个 100% 会被错误拒绝。
            if($weightTotal!==100)throw self::failure('debt_repayment_salespeople_group_weight_invalid',['allocationGroupKey'=>$groupKey]);
            $manualIndexes=array_values(array_filter($indexes,static fn(int $index):bool => !empty($result[$index]['performanceAmountManual'])));
            if($manualIndexes){
                // 手填金额是最终人员业绩，独立于比例和补交金额；未手填的人
                // 仍按原比例自动计算，绝不拿手填值去平衡或覆盖其他人员。
                $autoIndexes=array_values(array_filter($indexes,static fn(int $index):bool => empty($result[$index]['performanceAmountManual'])));
                $autoWeight=0;foreach($autoIndexes as $index)$autoWeight+=(int)$result[$index]['allocationWeight'];
                $autoBase=intdiv($amountYuan*$autoWeight,100)*100;$allocated=0;$lastAuto=$autoIndexes[count($autoIndexes)-1]??null;
                foreach($autoIndexes as $index){
                    $share=$index===$lastAuto?$autoBase-$allocated:intdiv($amountYuan*(int)$result[$index]['allocationWeight'],100)*100;
                    if($share<0||$share%100!==0)throw self::failure('debt_repayment_salespeople_amount_invalid');
                    $allocated+=$share;$result[$index]['amountCents']=$share;
                }
                foreach($manualIndexes as $index)$result[$index]['amountCents']=(int)$result[$index]['performanceAmountCents'];
                continue;
            }
            $allocated=0;$last=$indexes[count($indexes)-1];
            foreach($indexes as $index){
                $share=$index===$last?$amountCents-$allocated:intdiv($amountYuan*(int)$result[$index]['allocationWeight'],100)*100;
                if($share<0||$share%100!==0)throw self::failure('debt_repayment_salespeople_amount_invalid');
                $allocated+=$share;$result[$index]['amountCents']=$share;
            }
            if($allocated!==$amountCents)throw self::failure('debt_repayment_salespeople_amount_invalid');
        }
        return $result;
    }

    private static function normalizedSalespeople(array $rows):array
    {
        $out=[];$weightsByGroup=[];$sequences=[];
        foreach(array_values($rows) as $index=>$row){
            $person=['employeeId'=>(int)($row['employeeId']??0),'name'=>trim((string)($row['name']??'')),'employeeTypeCodeSnapshot'=>trim((string)($row['employeeTypeCodeSnapshot']??'')),'employeeTypeAuthorityVersion'=>(int)($row['employeeTypeAuthorityVersion']??0),'allocationWeight'=>(int)($row['allocationWeight']??0),'sequence'=>(int)($row['sequence']??($index+1))];
            if($person['employeeId']<=0||$person['name']===''||!in_array($person['employeeTypeCodeSnapshot'],['internal','partner','outsourced'],true)||$person['employeeTypeAuthorityVersion']<=0||$person['allocationWeight']<=0||$person['sequence']<=0||isset($sequences[$person['sequence']]))throw self::failure('debt_repayment_personnel_snapshot_invalid');
            $positionId=max(0,(int)($row['positionId']??$row['position_id']??0));
            $independent=!empty($row['performanceIndependent'])||!empty($row['performance_independent']);
            $groupKey=$independent?'independent:'.($positionId>0?$positionId:$person['employeeId']):'normal';
            if($positionId>0){$person['positionId']=$positionId;$person['positionName']=trim((string)($row['positionName']??$row['position_name']??''));}
            if($independent){$person['performanceIndependent']=true;$person['allocationGroupKey']=$groupKey;}
            $sequences[$person['sequence']]=true;$weightsByGroup[$groupKey]=($weightsByGroup[$groupKey]??0)+$person['allocationWeight'];$out[]=$person;
        }
        foreach($weightsByGroup as $weight){if($weight!==100)throw self::failure('debt_repayment_personnel_group_weight_invalid');}
        usort($out,static function(array $a,array $b):int{return $a['sequence']<=>$b['sequence'];});
        return $out;
    }

    private static function manualPerformanceAmount(array $row): array
    {
        $manual=!empty($row['performanceAmountManual'])||!empty($row['performance_amount_manual']);
        if(!$manual)return [false,0];
        $raw=$row['performanceAmountCents']??$row['performance_amount_cents']??null;
        if((!is_int($raw)&&!is_string($raw))||preg_match('/^(?:0|[1-9][0-9]*)$/D',trim((string)$raw))!==1)throw self::failure('debt_repayment_salespeople_manual_amount_invalid');
        $cents=(int)$raw;
        if($cents<0||$cents%100!==0)throw self::failure('debt_repayment_salespeople_manual_amount_invalid');
        return [true,$cents];
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
    /** Replayed commands return the committed fact without consulting a prior draft or request version. */
    private function replayedResult(array $row,string $repaymentId,CashierV3OperatorScope $operator,CashierV3DataScopeContext $scope):array
    {
        if ((string)$row['status'] !== 'succeeded'
            || (string)$row['repayment_id'] !== $repaymentId
            || (int)$row['store_id'] !== $operator->storeId()
            || (string)$row['tenant_id'] !== $scope->tenantId()) {
            throw self::failure('debt_repayment_idempotency_conflict');
        }
        return $this->successResult((string)$row['repayment_id'], (string)$row['repayment_no'],
            (int)$row['repayment_amount_cents'], true);
    }
    /** Return only the immutable completed receipt; no draft/version identifier is exposed. */
    private function successResult(string $id, string $no, int $amount, bool $replayed): array
    {
        // Return committed tenders, not the browser's still-editing rows. Result queries and
        // idempotent retries therefore show the same confirmed balance/cash split.
        $collections = Db::name('cashier_v3_debt_repayment_collection')->where('repayment_id', $id)
            ->where('collection_status', 'succeeded')->order('payment_line_no asc')->select()->toArray();
        $resultLines = [];
        foreach ($collections as $collection) {
            $isBalance = (string)$collection['payment_method'] === CashierV3SalesDebtBalanceServices::METHOD;
            $resultLines[] = ['id' => (string)$collection['collection_id'],
                'kind' => $isBalance ? 'balance_deduction' : 'bookkeeping_collection',
                'lineRole' => $isBalance ? 'balance_payment' : 'payment', 'method' => (string)$collection['payment_method'],
                'name' => (string)$collection['payment_method_name_snapshot'], 'amount' => self::money((int)$collection['amount_cents']),
                'status' => 'succeeded', 'canEdit' => false, 'canRemove' => false];
        }
        return ['data' => ['debtRepayment' => ['contractVersion' => self::SUBMIT_CONTRACT_VERSION,
            'repaymentId' => $id, 'repaymentNo' => $no, 'amount' => self::money($amount),
            'paymentResultLines' => $resultLines, 'status' => 'succeeded', 'replayed' => $replayed]],
            'business_no' => $no, 'touched' => ['debt_record'],
            'return_root_state' => true, 'message' => '欠款补交成功。'];
    }
    private static function businessDate($value, int $now): string
    {
        $date = trim((string)$value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1) throw self::failure('debt_repayment_business_date_invalid');
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('Asia/Shanghai'));
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$parsed || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $parsed->format('Y-m-d') !== $date) throw self::failure('debt_repayment_business_date_invalid');
        $today = (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d');
        if ($date > $today) throw self::failure('debt_repayment_business_date_future');
        return $date;
    }
    private static function positiveInt($value,string $field):int{if(is_bool($value)||!is_numeric($value)||(int)$value<=0)throw self::failure('debt_repayment_positive_int_invalid',['field'=>$field]);return (int)$value;}
    /** Business amounts must be explicit whole-yuan integers, never booleans or fractional/coerced values. */
    private static function inputMoneyCents($value,string $field):int{if(!is_int($value)&&!is_string($value))throw self::failure('debt_repayment_money_invalid',['field'=>$field]);$raw=trim((string)$value);if(preg_match('/^[1-9][0-9]*$/D',$raw)!==1||strlen($raw)>10)throw self::failure('debt_repayment_money_invalid',['field'=>$field]);return (int)$raw*100;}
    private static function storedMoneyCents($value,string $field):int{$raw=trim((string)$value);if(preg_match('/^(?:0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?$/D',$raw,$m)!==1)throw self::failure('debt_repayment_stored_money_invalid',['field'=>$field]);[$whole,$fraction]=array_pad(explode('.',$raw,2),2,'');return (int)$whole*100+(int)str_pad($fraction,2,'0');}
    private static function money(int $cents):string{if($cents<0)throw self::failure('debt_repayment_negative_money');return number_format($cents/100,2,'.','');}
    private function secret():string{$secret=$this->serverIdSecret!==''?$this->serverIdSecret:trim((string)config('cashier_v3.checkout_namespace_secret'));if(strlen($secret)<32)throw self::failure('debt_repayment_secret_missing');return $secret;}
    private static function failure(string $reason,array $detail=[]):CashierV3CommandException{return new CashierV3CommandException(CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,'欠款补交资料不完整，请刷新后重试。',CashierV3ResultCode::STATUS_FAILED,array_merge(['reason'=>$reason],$detail));}
}
