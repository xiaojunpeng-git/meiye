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
 * business event.  Financial/benefit reversals are admitted only after the
 * source has passed the downstream-use gate; ambiguous legacy records are
 * rejected rather than being silently corrected.
 */
final class CashierV3OrderLifecycleServices
{
    public const OPERATION_TABLE = 'cashier_v3_order_lifecycle_operation';
    public const REOPEN_TABLE = 'cashier_v3_order_reopen_draft';
    public const REFUND_LINE_TABLE = 'cashier_v3_order_lifecycle_refund_line';
    public const CONTRACT_VERSION = 'cashier-v3-order-lifecycle-v1';

    private const SALES_ACTIONS = [
        'adjust-sales-order-personnel', 'refund-sales-order', 'void-sales-order', 'reopen-sales-order',
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
            'payload' => ['contractVersion' => self::CONTRACT_VERSION, 'operationId' => $operationId, 'operationNo' => $operationNo, 'sourceOrderNo' => $source['sourceNo'], 'action' => $action],
        ]);

        $reopenDraftId = '';
        if ($action === 'adjust-sales-order-personnel') {
            $this->adjustPersonnelFacts($source, $input, $operationId, $commandKey, $event, $operator, $dataScope, $now);
        } elseif ($action === 'reopen-sales-order') {
            $reopenDraftId = $this->createReopenDraft($source, $input, $operationId, $commandKey, $now, $dataScope);
        } else {
            // A void/refund is an append-only reversal, never an order-status
            // overwrite.  Unsupported current-right domains are deliberately
            // rejected before a single accounting row is written. Product
            // inventory is restored only for a full void, never a refund.
            $cardOperationReversal = (new CashierV3CardOperationReversalServices())->prepare(
                $source, $action, $dataScope
            );
            $financial = $this->assertFinancialReversalEligible(
                $source, $action, $input, $dataScope, (int)$cardOperationReversal['entitlementCreditCents']
            );
            // A financial reversal never pulls back a completed presale
            // delivery. It only closes all remaining claim opportunities for
            // this source order, inside the same transaction as the reversal.
            (new CashierV3PresaleClaimServices())->closeForSalesOrderReversalInTx(
                $dataScope->tenantId(), (string)$source['sourceId'], $operationId, $now
            );
            $input['cashRefundCents'] = (int)$financial['cashRefundCents'];
            $input['restorePrincipalCents'] = (int)$financial['restorePrincipalCents'];
            $input['restoreBonusCents'] = (int)$financial['restoreBonusCents'];
            if ($action === 'void-sales-order') {
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
            ->field('order_line_id,item_type,item_name_snapshot')->order('line_no asc')->select()->toArray();
        $eligible = Db::name('system_store_staff')->alias('s')->join('employee e', 'e.id=s.employee_id')
            ->where('s.store_id', $operator->storeId())->where('s.status', 1)->where('s.is_del', 0)
            ->where('e.status', 1)->where('e.is_del', 0)
            ->field('s.id,s.staff_name,s.cashier_salesperson_enabled,s.cashier_craftsman_enabled,e.name')->order('s.id asc')->select()->toArray();
        $salespeople = [];
        foreach (Db::name('cashier_v3_performance_fact')->where('tenant_id', $scope->tenantId())->where('order_id', $source['sourceId'])
            ->where('performance_type', 'sales_performance_allocated')->where('fact_direction', 'forward')->where('status', 'effective')
            ->field('source_line_id,employee_id,employee_name_snapshot')->order('id asc')->select()->toArray() as $fact) {
            $salespeople[(string)$fact['source_line_id']][] = ['employeeId' => (int)$fact['employee_id'], 'name' => (string)$fact['employee_name_snapshot']];
        }
        return ['contractVersion' => self::CONTRACT_VERSION, 'salesOrderId' => (string)$source['sourceId'], 'salesOrderNo' => (string)$source['sourceNo'],
            'lines' => array_map(static function (array $line) use ($salespeople): array { return ['orderLineId' => (string)$line['order_line_id'], 'itemType' => (string)$line['item_type'], 'itemName' => (string)$line['item_name_snapshot'], 'currentSalespeople' => $salespeople[(string)$line['order_line_id']] ?? [], 'canAdjustCraftsman' => (string)$line['item_type'] === 'project']; }, $lines),
            'salespeople' => array_values(array_map(static function (array $row): array { return ['staffId' => (int)$row['id'], 'name' => trim((string)$row['name']) ?: (string)$row['staff_name']]; }, array_filter($eligible, static function (array $row): bool { return (int)$row['cashier_salesperson_enabled'] === 1; }))),
            'craftsmen' => array_values(array_map(static function (array $row): array { return ['staffId' => (int)$row['id'], 'name' => trim((string)$row['name']) ?: (string)$row['staff_name']]; }, array_filter($eligible, static function (array $row): bool { return (int)$row['cashier_craftsman_enabled'] === 1; })))];
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
        return ['sourceType' => 'sales', 'sourceId' => $id, 'sourceRecordId' => (int)$row['id'], 'resourceId' => $id, 'sourceNo' => (string)$row['order_no'], 'memberId' => (int)$row['member_id'], 'storeId' => (int)$row['store_id'], 'storeName' => (string)$row['store_name_snapshot'], 'checkoutRequestId' => (string)$row['checkout_request_id'], 'amountCents' => (int)$row['sale_amount_cents'], 'tenantId' => $scope->tenantId()];
    }

    private function input(string $action, array $payload, array $source): array
    {
        if (!in_array($action, self::SALES_ACTIONS, true) || $source['sourceType'] !== 'sales') throw self::failure('order_lifecycle_action_source_mismatch');
        $reason = trim((string)($payload['reason'] ?? ''));
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
        return ['reason' => $reason, 'cashRefundCents' => $cashRefund, 'restorePrincipalCents' => $restorePrincipal, 'restoreBonusCents' => $restoreBonus, 'refundLines' => $refundLines, 'replaceWorkspace' => !empty($payload['replaceWorkspace']), 'personnel' => is_array($payload['personnel'] ?? null) ? array_values($payload['personnel']) : []];
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
        $hasEntitlement = Db::name('cashier_v3_entitlement_completion_receipt')
            ->where('tenant_id', $scope->tenantId())
            ->where('checkout_request_id', $source['checkoutRequestId'])->count() > 0;
        // 卡项由专用权益撤销服务逐张验证，仅完全未使用时放行。商品
        // 的原批次库存回补在同一事务内由专用库存冲销服务完成。
        if ($hasEntitlement) throw self::failure('order_reversal_entitlement_already_consumed');
        return (new CashierV3SalesOrderReversalServices())->prepare(
            $source, $action, $input, $scope, $entitlementCreditCents
        );
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
        foreach ($input['personnel'] as $item) {
            $lineId = trim((string)($item['orderLineId'] ?? ''));
            $role = trim((string)($item['role'] ?? ''));
            $staffId = (int)($item['staffId'] ?? 0);
            if ($lineId === '' || !in_array($role, ['salesperson', 'craftsman'], true) || $staffId <= 0) throw self::failure('personnel_adjustment_item_invalid');
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
            $total = 0; foreach ($facts as $fact) $total += abs((int)$fact['amount_cents']);
            foreach ($facts as $fact) $this->insertReversal('cashier_v3_performance_fact', $fact, $operationId, $commandKey, $event, $operator, $now);
            $template = $facts[0] ?? $this->personnelAdjustmentTemplate($scope->tenantId(), $source['sourceId']);
            if (!$facts && $role === 'salesperson') {
                $total = $this->cashPerformanceForLine($scope->tenantId(), $source['sourceId'], $lineId);
            }
            $marked = $role === 'salesperson' ? !empty($item['isPreSale']) : !empty($item['isPointCustomer']);
            $this->insertAdjustedPerformance($template, $operationId, $commandKey, $event, $operator, $now, $staff, $role, $factType, $total, $lineId, $marked);
        }
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

    private function insertAdjustedPerformance(array $template, string $operationId, string $commandKey, array $event, CashierV3OperatorScope $operator, int $now, array $staff, string $role, string $factType, int $amount, string $lineId, bool $marked): void
    {
        $factId = 'OLA-' . strtoupper(substr(hash_hmac('sha256', $operationId . '|' . $lineId . '|' . $role, $this->secret()), 0, 40));
        $row = $template; unset($row['id']);
        $row['fact_id'] = $factId; $row['business_event_no'] = (string)$event['event_no']; $row['fact_type'] = $factType; $row['performance_type'] = $factType; $row['fact_direction'] = 'forward'; $row['natural_key'] = 'order_lifecycle:adjust:' . hash('sha256', $operationId . '|' . $lineId . '|' . $role); $row['command_idempotency_key'] = $commandKey; $row['fact_version'] = 1; $row['reversal_of'] = ''; $row['operator_id'] = $operator->operatorId(); $row['business_date'] = date('Y-m-d', $now); $row['occurred_at'] = $now; $row['settled_at'] = $now; $row['recorded_at'] = $now; $row['source_line_id'] = $lineId; $row['employee_id'] = (int)$staff['employee_id']; $row['employee_name_snapshot'] = trim((string)$staff['name']) ?: (string)$staff['staff_name']; $row['employee_type_snapshot'] = (string)$staff['employment_type_code']; $row['employee_type_authority_version'] = max(1, (int)$staff['employment_type_version']); $row['role_snapshot'] = $role . ($role === 'salesperson' ? ($marked ? ':presale' : ':postsale') : ($marked ? ':point' : ':round')); $row['allocation_weight_numerator'] = $amount > 0 ? $amount : 0; $row['allocation_weight_denominator'] = max(1, $amount); $row['allocation_base_amount_cents'] = $amount; $row['amount_cents'] = $amount; $row['rule_code_snapshot'] = 'ORDER-PERSONNEL-ADJUST-V1'; $row['rule_name_snapshot'] = '订单人员调整'; $row['rule_version_snapshot'] = 'v1'; $row['immutable_fingerprint'] = hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
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
    private function operationType(string $action): string { return strpos($action, 'adjust-') === 0 ? 'personnel_adjustment' : (strpos($action, 'refund-') === 0 ? 'refund' : (strpos($action, 'void-') === 0 ? 'void' : 'reopen')); }
    private function eventType(string $action): string { return str_replace(['adjust-sales-order-personnel','refund-sales-order','void-sales-order','reopen-sales-order'], ['sales_order.personnel_adjusted','sales_order.refunded','sales_order.voided','sales_order.reopened'], $action); }
    private function operationNo(string $action, string $id, int $time, string $tenant): string { $prefix = strpos($action, 'refund-') === 0 ? 'TK' : (strpos($action, 'void-') === 0 ? 'ZF' : (strpos($action, 'reopen-') === 0 ? 'CK' : 'RY')); return $prefix . date('ymd', $time) . strtoupper(substr(hash('sha256', $tenant . '|' . $id), 0, 5)); }
    private function result(array $row, bool $replayed): array { $touched = ['sales_order']; if ((int)($row['restored_principal_cents'] ?? 0) + (int)($row['restored_bonus_cents'] ?? 0) > 0) $touched[] = 'member_balance'; return ['contractVersion' => self::CONTRACT_VERSION, 'operationId' => (string)$row['operation_id'], 'operationNo' => (string)$row['operation_no'], 'operationType' => (string)$row['operation_type'], 'sourceOrderNo' => (string)$row['source_order_no_snapshot'], 'status' => (string)$row['status'], 'replayed' => $replayed, 'touchedRoles' => $touched, 'message' => '订单操作已完成。']; }
    private function secret(): string { $secret = trim((string)config('cashier_v3.checkout_namespace_secret')); if (strlen($secret) < 32) throw self::failure('order_lifecycle_secret_missing'); return $secret; }
    private function moneyCents($value): int { $raw = trim((string)$value); if (preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/D', $raw) !== 1) throw self::failure('order_lifecycle_money_invalid'); [$a,$b] = array_pad(explode('.', $raw, 2), 2, ''); return (int)$a * 100 + (int)str_pad($b, 2, '0'); }
    private function optionalMoneyCents($value): int { return $this->moneyCents($value); }
    private static function failure(string $reason): CashierV3CommandException { return new CashierV3CommandException(CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE, '订单资料或后续使用状态已变化，本次操作未提交。', CashierV3ResultCode::STATUS_FAILED, ['reason' => $reason]); }
}
