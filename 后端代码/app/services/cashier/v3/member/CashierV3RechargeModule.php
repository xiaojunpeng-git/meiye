<?php
declare(strict_types=1);

namespace app\services\cashier\v3\member;

use app\services\cashier\v3\config\CashierV3BusinessConfigServices;
use app\services\cashier\v3\CashierV3ActionDispatcher;
use app\services\cashier\v3\CashierV3BusinessDocumentNumberServices;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\checkout\provider\CashierV3MemberBalanceWriterAdapter;
use app\services\cashier\v3\event\CashierV3BusinessEventExecution;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\fact\CashierV3CheckoutFactIdFactory;
use app\services\cashier\v3\fact\CashierV3CheckoutFactPlanV1;
use app\services\cashier\v3\fact\ThinkPhpCashierV3CheckoutFactRepository;
use app\services\cashier\v3\registry\CashierV3ContextPolicy;
use app\services\cashier\v3\settlement\CashierV3RechargeDebtAuthorityServices;
use think\facade\Db;

/**
 * V3 recharge completion authority.
 *
 * The legacy recharge HTTP endpoint is intentionally not called: it has a
 * read-modify-write balance update outside a transaction. This module keeps
 * the legacy recharge master record as the compatibility source while making
 * its balance, event and fact effects one V3 command transaction.
 */
final class CashierV3RechargeModule
{
    private const ACTION = 'submit-recharge';
    private const PAYMENT_METHODS = [
        'unionpay', 'wechat', 'alipay', 'dianping_voucher',
        'douyin_voucher', 'partner_collection', 'other_collection',
    ];

    public static function install(CashierV3ActionDispatcher $dispatcher): void
    {
        if (!$dispatcher->policies()->has(self::ACTION)) {
            $dispatcher->policies()->register(new CashierV3ContextPolicy(
                self::ACTION,
                ['cashier_workspace', 'member', 'member_balance'],
                [],
                static function (array $payload, array $base): array {
                    $workspaceId = trim((string)($base['session']['workspace_id'] ?? ''));
                    $memberId = trim((string)($payload['memberId'] ?? ''));
                    if ($workspaceId === '' || preg_match('/^[1-9][0-9]*$/D', $memberId) !== 1) {
                        throw CashierV3CommandException::invalidContext(
                            '充值会员或当前工作台版本无效，请刷新后重试。',
                            ['reason' => 'recharge_context_identity_invalid']
                        );
                    }
                    return [
                        'required' => ['cashier_workspace', 'member', 'member_balance'],
                        'identities' => [
                            ['role' => 'cashier_workspace', 'kind' => 'cashier_workspace', 'id' => $workspaceId, 'required' => true],
                            ['role' => 'member', 'kind' => 'member', 'id' => $memberId, 'required' => true],
                            ['role' => 'member_balance', 'kind' => 'member_balance', 'id' => $memberId, 'required' => true],
                        ],
                        'required_read_roles' => ['cashier_workspace', 'member', 'member_balance'],
                        // 充值不修改会员档案或工作台投影；余额才是唯一业务写资源。
                        'required_touched_roles' => ['member_balance'],
                    ];
                },
                ['member_balance'],
                ['cashier_workspace', 'member', 'member_balance'],
                ['cashier_workspace', 'member', 'member_balance']
            ));
        }
        if (!$dispatcher->handlers()->hasCommand(self::ACTION)) {
            $service = new self();
            $dispatcher->handlers()->registerCommand(self::ACTION, static function (array $scope) use ($service): array {
                return $service->submitInTx($scope);
            });
        }
    }

