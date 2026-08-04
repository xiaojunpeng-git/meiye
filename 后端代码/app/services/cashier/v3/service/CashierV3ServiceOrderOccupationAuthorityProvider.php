<?php

namespace app\services\cashier\v3\service;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\checkout\provider\CashierV3EntitlementProviderContracts;
use app\services\cashier\v3\checkout\provider\CashierV3EntitlementProviderSchemaProbe;
use app\services\cashier\v3\checkout\provider\CashierV3ServiceOrderOccupationAuthority;

/**
 * Authoritative C3 service-order occupation scanner.
 *
 * It deliberately returns all active service-order contributors for direct,
 * reservation and service-order requests. Only the current service order may
 * expose convertibleTimes. The shared C2 provider decides when to invoke this
 * authority; this class never activates itself in Gateway/bootstrap.
 */
final class CashierV3ServiceOrderOccupationAuthorityProvider implements CashierV3ServiceOrderOccupationAuthority
{
    /** @var CashierV3ServiceOrderRepository */
    private $repository;

    public function __construct(CashierV3ServiceOrderRepository $repository)
    {
        $this->repository = $repository;
    }

    public function contractVersion(): string
    {
        return CashierV3EntitlementProviderContracts::SERVICE_ORDER_OCCUPATION;
    }

    public function readinessStatus(): array
    {
        $schema = CashierV3EntitlementProviderSchemaProbe::tablesStatus([
            'eb_' . ThinkPhpCashierV3ServiceOrderRepository::ORDER_TABLE => [
                'id', 'service_order_no', 'tenant_id', 'business_store_id', 'member_id',
                'participant_employee_ids_json', 'status', 'version', 'completed_at',
                'created_at', 'updated_at',
            ],
            'eb_' . ThinkPhpCashierV3ServiceOrderRepository::LINE_TABLE => [
                'id', 'tenant_id', 'service_order_id', 'line_key',
                'source_type', 'source_id', 'source_version_snapshot', 'hang_line_id',
                'service_quantity', 'authority_fingerprint',
                'entitlement_source_detail_id', 'occupied_times', 'status', 'version', 'updated_at',
            ],
            'eb_' . ThinkPhpCashierV3ServiceOrderRepository::GUARD_TABLE => [
                'id', 'tenant_id', 'entitlement_source_detail_id', 'current_version',
                'last_action', 'created_at', 'updated_at',
            ],
            'eb_' . ThinkPhpCashierV3ServiceOrderRepository::OPERATION_TABLE => [
                'id', 'operation_key', 'command_idempotency_key', 'request_fingerprint',
                'tenant_id', 'business_store_id', 'operation_type', 'service_order_id',
                'line_id', 'entitlement_source_detail_id', 'status_before', 'status_after',
                'service_order_version_before', 'service_order_version_after',
                'line_version_before', 'line_version_after', 'occupied_times_before',
                'occupied_times_after', 'guard_version_before', 'guard_version_after',
                'actor_staff_id', 'actor_employee_id', 'actor_name_snapshot', 'reason',
                'result_json', 'occurred_at', 'recorded_at',
            ],
        ]);
        return [
            'dependency' => 'c3_service_order_occupation',
            'contractVersion' => $this->contractVersion(),
            'ready' => $schema['ready'],
            'reasons' => $schema['ready'] ? [] : ['c3_service_order_schema_not_ready'],
            'schema' => $schema,
        ];
    }

    public function lockContributorsInTx(array $request, CashierV3DataScopeContext $dataScope): array
    {
        CashierV3TransactionGuard::assertInTransaction('c3ServiceOrderOccupationAuthority');
        $request = $this->normalizeRequest($request);
        $this->assertBaseScope($request, $dataScope);

        $this->repository->lockOrCreateEntitlementGuard(
            $request['tenantId'],
            $request['entitlementSourceDetailId']
        );
        $currentServiceOrderId = $request['source']['type'] === 'service_order'
            ? $request['source']['serviceOrderId']
            : 0;
        $set = $this->repository->lockOccupationSet(
            $request['tenantId'],
            $request['entitlementSourceDetailId'],
            $currentServiceOrderId
        );

        $orders = [];
        foreach ($set['orders'] as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0 || isset($orders[$id])) {
                throw self::failure('service_order_authority_order_set_invalid');
            }
            if ((string)($row['tenant_id'] ?? '') !== $request['tenantId']) {
                throw self::failure('service_order_authority_tenant_corrupted', ['serviceOrderId' => $id]);
            }
            $orders[$id] = $row;
        }

