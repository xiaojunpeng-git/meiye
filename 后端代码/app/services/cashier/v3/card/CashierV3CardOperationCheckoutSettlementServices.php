<?php
declare(strict_types=1);

namespace app\services\cashier\v3\card;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\cashier\CashierV3CashierReadinessGuard;
use app\services\cashier\v3\cashier\CashierV3EntitlementResourceVersionProvider;
use app\services\cashier\v3\event\CashierV3BusinessEventExecution;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\order\settlement\CashierV3SalesOrderPlanV1;
use think\facade\Db;

/**
 * Binds the server-created upgrade cart line to one checkout request and
 * settles the pending right mutation in that request's terminal transaction.
 * A pending row is audit state only; it is never a right or accounting fact.
 */
final class CashierV3CardOperationCheckoutSettlementServices
{
    private const OPERATION_TABLE = 'cashier_v3_card_operation';
    private const OPERATION_LINE_TABLE = 'cashier_v3_card_operation_line';
    private const STATE_TABLE = 'cashier_v3_card_state';
    private const SETTLEMENT_TABLE = 'cashier_v3_card_operation_settlement';
    private const CHECKOUT_REQUEST_TABLE = 'cashier_v3_checkout_request';

    /** @var CashierV3EntitlementResourceVersionProvider */
    private $versions;

    public function __construct(?CashierV3EntitlementResourceVersionProvider $versions = null)
    {
        $this->versions = $versions ?: new CashierV3EntitlementResourceVersionProvider(
            new CashierV3CashierReadinessGuard()
        );
    }

    /**
     * The checkout draft remains payable only for the cash delta. This method
     * exposes the frozen old-right credit to the formal sales plan so the sale
     * is recorded at the target's catalogue price without touching stored value.
     * When the source value exceeds the target price, only the target price is
     * booked as financial credit; the source snapshot remains intact for audit.
     */
    public function creditForCheckoutInTx(string $checkoutRequestId, CashierV3DataScopeContext $dataScope): array
    {
        CashierV3TransactionGuard::assertInTransaction('cardOperationCheckoutCredit');
        $checkoutRequestId = self::checkoutRequestId($checkoutRequestId);
        $rows = self::rows(Db::name(self::OPERATION_TABLE)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('checkout_request_id', $checkoutRequestId)
            ->lock(true)->select());
        if ($rows === []) {
            return [];
        }
        if (count($rows) !== 1 || (string)($rows[0]['operation_status'] ?? '') !== 'awaiting_checkout') {
            throw self::failure('card_operation_checkout_credit_ambiguous');
        }
        $row = $rows[0];
        $sourceValue = (int)($row['source_remaining_value_cents'] ?? -1);
        $target = (int)($row['target_price_cents'] ?? -1);
        $amount = min($sourceValue, $target);
        $delta = max(0, $target - $sourceValue);
        if ($sourceValue < 0 || $target < 0
            || $delta !== (int)($row['settlement_delta_cents'] ?? -1)) {
            throw self::failure('card_operation_checkout_credit_invalid');
        }
        return [
            'operationId' => (string)$row['operation_id'],
            'operationType' => (string)$row['operation_type'],
            'checkoutRequestId' => $checkoutRequestId,
            'amountCents' => $amount,
            'targetPriceCents' => $target,
        ];
    }

    /**
     * Persist the only allowed relationship between a pending upgrade and a
     * checkout request. It runs after the request draft has been persisted so
     * no operation can point at an imaginary request id.
     */
    public function bindPreparedCheckoutInTx(
        array $storedWorkspaceRows,
        string $checkoutRequestId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        CashierV3TransactionGuard::assertInTransaction('cardOperationBindPreparedCheckout');
        $checkoutRequestId = self::checkoutRequestId($checkoutRequestId);
        $bindings = $this->upgradeBindingsFromWorkspaceRows($storedWorkspaceRows);
        if ($bindings === []) {
            return [];
        }
        if (count($bindings) !== 1) {
            throw self::failure('card_operation_upgrade_checkout_binding_ambiguous');
        }
        $binding = $bindings[0];
        $operation = $this->lockOperation((string)$binding['operationId'], $dataScope->tenantId());
        $this->assertBindingMatchesOperation($binding, $operation, $operatorScope, $dataScope);
        $status = (string)($operation['operation_status'] ?? '');
        $existingRequestId = (string)($operation['checkout_request_id'] ?? '');
        if ($status === 'succeeded' && $existingRequestId === $checkoutRequestId) {
            return $this->publicBinding($operation);
        }
        if ($status !== 'awaiting_checkout') {
            throw self::failure('card_operation_upgrade_not_pending');
        }
        if ($existingRequestId !== '' && $existingRequestId !== $checkoutRequestId) {
            // A failed terminal submit rolls every business mutation back, but
            // its earlier preparation request remains for audit. Rebind only
            // that untouched request state so the operator can create a fresh
            // normal checkout without being trapped by the stale draft.
            $previous = Db::name(self::CHECKOUT_REQUEST_TABLE)
                ->where('tenant_id', $dataScope->tenantId())
                ->where('request_id', $existingRequestId)
                ->lock(true)
                ->find();
            if ($previous && !in_array((string)($previous['request_status'] ?? ''), ['editing', 'ready_for_submit'], true)) {
                throw CashierV3CommandException::versionConflict(
                    '该升级操作已经进入其他结账流程，请处理原结账单。',
                    ['reason' => 'card_operation_upgrade_checkout_already_bound']
                );
            }
        }
        if ($existingRequestId !== $checkoutRequestId) {
            $updated = Db::name(self::OPERATION_TABLE)
                ->where('id', (int)$operation['id'])
                ->where('operation_status', 'awaiting_checkout')
                ->where('checkout_request_id', $existingRequestId)
                ->update([
                    'checkout_request_id' => $checkoutRequestId,
                    'update_time' => time(),
                ]);
            if ((int)$updated !== 1) {
                throw CashierV3CommandException::versionConflict(
                    '升级操作已经变化，请重新打开后处理。',
                    ['reason' => 'card_operation_upgrade_checkout_bind_race']
                );
            }
            $operation['checkout_request_id'] = $checkoutRequestId;
        }
        return $this->publicBinding($operation);
    }

