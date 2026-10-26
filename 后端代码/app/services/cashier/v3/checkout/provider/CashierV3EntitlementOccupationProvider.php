<?php

namespace app\services\cashier\v3\checkout\provider;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3TransactionGuard;
use think\facade\Db;

/**
 * Locks the complete legacy reservation occupation range for an entitlement
 * detail. C3 service-order occupation remains fail-closed until C3 supplies
 * its own authoritative implementation of CashierV3ServiceOrderOccupationAuthority.
 */
final class CashierV3EntitlementOccupationProvider
{
    public const TABLE = 'cashier_v3_entitlement_occupation_version';

    /** @var CashierV3ServiceOrderOccupationAuthority|null */
    private $serviceOrderAuthority;

    public function __construct(CashierV3ServiceOrderOccupationAuthority $serviceOrderAuthority = null)
    {
        $this->serviceOrderAuthority = $serviceOrderAuthority;
    }

    public function contractVersion(): string
    {
        return CashierV3EntitlementProviderContracts::OCCUPATION;
    }

    public function readinessStatus(): array
    {
        $schema = CashierV3EntitlementProviderSchemaProbe::tablesStatus([
            'eb_store_reservation_order' => [
                'id', 'store_id', 'cart_info_id', 'status', 'is_del', 'is_system_del',
            ],
            'eb_' . self::TABLE => [
                'id', 'tenant_id', 'source_kind', 'source_id', 'store_id_snapshot',
                'entitlement_source_detail_id_snapshot', 'authority_fingerprint',
                'current_version', 'last_action', 'created_at', 'updated_at',
            ],
        ]);
        $serviceStatus = $this->serviceOrderAuthority
            ? $this->serviceOrderAuthority->readinessStatus()
            : ['ready' => false, 'reasons' => ['c3_service_order_authority_missing']];
        $serviceContract = $this->serviceOrderAuthority
            ? $this->serviceOrderAuthority->contractVersion()
            : '';
        $serviceReady = !empty($serviceStatus['ready'])
            && $serviceContract === CashierV3EntitlementProviderContracts::SERVICE_ORDER_OCCUPATION;
        $reasons = [];
        if (!$schema['ready']) {
            $reasons[] = 'reservation_occupation_schema_not_ready';
        }
        if (!$serviceReady) {
            $reasons[] = 'service_order_occupation_authority_not_ready';
        }
        return [
            'dependency' => 'occupation',
            'contractVersion' => $this->contractVersion(),
            'reservationAuthorityContractVersion' => CashierV3EntitlementProviderContracts::RESERVATION_OCCUPATION,
            'ready' => $schema['ready'] && $serviceReady,
            'reasons' => $reasons,
            'schema' => $schema,
            'serviceOrderAuthority' => [
                'contractVersion' => $serviceContract,
                'status' => $serviceStatus,
            ],
        ];
    }