        $occupiedByOrder = [];
        foreach ($set['targetLines'] as $line) {
            $lineId = (int)($line['id'] ?? 0);
            $orderId = (int)($line['service_order_id'] ?? 0);
            $occupiedTimes = (int)($line['occupied_times'] ?? 0);
            if ($lineId <= 0
                || $orderId <= 0
                || (string)($line['tenant_id'] ?? '') !== $request['tenantId']
                || (string)($line['source_type'] ?? ThinkPhpCashierV3ServiceOrderRepository::LINE_SOURCE_ENTITLEMENT)
                    !== ThinkPhpCashierV3ServiceOrderRepository::LINE_SOURCE_ENTITLEMENT
                || (int)($line['entitlement_source_detail_id'] ?? 0)
                    !== $request['entitlementSourceDetailId']
                || !isset($orders[$orderId])) {
                throw self::failure('service_order_authority_line_set_invalid', ['lineId' => $lineId]);
            }
            if (!CashierV3ServiceOrderState::isActiveLine((string)($line['status'] ?? ''))) {
                continue;
            }
            if ($occupiedTimes <= 0) {
                throw self::failure('service_order_active_line_occupation_invalid', ['lineId' => $lineId]);
            }
            $occupiedByOrder[$orderId] = ($occupiedByOrder[$orderId] ?? 0) + $occupiedTimes;
            if ($occupiedByOrder[$orderId] > PHP_INT_MAX) {
                throw self::failure('service_order_occupation_overflow', ['serviceOrderId' => $orderId]);
            }
        }

        $contributors = [];
        foreach ($orders as $orderId => $order) {
            if (!CashierV3ServiceOrderState::isActiveOrder((string)($order['status'] ?? ''))
                || !isset($occupiedByOrder[$orderId])) {
                continue;
            }
            $storeId = (int)($order['business_store_id'] ?? 0);
            $version = (int)($order['version'] ?? 0);
            if ($storeId <= 0 || $version <= 0) {
                throw self::failure('service_order_authority_order_invalid', ['serviceOrderId' => $orderId]);
            }
            // Competing orders are counted across stores. DataScope authorizes
            // the current operation; it must never hide another store's claim
            // on the same member entitlement.
            $convertible = $request['source']['type'] === 'service_order'
                && $request['source']['serviceOrderId'] === $orderId
                ? $occupiedByOrder[$orderId]
                : 0;
            $contributors[] = [
                'kind' => 'service_order',
                'id' => $orderId,
                'version' => $version,
                'occupiedTimes' => $occupiedByOrder[$orderId],
                'convertibleTimes' => $convertible,
            ];
        }

        if ($request['source']['type'] === 'service_order') {
            $currentId = $request['source']['serviceOrderId'];
            $current = $orders[$currentId] ?? null;
            if ($current === null
                || !CashierV3ServiceOrderState::isActiveOrder((string)($current['status'] ?? ''))
                || !isset($occupiedByOrder[$currentId])) {
                throw self::failure('current_service_order_not_active_contributor');
            }
            if ((int)$current['business_store_id'] !== $request['storeId']) {
                throw self::failure('current_service_order_store_mismatch');
            }
            $this->assertCurrentOrderScope($current, $dataScope);
        }