    /**
     * Bind a browser-created upgrade operation after its snapshot checkout
     * request has been materialized in the same final transaction.
     */
    public function bindSnapshotOperationInTx(
        string $operationId,
        string $checkoutRequestId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        CashierV3TransactionGuard::assertInTransaction('cardOperationBindSnapshot');
        $operationId = trim($operationId);
        $checkoutRequestId = self::checkoutRequestId($checkoutRequestId);
        $operation = $this->lockOperation($operationId, $dataScope->tenantId());
        if ((int)($operation['store_id'] ?? 0) !== $operatorScope->storeId()) {
            throw self::failure('card_operation_snapshot_store_mismatch');
        }
        $status = (string)($operation['operation_status'] ?? '');
        $existingRequestId = (string)($operation['checkout_request_id'] ?? '');
        if ($status === 'succeeded' && $existingRequestId === $checkoutRequestId) {
            return $this->publicBinding($operation);
        }
        if ($status !== 'awaiting_checkout'
            || ($existingRequestId !== '' && $existingRequestId !== $checkoutRequestId)) {
            throw CashierV3CommandException::versionConflict(
                '升级操作已经进入其他结账流程，请重新打开后处理。',
                ['reason' => 'card_operation_snapshot_already_bound']
            );
        }
        if ($existingRequestId === '') {
            $updated = Db::name(self::OPERATION_TABLE)
                ->where('id', (int)$operation['id'])
                ->where('operation_status', 'awaiting_checkout')
                ->where('checkout_request_id', '')
                ->update([
                    'checkout_request_id' => $checkoutRequestId,
                    'update_time' => time(),
                ]);
            if ((int)$updated !== 1) {
                throw CashierV3CommandException::versionConflict(
                    '升级操作已经变化，请重新打开后处理。',
                    ['reason' => 'card_operation_snapshot_bind_race']
                );
            }
            $operation['checkout_request_id'] = $checkoutRequestId;
        }
        return $this->publicBinding($operation);
    }

    /** A protected upgrade line is cancelled when the operator removes it. */
    public function cancelForRemovedWorkspaceLineInTx(
        array $workspaceLine,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): bool {
        CashierV3TransactionGuard::assertInTransaction('cardOperationCancelRemovedCheckoutLine');
        $binding = self::upgradeBindingFromWorkspaceRow($workspaceLine);
        if ($binding === null) {
            return false;
        }
        $operation = $this->lockOperation((string)$binding['operationId'], $dataScope->tenantId());
        $this->assertBindingMatchesOperation($binding, $operation, $operatorScope, $dataScope);
        if ((string)($operation['operation_status'] ?? '') === 'cancelled') {
            return true;
        }
        if ((string)($operation['operation_status'] ?? '') !== 'awaiting_checkout'
            || (string)($operation['checkout_request_id'] ?? '') !== '') {
            throw CashierV3CommandException::versionConflict(
                '该升级操作已经进入结账，不能直接删除。',
                ['reason' => 'card_operation_upgrade_remove_after_checkout_prepare']
            );
        }
        $updated = Db::name(self::OPERATION_TABLE)
            ->where('id', (int)$operation['id'])
            ->where('operation_status', 'awaiting_checkout')
            ->where('checkout_request_id', '')
            ->update([
                'operation_status' => 'cancelled',
                'update_time' => time(),
            ]);
        if ((int)$updated !== 1) {
            throw CashierV3CommandException::versionConflict(
                '升级操作已经变化，请刷新后重试。',
                ['reason' => 'card_operation_upgrade_cancel_race']
            );
        }
        return true;
    }