    /**
     * Request shape:
     * tenantId, storeId, entitlementSourceDetailId,
     * source(type,serviceOrderId,reservationId).
     */
    public function lockSnapshotInTx(
        array $request,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        CashierV3TransactionGuard::assertInTransaction('entitlementOccupationSnapshot');
        CashierV3EntitlementProviderDataScope::assertBase($operatorScope, $dataScope);
        $request = $this->normalizeRequest($request);
        CashierV3EntitlementProviderDataScope::assertTenant($request['tenantId'], $dataScope);
        if ($dataScope->isSelfParticipantMode()) {
            // SELF_PARTICIPANT has no store-set grant. It is valid only for a
            // current service order; the C3 authority verifies that the
            // signed-in employee is present in that order's frozen participant
            // snapshot before returning any contributors.
            if ($request['source']['type'] !== 'service_order') {
                throw self::failure('occupation_self_participant_service_order_required');
            }
            if ($request['storeId'] !== $dataScope->forcedStoreId()) {
                throw self::failure('occupation_operator_store_mismatch');
            }
        } else {
            CashierV3EntitlementProviderDataScope::assertStore($request['storeId'], $dataScope);
        }
        if ($request['storeId'] !== $operatorScope->storeId()) {
            throw self::failure('occupation_operator_store_mismatch');
        }

        // The cart_info_id leading index makes this a next-key range lock. We
        // deliberately lock inactive rows too, then filter in PHP, so another
        // transaction cannot activate or insert a contributor into the range.
        $rows = Db::name('store_reservation_order')
            ->where('cart_info_id', $request['entitlementSourceDetailId'])
            ->order('id asc')
            ->lock(true)
            ->select();
        if (is_object($rows) && method_exists($rows, 'toArray')) {
            $rows = $rows->toArray();
        }
        if (!is_array($rows)) {
            throw self::failure('reservation_occupation_authority_unavailable');
        }

        $contributors = [];
        $reservationRowsById = [];
        foreach ($rows as $row) {
            $reservationId = (int)($row['id'] ?? 0);
            $rowStoreId = (int)($row['store_id'] ?? 0);
            if ($reservationId <= 0
                || $rowStoreId <= 0
                || (int)($row['cart_info_id'] ?? 0) !== $request['entitlementSourceDetailId']) {
                throw self::failure('reservation_occupation_row_invalid');
            }
            $reservationRowsById[$reservationId] = $row;
            if (!$this->activeReservation($row)) {
                continue;
            }
            $fingerprint = $this->reservationFingerprint($row);
            $version = $this->synchronizeVersion(
                $request['tenantId'],
                'reservation',
                $reservationId,
                $rowStoreId,
                $request['entitlementSourceDetailId'],
                $fingerprint
            );
            $convertible = $request['source']['type'] === 'reservation'
                && $request['source']['reservationId'] === $reservationId
                ? 1
                : 0;
            $contributors[] = [
                'kind' => 'reservation',
                'id' => $reservationId,
                'version' => $version,
                'occupiedTimes' => 1,
                'convertibleTimes' => $convertible,
            ];
        }

        if ($request['source']['type'] === 'reservation') {
            $currentId = $request['source']['reservationId'];
            $current = $reservationRowsById[$currentId] ?? null;
            if (!$current || !$this->activeReservation($current)) {
                throw self::failure('current_reservation_not_active_contributor');
            }
            if ((int)$current['store_id'] !== $request['storeId']) {
                throw self::failure('current_reservation_store_mismatch');
            }
        }

        // Every source path must count all active service-order claims on the
        // same entitlement. Calling C3 only for a service_order source would
        // let direct or reservation checkout overspend an already occupied
        // entitlement.
        if (!$this->serviceOrderAuthority
            || $this->serviceOrderAuthority->contractVersion()
                !== CashierV3EntitlementProviderContracts::SERVICE_ORDER_OCCUPATION
            || empty($this->serviceOrderAuthority->readinessStatus()['ready'])) {
            throw self::failure('service_order_occupation_authority_not_ready');
        }
        $serviceContributors = $this->serviceOrderAuthority->lockContributorsInTx($request, $dataScope);
        foreach ($this->normalizeServiceContributors($serviceContributors, $request) as $contributor) {
            $contributors[] = $contributor;
        }

        usort($contributors, static function (array $left, array $right): int {
            $kind = strcmp($left['kind'], $right['kind']);
            return $kind !== 0 ? $kind : ($left['id'] <=> $right['id']);
        });
        $seen = [];
        $occupiedTimes = 0;
        $convertibleTimes = 0;
        foreach ($contributors as $contributor) {
            $key = $contributor['kind'] . ':' . $contributor['id'];
            if (isset($seen[$key])) {
                throw self::failure('occupation_contributor_duplicate', ['contributor' => $key]);
            }
            $seen[$key] = true;
            $occupiedTimes += $contributor['occupiedTimes'];
            $convertibleTimes += $contributor['convertibleTimes'];
        }
        if ($request['source']['type'] === 'direct' && $convertibleTimes !== 0) {
            throw self::failure('direct_source_cannot_convert_occupation');
        }
        if ($request['source']['type'] !== 'direct' && $convertibleTimes <= 0) {
            throw self::failure('current_source_not_occupation_contributor');
        }
        foreach ($contributors as $contributor) {
            if ($contributor['convertibleTimes'] <= 0) {
                continue;
            }
            $expectedKind = $request['source']['type'] === 'reservation'
                ? 'reservation'
                : 'service_order';
            $expectedId = $request['source']['type'] === 'reservation'
                ? $request['source']['reservationId']
                : $request['source']['serviceOrderId'];
            if ($contributor['kind'] !== $expectedKind || $contributor['id'] !== $expectedId) {
                throw self::failure('current_source_conversion_mismatch');
            }
        }

        return [
            'contractVersion' => $this->contractVersion(),
            'tenantId' => $request['tenantId'],
            'storeId' => $request['storeId'],
            'entitlementSourceDetailId' => $request['entitlementSourceDetailId'],
            'occupationAuthorityComplete' => true,
            'occupiedTimes' => $occupiedTimes,
            'currentSourceConvertibleTimes' => $convertibleTimes,
            'occupationContributors' => $contributors,
        ];
    }