    public function submitInTx(array $scope): array
    {
        $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
        $operator = $scope['operator_scope'] ?? null;
        $dataScope = $scope['data_scope'] ?? null;
        $eventRecorder = $scope['event_recorder'] ?? null;
        $eventExecution = $scope['event_execution'] ?? null;
        if (!$operator instanceof CashierV3OperatorScope || !$dataScope instanceof CashierV3DataScopeContext
            || !$eventRecorder instanceof CashierV3BusinessEventRecorder
            || !$eventExecution instanceof CashierV3BusinessEventExecution) {
            throw self::failure('recharge_command_scope_incomplete', '充值服务尚未就绪，请刷新后重试。');
        }
        $input = $this->normalizeInput($payload);
        $input = $this->normalizeBusinessDate($input);
        $input['businessSource'] = $this->normalizeBusinessSource((array)($scope['business_source'] ?? []));
        $member = $this->lockedMember($input['memberId'], $operator, $dataScope);
        // 套餐金额和赠金必须在服务端按当前有效配置重算，不能信任页面
        // 在准备浮层时取得的历史数值。
        $input = $this->resolveRechargeTerms($input, $member);
        if ($input['debtCents'] > $input['principalCents']) {
            throw self::failure('recharge_debt_exceeds_principal', '欠款金额不能超过本次充值本金。');
        }
        // A recharge debt is not yet a balance credit. Preserve the original
        // principal on the recharge/debt documents, but credit only collected
        // principal so a later repayment has a single, auditable credit path.
        $input['creditedPrincipalCents'] = $input['principalCents'] - $input['debtCents'];
        $input['paymentLines'] = $this->resolvePaymentLines(
            (array)($input['paymentLines'] ?? []),
            $input['creditedPrincipalCents']
        );
        $accountingConfig = new CashierV3BusinessConfigServices();
        foreach ($input['paymentLines'] as $index => $paymentLine) {
            $snapshot = $accountingConfig->resolveAccountingMethodSnapshot((string)$paymentLine['paymentMethod'], true);
            $input['paymentLines'][$index]['paymentMethodNameSnapshot'] = (string)$snapshot['displayNameSnapshot'];
        }
        $preparedSalespeople = $scope['recharge_checkout_salespeople_snapshot'] ?? null;
        $input['salespeople'] = is_array($preparedSalespeople)
            ? $this->normalizePreparedSalespeopleSnapshot($preparedSalespeople)
            : $this->resolveSalespeople(
                (array)($input['salespersonAllocations'] ?? []),
                $input['creditedPrincipalCents'],
                $operator
            );
        $now = time();
        $orderNo = (new CashierV3BusinessDocumentNumberServices())->allocateForSourceInTx(
            $dataScope->tenantId(),
            CashierV3BusinessDocumentNumberServices::RECHARGE,
            'recharge_command',
            (string)$scope['idempotency_key'],
            $input['businessDate'],
            $now
        );
        $rechargeId = (int)Db::name('user_recharge')->insertGetId([
            'order_id' => $orderNo,
            'uid' => $input['memberId'],
            'store_id' => $operator->storeId(),
            'staff_id' => $operator->operatorId(),
            'price' => $this->centsToMoney($input['principalCents']),
            'give_price' => $this->centsToMoney($input['bonusCents']),
            'recharge_type' => count($input['paymentLines']) === 1 ? $input['paymentLines'][0]['paymentMethod'] : 'debt',
            'cash_choose' => 0,
            'channel_type' => (string)($member['user_type'] ?? ''),
            'paid' => 1,
            'pay_time' => $now,
            'add_time' => $now,
            'trade_no' => (string)($input['paymentLines'][0]['collectionReference'] ?? ''),
            'combination_info' => json_encode($input['paymentLines'], JSON_UNESCAPED_UNICODE),
            'staff_choose' => json_encode($this->legacySalespeopleSnapshot($input['salespeople']), JSON_UNESCAPED_UNICODE),
            'debt_amount' => $this->centsToMoney($input['debtCents']),
            'repaid_debt_amount' => '0.00',
            'business_source_primary_id' => (int)$input['businessSource']['primarySourceId'],
            'business_source_primary_name_snapshot' => (string)$input['businessSource']['primarySourceNameSnapshot'],
            'business_source_secondary_id' => (int)$input['businessSource']['secondarySourceId'],
            'business_source_secondary_name_snapshot' => (string)$input['businessSource']['secondarySourceNameSnapshot'],
            'business_source_label_snapshot' => (string)$input['businessSource']['displayNameSnapshot'],
        ]);
        if ($rechargeId <= 0) {
            throw self::failure('recharge_order_create_failed', '充值订单创建失败，本次操作已取消。');
        }

        $debt = (new CashierV3RechargeDebtAuthorityServices())->persistInTx(
            $input,
            $rechargeId,
            $orderNo,
            (string)$scope['idempotency_key'],
            $now,
            $operator,
            $dataScope
        );

        $balances = new CashierV3MemberBalanceWriterAdapter();
        try {
            $balance = $balances->creditRechargeInTx([
                'memberId' => $input['memberId'],
                'expectedVersion' => $input['balanceVersion'],
                'principalCents' => $input['creditedPrincipalCents'],
                'bonusCents' => $input['bonusCents'],
                'sourceRechargeId' => $rechargeId,
                'commandIdempotencyKey' => (string)$scope['idempotency_key'],
            ], $operator, $dataScope);
        } catch (\Throwable $exception) {
            throw self::failure('recharge_balance_credit_failed', '充值余额写入失败，本次操作已取消。', ['cause' => get_class($exception)]);
        }

        // A package gift is a separate authority from the balance credit. It
        // still belongs to this command transaction: a recharge is never
        // successful when only its money has landed but its configured gift
        // cannot be issued exactly once.
        $gifts = ['giftId' => '', 'items' => [], 'issuedCount' => 0, 'replayed' => false];
        if ($input['mode'] === 'package') {
            $gifts = (new CashierV3RechargeGiftIssuanceServices())->issueInTx(
                $rechargeId,
                $orderNo,
                (int)$input['packageId'],
                (int)$input['memberId'],
                $member,
                (string)$scope['idempotency_key'],
                $now,
                $operator,
                $dataScope,
                $eventRecorder,
                $eventExecution,
                (array)($scope['event_contract'] ?? [])
            );
        }

        $event = $eventRecorder->recordInTx($eventExecution, (array)($scope['event_contract'] ?? []), [
            'event_type' => 'recharge.completed',
            'aggregate_type' => 'recharge_order',
            'aggregate_id' => 'RCH:' . $rechargeId,
            'aggregate_version' => 1,
            'event_version' => 1,
            'source_type' => self::ACTION,
            'source_id' => 'RCH:' . $rechargeId,
            'member_id' => $input['memberId'],
            'business_date' => $input['businessDate'],
            'occurred_at' => $now,
            'settled_at' => $now,
            'recorded_at' => $now,
            'aggregate_name_snapshot' => $orderNo,
            'store_name_snapshot' => (string)Db::name('system_store')->where('id', $operator->storeId())->value('name'),
            'payload' => [
                'contractVersion' => 'cashier-v3-recharge-completion-v1',
                'rechargeId' => $rechargeId,
                'rechargeOrderNo' => $orderNo,
                'principalCents' => $input['principalCents'],
                'creditedPrincipalCents' => $input['creditedPrincipalCents'],
                'bonusCents' => $input['bonusCents'],
                'debtCents' => $input['debtCents'],
                'paymentLines' => $input['paymentLines'],
                'salespeople' => $input['salespeople'],
                'balanceLedgerId' => (int)$balance['ledgerId'],
                'rechargeGiftId' => (string)$gifts['giftId'],
                'rechargeGiftIssuedCount' => (int)$gifts['issuedCount'],
            ],
        ]);

        if ((int)$debt['amountCents'] > 0) {
            $eventRecorder->recordInTx($eventExecution, (array)($scope['event_contract'] ?? []), [
                'event_type' => 'debt.recorded',
                'aggregate_type' => 'store_debt',
                'aggregate_id' => (string)$debt['debtNo'],
                'aggregate_version' => 1,
                'event_version' => 1,
                'source_type' => self::ACTION,
                'source_id' => 'RCH:' . $rechargeId,
                'member_id' => $input['memberId'],
                'business_date' => $input['businessDate'],
                'occurred_at' => $now,
                'settled_at' => $now,
                'recorded_at' => $now,
                'aggregate_name_snapshot' => (string)$debt['debtNo'],
                'store_name_snapshot' => (string)Db::name('system_store')->where('id', $operator->storeId())->value('name'),
                'payload' => [
                    'contractVersion' => CashierV3RechargeDebtAuthorityServices::CONTRACT_VERSION,
                    'rechargeId' => $rechargeId,
                    'rechargeOrderNo' => $orderNo,
                    'debtNo' => (string)$debt['debtNo'],
                    'debtAmountCents' => (int)$debt['amountCents'],
                ],
            ]);
        }

        $facts = $this->factPlan(
            $input,
            $operator,
            $dataScope,
            $member,
            $rechargeId,
            $orderNo,
            $now,
            $event,
            $balance,
            (string)$scope['idempotency_key']
        );
        try {
            $factResult = (new ThinkPhpCashierV3CheckoutFactRepository())->persistInTx($facts, $operator, $dataScope);
        } catch (\Throwable $exception) {
            throw self::failure('recharge_fact_write_failed', '充值事实写入失败，本次操作已取消。', ['cause' => get_class($exception)]);
        }
        return [
            'data' => [
                'recharge' => [
                    'rechargeId' => $rechargeId,
                    'rechargeOrderNo' => $orderNo,
                    'principalAmount' => $this->centsToMoney($input['principalCents']),
                    'creditedPrincipalAmount' => $this->centsToMoney($input['creditedPrincipalCents']),
                    'bonusAmount' => $this->centsToMoney($input['bonusCents']),
                    'debtAmount' => $this->centsToMoney($input['debtCents']),
                    'debtNo' => (string)$debt['debtNo'],
                    'paymentLines' => $input['paymentLines'],
                    'salespeople' => $input['salespeople'],
                    'balanceAfter' => $this->centsToMoney((int)$balance['after']['totalCents']),
                    'balanceVersion' => (int)$balance['accountVersionAfter'],
                    'businessEventNo' => (string)$event['event_no'],
                    'factFingerprint' => (string)$factResult['planFingerprint'],
                    'giftId' => (string)$gifts['giftId'],
                    'gifts' => (array)$gifts['items'],
                ],
            ],
            'business_no' => $orderNo,
            'touched' => ['member_balance'],
            'message' => '会员充值成功。',
        ];
    }

