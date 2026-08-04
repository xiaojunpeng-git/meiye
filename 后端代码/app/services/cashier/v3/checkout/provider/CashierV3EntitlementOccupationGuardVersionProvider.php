<?php

namespace app\services\cashier\v3\checkout\provider;

use app\services\cashier\v3\CashierV3DataScopedVersionProvider;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResourceScope;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\service\ThinkPhpCashierV3ServiceOrderRepository;
use think\facade\Db;

/** Gateway version boundary for the C3 entitlement occupation guard. */
final class CashierV3EntitlementOccupationGuardVersionProvider implements CashierV3DataScopedVersionProvider
{
    public const CONTRACT_VERSION = 'cashier-v3-entitlement-occupation-guard-provider-v1';
    public const KIND = 'entitlement_occupation_guard';

    /** @var ThinkPhpCashierV3ServiceOrderRepository */
    private $repository;

    public function __construct(?ThinkPhpCashierV3ServiceOrderRepository $repository = null)
    {
        $this->repository = $repository ?: new ThinkPhpCashierV3ServiceOrderRepository();
    }

    public function contractVersion(): string
    {
        return self::CONTRACT_VERSION;
    }

    /** Read-only discovery. Missing rows have the deterministic create version 1. */
    public function discoverVersion(
        int $entitlementSourceDetailId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): int {
        $this->assertScope($operatorScope, $dataScope);
        $this->assertActiveDetail($entitlementSourceDetailId, false);
        $row = $this->row(Db::name(ThinkPhpCashierV3ServiceOrderRepository::GUARD_TABLE)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('entitlement_source_detail_id', $entitlementSourceDetailId)
            ->field('current_version')
            ->find());
        if (!$row) {
            return 1;
        }
        return $this->positiveVersion($row['current_version'] ?? null);
    }

    public function resolveScopeWithDataScope(
        string $kind,
        string $resourceId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ) {
        try {
            $this->assertKind($kind);
            $this->assertScope($operatorScope, $dataScope);
            $this->assertActiveDetail($this->positiveId($resourceId), false);
            return CashierV3ResourceScope::of(
                CashierV3ResourceScope::TYPE_TENANT,
                $dataScope->tenantId()
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
        CashierV3TransactionGuard::assertInTransaction('entitlementOccupationGuardLock');
        $this->assertKind($kind);
        if ($scope->type() !== CashierV3ResourceScope::TYPE_TENANT
            || !hash_equals($scope->id(), $dataScope->tenantId())) {
            return null;
        }
        $detailId = $this->positiveId($resourceId);
        $this->assertDataScope($dataScope);
        $this->assertActiveDetail($detailId, false);
        $row = $this->repository->lockOrCreateEntitlementGuard(
            $dataScope->tenantId(),
            $detailId
        );
        return $this->positiveVersion($row['current_version'] ?? null);
    }

    public function bumpVersionWithDataScope(
        CashierV3ResourceScope $scope,
        string $kind,
        string $resourceId,
        string $action,
        CashierV3DataScopeContext $dataScope
    ): int {
        CashierV3TransactionGuard::assertInTransaction('entitlementOccupationGuardBump');
        throw self::failure('occupation_guard_advance_owned_by_service_order_writer', [
            'kind' => $kind,
            'resourceId' => $resourceId,
            'action' => $action,
        ]);
    }

    private function assertScope(
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): void {
        CashierV3EntitlementProviderDataScope::assertBase($operatorScope, $dataScope);
        CashierV3EntitlementProviderDataScope::assertTenant($operatorScope->tenantId(), $dataScope);
        CashierV3EntitlementProviderDataScope::assertStore($operatorScope->storeId(), $dataScope);
    }

    private function assertDataScope(CashierV3DataScopeContext $dataScope): void
    {
        CashierV3EntitlementProviderDataScope::assertTenant($dataScope->tenantId(), $dataScope);
        CashierV3EntitlementProviderDataScope::assertStore($dataScope->forcedStoreId(), $dataScope);
    }

    private function assertActiveDetail(int $detailId, bool $lock): void
    {
        $query = Db::name('store_order_cart_info')
            ->where('id', $detailId)
            ->where('cart_type', 2)
            ->where('product_type', 6)
            ->where('is_writeoff', 0)
            ->where('write_surplus_times', '>', 0)
            ->field('id');
        if ($lock) {
            $query->lock(true);
        }
        if (!$query->find()) {
            throw self::failure('occupation_guard_entitlement_detail_not_active');
        }
    }

    private function assertKind(string $kind): void
    {
        if ($kind !== self::KIND) {
            throw self::failure('occupation_guard_kind_invalid', ['kind' => $kind]);
        }
    }

    private function positiveId(string $value): int
    {
        if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1 || (string)(int)$value !== $value) {
            throw self::failure('occupation_guard_identity_invalid');
        }
        return (int)$value;
    }

    private function positiveVersion($value): int
    {
        $raw = is_int($value) ? (string)$value : trim((string)$value);
        if (preg_match('/^[1-9][0-9]*$/D', $raw) !== 1 || (string)(int)$raw !== $raw) {
            throw self::failure('occupation_guard_version_invalid');
        }
        return (int)$raw;
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
