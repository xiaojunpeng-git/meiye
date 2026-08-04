<?php

namespace app\services\cashier\v3\settlement;

use app\services\cashier\v3\CashierV3TransactionGuard;

/**
 * Final transaction coordinator for sale-only, entitlement-only and mixed
 * checkout requests.
 *
 * It deliberately owns no transaction API. The Gateway starts the transaction
 * and locks public contexts first; every port method below must remain in that
 * same transaction. Exceptions are never swallowed, so a failed slice cannot
 * leave sales, collection, entitlement, inventory, facts or cart cleanup in a
 * partially committed state.
 */
final class CashierV3CheckoutSubmissionOrchestrator
{
    public const CONTRACT_VERSION = 'cashier-v3-checkout-submission-orchestrator-v1';
    public const AUTHORITY_CONTRACT_VERSION = 'cashier-v3-checkout-submission-authority-v1';

    public const COMPOSITION_SALE_ONLY = 'sale_only';
    public const COMPOSITION_ENTITLEMENT_ONLY = 'entitlement_only';
    public const COMPOSITION_MIXED = 'mixed';

    private const READY = 'ready_for_submit';
    private const SUCCEEDED = 'succeeded';

    /** @var CashierV3CheckoutSubmissionExecutionPort */
    private $port;

    public function __construct(CashierV3CheckoutSubmissionExecutionPort $port)
    {
        $this->port = $port;
    }