    private function lockedMember(int $memberId, CashierV3OperatorScope $operator, CashierV3DataScopeContext $dataScope): array
    {
        $member = (array)Db::name('user')->where('uid', $memberId)->lock(true)->find();
        if (!$member || (int)($member['status'] ?? 0) !== 1 || (int)($member['is_del'] ?? 0) !== 0) {
            throw self::failure('recharge_member_unavailable', '该会员不存在或已停用，不能充值。');
        }
        if (!$dataScope->allowsStore($operator->storeId())
            || !Db::name('store_user')->where('uid', $memberId)->where('store_id', $operator->storeId())->where('status', 1)->lock(true)->find()) {
            throw self::failure('recharge_member_scope_denied', '当前门店没有为该会员充值的权限。');
        }
        return $member;
    }

    private function factPlan(array $input, CashierV3OperatorScope $operator, CashierV3DataScopeContext $dataScope, array $member, int $rechargeId, string $orderNo, int $now, array $event, array $balance, string $idempotencyKey): CashierV3CheckoutFactPlanV1
    {
        $secret = trim((string)config('cashier_v3.checkout_namespace_secret'));
        if (strlen($secret) < 32) {
            throw self::failure('recharge_fact_secret_missing', '充值事实签名服务尚未配置。');
        }
        $orderId = 'RCH:' . $rechargeId;
        $lineId = 'RCH:' . $rechargeId . ':payment';
        $ids = new CashierV3CheckoutFactIdFactory($secret);
        $store = (array)Db::name('system_store')->where('id', $operator->storeId())->field('name')->find();
        $memberName = trim((string)($member['real_name'] ?? '')) ?: trim((string)($member['nickname'] ?? ''));
        $operatorName = (string)Db::name('system_store_staff')->where('id', $operator->operatorId())->value('staff_name');
        $paymentCents = $input['principalCents'] - $input['debtCents'];
        $paymentFacts = [];
        foreach ($input['paymentLines'] as $index => $payment) {
            $paymentLineId = $lineId . ':' . ($index + 1);
            $paymentFacts[] = [
                'factId' => $ids->paymentFactId($operator->tenantId(), $orderId, $paymentLineId),
                'naturalKey' => $ids->paymentNaturalKey($operator->tenantId(), $orderId, $paymentLineId),
                'factVersion' => 1,
                'reversalOf' => '', 'status' => 'effective', 'sourceLineId' => $paymentLineId,
                'paymentMethod' => (string)$payment['paymentMethod'],
                'paymentAuthorityKey' => 'recharge:' . $rechargeId . ':payment:' . ($index + 1),
                'collectionReference' => (string)$payment['collectionReference'],
                'amountCents' => (int)$payment['amountCents'],
            ];
        }
        $performanceFacts = [];
        $externalSalesAmount = 0;
        foreach ($input['salespeople'] as $person) {
            $employeeId = (int)$person['employeeId'];
            $sequence = (int)$person['sequence'];
            $amountCents = (int)$person['amountCents'];
            if (in_array((string)$person['employeeTypeCodeSnapshot'], ['partner', 'outsourced'], true)) {
                $externalSalesAmount += $amountCents;
            }
            $performanceFacts[] = [
                'factId' => $ids->salesPerformanceFactId($operator->tenantId(), $orderId, $lineId, (string)$employeeId, (string)$sequence),
                'naturalKey' => $ids->salesPerformanceNaturalKey($operator->tenantId(), $orderId, $lineId, (string)$employeeId, (string)$sequence),
                'factVersion' => 1,
                'reversalOf' => '', 'status' => 'effective', 'sourceLineId' => $lineId,
                'performanceType' => 'sales_performance_allocated',
                'employeeId' => $employeeId,
                'employeeNameSnapshot' => (string)$person['name'],
                'employeeTypeSnapshot' => (string)$person['employeeTypeCodeSnapshot'],
                'employeeTypeAuthorityVersion' => (int)$person['employeeTypeAuthorityVersion'],
                'roleSnapshot' => 'salesperson',
                'allocationWeightNumerator' => (int)$person['allocationWeight'],
                'allocationWeightDenominator' => 100,
                'allocationBaseAmountCents' => $paymentCents,
                'amountCents' => $amountCents,
                'ruleCodeSnapshot' => 'recharge_salesperson_allocation',
                'ruleNameSnapshot' => '充值销售人分配',
                'ruleVersionSnapshot' => 'v1',
            ];
        }
        if ($paymentCents > 0) {
            $performanceFacts[] = [
            'factId' => $ids->actualPerformanceFactId($operator->tenantId(), $orderId, $lineId),
            'naturalKey' => $ids->actualPerformanceNaturalKey($operator->tenantId(), $orderId, $lineId),
            'factVersion' => 1,
            'reversalOf' => '', 'status' => 'effective', 'sourceLineId' => $lineId,
            'performanceType' => 'actual_performance_recorded',
            'employeeId' => 0, 'employeeNameSnapshot' => '', 'employeeTypeSnapshot' => '',
            'employeeTypeAuthorityVersion' => 0, 'roleSnapshot' => '',
            'allocationWeightNumerator' => 0, 'allocationWeightDenominator' => 1,
            'allocationBaseAmountCents' => $paymentCents,
            'amountCents' => $paymentCents - $externalSalesAmount,
            'ruleCodeSnapshot' => 'recharge_cash_performance',
            'ruleNameSnapshot' => '充值现金业绩', 'ruleVersionSnapshot' => 'v1',
            ];
        }
        $plan = [
            'contractVersion' => CashierV3CheckoutFactPlanV1::CONTRACT_VERSION,
            'commandIdempotencyKey' => $idempotencyKey,
            'context' => [
                'tenantId' => $operator->tenantId(),
                'tenantNameSnapshot' => '',
                'organizationId' => $operator->organizationId(),
                'organizationNameSnapshot' => '',
                'organizationPathSnapshot' => $operator->organizationId(),
                'storeId' => $operator->storeId(),
                'storeNameSnapshot' => (string)($store['name'] ?? ''),
                'memberId' => $input['memberId'],
                'memberNameSnapshot' => $memberName,
                'operatorId' => $operator->operatorId(),
                'operatorNameSnapshot' => $operatorName,
                'businessDate' => $input['businessDate'],
                'businessTimezone' => 'Asia/Shanghai',
                'occurredAt' => $now,
                'settledAt' => $now,
                'recordedAt' => $now,
                'checkoutRequestId' => 'RCH:' . $rechargeId,
                'orderId' => $orderId,
                'orderNoSnapshot' => $orderNo,
                'sourceDocumentType' => 'recharge',
                'businessEventNo' => (string)$event['event_no'],
                'businessSourcePrimaryId' => 0,
                'businessSourcePrimaryNameSnapshot' => '',
                'businessSourceSecondaryId' => 0,
                'businessSourceSecondaryNameSnapshot' => '',
                'businessSourceLabelSnapshot' => '',
            ],
            'saleFacts' => [],
            'paymentFacts' => $paymentFacts,
            'balanceFacts' => [[
                'factId' => $ids->balanceFactId($operator->tenantId(), $orderId, (string)$balance['ledgerId']),
                'naturalKey' => $ids->balanceNaturalKey($operator->tenantId(), $orderId, (string)$balance['ledgerId']),
                'factVersion' => 1,
                'reversalOf' => '', 'status' => 'effective', 'sourceLineId' => 'RCH:' . $rechargeId . ':balance',
                'balanceChangeType' => 'recharge_credit',
                'balanceAccountId' => (string)$balance['accountId'],
                'accountVersion' => (int)$balance['accountVersionAfter'],
                'principalDeltaCents' => (int)$balance['change']['principalCents'],
                'bonusDeltaCents' => (int)$balance['change']['giftCents'],
                'principalAfterCents' => (int)$balance['after']['principalCents'],
                'bonusAfterCents' => (int)$balance['after']['giftCents'],
            ]],
            'performanceFacts' => $performanceFacts,
        ];
        return CashierV3CheckoutFactPlanV1::fromInternalAuthority($plan);
    }

