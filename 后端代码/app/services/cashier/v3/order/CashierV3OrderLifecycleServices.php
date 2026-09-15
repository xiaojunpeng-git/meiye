<?php

namespace app\services\cashier\v3\order;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\cashier\CashierV3CashierWorkspaceServices;
use app\services\cashier\v3\cashier\CashierV3SaleCatalogServices;
use app\services\cashier\v3\checkout\provider\CashierV3MemberBalanceProvider;
use app\services\cashier\v3\event\CashierV3BusinessEventExecution;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\presale\CashierV3PresaleClaimServices;
use app\services\report\StoreReportPaymentSaleAllocationFactServices;
use think\facade\Db;

/**
 * Immutable operation ledger for V3 sales orders.
 *
 * This service deliberately never changes an original order, line or fact.
 * A lifecycle action is a new authority row, with an idempotency key and a
 * business event. The single exception is the user-maintained order note:
 * its latest value is stored on the settled order while every edit is still
 * recorded in the lifecycle ledger. Financial/benefit reversals are admitted
 * only after the source has passed the downstream-use gate; ambiguous legacy
 * records are rejected rather than being silently corrected.
 */
final class CashierV3OrderLifecycleServices
{
    public const OPERATION_TABLE = 'cashier_v3_order_lifecycle_operation';
    public const REOPEN_TABLE = 'cashier_v3_order_reopen_draft';
    public const REFUND_LINE_TABLE = 'cashier_v3_order_lifecycle_refund_line';
    public const CONTRACT_VERSION = 'cashier-v3-order-lifecycle-v1';

    private const SALES_ACTIONS = [
        'adjust-sales-order-personnel', 'update-sales-order-note', 'refund-sales-order', 'void-sales-order', 'reopen-sales-order',
    ];

    /** @var CashierV3CashierWorkspaceServices */
    private $workspace;
    /** @var CashierV3SaleCatalogServices */
    private $saleCatalog;

    public function __construct(?CashierV3CashierWorkspaceServices $workspace = null, ?CashierV3SaleCatalogServices $saleCatalog = null)
    {
        $this->workspace = $workspace;
        $this->saleCatalog = $saleCatalog;
    }