        usort($contributors, static function (array $left, array $right): int {
            return $left['id'] <=> $right['id'];
        });
        return $contributors;
    }

    private function assertBaseScope(array $request, CashierV3DataScopeContext $dataScope): void
    {
        if ($request['tenantId'] !== $dataScope->tenantId()) {
            throw self::failure('service_order_authority_tenant_denied');
        }
        if ($request['storeId'] !== $dataScope->forcedStoreId()) {
            throw self::failure('service_order_authority_store_mismatch');
        }
        $mode = $dataScope->authorizationMode();
        if ($mode === CashierV3DataScopeContext::MODE_NONE) {
            throw self::failure('service_order_authority_scope_denied');
        }
        if ($mode === CashierV3DataScopeContext::MODE_STORES && !$dataScope->allowsStore($request['storeId'])) {
            throw self::failure('service_order_authority_store_denied');
        }
        if ($mode === CashierV3DataScopeContext::MODE_SELF_PARTICIPANT
            && $request['source']['type'] !== 'service_order') {
            throw self::failure('service_order_authority_self_participant_source_required');
        }
        if (!in_array($mode, [
            CashierV3DataScopeContext::MODE_ALL,
            CashierV3DataScopeContext::MODE_STORES,
            CashierV3DataScopeContext::MODE_SELF_PARTICIPANT,
        ], true)) {
            throw self::failure('service_order_authority_scope_invalid');
        }
    }

    private function assertCurrentOrderScope(array $order, CashierV3DataScopeContext $dataScope): void
    {
        if ($dataScope->authorizationMode() !== CashierV3DataScopeContext::MODE_SELF_PARTICIPANT) {
            return;
        }
        $participants = json_decode((string)($order['participant_employee_ids_json'] ?? ''), true);
        if (!is_array($participants)) {
            throw self::failure('service_order_participant_snapshot_invalid');
        }
        $normalized = [];
        foreach ($participants as $employeeId) {
            if (!is_int($employeeId) || $employeeId <= 0) {
                throw self::failure('service_order_participant_snapshot_invalid');
            }
            $normalized[$employeeId] = true;
        }
        if ($dataScope->employeeId() <= 0 || !isset($normalized[$dataScope->employeeId()])) {
            throw self::failure('service_order_authority_self_participant_denied');
        }
    }

    private function normalizeRequest(array $request): array
    {
        $keys = array_keys($request);
        sort($keys, SORT_STRING);
        if ($keys !== ['entitlementSourceDetailId', 'source', 'storeId', 'tenantId']) {
            throw self::failure('service_order_authority_request_shape_invalid');
        }
        $tenantId = trim((string)$request['tenantId']);
        $storeId = is_int($request['storeId']) ? $request['storeId'] : 0;
        $detailId = is_int($request['entitlementSourceDetailId'])
            ? $request['entitlementSourceDetailId']
            : 0;
        if ($tenantId === ''
            || strlen($tenantId) > 32
            || preg_match('/^[A-Za-z0-9:._-]+$/D', $tenantId) !== 1
            || $storeId <= 0
            || $detailId <= 0
            || !is_array($request['source'])) {
            throw self::failure('service_order_authority_request_invalid');
        }
        $sourceKeys = array_keys($request['source']);
        sort($sourceKeys, SORT_STRING);
        if ($sourceKeys !== ['reservationId', 'serviceOrderId', 'type']) {
            throw self::failure('service_order_authority_source_shape_invalid');
        }
        $type = (string)$request['source']['type'];
        $serviceOrderId = is_int($request['source']['serviceOrderId'])
            ? $request['source']['serviceOrderId']
            : -1;
        $reservationId = is_int($request['source']['reservationId'])
            ? $request['source']['reservationId']
            : -1;
        if (!in_array($type, ['direct', 'reservation', 'service_order'], true)
            || $serviceOrderId < 0
            || $reservationId < 0
            || ($type === 'direct' && ($serviceOrderId !== 0 || $reservationId !== 0))
            || ($type === 'reservation' && ($reservationId <= 0 || $serviceOrderId !== 0))
            || ($type === 'service_order' && ($serviceOrderId <= 0 || $reservationId !== 0))) {
            throw self::failure('service_order_authority_source_invalid');
        }
        return [
            'tenantId' => $tenantId,
            'storeId' => $storeId,
            'entitlementSourceDetailId' => $detailId,
            'source' => compact('type', 'serviceOrderId', 'reservationId'),
        ];
    }

    private static function failure(string $reason, array $detail = []): CashierV3ServiceOrderAuthorityException
    {
        return new CashierV3ServiceOrderAuthorityException($reason, $detail);
    }
}