    private function normalizeBusinessSource(array $source): array
    {
        if (!$source || (int)($source['primarySourceId'] ?? 0) <= 0) {
            return ['primarySourceId'=>0,'primarySourceNameSnapshot'=>'','secondarySourceId'=>0,'secondarySourceNameSnapshot'=>'','displayNameSnapshot'=>''];
        }
        $primaryId=(int)$source['primarySourceId']; $secondaryId=(int)($source['secondarySourceId']??0);
        $primary=trim((string)($source['primarySourceNameSnapshot']??'')); $secondary=trim((string)($source['secondarySourceNameSnapshot']??'')); $label=trim((string)($source['displayNameSnapshot']??''));
        if ($primaryId<=0 || $secondaryId<0 || $primary==='' || $label==='' || mb_strlen($primary)>64 || mb_strlen($secondary)>64 || mb_strlen($label)>140 || ($secondaryId>0 && $secondary==='')) throw self::failure('recharge_business_source_invalid','业务来源资料无效，请重新选择。');
        return ['primarySourceId'=>$primaryId,'primarySourceNameSnapshot'=>$primary,'secondarySourceId'=>$secondaryId,'secondarySourceNameSnapshot'=>$secondary,'displayNameSnapshot'=>$label];
    }

    private function normalizeInput(array $payload): array
    {
        $memberId = (int)($payload['memberId'] ?? 0);
        $mode = trim((string)($payload['rechargeMode'] ?? 'custom'));
        $packageId = (int)($payload['rechargePackageId'] ?? 0);
        $principal = $mode === 'package' ? 0 : $this->moneyToCents($payload['principalAmount'] ?? null);
        $bonus = $mode === 'package' ? 0 : $this->moneyToCents($payload['bonusAmount'] ?? null);
        $debt = $this->moneyToCents($payload['debtAmount'] ?? '0');
        $paymentMethod = trim((string)($payload['paymentMethod'] ?? ''));
        $reference = trim((string)($payload['collectionReference'] ?? ''));
        $paymentLines = is_array($payload['paymentLines'] ?? null)
            ? array_values($payload['paymentLines'])
            : [];
        $balanceVersion = (int)($payload['balanceVersion'] ?? 0);
        $salespersonAllocations = is_array($payload['salespersonAllocations'] ?? null)
            ? array_values($payload['salespersonAllocations'])
            : [];
        if ($memberId <= 0 || !in_array($mode, ['package', 'custom'], true)
            || ($mode === 'package' && $packageId <= 0)
            || ($mode === 'custom' && ($principal <= 0 || $bonus < 0))
            || $debt < 0
            || $balanceVersion <= 0
            || (!$paymentLines && (strlen($reference) > 128
                || ($reference !== '' && preg_match('/^[A-Za-z0-9_.:\/-]+$/D', $reference) !== 1)))) {
            throw self::failure('recharge_payload_invalid', '充值信息不完整或金额无效，请重新填写。');
        }
        if (!$paymentLines && in_array($paymentMethod, self::PAYMENT_METHODS, true)) {
            $paymentLines = [[
                'paymentMethod' => $paymentMethod,
                'amount' => $mode === 'package' ? '' : (string)($payload['principalAmount'] ?? ''),
                'collectionReference' => $reference,
            ]];
        }
        return compact('memberId', 'mode', 'packageId', 'principal', 'bonus', 'paymentMethod', 'reference', 'balanceVersion', 'salespersonAllocations', 'paymentLines') + [
            'principalCents' => $principal,
            'bonusCents' => $bonus,
            'debtCents' => $debt,
            'collectionReference' => $reference,
            'businessDate' => trim((string)($payload['businessDate'] ?? '')),
            'businessDateReason' => trim((string)($payload['businessDateReason'] ?? '')),
        ];
    }

