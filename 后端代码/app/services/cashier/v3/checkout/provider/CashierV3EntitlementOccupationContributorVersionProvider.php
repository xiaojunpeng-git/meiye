<?php

namespace app\services\cashier\v3\checkout\provider;

use app\services\cashier\v3\CashierV3DataScopedVersionProvider;
use app\services\cashier\v3\CashierV3CrossStoreEntitlementPolicy;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResourceScope;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\service\CashierV3ServiceOrderState;
use app\services\cashier\v3\service\ThinkPhpCashierV3ServiceOrderRepository;
use think\facade\Db;

/**
 * Read-only version provider for active reservation/service-order authorities.
 *
 * Reservation is a global resource kind: an unpurchased-project reservation has
 * cart_info_id=0 and is still a valid authority. Only positive detail IDs carry
 * entitlement-occupation semantics. Generic bumps stay fail-closed until the
 * reservation/service-order source writers own their version advancement.
 */
final class CashierV3EntitlementOccupationContributorVersionProvider implements CashierV3DataScopedVersionProvider
{
    public const CONTRACT_VERSION = 'cashier-v3-entitlement-occupation-contributor-provider-v1';
    public const KINDS = ['service_order', 'reservation'];

    public function contractVersion(): string
    {
        return self::CONTRACT_VERSION;
    }

    public function discoverVersion(
        string $kind,
        int $resourceId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): int {
        $this->assertScope($operatorScope, $dataScope);
        $authority = $this->authority($kind, $resourceId, $dataScope, false);
        return (int)$authority['version'];
    }

    public function resolveScopeWithDataScope(
        string $kind,
        string $resourceId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ) {
        try {
            $this->assertScope($operatorScope, $dataScope);
            $authority = $this->authority($kind, $this->positiveId($resourceId), $dataScope, false);
            if ((int)$authority['storeId'] !== $dataScope->forcedStoreId()) {
                return null;
            }
            return CashierV3ResourceScope::of(
                CashierV3ResourceScope::TYPE_STORE,
                (string)$authority['storeId']
            );
        } catch (CashierV3EntitlementProviderContractException $exception) {
            return null;
        }
    }

    public function lockAndReadVersionWithDataScope(
        CashierV3ResourceScope $scope,
        string $kind,
        string $resourceId,
        CashierV3DataScopeContext $dataScope
    ) {
        CashierV3TransactionGuard::assertInTransaction('entitlementOccupationContributorLock:' . $kind);
        $authority = $this->authority($kind, $this->positiveId($resourceId), $dataScope, true);
        if ($scope->type() !== CashierV3ResourceScope::TYPE_STORE
            || $scope->id() !== (string)$authority['storeId']) {
            return null;
        }
        return (int)$authority['version'];
    }

    public function bumpVersionWithDataScope(
        CashierV3ResourceScope $scope,
        string $kind,
        string $resourceId,
        string $action,
        CashierV3DataScopeContext $dataScope
    ): int {
        CashierV3TransactionGuard::assertInTransaction('entitlementOccupationContributorBump:' . $kind);
        $id = $this->positiveId($resourceId);
        if ($kind !== 'reservation') {
            throw self::failure('occupation_contributor_advance_owned_by_source_writer', [
                'kind' => $kind,
                'resourceId' => $resourceId,
                'action' => $action,
            ]);
        }
        // The reservation command owns its header update and advances the
        // authoritative version with an SQL compare-and-set in that same
        // transaction.  The gateway calls us afterwards to validate the
        // resulting version, not to apply a second increment.
        $row = $this->row(Db::name('cashier_v3_reservation')
            ->where('tenant_id', $dataScope->tenantId())
            ->where('id', $id)
            ->lock(true)
            ->find());
        $storeId = (int)($row['store_id'] ?? 0);
        if (!$row || $storeId <= 0 || $storeId !== $dataScope->forcedStoreId()
            || $scope->type() !== CashierV3ResourceScope::TYPE_STORE
            || $scope->id() !== (string)$storeId) {
            return 0;
        }
        $current = (int)($row['version'] ?? 0);
        if ($current <= 0) {
            throw self::failure('reservation_authority_version_invalid');
        }
        return $current;
    }