    /**
     * Apply the pending source-right mutation only after sales, collection and
     * card issuance have all succeeded in the surrounding checkout transaction.
     */
    public function settleFromCheckoutInTx(
        array $lockedAggregate,
        CashierV3SalesOrderPlanV1 $salesPlan,
        array $salesResult,
        array $cardPurchaseResult,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        CashierV3BusinessEventRecorder $eventRecorder,
        CashierV3BusinessEventExecution $eventExecution,
        array $eventContract,
        int $settledAt
    ): array {
        CashierV3TransactionGuard::assertInTransaction('cardOperationCheckoutSettlement');
        $header = $salesPlan->header();
        $checkoutRequestId = self::checkoutRequestId((string)($header['checkout_request_id'] ?? ''));
        $rows = Db::name(self::OPERATION_TABLE)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('checkout_request_id', $checkoutRequestId)
            ->lock(true)
            ->select();
        $rows = self::rows($rows);
        if ($rows === []) {
            return ['settledOperationCount' => 0, 'operations' => []];
        }
        if (count($rows) !== 1) {
            throw self::failure('card_operation_checkout_multiple_bound_operations');
        }
        $operation = $rows[0];
        if ((string)($operation['operation_status'] ?? '') === 'succeeded') {
            return ['settledOperationCount' => 1, 'operations' => [$this->publicBinding($operation)], 'replayed' => true];
        }
        if ((string)($operation['operation_status'] ?? '') !== 'awaiting_checkout') {
            throw self::failure('card_operation_checkout_operation_not_pending');
        }
        $type = (string)($operation['operation_type'] ?? '');
        if (!in_array($type, ['card_upgrade', 'project_upgrade'], true)) {
            throw self::failure('card_operation_checkout_type_invalid');
        }
        $target = self::decodeJson((string)($operation['target_snapshot_json'] ?? ''), 'card_operation_target_snapshot_invalid');
        $result = self::decodeJson((string)($operation['result_snapshot_json'] ?? ''), 'card_operation_result_snapshot_invalid');
        $this->assertCheckoutMatchesOperation($operation, $target, $salesPlan, $header, $operatorScope, $dataScope);
        $targetAuthority = [];
        if ($type === 'card_upgrade') {
            $targetAuthority = $this->settleCardUpgradeInTx($operation, $target, $result, $salesPlan, $cardPurchaseResult, $operatorScope, $dataScope, $settledAt);
        } else {
            $targetAuthority = $this->settleProjectUpgradeInTx($operation, $target, $result, $salesPlan, $salesResult, $operatorScope, $dataScope, $settledAt);
        }
        $settlement = $this->persistSettlementInTx($operation, $salesPlan, $salesResult, $targetAuthority, $dataScope, $settledAt);
        $operationUpdate = [
            'operation_status' => 'succeeded',
            'settled_at' => $settledAt,
            'update_time' => $settledAt,
        ];
        if ($type === 'card_upgrade') {
            // The authority state has just been transitioned in the same
            // transaction. Persist the terminal state on the audit record too.
            $operationUpdate['card_status_after'] = 'upgraded';
        }
        $updated = Db::name(self::OPERATION_TABLE)
            ->where('id', (int)$operation['id'])
            ->where('operation_status', 'awaiting_checkout')
            ->where('checkout_request_id', $checkoutRequestId)
            ->update($operationUpdate);
        if ((int)$updated !== 1) {
            throw CashierV3CommandException::versionConflict(
                '升级操作已经变化，结账已回滚，请重新处理。',
                ['reason' => 'card_operation_checkout_terminal_update_race']
            );
        }
        $eventRecorder->recordInTx($eventExecution, $eventContract, [
            'event_type' => 'card.operation.settled',
            'aggregate_type' => 'card_operation',
            'aggregate_id' => (string)$operation['operation_id'],
            'aggregate_version' => $this->nextStateVersion($operation, $result),
            'event_version' => 1,
            'source_type' => 'submit-checkout',
            'source_id' => $checkoutRequestId,
            'member_id' => (int)$operation['member_id_before'],
            'business_date' => (string)$header['business_date'],
            'occurred_at' => $settledAt,
            'settled_at' => $settledAt,
            'recorded_at' => $settledAt,
            'aggregate_name_snapshot' => (string)$operation['operation_no'],
            'store_name_snapshot' => (string)$header['store_name_snapshot'],
            'payload' => [
                'operationId' => (string)$operation['operation_id'],
                'operationNo' => (string)$operation['operation_no'],
                'operationType' => $type,
                'checkoutRequestId' => $checkoutRequestId,
                'salesOrderId' => (string)($salesResult['orderId'] ?? ''),
                'targetCatalogId' => (int)$operation['target_catalog_id'],
                'targetPriceCents' => (int)$operation['target_price_cents'],
                'sourceRemainingValueCents' => (int)$operation['source_remaining_value_cents'],
                'entitlementCreditCents' => $this->effectiveEntitlementCreditCents($operation),
                'settlementDeltaCents' => (int)$operation['settlement_delta_cents'],
            ],
        ]);
        $operation['operation_status'] = 'succeeded';
        $operation['settled_at'] = $settledAt;
        $binding = array_merge($this->publicBinding($operation), [
            'salesOrderId' => (string)$salesResult['orderId'],
            'entitlementCreditCents' => $this->effectiveEntitlementCreditCents($operation),
        ], $targetAuthority);
        return ['settledOperationCount' => 1, 'operations' => [$binding], 'settlement' => $settlement, 'replayed' => false];
    }