    private function normalizeRequest(array $request): array
    {
        $keys = array_keys($request);
        sort($keys, SORT_STRING);
        if ($keys !== ['entitlementSourceDetailId', 'source', 'storeId', 'tenantId']) {
            throw self::failure('occupation_request_shape_invalid');
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
            throw self::failure('occupation_request_invalid');
        }
        $sourceKeys = array_keys($request['source']);
        sort($sourceKeys, SORT_STRING);
        if ($sourceKeys !== ['reservationId', 'serviceOrderId', 'type']) {
            throw self::failure('occupation_source_shape_invalid');
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
            || ($type === 'reservation' && $reservationId <= 0)
            || ($type === 'service_order' && ($serviceOrderId <= 0 || $reservationId !== 0))) {
            throw self::failure('occupation_source_invalid');
        }
        return [
            'tenantId' => $tenantId,
            'storeId' => $storeId,
            'entitlementSourceDetailId' => $detailId,
            'source' => compact('type', 'serviceOrderId', 'reservationId'),
        ];
    }

    private function normalizeServiceContributors(array $contributors, array $request): array
    {
        $normalized = [];
        foreach ($contributors as $index => $row) {
            $keys = is_array($row) ? array_keys($row) : [];
            sort($keys, SORT_STRING);
            if ($keys !== ['convertibleTimes', 'id', 'kind', 'occupiedTimes', 'version']) {
                throw self::failure('service_occupation_contributor_shape_invalid', ['index' => $index]);
            }
            if ($row['kind'] !== 'service_order'
                || !is_int($row['id'])
                || $row['id'] <= 0
                || !is_int($row['version'])
                || $row['version'] <= 0
                || !is_int($row['occupiedTimes'])
                || $row['occupiedTimes'] <= 0
                || !is_int($row['convertibleTimes'])
                || $row['convertibleTimes'] < 0
                || $row['convertibleTimes'] > $row['occupiedTimes']) {
                throw self::failure('service_occupation_contributor_invalid', ['index' => $index]);
            }
            if ($row['convertibleTimes'] > 0 && $row['id'] !== $request['source']['serviceOrderId']) {
                throw self::failure('service_occupation_current_source_mismatch');
            }
            $normalized[] = $row;
        }
        return $normalized;
    }

    private function activeReservation(array $row): bool
    {
        return in_array((int)($row['status'] ?? -999), [0, 1, 3], true)
            && (int)($row['is_del'] ?? 1) === 0
            && (int)($row['is_system_del'] ?? 1) === 0;
    }

    private function reservationFingerprint(array $row): string
    {
        return hash('sha256', json_encode([
            'id' => (int)$row['id'],
            'storeId' => (int)$row['store_id'],
            'cartInfoId' => (int)$row['cart_info_id'],
            'status' => (int)$row['status'],
            'isDel' => (int)$row['is_del'],
            'isSystemDel' => (int)$row['is_system_del'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function synchronizeVersion(
        string $tenantId,
        string $sourceKind,
        int $sourceId,
        int $storeId,
        int $detailId,
        string $fingerprint
    ): int {
        $row = Db::name(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('source_kind', $sourceKind)
            ->where('source_id', $sourceId)
            ->lock(true)
            ->find();
        $now = time();
        if (!$row) {
            Db::name(self::TABLE)->insert([
                'tenant_id' => $tenantId,
                'source_kind' => $sourceKind,
                'source_id' => $sourceId,
                'store_id_snapshot' => $storeId,
                'entitlement_source_detail_id_snapshot' => $detailId,
                'authority_fingerprint' => $fingerprint,
                'current_version' => 1,
                'last_action' => 'occupation_discovered',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            return 1;
        }
        $version = (int)($row['current_version'] ?? 0);
        if ($version <= 0 || $version >= PHP_INT_MAX) {
            throw self::failure('occupation_version_invalid');
        }
        if (!hash_equals((string)$row['authority_fingerprint'], $fingerprint)) {
            $affected = Db::name(self::TABLE)
                ->where('id', (int)$row['id'])
                ->where('current_version', $version)
                ->update([
                    'store_id_snapshot' => $storeId,
                    'entitlement_source_detail_id_snapshot' => $detailId,
                    'authority_fingerprint' => $fingerprint,
                    'current_version' => Db::raw('current_version + 1'),
                    'last_action' => 'authority_snapshot_changed',
                    'updated_at' => $now,
                ]);
            if ((int)$affected !== 1) {
                throw self::failure('occupation_version_conflict');
            }
            return $version + 1;
        }
        return $version;
    }

    private static function failure(string $reason, array $detail = []): CashierV3EntitlementProviderContractException
    {
        return new CashierV3EntitlementProviderContractException($reason, $detail);
    }
}