    private function authority(
        string $kind,
        int $resourceId,
        CashierV3DataScopeContext $dataScope,
        bool $lock
    ): array {
        $this->assertKind($kind);
        CashierV3EntitlementProviderDataScope::assertTenant($dataScope->tenantId(), $dataScope);
        if ($kind === 'service_order') {
            $query = Db::name(ThinkPhpCashierV3ServiceOrderRepository::ORDER_TABLE)
                ->where('tenant_id', $dataScope->tenantId())
                ->where('id', $resourceId);
            if ($lock) {
                $query->lock(true);
            }
            $row = $this->row($query->find());
            $storeId = (int)($row['business_store_id'] ?? 0);
            $version = (int)($row['version'] ?? 0);
            if (!$row
                || !CashierV3ServiceOrderState::isActiveOrder((string)($row['status'] ?? ''))
                || $storeId <= 0
                || $version <= 0) {
                throw self::failure('service_order_occupation_contributor_not_active');
            }
            $this->assertContributorStore($storeId, $dataScope, $kind, $resourceId);
            return ['storeId' => $storeId, 'version' => $version];
        }

        // C3 新预约使用独立编号区间，并以 V3 表作为权威源。旧预约
        // 只保留既有权益占用流程的读取能力；新预约绝不回写或解释旧表。
        $v3Query = Db::name('cashier_v3_reservation')
            ->where('tenant_id', $dataScope->tenantId())
            ->where('id', $resourceId);
        if ($lock) {
            $v3Query->lock(true);
        }
        $v3 = $this->row($v3Query->find());
        if ($v3) {
            $storeId = (int)($v3['store_id'] ?? 0);
            $version = (int)($v3['version'] ?? 0);
            if ($storeId <= 0 || $version <= 0
                || in_array((string)($v3['status'] ?? ''), ['CANCELLED', 'NO_SHOW'], true)) {
                throw self::failure('v3_reservation_occupation_contributor_not_active');
            }
            $this->assertContributorStore($storeId, $dataScope, $kind, $resourceId);
            return ['storeId' => $storeId, 'version' => $version];
        }

        $query = Db::name('store_reservation_order')->where('id', $resourceId);
        if ($lock) {
            $query->lock(true);
        }
        $row = $this->row($query->find());
        $storeId = (int)($row['store_id'] ?? 0);
        $detailId = (int)($row['cart_info_id'] ?? -1);
        if (!$row
            || !in_array((int)($row['status'] ?? -999), [0, 1, 3], true)
            || (int)($row['is_del'] ?? 1) !== 0
            || (int)($row['is_system_del'] ?? 1) !== 0
            || $detailId < 0
            || $storeId <= 0) {
            throw self::failure('reservation_occupation_contributor_not_active');
        }
        $this->assertContributorStore($storeId, $dataScope, $kind, $resourceId);
        $fingerprint = hash('sha256', json_encode([
            'id' => $resourceId,
            'storeId' => $storeId,
            'cartInfoId' => $detailId,
            'status' => (int)$row['status'],
            'isDel' => (int)$row['is_del'],
            'isSystemDel' => (int)$row['is_system_del'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return [
            'storeId' => $storeId,
            'version' => $this->reservationVersion(
                $dataScope->tenantId(),
                $resourceId,
                $storeId,
                $detailId,
                $fingerprint,
                $lock
            ),
        ];
    }

    private function reservationVersion(
        string $tenantId,
        int $reservationId,
        int $storeId,
        int $detailId,
        string $fingerprint,
        bool $lock
    ): int {
        $query = Db::name(CashierV3EntitlementOccupationProvider::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('source_kind', 'reservation')
            ->where('source_id', $reservationId);
        if ($lock) {
            $query->lock(true);
        }
        $row = $this->row($query->find());
        if (!$row) {
            if (!$lock) {
                return 1;
            }
            try {
                Db::name(CashierV3EntitlementOccupationProvider::TABLE)->insert([
                    'tenant_id' => $tenantId,
                    'source_kind' => 'reservation',
                    'source_id' => $reservationId,
                    'store_id_snapshot' => $storeId,
                    'entitlement_source_detail_id_snapshot' => $detailId,
                    'authority_fingerprint' => $fingerprint,
                    'current_version' => 1,
                    'last_action' => $detailId > 0
                        ? 'occupation_discovered'
                        : 'reservation_authority_discovered',
                    'created_at' => time(),
                    'updated_at' => time(),
                ]);
                return 1;
            } catch (\Throwable $exception) {
                $row = $this->row(Db::name(CashierV3EntitlementOccupationProvider::TABLE)
                    ->where('tenant_id', $tenantId)
                    ->where('source_kind', 'reservation')
                    ->where('source_id', $reservationId)
                    ->lock(true)
                    ->find());
                if (!$row) {
                    throw $exception;
                }
            }
        }
        $version = (int)($row['current_version'] ?? 0);
        if ($version <= 0) {
            throw self::failure('reservation_occupation_version_invalid');
        }
        if (!hash_equals((string)($row['authority_fingerprint'] ?? ''), $fingerprint)) {
            if (!$lock) {
                // Predict the CAS result without writing. Gateway later locks
                // the row, performs this exact increment and verifies N+1.
                if ($version >= PHP_INT_MAX) {
                    throw self::failure('reservation_occupation_version_invalid');
                }
                return $version + 1;
            }
            $affected = Db::name(CashierV3EntitlementOccupationProvider::TABLE)
                ->where('id', (int)$row['id'])
                ->where('current_version', $version)
                ->update([
                    'store_id_snapshot' => $storeId,
                    'entitlement_source_detail_id_snapshot' => $detailId,
                    'authority_fingerprint' => $fingerprint,
                    'current_version' => Db::raw('current_version + 1'),
                    'last_action' => 'authority_snapshot_changed',
                    'updated_at' => time(),
                ]);
            if ((int)$affected !== 1) {
                throw self::failure('reservation_occupation_version_conflict');
            }
            return $version + 1;
        }
        return $version;
    }

    private function assertScope(
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): void {
        CashierV3EntitlementProviderDataScope::assertBase($operatorScope, $dataScope);
        CashierV3EntitlementProviderDataScope::assertTenant($operatorScope->tenantId(), $dataScope);
        CashierV3EntitlementProviderDataScope::assertStore($operatorScope->storeId(), $dataScope);
    }

    private function assertContributorStore(
        int $storeId,
        CashierV3DataScopeContext $dataScope,
        string $kind,
        int $resourceId
    ): void {
        if ($storeId === $dataScope->forcedStoreId()) {
            CashierV3EntitlementProviderDataScope::assertStore($storeId, $dataScope);
            return;
        }
        if (!CashierV3CrossStoreEntitlementPolicy::enabled()
            || $dataScope->authorizationMode() === CashierV3DataScopeContext::MODE_NONE
            || $dataScope->authorizationMode() === CashierV3DataScopeContext::MODE_SELF_PARTICIPANT) {
            throw self::failure('cross_store_occupation_contributor_fail_closed', [
                'kind' => $kind,
                'resourceId' => $resourceId,
                'contributorStoreId' => $storeId,
                'forcedStoreId' => $dataScope->forcedStoreId(),
            ]);
        }
    }

    private function assertKind(string $kind): void
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw self::failure('occupation_contributor_kind_invalid', ['kind' => $kind]);
        }
    }

    private function positiveId(string $value): int
    {
        if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1 || (string)(int)$value !== $value) {
            throw self::failure('occupation_contributor_identity_invalid');
        }
        return (int)$value;
    }

    private function row($value): array
    {
        if (is_object($value) && method_exists($value, 'toArray')) {
            $value = $value->toArray();
        }
        return is_array($value) && $value ? $value : [];
    }

    private static function failure(
        string $reason,
        array $detail = []
    ): CashierV3EntitlementProviderContractException {
        return new CashierV3EntitlementProviderContractException($reason, $detail);
    }
}