    private function settleCardUpgradeInTx(
        array $operation,
        array $target,
        array $result,
        CashierV3SalesOrderPlanV1 $salesPlan,
        array $cardPurchaseResult,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        int $now
    ): array {
        $receipts = array_values((array)($cardPurchaseResult['receipts'] ?? []));
        $line = $this->onlySalesLine($salesPlan);
        if (count($receipts) !== 1
            || (int)($receipts[0]['legacyOrderId'] ?? 0) <= 0
            || (string)($receipts[0]['salesOrderLineId'] ?? '') !== (string)($line['order_line_id'] ?? '')
            || (int)($receipts[0]['holderId'] ?? 0) <= 0) {
            throw self::failure('card_operation_card_upgrade_target_issue_missing');
        }
        $oldOrder = Db::name('store_order')
            ->where('id', (int)$operation['origin_order_id'])
            ->lock(true)
            ->find();
        // The card may have been transferred before it is upgraded.  The
        // legacy source order is immutable and remains owned by the original
        // member, whereas member_id_before is the card's current holder.
        if (!$oldOrder
            || (int)($oldOrder['uid'] ?? 0) !== (int)$operation['origin_member_id']
            || (int)($oldOrder['store_id'] ?? 0) !== $operatorScope->storeId()
            || (int)($oldOrder['paid'] ?? 0) !== 1
            || (int)($oldOrder['card_upgrade_use_oid'] ?? -1) !== 0) {
            throw CashierV3CommandException::versionConflict(
                '原卡已经变化或已升级，结账未提交。',
                ['reason' => 'card_operation_card_upgrade_source_changed']
            );
        }
        $state = $this->lockSourceState($operation, $result);
        $newLegacyOrderId = (int)$receipts[0]['legacyOrderId'];
        $oldUpdated = Db::name('store_order')
            ->where('id', (int)$oldOrder['id'])
            ->where('card_upgrade_use_oid', 0)
            ->update(['card_upgrade_use_oid' => $newLegacyOrderId]);
        if ((int)$oldUpdated !== 1) {
            throw CashierV3CommandException::versionConflict(
                '原卡已经变化或已升级，结账未提交。',
                ['reason' => 'card_operation_card_upgrade_source_update_race']
            );
        }
        $this->disableSourceStateInTx($state, $operation, $now);
        return [
            'targetHolderId' => (int)$receipts[0]['holderId'],
            'targetLegacyOrderId' => $newLegacyOrderId,
            'targetEntitlementDetailId' => 0,
        ];
    }