    public function discover(array $scope): array
    {
        $operator = $scope['operator_scope'] ?? null;
        $dataScope = $scope['data_scope'] ?? null;
        if (!$operator instanceof CashierV3OperatorScope || !$dataScope instanceof CashierV3DataScopeContext) {
            throw CashierV3CommandException::invalidContext('订单资料不完整，请重新打开订单后再试。');
        }
        $source = $this->source((array)($scope['payload'] ?? []), $operator, $dataScope, false);
        $version = 1 + (int)Db::name(self::OPERATION_TABLE)->where('tenant_id', $dataScope->tenantId())
            ->where('source_type', $source['sourceType'])->where('source_order_id', $source['sourceId'])->count();
        $resources = [[
            'kind' => 'sales_order', 'id' => (string)$source['resourceId'], 'expectedVersion' => $version,
            'roles' => ['sales_order'], 'accessMode' => 'mutate',
            'providerContractVersion' => self::CONTRACT_VERSION,
            'authorityFingerprint' => hash('sha256', implode('|', [$dataScope->tenantId(), $source['resourceId'], $version])),
        ]];
        $action = trim((string)($scope['action'] ?? ''));
        if (in_array($action, ['refund-sales-order', 'void-sales-order'], true)
            && (int)$source['memberId'] > 0
            && Db::name('cashier_v3_balance_fact')->where('tenant_id', $dataScope->tenantId())
                ->where('order_id', (string)$source['sourceId'])->where('fact_direction', 'forward')->where('status', 'effective')->count() > 0) {
            $member = (array)Db::name('user')->where('uid', (int)$source['memberId'])
                ->field('uid,balance_version,now_money,ben_money,give_money,status,is_del,delete_time')->find();
            $balanceVersion = (int)($member['balance_version'] ?? 0);
            if (!$member || $balanceVersion <= 0 || (int)$member['status'] !== 1 || (int)$member['is_del'] !== 0) {
                throw self::failure('order_lifecycle_member_balance_unavailable');
            }
            $payload = (array)($scope['payload'] ?? []);
            $principalRaw = trim((string)($payload['restorePrincipalAmount'] ?? $payload['refundPrincipalAmount'] ?? $payload['balancePrincipalRefundAmount'] ?? '0'));
            $bonusRaw = trim((string)($payload['restoreBonusAmount'] ?? $payload['refundBonusAmount'] ?? $payload['balanceGiftRefundAmount'] ?? '0'));
            $restoresBalance = $action === 'void-sales-order'
                || (preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/D', $principalRaw) === 1 && bccomp($principalRaw, '0', 2) > 0)
                || (preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/D', $bonusRaw) === 1 && bccomp($bonusRaw, '0', 2) > 0);
            $resources[] = [
                'kind' => CashierV3MemberBalanceProvider::KIND, 'id' => (string)$source['memberId'],
                'expectedVersion' => $balanceVersion, 'roles' => ['member_balance'], 'accessMode' => $restoresBalance ? 'mutate' : 'read',
                'providerContractVersion' => CashierV3MemberBalanceProvider::CONTRACT_VERSION,
                'authorityFingerprint' => hash('sha256', json_encode($member, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            ];
        }
        return ['resources' => $resources];
    }

    /** @return array<string,mixed> */
    public function executeInTx(string $action, array $scope): array
    {
        CashierV3TransactionGuard::assertInTransaction('orderLifecycle.executeInTx');
        $operator = $scope['operator_scope'] ?? null;
        $dataScope = $scope['data_scope'] ?? null;
        $recorder = $scope['event_recorder'] ?? null;
        $execution = $scope['event_execution'] ?? null;
        if (!$operator instanceof CashierV3OperatorScope || !$dataScope instanceof CashierV3DataScopeContext
            || !$recorder instanceof CashierV3BusinessEventRecorder || !$execution instanceof CashierV3BusinessEventExecution) {
            throw self::failure('order_lifecycle_scope_incomplete');
        }
        $source = $this->source((array)($scope['payload'] ?? []), $operator, $dataScope, true);
        // The checkout request is the event-set boundary.  Build the set once
        // inside the same transaction and let the void cascade invoke only
        // domains that actually emitted a checkout event.  Financial
        // reversal services still lock their authoritative facts as the
        // final guard; this plan only prevents unrelated domain work.
        $occurredEvents = $action === 'void-sales-order'
            ? $this->occurredCheckoutEvents($source, $dataScope)
            : ['checkout' => true, 'inventory' => true, 'service' => true, 'card' => true, 'presale' => true];
        $commandKey = trim((string)($scope['idempotency_key'] ?? ''));
        if ($commandKey === '') throw self::failure('order_lifecycle_idempotency_missing');
        $input = $this->input($action, (array)($scope['payload'] ?? []), $source);
        $fingerprint = hash('sha256', json_encode([$action, $source['sourceType'], $source['sourceId'], $input], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $existing = Db::name(self::OPERATION_TABLE)->where('tenant_id', $dataScope->tenantId())
            ->where('command_idempotency_key', $commandKey)->lock(true)->find();
        if ($existing) {
            if ((string)($existing['immutable_fingerprint'] ?? '') !== $fingerprint) {
                throw self::failure('order_lifecycle_idempotency_conflict');
            }
            return $this->result((array)$existing, true);
        }

        $now = time();
        $operationId = 'OLO-' . strtoupper(substr(hash_hmac('sha256', implode('|', [$dataScope->tenantId(), $action, $source['sourceType'], $source['sourceId'], $commandKey]), $this->secret()), 0, 40));
        $operationNo = $this->operationNo($action, $operationId, $now, $dataScope->tenantId());
        $event = $recorder->recordInTx($execution, (array)($scope['event_contract'] ?? []), [
            'event_type' => $this->eventType($action),
            'aggregate_type' => 'sales_order',
            'aggregate_id' => (string)$source['sourceId'],
            'aggregate_version' => $this->nextVersion($source, $dataScope->tenantId()),
            'event_version' => 1,
            'source_type' => $action,
            'source_id' => $operationId,
            'member_id' => (int)$source['memberId'],
            'business_date' => date('Y-m-d', $now),
            'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now,
            'aggregate_name_snapshot' => (string)$source['sourceNo'],
            'store_name_snapshot' => (string)$source['storeName'],
            'payload' => ['contractVersion' => self::CONTRACT_VERSION, 'operationId' => $operationId, 'operationNo' => $operationNo, 'sourceOrderNo' => $source['sourceNo'], 'action' => $action,
                'targetOrderLineId' => (string)($input['targetOrderLineId'] ?? ''), 'targetRole' => (string)($input['targetRole'] ?? ''),
                'orderNote' => (string)($input['orderNote'] ?? '')],
        ]);

        $reopenDraftId = '';
        if ($action === 'adjust-sales-order-personnel') {
            $this->adjustPersonnelFacts($source, $input, $operationId, $commandKey, $event, $operator, $dataScope, $now);
        } elseif ($action === 'update-sales-order-note') {
            $this->updateSalesOrderNote($source, (string)$input['orderNote'], $now, $dataScope);
        } elseif ($action === 'reopen-sales-order') {
            $reopenDraftId = $this->createReopenDraft($source, $input, $operationId, $commandKey, $now, $dataScope);
        } else {
            // A void/refund is an append-only reversal, never an order-status
            // overwrite.  Unsupported current-right domains are deliberately
            // rejected before a single accounting row is written. Product
            // inventory is restored only for a full void, never a refund.
            $cardOperationReversal = $occurredEvents['card']
                ? (new CashierV3CardOperationReversalServices())->prepare($source, $action, $dataScope)
                : ['isUpgrade' => false, 'entitlementCreditCents' => 0];
            $financial = $this->assertFinancialReversalEligible(
                $source, $action, $input, $dataScope, (int)$cardOperationReversal['entitlementCreditCents']
            );
            // A financial reversal never pulls back a completed presale
            // delivery. It only closes all remaining claim opportunities for
            // this source order, inside the same transaction as the reversal.
            if ($occurredEvents['presale']) {
                (new CashierV3PresaleClaimServices())->closeForSalesOrderReversalInTx(
                    $dataScope->tenantId(), (string)$source['sourceId'], $operationId, $now
                );
            }
            $input['cashRefundCents'] = (int)$financial['cashRefundCents'];
            $input['restorePrincipalCents'] = (int)$financial['restorePrincipalCents'];
            $input['restoreBonusCents'] = (int)$financial['restoreBonusCents'];
            if ($action === 'void-sales-order' && $occurredEvents['inventory']) {
                (new CashierV3SalesOrderInventoryReversalServices())->reverseForVoidInTx(
                    $source, $operationId, $operator, $dataScope, $now
                );
            }
            (new CashierV3SalesOrderReversalServices())->apply(
                $source, $action, $financial, $operationId, $commandKey, $operator, $dataScope, $now
            );
            (new CashierV3CardOperationReversalServices())->apply(
                $cardOperationReversal, $action, $operationId, $operator, $dataScope, $now
            );
            if ($action === 'void-sales-order' && $occurredEvents['service']) {
                // A sale can already have produced completed service facts.
                // Full-order void is an atomic cascade: reverse those service
                // facts (and their entitlement occupation/performance) when
                // present, while orders without service facts simply skip it.
                $this->voidCompletedServiceFactsInTx(
                    $source, $commandKey, $operator, $dataScope,
                    $recorder,
                    (string)($scope['state_context_id'] ?? '')
                );
            }
            if ($action === 'void-sales-order') {
                // Attribution facts are created during sale checkout, not
                // only after a completed-service event. Closing them is
                // idempotent when the order has none and mandatory when it
                // does, otherwise a voided sale still appears in attribution.
                $this->voidOrderAttributionFactsInTx($source, $dataScope);
            }
            if ($action === 'refund-sales-order') {
                $input['refundLines'] = $this->allocateRefundLines($input['refundLines'], $input, $financial);
            }
            $paymentReversals = $this->writeFactReversals(
                $source, $action, $input, $financial, $operationId, $commandKey, $event, $operator, $dataScope, $now
            );
            (new StoreReportPaymentSaleAllocationFactServices())->persistLifecycleReversalsInTx([
                'tenant_id' => $dataScope->tenantId(),
                'organization_id' => $dataScope->organizationId(),
                'store_id' => (int)$source['storeId'],
                'member_id' => (int)$source['memberId'],
                'order_id' => (string)$source['sourceId'],
                'order_no_snapshot' => (string)$source['sourceNo'],
                'business_date' => date('Y-m-d', $now),
                'occurred_at' => $now,
                'settled_at' => $now,
                'recorded_at' => $now,
                'business_event_no' => (string)$event['event_no'],
                'checkout_request_id' => (string)$source['checkoutRequestId'],
            ], $paymentReversals, $action === 'refund-sales-order' ? $input['refundLines'] : []);
            $reversalId = $this->recordFinancialReversal($source, $action, $operationId, $commandKey, $input, $financial, $dataScope, $now);
            if ($action === 'refund-sales-order') {
                $this->recordRefundLineDetails($source, $operationId, $reversalId, $commandKey, $input['refundLines'], $dataScope, $now);
            }
        }

        $row = [
            'operation_id' => $operationId, 'operation_no' => $operationNo,
            'tenant_id' => $dataScope->tenantId(), 'store_id' => $operator->storeId(), 'member_id' => (int)$source['memberId'], 'operator_id' => $operator->operatorId(),
            'source_type' => $source['sourceType'], 'source_order_id' => (string)$source['sourceId'], 'source_order_no_snapshot' => (string)$source['sourceNo'],
            'operation_type' => $this->operationType($action), 'command_idempotency_key' => $commandKey, 'immutable_fingerprint' => $fingerprint,
            'reason_snapshot' => (string)$input['reason'], 'request_json' => json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'business_event_no' => (string)$event['event_no'],
            // 退款统计只统计实际现金退款；余额本金／赠金返还另有独立字段与事实。
            'amount_cents' => (int)$input['cashRefundCents'], 'cash_refund_cents' => (int)$input['cashRefundCents'],
            'reversed_cash_cents' => isset($financial) ? (int)$financial['cashReversalCents'] : 0,
            'restored_principal_cents' => (int)$input['restorePrincipalCents'], 'restored_bonus_cents' => (int)$input['restoreBonusCents'],
            'status' => 'succeeded', 'version' => 1,
            'business_date' => date('Y-m-d', $now), 'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ];
        if ((int)Db::name(self::OPERATION_TABLE)->insert($row) !== 1) throw self::failure('order_lifecycle_operation_insert_failed');
        $result = $this->result($row, false);
        if ($action === 'update-sales-order-note') {
            $result['orderNote'] = (string)$input['orderNote'];
            $result['previousOrderNote'] = (string)$input['previousOrderNote'];
        } elseif ($action === 'adjust-sales-order-personnel') {
            $result['targetOrderLineId'] = (string)$input['targetOrderLineId'];
            $result['targetRole'] = (string)$input['targetRole'];
            $result['personnelCount'] = count($input['personnel']);
        }
        if ($reopenDraftId !== '') {
            $result['reopenDraftId'] = $reopenDraftId;
            $result['cashierDraft'] = $this->loadReopenDraftToWorkspace(
                $reopenDraftId,
                $source,
                $commandKey,
                !empty($input['replaceWorkspace']),
                (string)($scope['state_context_id'] ?? ''),
                $operator,
                $dataScope,
                $now
            );
        }
        return $result;
    }

    /** @return array<string,mixed> */
    public function debtRepaymentEntry(array $payload, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): array
    {
        $source = $this->source($payload, $operator, $scope, false);
        if ((int)$source['memberId'] <= 0) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::RESOURCE_NOT_FOUND,
                '游客订单不支持欠款补交。',
                CashierV3ResultCode::STATUS_FAILED
            );
        }
        $debt = Db::name('store_debt')->alias('d')->join('cashier_v3_debt_authority a', 'a.debt_id=d.id')
            ->where('a.tenant_id', $scope->tenantId())->where('a.sales_order_id', (string)$source['sourceId'])
            ->where('d.status', 0)->whereRaw('d.total_debt > d.repaid_debt')
            ->field('d.id,d.debt_no,d.total_debt,d.repaid_debt')->order('d.id desc')->find();
        if (!$debt) {
            throw new CashierV3CommandException(CashierV3ResultCode::RESOURCE_NOT_FOUND, '该订单当前没有待补交欠款。', CashierV3ResultCode::STATUS_FAILED);
        }
        return ['contractVersion' => self::CONTRACT_VERSION, 'memberId' => (int)$source['memberId'], 'salesOrderId' => (string)$source['sourceId'], 'salesOrderNo' => (string)$source['sourceNo'], 'debtId' => (int)$debt['id'], 'debtNo' => (string)$debt['debt_no'], 'remainingAmount' => bcsub((string)$debt['total_debt'], (string)$debt['repaid_debt'], 2), 'nextAction' => 'open-member-debt-repayment'];
    }

    /** Read-only entry contract for the order-detail personnel dialog. */
    public function personnelAdjustmentEntry(array $payload, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): array
    {
        $source = $this->source(array_merge($payload, ['sourceType' => 'sales']), $operator, $scope, false);
        $lines = Db::name('cashier_v3_sales_order_line')->where('tenant_id', $scope->tenantId())
            ->where('order_id', $source['sourceId'])->where('line_direction', 'forward')->where('line_status', 'settled')
            ->field('order_line_id,checkout_line_id,item_type,item_name_snapshot,sale_amount_cents')->order('line_no asc')->select()->toArray();
        $eligible = Db::name('system_store_staff')->alias('s')->join('employee e', 'e.id=s.employee_id')
            ->leftJoin('staff_job_position sjp', 'sjp.staff_id = s.id AND sjp.status = 1 AND sjp.is_del = 0 AND sjp.end_time = 0')
            ->leftJoin('position p', 'p.id = sjp.position_id AND p.status = 1')
            ->where('s.store_id', $operator->storeId())->where('s.status', 1)->where('s.is_del', 0)
            ->where('e.status', 1)->where('e.is_del', 0)
            ->field('s.id,s.employee_id,s.staff_name,s.cashier_salesperson_enabled,s.cashier_craftsman_enabled,sjp.position_id,p.name as position_name,p.performance_independent,e.name,e.employment_type_code,e.employment_type_version')->order('s.id asc')->select()->toArray();
        // Personnel adjustments are append-only: the latest effective
        // allocation is represented by forward facts whose originals have
        // not been reversed. Reading every forward row here would resurrect
        // the pre-adjustment salesperson and, because the old rows do not
        // carry the current role, make a presale appear as “否”.
        $eligibleByStaffId = [];
        foreach ($eligible as $eligibleRow) {
            $eligibleByStaffId[(int)($eligibleRow['id'] ?? 0)] = $eligibleRow;
            $eligibleByStaffId[(int)($eligibleRow['employee_id'] ?? 0)] = $eligibleRow;
        }
        $salespeople = [];
        foreach ($lines as $line) {
            $lineId = (string)$line['order_line_id'];
            $facts = $this->effectivePersonnelFactsForLine(
                $scope->tenantId(), $source['sourceId'], $lineId, 'sales_performance_allocated'
            );
            $totalAmount = array_sum(array_map(static function (array $fact): int {
                return abs((int)($fact['amount_cents'] ?? 0));
            }, $facts));
            $allocatedWeight = 0;
            $lastFactIndex = count($facts) - 1;
            foreach ($facts as $factIndex => $fact) {
                $roleSnapshot = (string)($fact['role_snapshot'] ?? '');
                // Lifecycle-adjusted facts store exact cents as their
                // allocation numerator/denominator. The editor contract is
                // percentage-based, so derive a stable 0–100 percentage
                // from the effective amounts instead of exposing 13400%.
                $amount = abs((int)($fact['amount_cents'] ?? 0));
                $allocationWeight = $lastFactIndex === $factIndex
                    ? max(0, 100 - $allocatedWeight)
                    : ($totalAmount > 0 ? intdiv($amount * 100, $totalAmount) : 0);
                $allocatedWeight += $allocationWeight;
                $staffId = (int)($fact['employee_id'] ?? 0);
                $staffRow = $eligibleByStaffId[$staffId] ?? [];
                $positionId = (int)($staffRow['position_id'] ?? 0);
                $independent = (int)($staffRow['performance_independent'] ?? 0) === 1;
                $salespeople[$lineId][] = [
                    'factId' => (string)($fact['fact_id'] ?? ''),
                    'employeeId' => (int)$fact['employee_id'],
                    'name' => (string)$fact['employee_name_snapshot'],
                    'roleSnapshot' => $roleSnapshot,
                    'isPreSale' => str_ends_with(strtolower($roleSnapshot), ':presale'),
                    'allocationWeight' => $allocationWeight,
                    'allocationWeightDenominator' => 100,
                    'salesPerformanceAmount' => (int)($fact['amount_cents'] ?? 0),
                    'performanceAmountCents' => $amount,
                    'performanceAmountManual' => strpos((string)($fact['rule_code_snapshot'] ?? ''), 'MANUAL-AMOUNT') !== false,
                    'positionId' => $positionId,
                    'positionName' => trim((string)($staffRow['position_name'] ?? '')),
                    'performanceIndependent' => $independent,
                    'allocationGroupKey' => $independent && $positionId > 0 ? 'independent:' . $positionId : 'normal',
                ];
            }
        }
        // 早期结账事实按 checkout_line_id 写入，订单中心调整按
        // order_line_id 定位。两者属于同一笔商品行，读取时必须归并，
        // 否则初始归属会在调整弹窗中“消失”。
        $attributionLineToOrderLine = [];
        foreach ($lines as $line) {
            $orderLineId = trim((string)($line['order_line_id'] ?? ''));
            if ($orderLineId === '') continue;
            $attributionLineToOrderLine[$orderLineId] = $orderLineId;
            $checkoutLineId = trim((string)($line['checkout_line_id'] ?? ''));
            if ($checkoutLineId !== '') $attributionLineToOrderLine[$checkoutLineId] = $orderLineId;
        }
        // 导购/销售经理当前都是订单参与归属事实，不参与销售人业绩金额
        // 分配，也不在这里推导他们的独立业绩或提成。
        $guides = [];
        foreach (Db::name('cashier_v3_customer_guide_round_fact')->where('tenant_id', $scope->tenantId())->where('order_id', $source['sourceId'])->where('status', 'effective')->field('source_line_id,guide_employee_id,guide_employee_name_snapshot,guide_round_no')->order('id asc')->select()->toArray() as $fact) {
            $lineId = $attributionLineToOrderLine[(string)$fact['source_line_id']] ?? (string)$fact['source_line_id'];
            $guides[$lineId][] = ['employeeId' => (int)$fact['guide_employee_id'], 'name' => (string)$fact['guide_employee_name_snapshot'], 'guideRoundNo' => (int)$fact['guide_round_no']];
        }
        $salesManagers = [];
        foreach (Db::name('cashier_v3_sales_manager_fact')->where('tenant_id', $scope->tenantId())->where('order_id', $source['sourceId'])->where('status', 'effective')->field('source_line_id,sales_manager_employee_id,sales_manager_name_snapshot')->order('id asc')->select()->toArray() as $fact) {
            $lineId = $attributionLineToOrderLine[(string)$fact['source_line_id']] ?? (string)$fact['source_line_id'];
            $salesManagers[$lineId][] = [
                'employeeId' => (int)$fact['sales_manager_employee_id'],
                'name' => (string)$fact['sales_manager_name_snapshot'],
            ];
        }
        // 销售订单详情沿用收银人员控件，但候选人必须服从当前门店边界。
        // 这里不能直接读取 employee 全表，否则详情弹窗会把其他门店员工暴露给门店端。
        $attributionCandidates = [];
        $seenAttributionEmployees = [];
        foreach ($eligible as $row) {
            $employeeId = (int)($row['employee_id'] ?? 0);
            $name = trim((string)($row['name'] ?? '')) ?: trim((string)($row['staff_name'] ?? ''));
            if ($employeeId <= 0 || $name === '' || isset($seenAttributionEmployees[$employeeId])) continue;
            $seenAttributionEmployees[$employeeId] = true;
            $attributionCandidates[] = [
                // 导购/销售经理事实使用 employee_id，不能误传门店任职行 id。
                'staffId' => $employeeId,
                'employeeId' => $employeeId,
                'name' => $name,
                'employeeTypeCode' => (string)($row['employment_type_code'] ?? ''),
                'storeId' => (int)$operator->storeId(),
                'storeName' => (string)$source['storeName'],
                'attributionRole' => 'guide_and_sales_manager',
            ];
        }
        return ['contractVersion' => self::CONTRACT_VERSION, 'salesOrderId' => (string)$source['sourceId'], 'salesOrderNo' => (string)$source['sourceNo'],
            // Directly opened order-center editors must carry the server's
            // current lifecycle version into the command version store.
            'recordVersion' => $this->nextVersion($source, $scope->tenantId()),
            'lines' => array_map(static function (array $line) use ($salespeople, $guides, $salesManagers): array {
                $lineId = (string)$line['order_line_id'];
                return ['orderLineId' => $lineId, 'itemType' => (string)$line['item_type'], 'itemName' => (string)$line['item_name_snapshot'],
                    'totalAmountCents' => max(0, (int)($line['sale_amount_cents'] ?? 0)),
                    'currentSalespeople' => $salespeople[$lineId] ?? [], 'currentGuides' => $guides[$lineId] ?? [],
                    'currentSalesManagers' => $salesManagers[$lineId] ?? [], 'canAdjustCraftsman' => (string)$line['item_type'] === 'project'];
            }, $lines),
            'salespeople' => array_values(array_map(static function (array $row): array {
                $positionId = (int)($row['position_id'] ?? 0);
                $independent = (int)($row['performance_independent'] ?? 0) === 1;
                return [
                    'staffId' => (int)$row['id'], 'employeeId' => (int)$row['employee_id'],
                    'name' => trim((string)$row['name']) ?: (string)$row['staff_name'],
                    'positionId' => $positionId, 'positionName' => trim((string)($row['position_name'] ?? '')),
                    'performanceIndependent' => $independent,
                    'allocationGroupKey' => $independent && $positionId > 0 ? 'independent:' . $positionId : 'normal',
                ];
            }, array_filter($eligible, static function (array $row): bool { return (int)$row['cashier_salesperson_enabled'] === 1; }))),
            'craftsmen' => array_values(array_map(static function (array $row): array {
                $positionId = (int)($row['position_id'] ?? 0);
                $independent = (int)($row['performance_independent'] ?? 0) === 1;
                return [
                    'staffId' => (int)$row['id'], 'employeeId' => (int)$row['employee_id'],
                    'name' => trim((string)$row['name']) ?: (string)$row['staff_name'],
                    'positionId' => $positionId, 'positionName' => trim((string)($row['position_name'] ?? '')),
                    'performanceIndependent' => $independent,
                    'allocationGroupKey' => $independent && $positionId > 0 ? 'independent:' . $positionId : 'normal',
                ];
            }, array_filter($eligible, static function (array $row): bool { return (int)$row['cashier_craftsman_enabled'] === 1; }))),
            'guides' => $attributionCandidates, 'salesManagers' => $attributionCandidates];
    }

    private function source(array $payload, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope, bool $lock): array
    {
        $type = trim((string)($payload['sourceType'] ?? $payload['source_type'] ?? 'sales'));
        $id = trim((string)($payload['orderId'] ?? $payload['salesOrderId'] ?? $payload['sourceOrderId'] ?? ''));
        if ($type !== 'sales' || $id === '') throw self::failure('order_lifecycle_sales_source_required');
        $q = Db::name('cashier_v3_sales_order')->where('tenant_id', $scope->tenantId())->where('organization_id', $scope->organizationId())->where('store_id', $operator->storeId())->where('order_id', $id);
        if ($lock) $q->lock(true);
        $row = (array)$q->find();
        if (!$row || (string)($row['order_status'] ?? '') !== 'settled' || (string)($row['order_direction'] ?? '') !== 'forward') throw self::failure('sales_order_not_mutable');
        return ['sourceType' => 'sales', 'sourceId' => $id, 'sourceRecordId' => (int)$row['id'], 'resourceId' => $id, 'sourceNo' => (string)$row['order_no'], 'memberId' => (int)$row['member_id'], 'storeId' => (int)$row['store_id'], 'storeName' => (string)$row['store_name_snapshot'], 'checkoutRequestId' => (string)$row['checkout_request_id'], 'amountCents' => (int)$row['sale_amount_cents'], 'orderNote' => (string)($row['order_note'] ?? ''), 'tenantId' => $scope->tenantId()];
    }

    private function input(string $action, array $payload, array $source): array
    {
        if (!in_array($action, self::SALES_ACTIONS, true) || $source['sourceType'] !== 'sales') throw self::failure('order_lifecycle_action_source_mismatch');
        $reason = trim((string)($payload['reason'] ?? ''));
        if ($action === 'update-sales-order-note') {
            $note = trim((string)($payload['orderNote'] ?? $payload['order_note'] ?? $payload['note'] ?? ''));
            if (mb_strlen($note) > 500) throw self::failure('sales_order_note_invalid');
            return [
                'reason' => '订单备注更新', 'orderNote' => $note, 'previousOrderNote' => (string)$source['orderNote'],
                'cashRefundCents' => 0, 'restorePrincipalCents' => 0, 'restoreBonusCents' => 0,
                'refundLines' => [], 'replaceWorkspace' => false, 'targetOrderLineId' => '', 'targetRole' => '', 'personnel' => [],
            ];
        }
        if ($action === 'reopen-sales-order' && $reason === '') $reason = '订单重开';
        if ($reason === '' || mb_strlen($reason) > 255) throw self::failure('order_lifecycle_reason_invalid');
        $cashRefund = 0;
        $restorePrincipal = 0;
        $restoreBonus = 0;
        if (strpos($action, 'refund-') === 0) {
            $cashRefund = $this->moneyCents($payload['cashRefundAmount'] ?? $payload['actualRefundAmount'] ?? $payload['refundAmount'] ?? $payload['amount'] ?? '');
            $restorePrincipal = $this->optionalMoneyCents($payload['restorePrincipalAmount'] ?? $payload['refundPrincipalAmount'] ?? $payload['balancePrincipalRefundAmount'] ?? 0);
            $restoreBonus = $this->optionalMoneyCents($payload['restoreBonusAmount'] ?? $payload['refundBonusAmount'] ?? $payload['balanceGiftRefundAmount'] ?? 0);
            $upgradeCredit = (int)Db::name(CashierV3CardOperationReversalServices::SETTLEMENT_TABLE)
                ->where('tenant_id', (string)($source['tenantId'] ?? ''))
                ->where('sales_order_id', (string)$source['sourceId'])
                ->where('settlement_status', 'settled')->value('entitlement_credit_cents');
            $amount = $cashRefund + $restorePrincipal + $restoreBonus + max(0, $upgradeCredit);
            if ($amount <= 0 || $amount > (int)$source['amountCents']) throw self::failure('order_lifecycle_refund_amount_invalid');
        }
        $refundLines = $action === 'refund-sales-order'
            ? $this->refundLineSnapshots((array)($payload['refundLineIds'] ?? []), $source)
            : [];
        $input = ['reason' => $reason, 'orderNote' => '', 'previousOrderNote' => '', 'cashRefundCents' => $cashRefund, 'restorePrincipalCents' => $restorePrincipal, 'restoreBonusCents' => $restoreBonus, 'refundLines' => $refundLines, 'replaceWorkspace' => !empty($payload['replaceWorkspace']), 'targetOrderLineId' => '', 'targetRole' => '', 'personnel' => []];
        if ($action === 'adjust-sales-order-personnel') {
            // `+` retains the left-hand empty defaults in PHP, which silently
            // discards the submitted line, role and personnel rows. This is a
            // replacement overlay: preserve common lifecycle fields while the
            // single-line editor values must overwrite their empty defaults.
            $input = array_merge($input, $this->singleLinePersonnelInput($payload));
        }
        return $input;
    }

    /** Normalize and scope the order-center personnel command to one line/role. */
    private function singleLinePersonnelInput(array $payload): array
    {
        $lineId = trim((string)($payload['targetOrderLineId'] ?? $payload['target_order_line_id'] ?? ''));
        $role = trim((string)($payload['targetRole'] ?? $payload['target_role'] ?? ''));
        if ($lineId === '' || preg_match('/^[A-Za-z0-9_.:-]{1,64}$/D', $lineId) !== 1
            || !in_array($role, ['salesperson', 'guide', 'sales_manager'], true)) {
            throw self::failure('personnel_adjustment_target_invalid');
        }
        $rows = $payload['personnel'] ?? null;
        if (!is_array($rows) || $rows === [] || count($rows) > 50) throw self::failure('personnel_adjustment_empty');
        // The order-centre editor exposes one independent sales manager per
        // line. Never turn a forged multi-row command into several 100%
        // manager performances.
        if ($role === 'sales_manager' && count($rows) !== 1) throw self::failure('personnel_adjustment_sales_manager_single_required');
        return ['targetOrderLineId' => $lineId, 'targetRole' => $role, 'personnel' => array_values($rows)];
    }

    /** @return array<int,array{lineId:string,itemName:string,itemType:string,quantity:int}> */
    private function refundLineSnapshots(array $rawLineIds, array $source): array
    {
        $lineIds = array_values(array_unique(array_filter(array_map(static function ($value): string {
            $lineId = trim((string)$value);
            return preg_match('/^[A-Za-z0-9_.:-]{1,64}$/D', $lineId) === 1 ? $lineId : '';
        }, $rawLineIds))));
        if (!$lineIds || count($lineIds) > 200) {
            throw self::failure('order_lifecycle_refund_lines_invalid');
        }
        $rows = Db::name('cashier_v3_sales_order_line')
            ->where('tenant_id', (string)$source['tenantId'])
            ->where('store_id', (int)$source['storeId'])
            ->where('order_id', (string)$source['sourceId'])
            ->whereIn('order_line_id', $lineIds)
            ->where('line_direction', 'forward')
            ->where('line_status', 'settled')
            ->field('order_line_id,item_name_snapshot,item_type,quantity,line_no,sale_amount_cents')
            ->lock(true)->order('line_no', 'asc')->select()->toArray();
        if (count($rows) !== count($lineIds)) {
            throw self::failure('order_lifecycle_refund_line_scope_invalid');
        }
        return array_map(static function (array $row): array {
            return [
                'lineId' => (string)$row['order_line_id'],
                'itemName' => (string)$row['item_name_snapshot'],
                'itemType' => (string)$row['item_type'],
                'quantity' => (int)$row['quantity'],
                'lineNo' => (int)$row['line_no'],
                'saleAmountCents' => (int)$row['sale_amount_cents'],
            ];
        }, $rows);
    }

    /** @return array<string,mixed> */
    private function assertFinancialReversalEligible(array $source, string $action, array $input, CashierV3DataScopeContext $scope, int $entitlementCreditCents = 0): array
    {
        if (Db::name(self::OPERATION_TABLE)->where('tenant_id', $scope->tenantId())
            ->where('source_type', 'sales')->where('source_order_id', $source['sourceId'])
            ->whereIn('operation_type', ['refund', 'void'])->where('status', 'succeeded')->count() > 0) {
            throw self::failure('order_already_reversed');
        }
        if ($action === 'refund-sales-order') {
            $prepared = (new CashierV3SalesOrderReversalServices())->prepareFinancialRefund(
                $source, $input, $scope, $entitlementCreditCents
            );
            if ($entitlementCreditCents > 0 && (int)$prepared['economicReversalCents'] !== (int)$source['amountCents']) {
                throw self::failure('card_operation_upgrade_refund_must_reverse_full_order');
            }
            return $prepared;
        }
        // 卡项、余额、欠款及现金事实由专用服务在同一事务内冲销；
        // 未发生的业务事实自然为空并跳过。
        return (new CashierV3SalesOrderReversalServices())->prepare(
            $source, $action, $input, $scope, $entitlementCreditCents
        );
    }

    /**
     * Reverse every completed service fact produced by this sale, if any.
     * Service records are a separate domain and therefore use their own
     * append-only void operation/facts, but execute inside the parent order
     * transaction so the sale cannot become voided while service state stays
     * consumed. A plain product sale has no rows and is intentionally a no-op.
     */
    private function voidCompletedServiceFactsInTx(
        array $source,
        string $commandKey,
        CashierV3OperatorScope $operator,
        CashierV3DataScopeContext $scope,
        CashierV3BusinessEventRecorder $recorder,
        string $stateContextId
    ): void {
        $rows = Db::name('cashier_v3_entitlement_service_fact')
            ->where('tenant_id', $scope->tenantId())
            ->where('store_id', (int)$source['storeId'])
            ->where('checkout_request_id', (string)$source['checkoutRequestId'])
            ->where('service_status', 'completed')
            ->field('id')
            ->order('id', 'asc')
            ->lock(true)
            ->select()
            ->toArray();
        if ($rows === []) return;

        $serviceVoid = new CashierV3ServiceRecordVoidServices();
        $serviceContract = [
            'required_event_types' => ['service_record.voided'],
            'allowed_event_types' => ['service_record.voided'],
            'event_rules' => ['service_record.voided' => [
                'min_count' => 1, 'max_count' => 1,
                'aggregate_type' => 'service_record', 'source_type' => 'void-service-record',
            ]],
            'eventless_reason' => '',
            'activation_blocked_until_event_contract' => false,
            'consumers' => ['service_record.voided' => []],
        ];
        foreach ($rows as $row) {
            $serviceFactId = (int)($row['id'] ?? 0);
            if ($serviceFactId <= 0) throw self::failure('sales_void_service_fact_invalid');
            $serviceCommandKey = $commandKey . ':service:' . $serviceFactId;
            $serviceExecution = $recorder->newExecution(
                'void-service-record', $serviceCommandKey, $operator, $scope, $stateContextId
            );
            $serviceVoid->executeInTx('void-service-record', [
                'operator_scope' => $operator,
                'data_scope' => $scope,
                'event_recorder' => $recorder,
                'event_execution' => $serviceExecution,
                'event_contract' => $serviceContract,
                'idempotency_key' => $serviceCommandKey,
                'payload' => [
                    'serviceFactId' => (string)$serviceFactId,
                    'reason' => '销售订单作废：' . (string)$source['sourceNo'],
                ],
            ]);
        }
    }

    /** Read the immutable checkout event set once; absent events mean no work. */
    private function occurredCheckoutEvents(array $source, CashierV3DataScopeContext $scope): array
    {
        $requestId = trim((string)($source['checkoutRequestId'] ?? ''));
        $orderId = trim((string)($source['sourceId'] ?? ''));
        $query = Db::name(CashierV3BusinessEventRecorder::EVENT_TABLE)
            ->where('tenant_id', $scope->tenantId())
            ->where(function ($nested) use ($requestId, $orderId): void {
                if ($requestId !== '') $nested->where('source_id', $requestId);
                if ($orderId !== '') {
                    if ($requestId !== '') $nested->whereOr('aggregate_id', $orderId);
                    else $nested->where('aggregate_id', $orderId);
                }
            })
            ->field('event_type')
            ->select()
            ->toArray();
        $types = [];
        foreach ($query as $row) $types[(string)($row['event_type'] ?? '')] = true;
        return [
            'checkout' => isset($types['checkout.completed']),
            'inventory' => isset($types['inventory.sale.deducted'])
                || isset($types['inventory.batch.consumed']),
            'service' => isset($types['service.completed'])
                || isset($types['entitlement.writeoff.completed'])
                || isset($types['performance.labor.allocated'])
                || isset($types['performance.consumption.recorded']),
            // Multi-card upgrades have no card-operation/version ledger;
            // their immutable snapshot emits its own checkout event and must
            // enter the same atomic void/recovery boundary.
            'card' => isset($types['card.operation.settled'])
                || isset($types['card.operation.recorded'])
                || isset($types['card.multi_upgrade.settled']),
            'presale' => isset($types['gift.consumed'])
                || isset($types['entitlement.writeoff.completed']),
        ];
    }

    /**
     * 导购和销售经理归属事实没有独立的 reversal 行，沿用人员调整域
     * 的 status=effective/reversed 约定：作废时只关闭本单当前有效归属，
     * 原始快照仍保留用于审计，报表因此不再统计该单业绩。
     */
    private function voidOrderAttributionFactsInTx(array $source, CashierV3DataScopeContext $scope): void
    {
        foreach (['cashier_v3_customer_guide_round_fact', 'cashier_v3_sales_manager_fact'] as $table) {
            $rows = Db::name($table)
                ->where('tenant_id', $scope->tenantId())
                ->where('order_id', (string)$source['sourceId'])
                ->where('status', 'effective')
                ->field('id')
                ->order('id', 'asc')
                ->lock(true)
                ->select()
                ->toArray();
            foreach ($rows as $row) {
                $updated = Db::name($table)
                    ->where('id', (int)$row['id'])
                    ->where('status', 'effective')
                    ->update(['status' => 'reversed']);
                if ((int)$updated !== 1) throw self::failure('sales_void_attribution_reversal_race');
            }
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function writeFactReversals(array $source, string $action, array $input, array $financial, string $operationId, string $commandKey, array $event, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope, int $now): array
    {
        $paymentReversals = [];
        $tables = ['cashier_v3_sale_fact', 'cashier_v3_payment_fact', 'cashier_v3_performance_fact'];
        foreach ($tables as $table) {
            $rows = Db::name($table)->where('tenant_id', $scope->tenantId())->where('order_id', $source['sourceId'])
                ->where('fact_direction', 'forward')->where('status', 'effective')->lock(true)->order('id', 'asc')->select()->toArray();
            if ($action === 'void-sales-order') {
                foreach ($rows as $row) {
                    if ($table === 'cashier_v3_performance_fact'
                        && in_array((string)($row['performance_type'] ?? ''), ['consumption_performance_recorded', 'labor_performance_allocated'], true)) {
                        continue;
                    }
                    $reversal = $this->insertReversal($table, $row, $operationId, $commandKey, $event, $operator, $now);
                    if ($table === 'cashier_v3_payment_fact') $paymentReversals[] = $reversal;
                }
                continue;
            }
            if ($table === 'cashier_v3_sale_fact') {
                if ($action === 'refund-sales-order') {
                    $lineRefunds = [];
                    foreach ((array)$input['refundLines'] as $line) {
                        $lineRefunds[(string)$line['lineId']] = (int)$line['totalRefundCents'];
                    }
                    foreach ($rows as $row) {
                        $lineId = (string)($row['source_line_id'] ?? '');
                        $amount = (int)($lineRefunds[$lineId] ?? 0);
                        if ($amount <= 0) continue;
                        if ($amount > (int)$row['sale_amount_cents']) throw self::failure('order_lifecycle_refund_line_amount_exceeds_sale');
                        $this->insertReversal($table, $row, $operationId, $commandKey, $event, $operator, $now, [
                            'original_amount_cents' => -$amount, 'discount_amount_cents' => 0,
                            'sale_amount_cents' => -$amount, 'debt_amount_cents' => 0,
                        ]);
                    }
                    continue;
                }
                $amounts = $this->allocateFactAmount($rows, (int)$financial['economicReversalCents'], 'sale_amount_cents');
                $debts = $this->allocateFactAmount($rows, (int)$financial['cancelledDebtCents'], 'debt_amount_cents');
                foreach ($rows as $index => $row) {
                    if (($amounts[$index] ?? 0) <= 0) continue;
                    $amount = (int)$amounts[$index];
                    $this->insertReversal($table, $row, $operationId, $commandKey, $event, $operator, $now, [
                        'original_amount_cents' => -$amount, 'discount_amount_cents' => 0,
                        'sale_amount_cents' => -$amount, 'debt_amount_cents' => -(int)($debts[$index] ?? 0),
                    ]);
                }
                continue;
            }
            if ($table === 'cashier_v3_payment_fact') {
                $amounts = $this->allocateFactAmount($rows, (int)$financial['cashReversalCents'], 'amount_cents');
                foreach ($rows as $index => $row) if (($amounts[$index] ?? 0) > 0) {
                    $paymentReversals[] = $this->insertReversal(
                        $table, $row, $operationId, $commandKey, $event, $operator, $now,
                        ['amount_cents' => -(int)$amounts[$index]]
                    );
                }
                continue;
            }
            $groups = [];
            foreach ($rows as $index => $row) $groups[(string)($row['performance_type'] ?? '')][$index] = $row;
            foreach ($groups as $group) {
                if ($action === 'refund-sales-order') {
                    $cashByLine = [];
                    foreach ((array)$input['refundLines'] as $line) $cashByLine[(string)$line['lineId']] = (int)$line['cashRefundCents'];
                    $selected = [];
                    foreach ($group as $index => $row) if (isset($cashByLine[(string)($row['source_line_id'] ?? '')])) $selected[$index] = $row;
                    if ($selected === []) continue;
                    $target = min(array_sum(array_map(static function (array $line): int { return (int)$line['amount_cents']; }, $selected)), (int)$financial['cashReversalCents']);
                    $amounts = $this->allocateFactAmount($selected, $target, 'amount_cents');
                    foreach ($selected as $index => $row) if (($amounts[$index] ?? 0) > 0) {
                        $amount = (int)$amounts[$index];
                        $this->insertReversal($table, $row, $operationId, $commandKey, $event, $operator, $now, [
                            'allocation_base_amount_cents' => -$amount, 'amount_cents' => -$amount,
                        ]);
                    }
                    continue;
                }
                $groupTotal = array_sum(array_map(static function (array $row): int { return (int)($row['amount_cents'] ?? 0); }, $group));
                $target = min($groupTotal, (int)$financial['cashReversalCents']);
                $amounts = $this->allocateFactAmount($group, $target, 'amount_cents');
                foreach ($group as $index => $row) if (($amounts[$index] ?? 0) > 0) {
                    $amount = (int)$amounts[$index];
                    $this->insertReversal($table, $row, $operationId, $commandKey, $event, $operator, $now, [
                        'allocation_base_amount_cents' => -$amount, 'amount_cents' => -$amount,
                    ]);
                }
            }
        }
        return $paymentReversals;
    }

    private function recordFinancialReversal(array $source, string $action, string $operationId, string $commandKey, array $input, array $financial, CashierV3DataScopeContext $scope, int $now): string
    {
        $cash = (int)$input['cashRefundCents'];
        $reversedCash = (int)$financial['cashReversalCents'];
        $row = [
            'reversal_id' => 'OLF-' . strtoupper(substr(hash_hmac('sha256', $operationId . '|financial', $this->secret()), 0, 40)),
            'operation_id' => $operationId, 'tenant_id' => $scope->tenantId(), 'store_id' => (int)$source['storeId'], 'member_id' => (int)$source['memberId'],
            'source_order_id' => (string)$source['sourceId'], 'source_order_no_snapshot' => (string)$source['sourceNo'],
            'reversal_type' => $action === 'void-sales-order' ? 'void' : 'refund', 'cash_refund_cents' => $cash, 'reversed_cash_cents' => $reversedCash,
            'restored_principal_cents' => (int)$input['restorePrincipalCents'], 'restored_bonus_cents' => (int)$input['restoreBonusCents'],
            'payment_fact_ids_json' => json_encode(array_values(array_map(static function (array $fact): string { return (string)($fact['fact_id'] ?? ''); }, (array)$financial['paymentFacts'])), JSON_UNESCAPED_UNICODE),
            'command_idempotency_key' => $commandKey, 'status' => 'succeeded', 'occurred_at' => $now, 'created_at' => $now,
        ];
        if ((int)Db::name('cashier_v3_order_lifecycle_financial_reversal')->insert($row) !== 1) throw self::failure('order_lifecycle_financial_reversal_insert_failed');
        return (string)$row['reversal_id'];
    }

    /**
     * Allocates each refund component by immutable sale amounts.  The largest
     * remainder rule keeps every component exact to the cent and deterministic.
     */
    private function allocateRefundLines(array $lines, array $input, array $financial): array
    {
        $selectedAmount = array_sum(array_map(static function (array $line): int { return (int)$line['saleAmountCents']; }, $lines));
        $economic = (int)$financial['economicReversalCents'];
        if ($selectedAmount <= 0 || $economic <= 0 || $economic > $selectedAmount) {
            throw self::failure('order_lifecycle_refund_selected_sale_amount_invalid');
        }
        $cash = $this->allocateRefundComponent($lines, (int)$input['cashRefundCents']);
        $principal = $this->allocateRefundComponent($lines, (int)$input['restorePrincipalCents']);
        $bonus = $this->allocateRefundComponent($lines, (int)$input['restoreBonusCents']);
        foreach ($lines as $index => $line) {
            $line['cashRefundCents'] = (int)$cash[$index];
            $line['restorePrincipalCents'] = (int)$principal[$index];
            $line['restoreBonusCents'] = (int)$bonus[$index];
            $line['totalRefundCents'] = $line['cashRefundCents'] + $line['restorePrincipalCents'] + $line['restoreBonusCents'];
            $line['allocationWeightNumerator'] = (int)$line['saleAmountCents'];
            $line['allocationWeightDenominator'] = $selectedAmount;
            $lines[$index] = $line;
        }
        return $lines;
    }

    /** @return array<int,int> */
    private function allocateRefundComponent(array $lines, int $target): array
    {
        $result = array_fill(0, count($lines), 0);
        if ($target === 0) return $result;
        $total = array_sum(array_map(static function (array $line): int { return (int)$line['saleAmountCents']; }, $lines));
        if ($target < 0 || $total <= 0) throw self::failure('order_lifecycle_refund_line_allocation_invalid');

        // Refund details are displayed in whole yuan. When the component itself
        // is a whole-yuan amount, allocate yuan units by the immutable line
        // weights and give every leftover yuan to the largest-remainder line.
        // This keeps the stored component exact while avoiding values such as
        // 396.82 / 103.18 in the refund detail.
        if ($target % 100 === 0) {
            $targetYuan = intdiv($target, 100);
            $allocatedYuan = 0;
            $remainders = [];
            foreach ($lines as $index => $line) {
                $product = $targetYuan * (int)$line['saleAmountCents'];
                $resultYuan = intdiv($product, $total);
                $result[$index] = $resultYuan * 100;
                $allocatedYuan += $resultYuan;
                $remainders[] = [
                    'index' => $index,
                    'remainder' => $product % $total,
                    'lineNo' => (int)$line['lineNo'],
                    'lineId' => (string)$line['lineId'],
                ];
            }
            usort($remainders, static function (array $left, array $right): int {
                return $right['remainder'] <=> $left['remainder']
                    ?: ($left['lineNo'] <=> $right['lineNo'])
                    ?: strcmp($left['lineId'], $right['lineId']);
            });
            for ($remaining = $targetYuan - $allocatedYuan, $index = 0;
                $remaining > 0;
                $remaining--, $index++) {
                $result[$remainders[$index]['index']] += 100;
            }
            return $result;
        }

        // Preserve cent-level accounting for a non-whole-yuan component. The
        // caller can still choose a whole-yuan refund to receive integer detail.
        $allocated = 0;
        $remainders = [];
        foreach ($lines as $index => $line) {
            $product = bcmul((string)$target, (string)(int)$line['saleAmountCents'], 0);
            $result[$index] = (int)bcdiv($product, (string)$total, 0);
            $allocated += $result[$index];
            $remainders[] = ['index' => $index, 'remainder' => (int)bcmod($product, (string)$total), 'lineNo' => (int)$line['lineNo'], 'lineId' => (string)$line['lineId']];
        }
        usort($remainders, static function (array $left, array $right): int {
            return $right['remainder'] <=> $left['remainder'] ?: ($left['lineNo'] <=> $right['lineNo']) ?: strcmp($left['lineId'], $right['lineId']);
        });
        for ($remaining = $target - $allocated, $index = 0; $remaining > 0; $remaining--, $index++) $result[$remainders[$index]['index']]++;
        return $result;
    }

    private function recordRefundLineDetails(array $source, string $operationId, string $financialReversalId, string $commandKey, array $lines, CashierV3DataScopeContext $scope, int $now): void
    {
        foreach ($lines as $line) {
            $row = [
                'refund_line_id' => 'OLFL-' . strtoupper(substr(hash_hmac('sha256', $operationId . '|' . (string)$line['lineId'], $this->secret()), 0, 40)),
                'operation_id' => $operationId, 'financial_reversal_id' => $financialReversalId,
                'tenant_id' => $scope->tenantId(), 'store_id' => (int)$source['storeId'], 'member_id' => (int)$source['memberId'],
                'source_order_id' => (string)$source['sourceId'], 'source_order_no_snapshot' => (string)$source['sourceNo'],
                'sales_order_line_id' => (string)$line['lineId'], 'line_no' => (int)$line['lineNo'],
                'item_type_snapshot' => (string)$line['itemType'], 'item_name_snapshot' => (string)$line['itemName'], 'original_quantity' => (int)$line['quantity'],
                'selected_sale_amount_cents' => (int)$line['saleAmountCents'], 'cash_refund_cents' => (int)$line['cashRefundCents'],
                'restored_principal_cents' => (int)$line['restorePrincipalCents'], 'restored_bonus_cents' => (int)$line['restoreBonusCents'],
                'total_refund_cents' => (int)$line['totalRefundCents'], 'allocation_weight_numerator' => (int)$line['allocationWeightNumerator'],
                'allocation_weight_denominator' => (int)$line['allocationWeightDenominator'], 'command_idempotency_key' => $commandKey,
                'status' => 'succeeded', 'business_date' => date('Y-m-d', $now), 'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now,
            ];
            $row['immutable_fingerprint'] = hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if ((int)Db::name(self::REFUND_LINE_TABLE)->insert($row) !== 1) throw self::failure('order_lifecycle_refund_line_insert_failed');
        }
    }

    private function adjustPersonnelFacts(array $source, array $input, string $operationId, string $commandKey, array $event, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope, int $now): void
    {
        if (!$input['personnel']) throw self::failure('personnel_adjustment_empty');
        $targetLineId = (string)$input['targetOrderLineId'];
        $targetRole = (string)$input['targetRole'];
        // 订单中心调整只作用于已结算销售订单的一个明细和一个归属角色，
        // 绝不触碰收银前台工作台、草稿、结账控件或原始收银输入。
        foreach ($input['personnel'] as $item) {
            if (trim((string)($item['orderLineId'] ?? '')) !== $targetLineId
                || trim((string)($item['role'] ?? '')) !== $targetRole) {
                throw self::failure('personnel_adjustment_target_scope_invalid');
            }
        }
        // 一个明细/角色下，同一名员工只能出现一次。除了给前端明确的
        // 业务错误外，这也保证调整事实的唯一键不会因重复分配而冲突。
        $seenStaffIds = [];
        foreach ($input['personnel'] as $item) {
            $staffId = (int)($item['staffId'] ?? 0);
            if ($staffId <= 0 || isset($seenStaffIds[$staffId])) {
                throw self::failure('personnel_adjustment_duplicate_staff');
            }
            $seenStaffIds[$staffId] = true;
        }
        if ($targetRole === 'salesperson') {
            // 岗位独立标记必须以当前门店任职记录为准，不能信任订单中心
            // 传入的显示字段。先锁定并补齐每一行的权威岗位，再按分组校验。
            $authoritativePersonnel = [];
            $weightByGroup = [];
            foreach ($input['personnel'] as $item) {
                $rawWeight = $item['allocationWeight'] ?? $item['performance'] ?? null;
                $weight = (int)$rawWeight;
                if (!is_numeric((string)$rawWeight) || (float)$rawWeight !== (float)$weight || $weight < 1 || $weight > 100) {
                    throw self::failure('personnel_adjustment_salesperson_weight_invalid');
                }
                $staffId = (int)($item['staffId'] ?? 0);
                $staff = (array)Db::name('system_store_staff')->alias('s')
                    ->join('employee e', 'e.id=s.employee_id')
                    ->leftJoin('staff_job_position sjp', 'sjp.staff_id = s.id AND sjp.status = 1 AND sjp.is_del = 0 AND sjp.end_time = 0')
                    ->leftJoin('position p', 'p.id = sjp.position_id AND p.status = 1')
                    ->where('s.id', $staffId)->where('s.store_id', $operator->storeId())
                    ->where('s.status', 1)->where('s.is_del', 0)->where('e.status', 1)->where('e.is_del', 0)
                    ->where('s.cashier_salesperson_enabled', 1)
                    ->field('s.id,s.employee_id,s.staff_name,e.name,e.employment_type_code,e.employment_type_version,sjp.position_id,p.name as position_name,p.performance_independent')
                    ->lock(true)->find();
                if (!$staff || !in_array((string)($staff['employment_type_code'] ?? ''), ['internal', 'partner', 'outsourced'], true)
                    || (int)($staff['employment_type_version'] ?? 0) <= 0) {
                    throw self::failure('personnel_adjustment_staff_ineligible');
                }
                $positionId = max(0, (int)($staff['position_id'] ?? 0));
                $independent = (int)($staff['performance_independent'] ?? 0) === 1;
                $groupKey = $independent && $positionId > 0 ? 'independent:' . $positionId : 'normal';
                $weightByGroup[$groupKey] = ($weightByGroup[$groupKey] ?? 0) + $weight;
                $authoritativePersonnel[] = array_merge($item, [
                    'positionId' => $positionId,
                    'positionName' => trim((string)($staff['position_name'] ?? '')),
                    'performanceIndependent' => $independent,
                    'allocationGroupKey' => $groupKey,
                ]);
            }
            if (array_filter($weightByGroup, static fn (int $sum): bool => $sum !== 100)) throw self::failure('personnel_adjustment_salesperson_weight_total_invalid');
            $input['personnel'] = $authoritativePersonnel;
        }
        $reversedAttributions = [];
        $salespersonState = null;
        foreach ($input['personnel'] as $item) {
            $lineId = trim((string)($item['orderLineId'] ?? ''));
            $role = trim((string)($item['role'] ?? ''));
            $staffId = (int)($item['staffId'] ?? 0);
            if ($lineId === '' || !in_array($role, ['salesperson', 'craftsman', 'guide', 'sales_manager'], true) || $staffId <= 0) throw self::failure('personnel_adjustment_item_invalid');
            if (in_array($role, ['guide', 'sales_manager'], true)) {
                $this->adjustAttributionFact($source, $item, $operationId, $commandKey, $event, $operator, $scope, $now, $reversedAttributions);
                continue;
            }
            $factType = $role === 'salesperson' ? 'sales_performance_allocated' : 'labor_performance_allocated';
            $line = (array)Db::name('cashier_v3_sales_order_line')->where('tenant_id', $scope->tenantId())
                ->where('order_id', $source['sourceId'])->where('order_line_id', $lineId)
                ->where('line_direction', 'forward')->where('line_status', 'settled')->lock(true)->find();
            if (!$line || ($role === 'craftsman' && (string)$line['item_type'] !== 'project')) {
                throw self::failure('personnel_adjustment_line_ineligible');
            }
            $facts = $this->effectivePersonnelFactsForLine($scope->tenantId(), $source['sourceId'], $lineId, $factType);
            $staff = (array)Db::name('system_store_staff')->alias('s')->join('employee e', 'e.id=s.employee_id')->where('s.id', $staffId)->where('s.store_id', $operator->storeId())->where('s.status', 1)->where('s.is_del', 0)->where('e.status', 1)->where('e.is_del', 0)->lock(true)->field('s.id,s.employee_id,s.staff_name,s.cashier_salesperson_enabled,s.cashier_craftsman_enabled,e.name,e.employment_type_code,e.employment_type_version')->find();
            if (!$staff || ($role === 'salesperson' && (int)$staff['cashier_salesperson_enabled'] !== 1) || ($role === 'craftsman' && (int)$staff['cashier_craftsman_enabled'] !== 1)) throw self::failure('personnel_adjustment_staff_ineligible');
            if ($role === 'salesperson' && is_array($salespersonState)) {
                continue;
            }
            $total = 0; foreach ($facts as $fact) $total += abs((int)$fact['amount_cents']);
            foreach ($facts as $fact) $this->insertReversal('cashier_v3_performance_fact', $fact, $operationId, $commandKey, $event, $operator, $now);
            $template = $facts[0] ?? $this->personnelAdjustmentTemplate($scope->tenantId(), $source['sourceId']);
            if (!$facts && $role === 'salesperson') {
                $total = $this->cashPerformanceForLine($scope->tenantId(), $source['sourceId'], $lineId);
            }
            $marked = $role === 'salesperson' ? !empty($item['isPreSale']) : !empty($item['isPointCustomer']);
            if ($role === 'salesperson') {
                // Reverse the old allocation once, then write the complete new
                // allocation in one pass so multi-person amounts still sum to
                // the line's authoritative cash performance.
                $salespersonState = ['template' => $template, 'total' => $total];
                continue;
            }
            $this->insertAdjustedPerformance($template, $operationId, $commandKey, $event, $operator, $now, $staff, $role, $factType, $total, $lineId, $marked, $staffId);
        }
        if ($targetRole === 'salesperson') {
            if (!is_array($salespersonState)) throw self::failure('personnel_adjustment_line_ineligible');
            $amounts = $this->allocateWeightedCentsByGroups((int)$salespersonState['total'], $input['personnel']);
            foreach ($input['personnel'] as $index => $item) {
                $staffId = (int)($item['staffId'] ?? 0);
                $staff = (array)Db::name('system_store_staff')->alias('s')->join('employee e', 'e.id=s.employee_id')->where('s.id', $staffId)->where('s.store_id', $operator->storeId())->where('s.status', 1)->where('s.is_del', 0)->where('e.status', 1)->where('e.is_del', 0)->lock(true)->field('s.id,s.employee_id,s.staff_name,s.cashier_salesperson_enabled,s.cashier_craftsman_enabled,e.name,e.employment_type_code,e.employment_type_version')->find();
                if (!$staff || (int)$staff['cashier_salesperson_enabled'] !== 1) throw self::failure('personnel_adjustment_staff_ineligible');
                $this->insertAdjustedPerformance($salespersonState['template'], $operationId, $commandKey, $event, $operator, $now, $staff, 'salesperson', 'sales_performance_allocated', (int)$amounts[$index], $targetLineId, !empty($item['isPreSale']), $staffId);
            }
        }
    }

    /** Allocate cents by integer percentage; the final selected person receives any remainder. */
    private function allocateWeightedCents(int $total, array $personnel): array
    {
        $amounts = [];
        $allocated = 0;
        $last = count($personnel) - 1;
        foreach ($personnel as $index => $item) {
            if ($index === $last) {
                $amounts[$index] = $total - $allocated;
                continue;
            }
            $amounts[$index] = (int)floor($total * (int)($item['allocationWeight'] ?? $item['performance'] ?? 0) / 100);
            $allocated += $amounts[$index];
        }
        return $amounts;
    }

    /** Allocate the full amount independently for each normal/independent group. */
    private function allocateWeightedCentsByGroups(int $total, array $personnel): array
    {
        $indicesByGroup = [];
        foreach ($personnel as $index => $item) {
            $positionId = max(0, (int)($item['positionId'] ?? $item['position_id'] ?? 0));
            $independent = !empty($item['performanceIndependent']) || !empty($item['performance_independent']);
            $groupKey = $independent && $positionId > 0 ? 'independent:' . $positionId : 'normal';
            $indicesByGroup[$groupKey][] = $index;
        }
        $result = array_fill(0, count($personnel), 0);
        foreach ($indicesByGroup as $indices) {
            $groupPeople = array_map(static fn (int $index): array => $personnel[$index], $indices);
            $groupAmounts = $this->allocateWeightedCents($total, $groupPeople);
            foreach ($indices as $offset => $index) $result[$index] = (int)($groupAmounts[$offset] ?? 0);
        }
        return $result;
    }

    private function updateSalesOrderNote(array $source, string $note, int $now, CashierV3DataScopeContext $scope): void
    {
        $updated = (int)Db::name('cashier_v3_sales_order')->where('tenant_id', $scope->tenantId())
            ->where('organization_id', $scope->organizationId())->where('store_id', (int)$source['storeId'])
            ->where('order_id', (string)$source['sourceId'])->where('order_status', 'settled')->where('order_direction', 'forward')
            ->update(['order_note' => mb_substr($note, 0, 500), 'update_time' => $now]);
        if ($updated !== 1) throw self::failure('sales_order_note_update_failed');
    }

    /**
     * 导购和销售经理都只保留订单参与归属。旧有效事实保留为审计行并
     * 转为 reversed，再插入本次操作的新快照；不在此推导独立业绩。
     */
    private function adjustAttributionFact(array $source, array $item, string $operationId, string $commandKey, array $event, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope, int $now, array &$reversedAttributions): void
    {
        $lineId = trim((string)($item['orderLineId'] ?? ''));
        $role = trim((string)($item['role'] ?? ''));
        $staffId = (int)($item['staffId'] ?? 0);
        $line = (array)Db::name('cashier_v3_sales_order_line')->where('tenant_id', $scope->tenantId())->where('order_id', $source['sourceId'])
            ->where('order_line_id', $lineId)->where('line_direction', 'forward')->where('line_status', 'settled')->lock(true)->find();
        if (!$line) throw self::failure('personnel_adjustment_line_ineligible');
        // 导购和销售经理是集团归属身份，不是“当前门店任职岗位”。初始
        // 收银选择器按集团在职人员查询；调整命令必须沿用同一 employee
        // 身份校验，不能错误地把跨店销售经理拦在调整之外。
        $employee = (array)Db::name('employee')->where('id', $staffId)
            ->where('status', 1)->where('is_del', 0)
            ->field('id,name,employment_type_code')->lock(true)->find();
        if (!$employee || trim((string)($employee['name'] ?? '')) === '') throw self::failure('personnel_adjustment_staff_ineligible');
        $table = $role === 'guide' ? 'cashier_v3_customer_guide_round_fact' : 'cashier_v3_sales_manager_fact';
        $reverseKey = $table . ':' . $lineId;
        $sourceLineId = $this->attributionFactLineId($line);
        if (!isset($reversedAttributions[$reverseKey])) {
            $oldRows = Db::name($table)->where('tenant_id', $scope->tenantId())->where('order_id', $source['sourceId'])
                ->whereIn('source_line_id', $this->attributionFactLineIds($line))->where('status', 'effective')->lock(true)->select()->toArray();
            foreach ($oldRows as $old) {
                Db::name($table)->where('id', (int)$old['id'])->where('status', 'effective')->update(['status' => 'reversed']);
            }
            $reversedAttributions[$reverseKey] = true;
        }
        $name = mb_substr(trim((string)$employee['name']), 0, 128);
        $common = [
            'tenant_id' => $scope->tenantId(), 'organization_id' => $scope->organizationId(), 'store_id' => $operator->storeId(),
            'member_id' => (int)$source['memberId'], 'order_id' => $source['sourceId'], 'source_line_id' => $sourceLineId,
            'business_date' => date('Y-m-d', $now), 'operator_id' => $operator->operatorId(), 'business_event_no' => (string)$event['event_no'],
            'command_idempotency_key' => $commandKey, 'occurred_at' => $now, 'recorded_at' => $now, 'status' => 'effective',
        ];
        if ($role === 'guide') {
            $round = (int)($item['guideRoundNo'] ?? $item['guide_round_no'] ?? 0);
            if ($round < 1 || $round > 3) throw self::failure('guide_round_required');
            $natural = 'sales-order-adjust:' . $operationId . ':' . $lineId . ':guide:' . $staffId . ':' . $round;
            $row = $common + ['fact_id' => 'GRA-' . strtoupper(substr(hash('sha256', $scope->tenantId() . '|' . $natural), 0, 40)), 'natural_key' => $natural,
                'immutable_fingerprint' => '', 'member_name_snapshot' => '', 'order_no_snapshot' => $source['sourceNo'], 'checkout_request_id' => $source['checkoutRequestId'],
                'guide_round_no' => $round, 'guide_employee_id' => $staffId, 'guide_employee_name_snapshot' => $name, 'guide_employee_type_snapshot' => (string)($employee['employment_type_code'] ?? ''), 'operator_name_snapshot' => ''];
        } else {
            $natural = 'sales-order-adjust:' . $operationId . ':' . $lineId . ':sales_manager:' . $staffId;
            $row = $common + ['fact_id' => 'SMA-' . strtoupper(substr(hash('sha256', $scope->tenantId() . '|' . $natural), 0, 40)), 'natural_key' => $natural,
                'immutable_fingerprint' => '', 'order_no_snapshot' => $source['sourceNo'], 'checkout_request_id' => $source['checkoutRequestId'],
                'sales_manager_employee_id' => $staffId, 'sales_manager_name_snapshot' => $name, 'sales_manager_type_snapshot' => (string)($employee['employment_type_code'] ?? '')];
        }
        $row['immutable_fingerprint'] = hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ((int)Db::name($table)->insert($row) !== 1) throw self::failure('personnel_adjustment_attribution_insert_failed');
    }

    /** The checkout line is canonical for attribution facts; old rows may use the formal order line. */
    private function attributionFactLineId(array $line): string
    {
        $checkoutLineId = trim((string)($line['checkout_line_id'] ?? ''));
        return $checkoutLineId !== '' ? $checkoutLineId : trim((string)($line['order_line_id'] ?? ''));
    }

    /** @return array<int,string> Every historical source-line key for one settled sales line. */
    private function attributionFactLineIds(array $line): array
    {
        $keys = [];
        foreach ([(string)($line['order_line_id'] ?? ''), (string)($line['checkout_line_id'] ?? '')] as $value) {
            $value = trim($value);
            if ($value !== '') $keys[$value] = $value;
        }
        if ($keys === []) throw self::failure('personnel_adjustment_line_ineligible');
        return array_values($keys);
    }

    /** @return array<int,array<string,mixed>> */
    private function effectivePersonnelFactsForLine(string $tenantId, string $orderId, string $lineId, string $factType): array
    {
        $rows = Db::name('cashier_v3_performance_fact')->where('tenant_id', $tenantId)->where('order_id', $orderId)
            ->where('source_line_id', $lineId)->where('performance_type', $factType)->where('status', 'effective')
            ->whereIn('fact_direction', ['forward', 'reversal'])->lock(true)->order('id', 'asc')->select()->toArray();
        $reversed = [];
        foreach ($rows as $row) {
            if ((string)$row['fact_direction'] === 'reversal' && (string)($row['reversal_of'] ?? '') !== '') {
                $reversed[(string)$row['reversal_of']] = true;
            }
        }
        return array_values(array_filter($rows, static function (array $row) use ($reversed): bool {
            return (string)$row['fact_direction'] === 'forward' && !isset($reversed[(string)$row['fact_id']]);
        }));
    }

    private function personnelAdjustmentTemplate(string $tenantId, string $orderId): array
    {
        $template = (array)Db::name('cashier_v3_performance_fact')->where('tenant_id', $tenantId)->where('order_id', $orderId)
            ->where('performance_type', 'actual_performance_recorded')->where('fact_direction', 'forward')
            ->where('status', 'effective')->lock(true)->order('id', 'asc')->find();
        if (!$template) throw self::failure('personnel_adjustment_template_missing');
        return $template;
    }

    private function cashPerformanceForLine(string $tenantId, string $orderId, string $lineId): int
    {
        $lines = Db::name('cashier_v3_sales_order_line')->where('tenant_id', $tenantId)->where('order_id', $orderId)
            ->where('line_direction', 'forward')->where('line_status', 'settled')
            ->field('order_line_id,sale_amount_cents')->lock(true)->order('line_no', 'asc')->select()->toArray();
        $batch = (array)Db::name('cashier_v3_payment_collection_batch')->where('tenant_id', $tenantId)
            ->where('sales_order_id', $orderId)->where('batch_direction', 'forward')->where('batch_status', 'settled')
            ->field('cash_performance_amount_cents')->lock(true)->find();
        if (!$lines || !$batch) throw self::failure('personnel_adjustment_cash_authority_missing');
        $amount = (int)$batch['cash_performance_amount_cents'];
        $total = array_sum(array_map(static function (array $line): int { return (int)$line['sale_amount_cents']; }, $lines));
        if ($amount < 0 || $total <= 0 || $amount > $total) throw self::failure('personnel_adjustment_cash_equation_invalid');
        $shares = [];
        $remainders = [];
        $allocated = 0;
        foreach (array_values($lines) as $index => $line) {
            $id = (string)$line['order_line_id'];
            $product = bcmul((string)$amount, (string)(int)$line['sale_amount_cents'], 0);
            $share = (int)bcdiv($product, (string)$total, 0);
            $shares[$id] = $share;
            $remainders[] = ['id' => $id, 'remainder' => (int)bcmod($product, (string)$total), 'index' => $index];
            $allocated += $share;
        }
        usort($remainders, static function (array $left, array $right): int {
            return $right['remainder'] <=> $left['remainder'] ?: $left['index'] <=> $right['index'];
        });
        for ($remaining = $amount - $allocated, $index = 0; $remaining > 0; $remaining--, $index++) {
            $shares[$remainders[$index]['id']]++;
        }
        if (!array_key_exists($lineId, $shares)) throw self::failure('personnel_adjustment_line_cash_missing');
        return (int)$shares[$lineId];
    }

    /** @return array<int,int> allocations keyed like the source rows */
    private function allocateFactAmount(array $rows, int $target, string $column): array
    {
        $out = [];
        foreach ($rows as $index => $row) $out[$index] = 0;
        if ($target === 0) return $out;
        $total = array_sum(array_map(static function (array $row) use ($column): int {
            return max(0, (int)($row[$column] ?? 0));
        }, $rows));
        if ($target < 0 || $target > $total || $total <= 0) throw self::failure('order_lifecycle_fact_allocation_invalid');
        $rank = [];
        $allocated = 0;
        foreach ($rows as $index => $row) {
            $sourceAmount = max(0, (int)($row[$column] ?? 0));
            $product = bcmul((string)$sourceAmount, (string)$target, 0);
            $base = (int)bcdiv($product, (string)$total, 0);
            $out[$index] = $base;
            $allocated += $base;
            $rank[] = ['index' => $index, 'remainder' => (int)bcmod($product, (string)$total), 'factId' => (string)($row['fact_id'] ?? '')];
        }
        usort($rank, static function (array $left, array $right): int {
            return $right['remainder'] <=> $left['remainder'] ?: strcmp($left['factId'], $right['factId']);
        });
        for ($remaining = $target - $allocated, $i = 0; $remaining > 0; $remaining--, $i++) {
            $out[$rank[$i]['index']]++;
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function insertReversal(string $table, array $source, string $operationId, string $commandKey, array $event, CashierV3OperatorScope $operator, int $now, array $amountOverrides = []): array
    {
        $factId = 'OLR-' . strtoupper(substr(hash_hmac('sha256', $table . '|' . $source['fact_id'] . '|' . $operationId, $this->secret()), 0, 40));
        $row = $source; unset($row['id']);
        $row['fact_id'] = $factId; $row['business_event_no'] = (string)$event['event_no']; $row['fact_direction'] = 'reversal'; $row['natural_key'] = 'order_lifecycle:reversal:' . hash('sha256', $table . '|' . $source['fact_id'] . '|' . $operationId); $row['command_idempotency_key'] = $commandKey; $row['fact_version'] = 1; $row['reversal_of'] = (string)$source['fact_id']; $row['operator_id'] = $operator->operatorId(); $row['business_date'] = date('Y-m-d', $now); $row['occurred_at'] = $now; $row['settled_at'] = $now; $row['recorded_at'] = $now;
        if (!$amountOverrides) {
            $columns = $table === 'cashier_v3_sale_fact'
                ? ['original_amount_cents', 'discount_amount_cents', 'coupon_discount_cents', 'sale_amount_cents', 'debt_amount_cents']
                : ($table === 'cashier_v3_performance_fact' ? ['allocation_base_amount_cents', 'amount_cents', 'labor_fee_amount_cents'] : ['amount_cents']);
            foreach ($columns as $column) $row[$column] = -(int)($source[$column] ?? 0);
        }
        foreach ($amountOverrides as $column => $amount) $row[$column] = (int)$amount;
        // Sale/payment/performance corrections are signed rows. Readers sum
        // their amount columns directly; fact_direction remains an audit tag.
        $row['immutable_fingerprint'] = hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ((int)Db::name($table)->insert($row) !== 1) throw self::failure('order_lifecycle_reversal_insert_failed');
        return $row;
    }

    private function insertAdjustedPerformance(array $template, string $operationId, string $commandKey, array $event, CashierV3OperatorScope $operator, int $now, array $staff, string $role, string $factType, int $amount, string $lineId, bool $marked, int $staffId): void
    {
        // 事实 ID 必须区分同一条明细/角色下的不同员工；旧算法只拼
        // operation/line/role，多人分配时会生成相同主键并触发 1062。
        $allocationIdentity = $operationId . '|' . $lineId . '|' . $role . '|' . $staffId;
        $factId = 'OLA-' . strtoupper(substr(hash_hmac('sha256', $allocationIdentity, $this->secret()), 0, 40));
        $row = $template; unset($row['id']);
        $row['fact_id'] = $factId; $row['business_event_no'] = (string)$event['event_no']; $row['fact_type'] = $factType; $row['performance_type'] = $factType; $row['fact_direction'] = 'forward'; $row['natural_key'] = 'order_lifecycle:adjust:' . hash('sha256', $allocationIdentity); $row['command_idempotency_key'] = $commandKey; $row['fact_version'] = 1; $row['reversal_of'] = ''; $row['operator_id'] = $operator->operatorId(); $row['business_date'] = date('Y-m-d', $now); $row['occurred_at'] = $now; $row['settled_at'] = $now; $row['recorded_at'] = $now; $row['source_line_id'] = $lineId; $row['employee_id'] = (int)$staff['employee_id']; $row['employee_name_snapshot'] = trim((string)$staff['name']) ?: (string)$staff['staff_name']; $row['employee_type_snapshot'] = (string)$staff['employment_type_code']; $row['employee_type_authority_version'] = max(1, (int)$staff['employment_type_version']); $row['role_snapshot'] = $role . ($role === 'salesperson' ? ($marked ? ':presale' : ':postsale') : ($marked ? ':point' : ':round')); $row['allocation_weight_numerator'] = $amount > 0 ? $amount : 0; $row['allocation_weight_denominator'] = max(1, $amount); $row['allocation_base_amount_cents'] = $amount; $row['amount_cents'] = $amount; $row['rule_code_snapshot'] = 'ORDER-PERSONNEL-ADJUST-V1'; $row['rule_name_snapshot'] = '订单人员调整'; $row['rule_version_snapshot'] = 'v1'; $row['immutable_fingerprint'] = hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ((int)Db::name('cashier_v3_performance_fact')->insert($row) !== 1) throw self::failure('personnel_adjustment_fact_insert_failed');
    }

    private function createReopenDraft(array $source, array $input, string $operationId, string $commandKey, int $now, CashierV3DataScopeContext $scope): string
    {
        $lines = Db::name('cashier_v3_sales_order_line')->where('tenant_id', $scope->tenantId())
            ->where('order_id', $source['sourceId'])->where('line_direction', 'forward')->where('line_status', 'settled')
            ->field('order_line_id,item_type,item_id,catalog_sku_id,item_version,quantity')->order('line_no asc')->select()->toArray();
        if (!$lines) throw self::failure('reopen_sales_order_lines_missing');
        $draftId = 'ORD-' . strtoupper(substr(hash_hmac('sha256', $source['sourceId'] . '|' . $operationId, $this->secret()), 0, 40));
        if ((int)Db::name(self::REOPEN_TABLE)->insert(['draft_id' => $draftId, 'tenant_id' => $scope->tenantId(), 'store_id' => (int)$source['storeId'], 'member_id' => (int)$source['memberId'], 'source_order_id' => $source['sourceId'], 'source_order_no_snapshot' => $source['sourceNo'], 'operation_id' => $operationId, 'command_idempotency_key' => $commandKey, 'line_snapshot_json' => json_encode($lines, JSON_UNESCAPED_UNICODE), 'draft_status' => 'ready', 'created_at' => $now, 'updated_at' => $now]) !== 1) throw self::failure('reopen_draft_insert_failed');
        return $draftId;
    }

    /**
     * Reopen is a new sale draft, never a mutation of the settled source.
     * It reads every SKU from the live catalogue so off-shelf items, current
     * prices and all card/stock eligibility checks are applied again.
     */
    private function loadReopenDraftToWorkspace(string $draftId, array $source, string $commandKey, bool $replaceWorkspace, string $stateContextId, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope, int $now): array
    {
        if (!$this->workspace instanceof CashierV3CashierWorkspaceServices || !$this->saleCatalog instanceof CashierV3SaleCatalogServices || $stateContextId === '') {
            throw self::failure('reopen_workspace_service_unavailable');
        }
        $draft = (array)Db::name(self::REOPEN_TABLE)->where('draft_id', $draftId)->where('tenant_id', $scope->tenantId())->where('store_id', $operator->storeId())->where('draft_status', 'ready')->lock(true)->find();
        $lines = json_decode((string)($draft['line_snapshot_json'] ?? ''), true);
        if (!$draft || !is_array($lines) || $lines === []) throw self::failure('reopen_draft_not_ready');
        $workspaceId = \app\services\cashier\v3\CashierV3CheckoutWorkspaceIdentity::id($operator->storeId(), $stateContextId);
        $memberDraft = $this->workspace->lockForSaleMutationInTx($workspaceId, $stateContextId, $operator);
        if ((array)($this->workspace->readDraft($workspaceId, $stateContextId, $operator, true)['lines'] ?? []) !== []) {
            if ($replaceWorkspace) {
                $this->workspace->clearForReopenInTx($workspaceId, $stateContextId, $operator);
            } else {
            throw new CashierV3CommandException(CashierV3ResultCode::RESOURCE_VERSION_CONFLICT, '当前购物车不为空，请先完成或清空现有内容后再重开订单。', CashierV3ResultCode::STATUS_CONFLICT, ['reason' => 'reopen_workspace_not_empty']);
            }
        }
        if ((int)$source['memberId'] > 0) {
            $this->workspace->selectMemberInTx($workspaceId, $stateContextId, $operator, (int)$source['memberId']);
            $memberDraft = $this->workspace->lockForSaleMutationInTx($workspaceId, $stateContextId, $operator);
        } else {
            $memberDraft = $this->workspace->selectGuestInTx($workspaceId, $stateContextId, $operator);
        }
        foreach ($lines as $lineIndex => $line) {
            $skuId = (int)($line['catalog_sku_id'] ?? 0);
            $quantity = (int)($line['quantity'] ?? 0);
            if ($skuId <= 0 || $quantity <= 0 || $quantity > 1000) throw self::failure('reopen_line_snapshot_invalid');
            for ($index = 0; $index < $quantity; $index++) {
                $lineKey = 'reopen:' . $draftId . ':' . (string)($line['order_line_id'] ?? $lineIndex) . ':' . $index;
                $currentLine = $this->saleCatalog->selectSaleLineInTx($skuId, $lineKey, $operator, $scope);
                $this->workspace->assertCardSaleMemberInTx($memberDraft, $currentLine);
                $current = $this->workspace->appendSaleLineInTx($workspaceId, $stateContextId, $operator, $currentLine);
            }
        }
        $updated = (int)Db::name(self::REOPEN_TABLE)->where('draft_id', $draftId)->where('draft_status', 'ready')->update(['draft_status' => 'loaded', 'updated_at' => $now]);
        if ($updated !== 1) throw self::failure('reopen_draft_load_conflict');
        return $current;
    }

    private function nextVersion(array $source, string $tenantId): int { return 1 + (int)Db::name(self::OPERATION_TABLE)->where('tenant_id', $tenantId)->where('source_type', $source['sourceType'])->where('source_order_id', $source['sourceId'])->count(); }
    private function operationType(string $action): string { return $action === 'update-sales-order-note' ? 'note_update' : (strpos($action, 'adjust-') === 0 ? 'personnel_adjustment' : (strpos($action, 'refund-') === 0 ? 'refund' : (strpos($action, 'void-') === 0 ? 'void' : 'reopen'))); }
    private function eventType(string $action): string { return str_replace(['adjust-sales-order-personnel','update-sales-order-note','refund-sales-order','void-sales-order','reopen-sales-order'], ['sales_order.personnel_adjusted','sales_order.note_updated','sales_order.refunded','sales_order.voided','sales_order.reopened'], $action); }
    private function operationNo(string $action, string $id, int $time, string $tenant): string { $prefix = $action === 'update-sales-order-note' ? 'BZ' : (strpos($action, 'refund-') === 0 ? 'TK' : (strpos($action, 'void-') === 0 ? 'ZF' : (strpos($action, 'reopen-') === 0 ? 'CK' : 'RY'))); return $prefix . date('ymd', $time) . strtoupper(substr(hash('sha256', $tenant . '|' . $id), 0, 5)); }
    private function result(array $row, bool $replayed): array { $touched = ['sales_order']; if ((int)($row['restored_principal_cents'] ?? 0) + (int)($row['restored_bonus_cents'] ?? 0) > 0) $touched[] = 'member_balance'; $message = (string)$row['operation_type'] === 'void' ? '订单已作废。' : '订单操作已完成。'; return ['contractVersion' => self::CONTRACT_VERSION, 'operationId' => (string)$row['operation_id'], 'operationNo' => (string)$row['operation_no'], 'operationType' => (string)$row['operation_type'], 'sourceOrderNo' => (string)$row['source_order_no_snapshot'], 'status' => (string)$row['status'], 'replayed' => $replayed, 'touchedRoles' => $touched, 'message' => $message]; }
    private function secret(): string { $secret = trim((string)config('cashier_v3.checkout_namespace_secret')); if (strlen($secret) < 32) throw self::failure('order_lifecycle_secret_missing'); return $secret; }
    private function moneyCents($value): int { $raw = trim((string)$value); if (preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/D', $raw) !== 1) throw self::failure('order_lifecycle_money_invalid'); [$a,$b] = array_pad(explode('.', $raw, 2), 2, ''); return (int)$a * 100 + (int)str_pad($b, 2, '0'); }
    private function optionalMoneyCents($value): int { return $this->moneyCents($value); }
    private static function failure(string $reason): CashierV3CommandException { return new CashierV3CommandException(CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE, '订单资料或后续使用状态已变化，本次操作未提交。', CashierV3ResultCode::STATUS_FAILED, ['reason' => $reason]); }
}