    private function normalizeBusinessDate(array $input): array
    {
        $timezone = new \DateTimeZone('Asia/Shanghai');
        $today = (new \DateTimeImmutable('now', $timezone))->format('Y-m-d');
        $businessDate = (string)($input['businessDate'] ?? '');
        $reason = trim((string)($input['businessDateReason'] ?? ''));
        if ($businessDate === '') {
            $businessDate = $today;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $businessDate, $timezone);
        if (!$date || $date->format('Y-m-d') !== $businessDate) {
            throw self::failure('recharge_business_date_invalid', '充值日期格式无效，请重新选择。');
        }
        if ($businessDate > $today) {
            throw self::failure('recharge_business_date_future', '充值日期不能晚于今天。');
        }
        $input['businessDate'] = $businessDate;
        // 第20轮起日期在充值弹窗准备阶段冻结，和补交一样只记录业务日期；
        // 结账阶段不再显示或修改历史日期原因。
        $input['businessDateReason'] = '';
        return $input;
    }

    private function resolveRechargeTerms(array $input, array $member): array
    {
        if ($input['mode'] === 'package') {
            $package = null;
            foreach ((array)(sys_data('user_recharge_quota') ?? []) as $candidate) {
                if (is_array($candidate) && (int)($candidate['id'] ?? 0) === $input['packageId']) {
                    $package = $candidate;
                    break;
                }
            }
            $principal = $this->moneyToCents($package['price'] ?? null);
            $bonus = $this->moneyToCents($package['give_money'] ?? 0);
            if (!$package || $principal <= 0 || $bonus < 0) {
                throw self::failure('recharge_package_unavailable', '所选充值套餐已下架或金额无效，请重新选择。');
            }
            $input['principalCents'] = $principal;
            $input['bonusCents'] = $bonus;
        }

        $minimum = $this->moneyToCents(sys_config('store_user_min_recharge', 0));
        if ($minimum > 0 && $input['principalCents'] < $minimum) {
            throw self::failure('recharge_below_minimum', '充值金额不能低于门店设置的最低充值金额。');
        }
        $maximumPercent = (float)($member['recharge_per'] ?? 0);
        if ($input['mode'] === 'custom' && $maximumPercent > 0
            && $input['bonusCents'] * 100 > $input['principalCents'] * $maximumPercent) {
            throw self::failure('recharge_bonus_exceeds_limit', '赠送金额占比超过当前会员允许的上限。');
        }
        return $input;
    }