    private function settleProjectUpgradeInTx(
        array $operation,
        array $target,
        array $result,
        CashierV3SalesOrderPlanV1 $salesPlan,
        array $salesResult,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        int $now
    ): array {
        $state = $this->lockSourceState($operation, $result);
        if ((string)($state['card_status'] ?? '') !== 'enabled') {
            throw CashierV3CommandException::versionConflict(
                '原卡当前不可用，结账未提交。',
                ['reason' => 'card_operation_project_upgrade_card_disabled']
            );
        }
        $holder = Db::name('user_card_holder')
            ->where('id', (int)$operation['source_card_holder_id'])
            ->where('uid', (int)$operation['member_id_before'])
            ->where('oid', (int)$operation['origin_order_id'])
            ->where('is_del', 0)
            ->lock(true)
            ->find();
        if (!$holder || (int)($holder['store_id'] ?? 0) !== $operatorScope->storeId()) {
            throw CashierV3CommandException::versionConflict(
                '原卡归属已经变化，结账未提交。',
                ['reason' => 'card_operation_project_upgrade_holder_changed']
            );
        }
        $mutations = is_array($result['stateMutation']['projectMutations'] ?? null)
            ? $result['stateMutation']['projectMutations'] : [];
        if ($mutations === []) {
            throw self::failure('card_operation_project_upgrade_mutations_missing');
        }
        $sourceLines = Db::name(self::OPERATION_LINE_TABLE)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('operation_id', (string)$operation['operation_id'])
            ->where('line_role', 'source_project')
            ->order('line_no asc')
            ->lock(true)
            ->select();
        $sourceLines = self::rows($sourceLines);
        if (count($sourceLines) !== count($mutations)) {
            throw self::failure('card_operation_project_upgrade_audit_lines_missing');
        }
        $sourceQuantity = 0;
        foreach ($mutations as $mutation) {
            if (!is_array($mutation)) {
                throw self::failure('card_operation_project_upgrade_mutation_invalid');
            }
            $detailId = (int)($mutation['sourceDetailId'] ?? 0);
            $before = (int)($mutation['quantityBefore'] ?? -1);
            $after = (int)($mutation['quantityAfter'] ?? -1);
            $delta = (int)($mutation['quantityDelta'] ?? 0);
            $expectedVersion = (int)($mutation['sourceDetailVersion'] ?? 0);
            if ($detailId <= 0 || $before <= 0 || $after < 0 || $delta >= 0
                || $before + $delta !== $after || $expectedVersion <= 0) {
                throw self::failure('card_operation_project_upgrade_mutation_shape_invalid');
            }
            $detail = Db::name('store_order_cart_info')
                ->where('id', $detailId)
                ->where('oid', (int)$operation['origin_order_id'])
                ->where('cart_type', 2)
                ->where('product_type', 6)
                ->lock(true)
                ->find();
            if (!$detail || (int)($detail['write_surplus_times'] ?? -1) !== $before) {
                throw CashierV3CommandException::versionConflict(
                    '原项目权益已经变化，结账未提交。',
                    ['reason' => 'card_operation_project_upgrade_source_changed', 'source_detail_id' => $detailId]
                );
            }
            $shadow = Db::name('cashier_v3_entitlement_resource_version')
                ->where('resource_kind', 'member_benefit_pool')
                ->where('resource_id', (string)$detailId)
                ->lock(true)
                ->find();
            if (!$shadow || (int)($shadow['current_version'] ?? 0) !== $expectedVersion) {
                throw CashierV3CommandException::versionConflict(
                    '原项目权益已经变化，结账未提交。',
                    ['reason' => 'card_operation_project_upgrade_source_version_changed', 'source_detail_id' => $detailId]
                );
            }
            $updated = Db::name('store_order_cart_info')
                ->where('id', $detailId)
                ->where('write_surplus_times', $before)
                ->update([
                    'write_surplus_times' => $after,
                    // Upgrade transfer is not service consumption/write-off.
                    'is_writeoff' => 0,
                ]);
            if ((int)$updated !== 1) {
                throw CashierV3CommandException::versionConflict(
                    '原项目权益已经变化，结账未提交。',
                    ['reason' => 'card_operation_project_upgrade_source_update_race', 'source_detail_id' => $detailId]
                );
            }
            $sourceQuantity += -$delta;
            $this->versions->synchronizeProjectionVersion(
                'member_benefit_pool',
                (string)$detailId,
                $operatorScope,
                $dataScope
            );
        }
        $line = $this->onlySalesLine($salesPlan);
        $targetSkuId = (int)($target['skuId'] ?? 0);
        $targetProductId = (int)($target['catalogId'] ?? 0);
        $targetPrice = (int)($target['priceCents'] ?? -1);
        // The target name is frozen from checkoutSnapshot.lines through the
        // operation target snapshot. Do not rebuild it from entitlement or
        // catalogue projections after settlement.
        $targetName = trim((string)($target['catalogName'] ?? ''));
        $targetQuantity = (int)($result['stateMutation']['targetEntitlementQuantity'] ?? 1);
        if ($targetQuantity <= 0 || $sourceQuantity <= 0 || $targetSkuId <= 0 || $targetProductId <= 0 || $targetPrice < 0 || $targetName === '') {
            throw self::failure('card_operation_project_upgrade_target_invalid');
        }
        $cartId = 'copu' . substr(hash('sha256', (string)$operation['operation_id']), 0, 27);
        $money = self::money($targetPrice);
        $targetLegacyOrderId = $this->insertProjectUpgradeLegacyOrderInTx(
            $operation,
            $salesPlan,
            $salesResult,
            $operatorScope,
            $now
        );
        $targetDetailId = (int)Db::name('store_order_cart_info')->insertGetId([
            'uid' => (int)$operation['member_id_before'],
            // The upgraded project is a new right on the same source card,
            // rather than a second card instance.  Keep its legacy benefit
            // pool under the original card order so entitlement discovery and
            // resource versions resolve it through the one current holder.
            // The new sales order remains linked through cart_info and its
            // cart_id projection below for compatibility and audit.
            'oid' => (int)$operation['origin_order_id'],
            'cart_id' => $cartId,
            'cart_type' => 2,
            'product_id' => $targetProductId,
            'product_type' => 6,
            'pay_price' => $money,
            'write_times' => $targetQuantity,
            'write_surplus_times' => $targetQuantity,
            'cart_num' => $targetQuantity,
            'surplus_num' => $targetQuantity,
            'split_surplus_num' => $targetQuantity,
            'write_start' => (int)($holder['write_start'] ?? 0),
            'write_end' => (int)($holder['write_end'] ?? 0),
            'is_writeoff' => 0,
            'cart_info' => self::json([
                'sourceType' => 'cashier_v3_project_upgrade',
                'cardOperationId' => (string)$operation['operation_id'],
                'checkoutRequestId' => (string)($salesPlan->header()['checkout_request_id'] ?? ''),
                'salesOrderLineId' => (string)($line['order_line_id'] ?? ''),
                'product_id' => $targetProductId,
                'product_attr_unique' => (string)($target['skuUnique'] ?? ''),
                'productInfo' => [
                    'store_name' => $targetName,
                ],
                'cart_num' => $targetQuantity,
                'truePrice' => $money,
                'pay_price' => $money,
            ]),
            'debt_amount' => '0.00',
            'repaid_debt_amount' => '0.00',
            'is_gift' => 0,
        ]);
        if ($targetDetailId <= 0) {
            throw self::failure('card_operation_project_upgrade_target_create_failed');
        }
        // Register the upgraded right before any version reader resolves it.
        // The old rule counters and the target component belong to this same
        // settlement transaction; failure must roll back the entire transfer.
        (new CashierV3CardRuleEntitlementAuthorityServices())->replaceProjectComponentsInTx(
            $dataScope->tenantId(),
            (int)$operation['source_card_holder_id'],
            array_map(static function (array $mutation): array {
                return [
                    'detailId' => (int)$mutation['sourceDetailId'],
                    'quantity' => -(int)$mutation['quantityDelta'],
                ];
            }, $mutations),
            $targetQuantity,
            $targetPrice,
            $target,
            $targetDetailId,
            (string)$operation['operation_id'],
            $now,
            'project_upgrade'
        );
        // Source versions also include rule counters, now updated above.
        foreach ($mutations as $mutation) {
            $this->versions->synchronizeProjectionVersion(
                'member_benefit_pool', (string)$mutation['sourceDetailId'], $operatorScope, $dataScope
            );
        }
        Db::name('store_order')->where('id', $targetLegacyOrderId)
            ->update(['cart_id' => self::json([(string)$targetDetailId])]);
        $this->advanceSourceStateInTx($state, $operation, $now);
        $this->versions->synchronizeProjectionVersion(
            'member_benefit_pool',
            (string)$targetDetailId,
            $operatorScope,
            $dataScope
        );
        $this->versions->synchronizeProjectionVersion(
            'card_holder',
            (string)$operation['source_card_holder_id'],
            $operatorScope,
            $dataScope
        );
        return [
            'targetHolderId' => 0,
            'targetLegacyOrderId' => $targetLegacyOrderId,
            'targetEntitlementDetailId' => $targetDetailId,
        ];
    }