    public function submitInTx(array $scope): array
    {
        CashierV3TransactionGuard::assertInTransaction('checkoutSubmissionOrchestrator');
        $authority = $this->normalizeAuthority(
            $this->port->lockSubmissionAuthorityInTx($scope)
        );

        if ($authority['requestStatus'] === self::SUCCEEDED) {
            return $this->normalizeReplayResult($authority);
        }

        if ($authority['composition'] === self::COMPOSITION_SALE_ONLY) {
            return $this->submitSaleOnly($scope, $authority);
        }

        $entitlementPlan = $this->normalizeEntitlementPlan(
            $this->port->planEntitlementCompletionInTx($authority),
            $authority
        );

        $salesOrder = [];
        $paymentCollection = [];
        $debt = [];
        $balancePayment = null;
        if ($authority['composition'] === self::COMPOSITION_MIXED) {
            $salesOrder = $this->normalizeSalesOrder(
                $this->port->persistSalesOrderInTx($authority),
                $authority
            );
            $paymentCollection = $this->normalizePaymentCollection(
                $this->port->persistPaymentCollectionInTx($authority, $salesOrder),
                $authority,
                $salesOrder
            );
            $debt = $this->normalizeDebt(
                $this->port->persistDebtInTx($authority, $salesOrder),
                $authority,
                $salesOrder
            );
            $balancePayment = $this->port->persistBalancePaymentInTx(
                $authority,
                $salesOrder,
                $paymentCollection
            );
        }

        $inventoryCompletion = $this->normalizeInventoryCompletion(
            $this->port->persistInventoryCompletionInTx($authority, $entitlementPlan),
            $authority
        );
        $businessEvents = $this->normalizeBusinessEvents(
            $this->port->recordCompletionEventsInTx(
                $authority,
                $salesOrder,
                $paymentCollection,
                $debt,
                $entitlementPlan,
                $inventoryCompletion
            )
        );
        $entitlementCompletion = $this->normalizeEntitlementCompletion(
            $this->port->persistEntitlementCompletionInTx(
                $authority,
                $entitlementPlan,
                $inventoryCompletion,
                $businessEvents
            ),
            $authority,
            $entitlementPlan
        );

        $saleFacts = [];
        if ($authority['composition'] === self::COMPOSITION_MIXED) {
            $saleFacts = $this->normalizeSaleFacts(
                $this->port->persistSaleFactsInTx(
                    $authority,
                    $salesOrder,
                    $paymentCollection,
                    $businessEvents,
                    $balancePayment
                )
            );
        }

        $completionReferenceId = $authority['composition'] === self::COMPOSITION_MIXED
            ? $salesOrder['orderId']
            : $entitlementCompletion['receiptId'];
        $requestResult = $this->normalizeRequestResult(
            $this->port->markSucceededInTx($authority, $completionReferenceId, [
                'salesOrder' => $salesOrder,
                'paymentCollection' => $paymentCollection,
                'debt' => $debt,
                'entitlementCompletion' => $entitlementCompletion,
                'inventoryCompletion' => $inventoryCompletion,
                'businessEvents' => $businessEvents,
                'saleFacts' => $saleFacts,
            ]),
            $authority,
            $completionReferenceId
        );
        $workspace = $this->normalizeWorkspaceCompletion(
            $this->port->completeWorkspaceInTx($authority),
            $authority
        );

        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'checkoutRequestId' => $authority['checkoutRequestId'],
            'checkoutRequestVersion' => $requestResult['checkoutRequestVersion'],
            'requestStatus' => self::SUCCEEDED,
            'composition' => $authority['composition'],
            'completionReferenceId' => $completionReferenceId,
            'salesOrder' => $salesOrder ?: null,
            'paymentCollection' => $paymentCollection ?: null,
            'balancePayment' => $balancePayment,
            'debt' => $debt ?: null,
            'entitlementCompletion' => $entitlementCompletion,
            'inventoryCompletion' => $inventoryCompletion,
            'businessEventNos' => $businessEvents['businessEventNos'],
            'factFingerprints' => [
                'sale' => $saleFacts ? $saleFacts['planFingerprint'] : null,
                'entitlement' => $entitlementCompletion['planFingerprint'],
            ],
            'settledAt' => $authority['settledAt'],
            'cashierDraft' => $workspace['cashierDraft'],
            'replayed' => $salesOrder && $salesOrder['replayed']
                || $paymentCollection && $paymentCollection['replayed']
                || $inventoryCompletion['replayed']
                || $entitlementCompletion['replayed']
                || $saleFacts && $saleFacts['replayed'],
        ];
    }

    private function submitSaleOnly(array $scope, array $authority): array
    {
        $result = $this->port->submitSaleOnlyInTx($scope, $authority);
        if (!is_array($result)
            || (string)($result['checkoutRequestId'] ?? '') !== $authority['checkoutRequestId']
            || (string)($result['requestStatus'] ?? '') !== self::SUCCEEDED
            || !is_array($result['salesOrder'] ?? null)
            || !is_array($result['paymentCollection'] ?? null)
            || !is_array($result['cashierDraft'] ?? null)) {
            throw self::failure('sale_only_submission_result_invalid');
        }
        $salesOrder = $this->normalizeSalesOrder($result['salesOrder'], $authority);
        $payment = $this->normalizePaymentCollection(
            $result['paymentCollection'],
            $authority,
            $salesOrder
        );
        $eventNo = self::token(
            $result['businessEventNo'] ?? null,
            64,
            'sale_only_business_event_invalid'
        );
        $factFingerprint = self::sha256(
            $result['factFingerprint'] ?? null,
            'sale_only_fact_fingerprint_invalid'
        );
        $version = self::positiveInt(
            $result['checkoutRequestVersion'] ?? null,
            'sale_only_checkout_version_invalid'
        );
        if ($version <= $authority['checkoutRequestVersion']) {
            throw self::failure('sale_only_checkout_version_not_advanced');
        }
        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'checkoutRequestId' => $authority['checkoutRequestId'],
            'checkoutRequestVersion' => $version,
            'requestStatus' => self::SUCCEEDED,
            'composition' => self::COMPOSITION_SALE_ONLY,
            'completionReferenceId' => $salesOrder['orderId'],
            'salesOrder' => $salesOrder,
            'paymentCollection' => $payment,
            'entitlementCompletion' => null,
            'inventoryCompletion' => null,
            'businessEventNos' => [$eventNo],
            'factFingerprints' => ['sale' => $factFingerprint, 'entitlement' => null],
            'settledAt' => self::positiveInt(
                $result['settledAt'] ?? null,
                'sale_only_settled_at_invalid'
            ),
            'hangOrder' => is_array($result['hangOrder'] ?? null) ? $result['hangOrder'] : null,
            'cashierDraft' => $result['cashierDraft'],
            'replayed' => (bool)($result['replayed'] ?? false),
        ];
    }

    private function normalizeAuthority(array $authority): array
    {
        self::assertExactKeys($authority, [
            'contractVersion', 'checkoutRequestId', 'checkoutRequestVersion',
            'commandIdempotencyKey', 'requestStatus', 'composition', 'workspaceId',
            'stateContextId', 'tenantId', 'storeId', 'memberId', 'settledAt',
            'lockedAggregateFingerprint', 'lockedResourcePlanFingerprint',
            'saleLineIds', 'entitlementLineIds', 'entitlementAuthority', 'replayResult',
            'executionContext',
        ], 'submissionAuthority');
        if ($authority['contractVersion'] !== self::AUTHORITY_CONTRACT_VERSION) {
            throw self::failure('submission_authority_contract_version_invalid');
        }
        self::opaqueId($authority['checkoutRequestId'], 'CKR', 'checkout_request_id_invalid');
        self::positiveInt($authority['checkoutRequestVersion'], 'checkout_request_version_invalid');
        self::commandKey($authority['commandIdempotencyKey']);
        self::token($authority['workspaceId'], 128, 'workspace_id_invalid');
        self::token($authority['stateContextId'], 128, 'state_context_id_invalid');
        self::token($authority['tenantId'], 32, 'tenant_id_invalid');
        self::positiveInt($authority['storeId'], 'store_id_invalid');
        self::nonNegativeInt($authority['memberId'], 'member_id_invalid');
        self::positiveInt($authority['settledAt'], 'settled_at_invalid');
        self::sha256($authority['lockedAggregateFingerprint'], 'locked_aggregate_fingerprint_invalid');
        self::sha256($authority['lockedResourcePlanFingerprint'], 'locked_resource_plan_fingerprint_invalid');
        if (!($authority['executionContext']
            instanceof CashierV3CheckoutSubmissionExecutionContext)) {
            throw self::failure('submission_execution_context_missing');
        }
        try {
            $authority['executionContext']->assertMatchesAuthority($authority);
        } catch (\LogicException $exception) {
            throw self::failure('submission_execution_context_mismatch');
        }
        if (!in_array($authority['requestStatus'], [self::READY, self::SUCCEEDED], true)
            || !in_array($authority['composition'], [
                self::COMPOSITION_SALE_ONLY,
                self::COMPOSITION_ENTITLEMENT_ONLY,
                self::COMPOSITION_MIXED,
            ], true)) {
            throw self::failure('submission_authority_state_invalid');
        }

        $saleIds = self::lineIds($authority['saleLineIds'], 'sale_line_ids_invalid');
        $entitlementIds = self::lineIds(
            $authority['entitlementLineIds'],
            'entitlement_line_ids_invalid'
        );
        if (array_intersect($saleIds, $entitlementIds)) {
            throw self::failure('checkout_line_role_overlap');
        }
        $authority['saleLineIds'] = $saleIds;
        $authority['entitlementLineIds'] = $entitlementIds;
        $compositionValid = $authority['composition'] === self::COMPOSITION_SALE_ONLY
            && $saleIds && !$entitlementIds
            || $authority['composition'] === self::COMPOSITION_ENTITLEMENT_ONLY
            && !$saleIds && $entitlementIds
            || $authority['composition'] === self::COMPOSITION_MIXED
            && $saleIds && $entitlementIds;
        if (!$compositionValid) {
            throw self::failure('checkout_composition_line_roles_mismatch');
        }

        if ($authority['requestStatus'] === self::SUCCEEDED) {
            if (!is_array($authority['replayResult'])) {
                throw self::failure('checkout_replay_result_missing');
            }
            return $authority;
        }
        if ($authority['replayResult'] !== null) {
            throw self::failure('checkout_pending_replay_result_forbidden');
        }
        if ($authority['composition'] === self::COMPOSITION_SALE_ONLY) {
            if ($authority['entitlementAuthority'] !== null) {
                throw self::failure('sale_only_entitlement_authority_forbidden');
            }
            return $authority;
        }
        $authority['entitlementAuthority'] = $this->normalizeEntitlementAuthority(
            $authority['entitlementAuthority'],
            $authority
        );
        return $authority;
    }

    private function normalizeEntitlementAuthority($value, array $authority): array
    {
        if (!is_array($value)) {
            throw self::failure('entitlement_locked_authority_missing');
        }
        self::assertExactKeys($value, [
            'command', 'lockedSnapshot', 'lockedResourcePlan', 'staffSnapshots',
            'inventoryProviderSnapshot', 'inventoryDataScope',
        ], 'entitlementAuthority');
        foreach (['command', 'lockedSnapshot', 'lockedResourcePlan', 'staffSnapshots', 'inventoryProviderSnapshot'] as $key) {
            if (!is_array($value[$key]) || !$value[$key]) {
                throw self::failure('entitlement_locked_input_missing', ['field' => $key]);
            }
        }
        if (!is_object($value['inventoryDataScope'])) {
            throw self::failure('inventory_data_scope_missing');
        }
        $command = $value['command'];
        $snapshot = $value['lockedSnapshot'];
        foreach ([
            ['actual' => $command['workspaceId'] ?? null, 'expected' => $authority['workspaceId']],
            ['actual' => $command['stateContextId'] ?? null, 'expected' => $authority['stateContextId']],
            ['actual' => $snapshot['workspaceId'] ?? null, 'expected' => $authority['workspaceId']],
            ['actual' => $snapshot['stateContextId'] ?? null, 'expected' => $authority['stateContextId']],
            ['actual' => $snapshot['tenantId'] ?? null, 'expected' => $authority['tenantId']],
        ] as $identity) {
            if (!is_string($identity['actual'])
                || !hash_equals((string)$identity['expected'], $identity['actual'])) {
                throw self::failure('entitlement_locked_identity_mismatch');
            }
        }
        if (($command['memberId'] ?? null) !== $authority['memberId']
            || ($snapshot['memberId'] ?? null) !== $authority['memberId']
            || ($snapshot['storeId'] ?? null) !== $authority['storeId']) {
            throw self::failure('entitlement_locked_scope_mismatch');
        }
        $commandLineIds = self::nestedLineIds($command['lines'] ?? null, 'command_line_id');
        $snapshotLineIds = self::nestedLineIds($snapshot['lines'] ?? null, 'snapshot_line_id');
        $expected = $authority['entitlementLineIds'];
        sort($commandLineIds, SORT_STRING);
        sort($snapshotLineIds, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($commandLineIds !== $expected || $snapshotLineIds !== $expected) {
            throw self::failure('entitlement_locked_line_set_mismatch');
        }
        foreach ($snapshot['lines'] as $line) {
            if (!is_array($line) || ($line['lineRole'] ?? null) !== 'entitlement_service') {
                throw self::failure('entitlement_locked_line_role_invalid');
            }
        }
        $inventory = $value['inventoryProviderSnapshot'];
        if (($inventory['contractVersion'] ?? null)
                !== 'inventory-entitlement-completion-provider-v1'
            || ($inventory['tenantId'] ?? null) !== $authority['tenantId']
            || ($inventory['storeId'] ?? null) !== $authority['storeId']) {
            throw self::failure('inventory_locked_snapshot_mismatch');
        }
        return $value;
    }

    private function normalizeReplayResult(array $authority): array
    {
        $result = $authority['replayResult'];
        if (($result['contractVersion'] ?? null) !== self::CONTRACT_VERSION
            || ($result['checkoutRequestId'] ?? null) !== $authority['checkoutRequestId']
            || ($result['requestStatus'] ?? null) !== self::SUCCEEDED
            || ($result['composition'] ?? null) !== $authority['composition']
            || !is_array($result['cashierDraft'] ?? null)) {
            throw self::failure('checkout_replay_result_invalid');
        }
        $reference = (string)($result['completionReferenceId'] ?? '');
        if ($authority['composition'] === self::COMPOSITION_ENTITLEMENT_ONLY) {
            self::opaqueId($reference, 'ECR', 'checkout_replay_reference_invalid');
        } else {
            self::opaqueId($reference, 'CSO', 'checkout_replay_reference_invalid');
        }
        $result['replayed'] = true;
        return $result;
    }

    private function normalizeSalesOrder(array $result, array $authority): array
    {
        $orderId = self::opaqueId($result['orderId'] ?? null, 'CSO', 'sales_order_id_invalid');
        self::token($result['orderNo'] ?? null, 64, 'sales_order_no_invalid');
        if (array_key_exists('checkoutRequestId', $result)
            && $result['checkoutRequestId'] !== $authority['checkoutRequestId']) {
            throw self::failure('sales_order_checkout_mismatch');
        }
        return array_merge($result, [
            'orderId' => $orderId,
            'replayed' => (bool)($result['replayed'] ?? false),
        ]);
    }

    private function normalizePaymentCollection(
        array $result,
        array $authority,
        array $salesOrder
    ): array {
        self::opaqueId($result['batchId'] ?? null, 'CPB', 'payment_batch_id_invalid');
        if (($result['salesOrderId'] ?? $salesOrder['orderId']) !== $salesOrder['orderId']
            || (array_key_exists('checkoutRequestId', $result)
                && $result['checkoutRequestId'] !== $authority['checkoutRequestId'])) {
            throw self::failure('payment_collection_sales_order_mismatch');
        }
        $result['salesOrderId'] = $salesOrder['orderId'];
        $result['replayed'] = (bool)($result['replayed'] ?? false);
        return $result;
    }

    private function normalizeDebt(array $result, array $authority, array $salesOrder): array
    {
        $amount = self::nonNegativeInt(
            $result['amountCents'] ?? null,
            'checkout_debt_amount_invalid'
        );
        if ($amount === 0) {
            if ((int)($result['debtId'] ?? 0) !== 0 || (string)($result['debtNo'] ?? '') !== '') {
                throw self::failure('checkout_empty_debt_authority_invalid');
            }
            return [];
        }
        self::positiveInt($result['debtId'] ?? null, 'checkout_debt_id_invalid');
        self::token($result['debtNo'] ?? null, 64, 'checkout_debt_no_invalid');
        if (($result['checkoutRequestId'] ?? $authority['checkoutRequestId'])
                !== $authority['checkoutRequestId']
            || ($result['salesOrderId'] ?? $salesOrder['orderId']) !== $salesOrder['orderId']) {
            throw self::failure('checkout_debt_scope_mismatch');
        }
        return array_merge($result, ['replayed' => (bool)($result['replayed'] ?? false)]);
    }

    private function normalizeEntitlementPlan(array $plan, array $authority): array
    {
        if (($plan['contractVersion'] ?? null) !== 'c2-entitlement-completion-v3'
            || ($plan['persistenceStatus'] ?? null) !== 'not_persisted') {
            throw self::failure('entitlement_plan_invalid');
        }
        self::opaqueId($plan['receiptId'] ?? null, 'ECR', 'entitlement_receipt_id_invalid');
        self::sha256(
            $plan['kernelPlanFingerprint'] ?? null,
            'entitlement_kernel_plan_fingerprint_invalid'
        );
        if (($plan['checkoutRequestId'] ?? null) !== $authority['checkoutRequestId']) {
            throw self::failure('entitlement_plan_checkout_mismatch');
        }
        return $plan;
    }

    private function normalizeInventoryCompletion(array $result, array $authority): array
    {
        if (($result['contractVersion'] ?? null)
                !== 'inventory-entitlement-completion-provider-v1'
            || ($result['checkoutRequestId'] ?? null) !== $authority['checkoutRequestId']) {
            throw self::failure('inventory_completion_result_invalid');
        }
        self::token($result['receiptKey'] ?? null, 96, 'inventory_receipt_key_invalid');
        $result['replayed'] = (bool)($result['replayed'] ?? false);
        return $result;
    }

    private function normalizeBusinessEvents(array $result): array
    {
        $eventNos = self::lineIds(
            $result['businessEventNos'] ?? null,
            'business_event_set_invalid'
        );
        if (!$eventNos) {
            throw self::failure('business_event_set_empty');
        }
        self::sha256($result['eventSetFingerprint'] ?? null, 'business_event_set_fingerprint_invalid');
        $result['businessEventNos'] = $eventNos;
        return $result;
    }

    private function normalizeEntitlementCompletion(
        array $result,
        array $authority,
        array $plan
    ): array {
        if (($result['contractVersion'] ?? null)
                !== 'cashier-v3-entitlement-completion-persistence-v1'
            || ($result['checkoutRequestId'] ?? null) !== $authority['checkoutRequestId']
            || ($result['persistenceStatus'] ?? null) !== 'persisted'
            || ($result['receiptId'] ?? null) !== $plan['receiptId']) {
            throw self::failure('entitlement_completion_result_invalid');
        }
        self::opaqueId($result['receiptId'], 'ECR', 'entitlement_receipt_id_invalid');
        self::sha256(
            $result['planFingerprint'] ?? null,
            'entitlement_persistence_plan_fingerprint_invalid'
        );
        $result['replayed'] = (bool)($result['replayed'] ?? false);
        return $result;
    }

    private function normalizeSaleFacts(array $result): array
    {
        self::sha256($result['planFingerprint'] ?? null, 'sale_fact_fingerprint_invalid');
        $result['replayed'] = (bool)($result['replayed'] ?? false);
        return $result;
    }

    private function normalizeRequestResult(
        array $result,
        array $authority,
        string $referenceId
    ): array {
        if (($result['checkoutRequestId'] ?? null) !== $authority['checkoutRequestId']
            || ($result['requestStatus'] ?? null) !== self::SUCCEEDED
            || ($result['completionReferenceId'] ?? null) !== $referenceId) {
            throw self::failure('checkout_request_success_result_invalid');
        }
        $version = self::positiveInt(
            $result['checkoutRequestVersion'] ?? null,
            'checkout_request_success_version_invalid'
        );
        if ($version <= $authority['checkoutRequestVersion']) {
            throw self::failure('checkout_request_success_version_not_advanced');
        }
        $result['checkoutRequestVersion'] = $version;
        return $result;
    }

    private function normalizeWorkspaceCompletion(array $result, array $authority): array
    {
        if (($result['workspaceId'] ?? null) !== $authority['workspaceId']
            || ($result['cleared'] ?? null) !== true
            || !is_array($result['cashierDraft'] ?? null)) {
            throw self::failure('checkout_workspace_completion_invalid');
        }
        return $result;
    }

    private static function nestedLineIds($rows, string $reason): array
    {
        if (!is_array($rows) || !self::isList($rows) || !$rows) {
            throw self::failure($reason);
        }
        $ids = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw self::failure($reason);
            }
            $id = self::token($row['lineId'] ?? null, 128, $reason);
            if (isset($ids[$id])) {
                throw self::failure($reason);
            }
            $ids[$id] = $id;
        }
        return array_values($ids);
    }

    private static function lineIds($values, string $reason): array
    {
        if (!is_array($values) || !self::isList($values)) {
            throw self::failure($reason);
        }
        $ids = [];
        foreach ($values as $value) {
            $id = self::token($value, 128, $reason);
            if (isset($ids[$id])) {
                throw self::failure($reason);
            }
            $ids[$id] = $id;
        }
        return array_values($ids);
    }

    private static function commandKey($value): string
    {
        if (!is_string($value)
            || preg_match(
                '/^CHECKOUT-[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}'
                . '-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',
                $value
            ) !== 1) {
            throw self::failure('checkout_command_idempotency_key_invalid');
        }
        return $value;
    }

    private static function opaqueId($value, string $prefix, string $reason): string
    {
        if (!is_string($value)
            || preg_match('/^' . preg_quote($prefix, '/') . '-[0-9a-f]{40}$/D', $value) !== 1) {
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

    private static function token($value, int $maxLength, string $reason): string
    {
        if (!is_string($value)) {
            throw self::failure($reason);
        }
        $value = trim($value);
        if ($value === '' || strlen($value) > $maxLength
            || preg_match('/^[A-Za-z0-9_.:-]+$/D', $value) !== 1) {
            throw self::failure($reason);
        }
        return $value;
    }

    private static function positiveInt($value, string $reason): int
    {
        if (!is_int($value) || $value <= 0) {
            throw self::failure($reason);
        }
        return $value;
    }

    private static function nonNegativeInt($value, string $reason): int
    {
        if (!is_int($value) || $value < 0) {
            throw self::failure($reason);
        }
        return $value;
    }

    private static function assertExactKeys(array $value, array $required, string $path): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($required, SORT_STRING);
        if ($actual !== $required) {
            throw self::failure('submission_contract_shape_invalid', ['path' => $path]);
        }
    }

    private static function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }

    private static function failure(
        string $reason,
        array $detail = []
    ): CashierV3CheckoutSubmissionOrchestrationException {
        return new CashierV3CheckoutSubmissionOrchestrationException($reason, $detail);
    }
}