    /**
     * 充值人员在准备阶段按当前门店资料锁定。每个“独立核算”职位单独
     * 组成一个 100% 分配组，普通职位共同组成 normal 组；手填业绩金额
     * 是该员工的最终金额，不在正式结账时二次折算。
     */
    public function resolveSalespeopleForPreparation(array $allocations, int $cashPerformanceCents, CashierV3OperatorScope $operator): array
    {
        return $this->resolveSalespeople($allocations, $cashPerformanceCents, $operator);
    }

    private function resolveSalespeople(array $allocations, int $cashPerformanceCents, CashierV3OperatorScope $operator): array
    {
        if (!$allocations) return [];
        if ($cashPerformanceCents <= 0 || $cashPerformanceCents % 100 !== 0 || count($allocations) > 20) {
            throw self::failure('recharge_salesperson_allocations_invalid', '销售人分配信息无效，请重新填写。');
        }
        $requested = [];
        foreach ($allocations as $index => $row) {
            $staffId = is_array($row) ? (int)($row['staffId'] ?? $row['id'] ?? 0) : 0;
            $weight = is_array($row) ? (int)($row['allocationWeight'] ?? 0) : 0;
            if ($staffId <= 0 || $weight <= 0 || $weight > 100 || isset($requested[$staffId])) {
                throw self::failure('recharge_salesperson_allocations_invalid', '销售人或分配比例无效，请重新填写。');
            }
            [$manual, $manualCents] = self::manualPerformanceAmount((array)$row);
            $requested[$staffId] = [
                'allocationWeight' => $weight,
                'isPreSale' => !empty($row['isPreSale']) || !empty($row['marked']),
                'performanceAmountManual' => $manual,
                'performanceAmountCents' => $manualCents,
                'sequence' => $index + 1,
            ];
        }
        $staffIds = array_keys($requested);
        sort($staffIds, SORT_NUMERIC);
        $rows = Db::name('system_store_staff')->alias('ss')->join('employee e', 'e.id=ss.employee_id')
            ->leftJoin('staff_job_position sjp', 'sjp.staff_id=ss.id AND sjp.status=1 AND sjp.is_del=0 AND sjp.end_time=0')
            ->leftJoin('position p', 'p.id=sjp.position_id AND p.status=1')
            ->whereIn('ss.id', $staffIds)->where('ss.store_id', $operator->storeId())->where('ss.status', 1)->where('ss.is_del', 0)
            ->where('ss.cashier_salesperson_enabled', 1)->where('e.status', 1)->where('e.is_del', 0)
            ->field('ss.id,ss.staff_name,ss.employee_id,e.name,e.employment_type_code,e.employment_type_version,sjp.position_id,p.name as position_name,p.performance_independent')
            ->lock(true)->select()->toArray();
        if (count($rows) !== count($requested)) throw self::failure('recharge_salesperson_not_active', '所选销售人已停用、离职或不属于当前门店，请重新选择。');
        $byStaffId = []; foreach ($rows as $row) $byStaffId[(int)$row['id']] = $row;
        $result = []; $groups = []; $cashYuan = intdiv($cashPerformanceCents, 100);
        foreach ($requested as $staffId => $selection) {
            $row = $byStaffId[$staffId] ?? null;
            if (!$row) throw self::failure('recharge_salesperson_not_active', '所选销售人已停用、离职或不属于当前门店，请重新选择。');
            $name = trim((string)($row['name'] ?? '')) ?: trim((string)($row['staff_name'] ?? ''));
            $type = (string)($row['employment_type_code'] ?? '');
            $typeVersion = (int)($row['employment_type_version'] ?? 0);
            if ($name === '' || !in_array($type, ['internal', 'partner', 'outsourced'], true) || $typeVersion <= 0) {
                throw self::failure('recharge_salesperson_profile_incomplete', '所选销售人员工档案不完整，请先维护员工信息。');
            }
            $positionId = (int)($row['position_id'] ?? 0);
            $independent = (int)($row['performance_independent'] ?? 0) === 1;
            $groupKey = $independent ? 'independent:' . ($positionId > 0 ? $positionId : $staffId) : 'normal';
            $result[] = [
                'staffId' => (int)$staffId, 'employeeId' => (int)$row['employee_id'], 'name' => $name,
                'employeeTypeCodeSnapshot' => $type, 'employeeTypeAuthorityVersion' => $typeVersion,
                'allocationWeight' => (int)$selection['allocationWeight'], 'amountCents' => 0,
                'performanceAmountManual' => (bool)$selection['performanceAmountManual'],
                'performanceAmountCents' => (int)$selection['performanceAmountCents'],
                'isPreSale' => (bool)$selection['isPreSale'], 'sequence' => (int)$selection['sequence'],
                'positionId' => $positionId, 'positionName' => (string)($row['position_name'] ?? ''),
                'performanceIndependent' => $independent, 'allocationGroupKey' => $groupKey,
            ];
            $groups[$groupKey][] = count($result) - 1;
        }
        foreach ($groups as $groupKey => $indexes) {
            $weightTotal = 0; foreach ($indexes as $index) $weightTotal += (int)$result[$index]['allocationWeight'];
            if ($weightTotal !== 100) throw self::failure('recharge_salespeople_group_weight_invalid', '每个销售业绩分配组的比例合计必须为 100%。');
            $manualIndexes = array_values(array_filter($indexes, static fn(int $index): bool => !empty($result[$index]['performanceAmountManual'])));
            if ($manualIndexes) {
                $autoIndexes = array_values(array_filter($indexes, static fn(int $index): bool => empty($result[$index]['performanceAmountManual'])));
                $autoWeight = 0; foreach ($autoIndexes as $index) $autoWeight += (int)$result[$index]['allocationWeight'];
                $autoBase = intdiv($cashYuan * $autoWeight, 100) * 100;
                $allocated = 0; $lastAuto = $autoIndexes[count($autoIndexes) - 1] ?? null;
                foreach ($autoIndexes as $index) {
                    $share = $index === $lastAuto ? $autoBase - $allocated : intdiv($cashYuan * (int)$result[$index]['allocationWeight'], 100) * 100;
                    if ($share <= 0 || $share % 100 !== 0) throw self::failure('recharge_salespeople_amount_invalid', '销售人业绩金额无效，请调整比例或金额。');
                    $allocated += $share; $result[$index]['amountCents'] = $share;
                }
                foreach ($manualIndexes as $index) $result[$index]['amountCents'] = (int)$result[$index]['performanceAmountCents'];
                continue;
            }
            $allocated = 0; $last = $indexes[count($indexes) - 1];
            foreach ($indexes as $index) {
                $share = $index === $last ? $cashPerformanceCents - $allocated : intdiv($cashYuan * (int)$result[$index]['allocationWeight'], 100) * 100;
                if ($share <= 0 || $share % 100 !== 0) throw self::failure('recharge_salespeople_amount_invalid', '销售人业绩金额无效，请调整比例或金额。');
                $allocated += $share; $result[$index]['amountCents'] = $share;
            }
            if ($allocated !== $cashPerformanceCents) throw self::failure('recharge_salespeople_amount_invalid', '销售人业绩金额无效，请调整比例或金额。');
        }
        return $result;
    }