    private function assertCheckoutMatchesOperation(
        array $operation,
        array $target,
        CashierV3SalesOrderPlanV1 $salesPlan,
        array $header,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): void {
        $line = $this->onlySalesLine($salesPlan);
        $type = (string)$operation['operation_type'];
        $expectedItemType = $type === 'card_upgrade' ? 'card' : 'project';
        $targetSkuId = (int)($target['skuId'] ?? 0);
        $expectedCredit = $this->effectiveEntitlementCreditCents($operation);
        $couponDiscount = (int)($line['coupon_discount_cents'] ?? 0);
        if ((string)($header['tenant_id'] ?? '') !== $dataScope->tenantId()
            || (int)($header['store_id'] ?? 0) !== $operatorScope->storeId()
            || (int)($header['member_id'] ?? 0) !== (int)$operation['member_id_before']
            || (string)($line['item_type'] ?? '') !== $expectedItemType
            || (int)($line['item_id'] ?? 0) !== (int)$operation['target_catalog_id']
            || (int)($line['catalog_sku_id'] ?? 0) !== $targetSkuId
            || (int)($line['original_amount_cents'] ?? -1) !== (int)$operation['target_price_cents']
            || $couponDiscount < 0 || $couponDiscount > (int)$operation['settlement_delta_cents']
            || (int)($line['discount_amount_cents'] ?? -1) !== $couponDiscount
            || (int)($line['sale_amount_cents'] ?? -1) !== (int)$operation['target_price_cents'] - $couponDiscount
            || (int)($line['sale_amount_cents'] ?? -1) - $expectedCredit
                !== (int)$operation['settlement_delta_cents'] - $couponDiscount) {
            throw CashierV3CommandException::versionConflict(
                '升级结账内容已经变化，请重新打开后办理。',
                ['reason' => 'card_operation_checkout_sale_line_mismatch']
            );
        }
    }

    private function insertProjectUpgradeLegacyOrderInTx(
        array $operation,
        CashierV3SalesOrderPlanV1 $salesPlan,
        array $salesResult,
        CashierV3OperatorScope $operatorScope,
        int $now
    ): int {
        $header = $salesPlan->header();
        $legacyOrderNo = 'v3p' . substr(hash('sha256', (string)$salesResult['orderId']), 0, 28);
        $money = self::money((int)$operation['target_price_cents']);
        $cash = self::money((int)$operation['settlement_delta_cents']);
        $credit = self::money($this->effectiveEntitlementCreditCents($operation));
        $id = (int)Db::name('store_order')->insertGetId([
            'type' => 11,
            'pid' => 0,
            'order_id' => $legacyOrderNo,
            'store_id' => $operatorScope->storeId(),
            'uid' => (int)$operation['member_id_before'],
            'real_name' => (string)($header['member_name_snapshot'] ?? ''),
            'cart_id' => '[]',
            'total_num' => 1,
            'total_price' => $money,
            'settle_price' => $money,
            'pay_price' => $money,
            'cash_pay_price' => $cash,
            'yue_pay_price' => $credit,
            'paid' => 1,
            'pay_type' => 'cashier_v3',
            'status' => 0,
            'refund_status' => 0,
            'card_upgrade_use_oid' => 0,
            'terminal_action' => 0,
            'product_type' => 6,
            'mark' => 'V3项目升级目标权益',
            'remark' => 'V3 sales order ' . (string)($header['order_no'] ?? ''),
            'unique' => md5('cashier-v3-project-upgrade:' . (string)$operation['operation_id']),
            'is_del' => 0,
            'is_user_del' => 0,
            'is_system_del' => 0,
            'channel_type' => 'cashier_v3',
            'pay_time' => $now,
            'add_time' => $now,
            'selected_product' => (string)$operation['target_catalog_id'],
        ]);
        if ($id <= 0) {
            throw self::failure('card_operation_project_upgrade_target_order_create_failed');
        }
        return $id;
    }

    private function persistSettlementInTx(
        array $operation,
        CashierV3SalesOrderPlanV1 $salesPlan,
        array $salesResult,
        array $targetAuthority,
        CashierV3DataScopeContext $dataScope,
        int $now
    ): array {
        $line = $this->onlySalesLine($salesPlan);
        $sourceLines = self::rows(Db::name(self::OPERATION_LINE_TABLE)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('operation_id', (string)$operation['operation_id'])
            ->where('line_role', 'source_project')
            ->order('line_no asc')->select());
        $sourceDetailIds = array_values(array_map(static function (array $row): int {
            return (int)$row['source_detail_id'];
        }, $sourceLines));
        $sourceOrderNo = (string)Db::name('store_order')
            ->where('id', (int)$operation['origin_order_id'])->value('order_id');
        $row = [
            'settlement_id' => 'COS-' . strtoupper(substr(hash('sha256', (string)$operation['operation_id']), 0, 40)),
            'tenant_id' => $dataScope->tenantId(),
            'operation_id' => (string)$operation['operation_id'],
            'operation_no_snapshot' => (string)$operation['operation_no'],
            'operation_type' => (string)$operation['operation_type'],
            'checkout_request_id' => (string)$operation['checkout_request_id'],
            'sales_order_id' => (string)$salesResult['orderId'],
            'sales_order_line_id' => (string)$line['order_line_id'],
            'source_card_holder_id' => (int)$operation['source_card_holder_id'],
            'source_legacy_order_id' => (int)$operation['origin_order_id'],
            'source_card_no_snapshot' => (string)$operation['card_no_snapshot'],
            'source_order_no_snapshot' => $sourceOrderNo,
            'source_detail_ids_json' => self::json($sourceDetailIds),
            'target_card_holder_id' => (int)($targetAuthority['targetHolderId'] ?? 0),
            'target_legacy_order_id' => (int)($targetAuthority['targetLegacyOrderId'] ?? 0),
            'target_entitlement_detail_id' => (int)($targetAuthority['targetEntitlementDetailId'] ?? 0),
            'entitlement_credit_cents' => $this->effectiveEntitlementCreditCents($operation),
            'cash_delta_cents' => (int)$operation['settlement_delta_cents'],
            'settlement_status' => 'settled',
            'reversed_by_operation_id' => '',
            'settled_at' => $now,
            'reversed_at' => 0,
            'recorded_at' => $now,
            'current_version' => 1,
        ];
        $row['immutable_fingerprint'] = hash('sha256', self::json($row));
        $id = (int)Db::name(self::SETTLEMENT_TABLE)->insertGetId($row);
        if ($id <= 0) {
            throw self::failure('card_operation_settlement_insert_failed');
        }
        return $row;
    }

    /**
     * The source-right value remains the immutable operation snapshot. For
     * financial facts, credit cannot exceed the target sale amount; any
     * excess source value is consumed by the approved upgrade without
     * creating a negative payment or a refund.
     */
    private function effectiveEntitlementCreditCents(array $operation): int
    {
        return min(
            max(0, (int)($operation['source_remaining_value_cents'] ?? 0)),
            max(0, (int)($operation['target_price_cents'] ?? 0))
        );
    }

    private function onlySalesLine(CashierV3SalesOrderPlanV1 $salesPlan): array
    {
        $lines = $salesPlan->lines();
        if (count($lines) !== 1 || !is_array($lines[0])) {
            throw self::failure('card_operation_checkout_sale_line_not_unique');
        }
        return $lines[0];
    }

    private function lockSourceState(array $operation, array $result): array
    {
        $state = Db::name(self::STATE_TABLE)
            ->where('tenant_id', (string)$operation['tenant_id'])
            ->where('card_holder_id', (int)$operation['source_card_holder_id'])
            ->lock(true)
            ->find();
        $expectedVersion = (int)($result['stateVersionAfter'] ?? 0);
        if (!$state
            || $expectedVersion <= 0
            || (int)($state['origin_order_id'] ?? 0) !== (int)$operation['origin_order_id']
            || (int)($state['current_member_id'] ?? 0) !== (int)$operation['member_id_before']
            || (int)($state['current_version'] ?? 0) !== $expectedVersion) {
            throw CashierV3CommandException::versionConflict(
                '原卡当前状态已经变化，结账未提交。',
                ['reason' => 'card_operation_checkout_source_state_changed']
            );
        }
        return (array)$state;
    }

    private function disableSourceStateInTx(array $state, array $operation, int $now): void
    {
        $version = (int)$state['current_version'];
        $updated = Db::name(self::STATE_TABLE)
            ->where('id', (int)$state['id'])
            ->where('current_version', $version)
            ->where('card_status', 'enabled')
            ->update([
                'card_status' => 'upgraded',
                'status_reason_snapshot' => '卡升级完成',
                'current_version' => $version + 1,
                'last_operation_id' => (string)$operation['operation_id'],
                'updated_at' => $now,
            ]);
        if ((int)$updated !== 1) {
            throw CashierV3CommandException::versionConflict(
                '原卡当前状态已经变化，结账未提交。',
                ['reason' => 'card_operation_card_upgrade_state_update_race']
            );
        }
    }

    private function advanceSourceStateInTx(array $state, array $operation, int $now): void
    {
        $version = (int)$state['current_version'];
        $updated = Db::name(self::STATE_TABLE)
            ->where('id', (int)$state['id'])
            ->where('current_version', $version)
            ->where('card_status', 'enabled')
            ->update([
                'current_version' => $version + 1,
                'last_operation_id' => (string)$operation['operation_id'],
                'updated_at' => $now,
            ]);
        if ((int)$updated !== 1) {
            throw CashierV3CommandException::versionConflict(
                '原卡当前状态已经变化，结账未提交。',
                ['reason' => 'card_operation_project_upgrade_state_update_race']
            );
        }
    }