    private function normalizePreparedSalespeopleSnapshot(array $rows): array
    {
        if ($rows === []) return [];
        if (count($rows) > 20) throw self::failure('recharge_salespeople_snapshot_invalid', '充值销售人快照无效，请重新打开充值后重试。');
        $result = []; $seen = []; $weightsByGroup = [];
        foreach (array_values($rows) as $index => $row) {
            if (!is_array($row)) throw self::failure('recharge_salespeople_snapshot_invalid', '充值销售人快照无效，请重新打开充值后重试。');
            $staffId = (int)($row['staffId'] ?? 0); $employeeId = (int)($row['employeeId'] ?? 0);
            $weight = (int)($row['allocationWeight'] ?? 0); $amount = (int)($row['amountCents'] ?? -1);
            $manual = !empty($row['performanceAmountManual']); $manualAmount = (int)($row['performanceAmountCents'] ?? 0);
            $positionId = max(0, (int)($row['positionId'] ?? 0)); $independent = !empty($row['performanceIndependent']);
            $groupKey = $independent ? 'independent:' . ($positionId > 0 ? $positionId : $staffId) : 'normal';
            $name = trim((string)($row['name'] ?? '')); $type = (string)($row['employeeTypeCodeSnapshot'] ?? ''); $typeVersion = (int)($row['employeeTypeAuthorityVersion'] ?? 0);
            if ($staffId <= 0 || $employeeId <= 0 || $weight <= 0 || $weight > 100 || $amount < 0 || $amount % 100 !== 0 || ($manual && ($manualAmount < 0 || $manualAmount % 100 !== 0)) || $name === '' || !in_array($type, ['internal', 'partner', 'outsourced'], true) || $typeVersion <= 0 || isset($seen[$staffId])) {
                throw self::failure('recharge_salespeople_snapshot_invalid', '充值销售人快照无效，请重新打开充值后重试。');
            }
            $seen[$staffId] = true; $weightsByGroup[$groupKey] = ($weightsByGroup[$groupKey] ?? 0) + $weight;
            $result[] = ['staffId'=>$staffId,'employeeId'=>$employeeId,'name'=>$name,'employeeTypeCodeSnapshot'=>$type,'employeeTypeAuthorityVersion'=>$typeVersion,'allocationWeight'=>$weight,'amountCents'=>$amount,'performanceAmountManual'=>$manual,'performanceAmountCents'=>$manualAmount,'isPreSale'=>!empty($row['isPreSale']),'sequence'=>max(1, (int)($row['sequence'] ?? ($index + 1))),'positionId'=>$positionId,'positionName'=>(string)($row['positionName'] ?? ''),'performanceIndependent'=>$independent,'allocationGroupKey'=>$groupKey];
        }
        foreach ($weightsByGroup as $weight) if ($weight !== 100) throw self::failure('recharge_salespeople_snapshot_invalid', '充值销售人快照无效，请重新打开充值后重试。');
        return $result;
    }