    private function upgradeBindingsFromWorkspaceRows(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $binding = self::upgradeBindingFromWorkspaceRow($row);
            if ($binding === null) {
                continue;
            }
            $operationId = (string)$binding['operationId'];
            if (isset($result[$operationId])) {
                throw self::failure('card_operation_upgrade_workspace_binding_duplicate');
            }
            $result[$operationId] = $binding;
        }
        return array_values($result);
    }

    private static function upgradeBindingFromWorkspaceRow(array $row): ?array
    {
        if ((string)($row['line_role'] ?? '') !== 'sale') {
            return null;
        }
        $snapshot = json_decode((string)($row['authority_snapshot_json'] ?? ''), true);
        if (!is_array($snapshot) || !is_array($snapshot['cardOperationUpgrade'] ?? null)) {
            return null;
        }
        return $snapshot['cardOperationUpgrade'];
    }

    private function lockOperation(string $operationId, string $tenantId): array
    {
        if (preg_match('/^COP-[A-F0-9]{40}$/D', $operationId) !== 1) {
            throw self::failure('card_operation_upgrade_id_invalid');
        }
        $row = Db::name(self::OPERATION_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('operation_id', $operationId)
            ->lock(true)
            ->find();
        if (!$row) {
            throw self::failure('card_operation_upgrade_not_found');
        }
        return (array)$row;
    }

    private function assertBindingMatchesOperation(
        array $binding,
        array $operation,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): void {
        $type = (string)($binding['operationType'] ?? '');
        if (!in_array($type, ['card_upgrade', 'project_upgrade'], true)
            || (string)($binding['operationId'] ?? '') !== (string)$operation['operation_id']
            || !hash_equals((string)($binding['operationFingerprint'] ?? ''), (string)($operation['immutable_fingerprint'] ?? ''))
            || (string)($operation['tenant_id'] ?? '') !== $dataScope->tenantId()
            || (int)($operation['store_id'] ?? 0) !== $operatorScope->storeId()
            || (string)($operation['operation_type'] ?? '') !== $type
            || (int)($binding['memberId'] ?? 0) !== (int)($operation['member_id_before'] ?? 0)
            || (int)($binding['sourceCardHolderId'] ?? 0) !== (int)($operation['source_card_holder_id'] ?? 0)
            || (int)($binding['sourceCardHolderVersion'] ?? 0) !== (int)($operation['source_card_holder_version'] ?? 0)
            || (int)($binding['targetProductId'] ?? 0) !== (int)($operation['target_catalog_id'] ?? 0)
            || (int)($binding['targetPriceCents'] ?? -1) !== (int)($operation['target_price_cents'] ?? -2)
            || (int)($binding['sourceRemainingValueCents'] ?? -1) !== (int)($operation['source_remaining_value_cents'] ?? -2)
            || (int)($binding['settlementDeltaCents'] ?? -1) !== (int)($operation['settlement_delta_cents'] ?? -2)) {
            throw CashierV3CommandException::versionConflict(
                '升级结算资料已经变化，请重新打开后办理。',
                ['reason' => 'card_operation_upgrade_binding_mismatch']
            );
        }
    }

    private function nextStateVersion(array $operation, array $result): int
    {
        $current = (int)($result['stateVersionAfter'] ?? 0);
        return $current > 0 ? $current + 1 : 1;
    }

    private function publicBinding(array $operation): array
    {
        return [
            'operationId' => (string)($operation['operation_id'] ?? ''),
            'operationNo' => (string)($operation['operation_no'] ?? ''),
            'operationType' => (string)($operation['operation_type'] ?? ''),
            'operationStatus' => (string)($operation['operation_status'] ?? ''),
            'checkoutRequestId' => (string)($operation['checkout_request_id'] ?? ''),
            'settledAt' => (int)($operation['settled_at'] ?? 0),
        ];
    }

    private static function checkoutRequestId(string $value): string
    {
        if (preg_match('/^CKR-[0-9a-f]{40}$/D', $value) !== 1) {
            throw self::failure('card_operation_checkout_request_id_invalid');
        }
        return $value;
    }

    private static function decodeJson(string $json, string $reason): array
    {
        $value = json_decode($json, true);
        if (!is_array($value)) {
            throw self::failure($reason);
        }
        return $value;
    }

    private static function money(int $cents): string
    {
        if ($cents < 0) {
            throw self::failure('card_operation_money_negative');
        }
        return (string)intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function json(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw self::failure('card_operation_json_encode_failed');
        }
        return $json;
    }

    private static function rows($rows): array
    {
        if (is_object($rows) && method_exists($rows, 'toArray')) {
            $rows = $rows->toArray();
        }
        return is_array($rows) ? array_values($rows) : [];
    }

    private static function failure(string $reason): CashierV3CommandException
    {
        return new CashierV3CommandException(
            CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
            '升级结算资料不完整或已经变化，本次结账未提交。',
            CashierV3ResultCode::STATUS_FAILED,
            ['reason' => $reason]
        );
    }
}