    private static function manualPerformanceAmount(array $row): array
    {
        $manual = !empty($row['performanceAmountManual']) || !empty($row['performance_amount_manual']);
        if (!$manual) return [false, 0];
        $raw = $row['performanceAmountCents'] ?? $row['performance_amount_cents'] ?? null;
        if ((!is_int($raw) && !is_string($raw)) || preg_match('/^(?:0|[1-9][0-9]*)$/D', trim((string)$raw)) !== 1) {
            throw self::failure('recharge_salespeople_manual_amount_invalid', '手填销售人业绩金额无效，请重新填写。');
        }
        $cents = (int)$raw;
        if ($cents < 0 || $cents % 100 !== 0) throw self::failure('recharge_salespeople_manual_amount_invalid', '手填销售人业绩金额无效，请重新填写。');
        return [true, $cents];
    }

    /** @return array<int,array{paymentMethod:string,amountCents:int,collectionReference:string}> */
    private function resolvePaymentLines(array $lines, int $principalCents): array
    {
        if ($principalCents < 0 || count($lines) > count(self::PAYMENT_METHODS)) {
            throw self::failure('recharge_payment_lines_invalid', '请至少选择一笔有效收款。');
        }
        if ($principalCents === 0) {
            foreach ($lines as $line) {
                $amount = is_array($line) ? trim((string)($line['amount'] ?? '')) : '';
                if ($amount !== '' && $this->moneyToCents($amount) !== 0) {
                    throw self::failure('recharge_payment_total_mismatch', '全额欠款时不能再填写收款金额。');
                }
            }
            return [];
        }
        if (!$lines) {
            throw self::failure('recharge_payment_lines_invalid', '请至少选择一笔有效收款。');
        }
        $result = [];
        $seenMethods = [];
        $total = 0;
        foreach ($lines as $line) {
            $method = is_array($line) ? trim((string)($line['paymentMethod'] ?? $line['method'] ?? '')) : '';
            $amount = is_array($line) ? $this->moneyToCents($line['amount'] ?? null) : -1;
            $reference = is_array($line) ? trim((string)($line['collectionReference'] ?? $line['reference'] ?? '')) : '';
            if (!in_array($method, self::PAYMENT_METHODS, true) || $amount <= 0
                || isset($seenMethods[$method]) || strlen($reference) > 128
                || ($reference !== '' && preg_match('/^[A-Za-z0-9_.:\/-]+$/D', $reference) !== 1)) {
                throw self::failure('recharge_payment_lines_invalid', '收款方式、金额或流水号信息无效，请重新填写。');
            }
            $seenMethods[$method] = true;
            $total += $amount;
            $result[] = [
                'paymentMethod' => $method,
                'amountCents' => $amount,
                'collectionReference' => $reference,
            ];
        }
        if ($total !== $principalCents) {
            throw self::failure('recharge_payment_total_mismatch', '各收款金额合计必须等于本次充值本金。');
        }
        return $result;
    }

    private function legacySalespeopleSnapshot(array $salespeople): array
    {
        return array_map(function (array $person): array {
            return [
                'staff_id' => (int)$person['staffId'],
                'staff_name' => (string)$person['name'],
                'yeji' => $this->centsToMoney((int)$person['amountCents']),
                'employee_id' => (int)$person['employeeId'],
                'employee_type' => (string)$person['employeeTypeCodeSnapshot'],
                'sequence' => (int)$person['sequence'],
                'allocation_weight' => (int)$person['allocationWeight'],
                'performance_amount_manual' => !empty($person['performanceAmountManual']),
                'performance_amount_cents' => (int)($person['performanceAmountCents'] ?? 0),
                'position_id' => (int)($person['positionId'] ?? 0),
                'position_name' => (string)($person['positionName'] ?? ''),
                'performance_independent' => !empty($person['performanceIndependent']),
                'allocation_group_key' => (string)($person['allocationGroupKey'] ?? 'normal'),
            ];
        }, $salespeople);
    }

    private function moneyToCents($value): int
    {
        $raw = is_int($value) || is_string($value) ? trim((string)$value) : '';
        if (preg_match('/^(0|[1-9][0-9]*)$/D', $raw, $match) !== 1) {
            return -1;
        }
        $cents = ((int)$match[1]) * 100;
        return $cents <= 9999999999 ? $cents : -1;
    }

    private function centsToMoney(int $cents): string
    {
        if ($cents < 0 || $cents % 100 !== 0) {
            throw self::failure('recharge_whole_yuan_required', '充值金额必须为整数元。');
        }
        return (string)intdiv($cents, 100);
    }

    private static function failure(string $reason, string $message, array $detail = []): CashierV3CommandException
    {
        return new CashierV3CommandException(
            CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
            $message,
            CashierV3ResultCode::STATUS_FAILED,
            ['reason' => $reason] + $detail
        );
    }
}
