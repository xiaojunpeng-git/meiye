<?php

declare(strict_types=1);

namespace app\services\cashier\v3\order;

use app\services\cashier\v3\CashierV3DataScopedVersionProvider;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResourceScope;
use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\cashier\v3\CashierV3TransactionGuard;
use think\facade\Db;

/**
 * Version for a completed, non-voided service record.
 *
 * The service fact itself is immutable. Each successful craftsman adjustment
 * appends one audit operation, so that operation count is the authoritative
 * optimistic-lock version used by both the command gateway and the domain
 * service.
 */
final class CashierV3ServiceRecordCraftsmanAdjustmentVersionProvider implements CashierV3DataScopedVersionProvider
{
    public const KIND = 'service_record';

    public function resolveScopeWithDataScope(string $kind, string $id, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope)
    {
        if ($kind !== self::KIND || !$this->base($operator, $scope) || !$this->visible($id, $scope)) {
            return null;
        }
        return CashierV3ResourceScope::of(CashierV3ResourceScope::TYPE_STORE, (string)$scope->forcedStoreId());
    }

    public function lockAndReadVersionWithDataScope(CashierV3ResourceScope $resourceScope, string $kind, string $id, CashierV3DataScopeContext $scope)
    {
        CashierV3TransactionGuard::assertInTransaction('serviceRecordCraftsmanAdjustmentVersion.lock');
        if ($kind !== self::KIND || $resourceScope->type() !== CashierV3ResourceScope::TYPE_STORE
            || (int)$resourceScope->id() !== $scope->forcedStoreId() || !$this->visible($id, $scope, true)) {
            return null;
        }
        return $this->version($id, $scope);
    }

    public function bumpVersionWithDataScope(CashierV3ResourceScope $resourceScope, string $kind, string $id, string $action, CashierV3DataScopeContext $scope): int
    {
        CashierV3TransactionGuard::assertInTransaction('serviceRecordCraftsmanAdjustmentVersion.bump');
        $version = $this->lockAndReadVersionWithDataScope($resourceScope, $kind, $id, $scope);
        if ($version === null) {
            throw CashierV3ScopeResolver::notFound($kind, $id);
        }
        // The append-only adjustment operation has already been inserted by
        // the command transaction, so this count-derived version includes it.
        return $version;
    }

    private function base(CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): bool
    {
        return $operator->tenantId() !== '' && $operator->tenantId() === $scope->tenantId()
            && $operator->storeId() === $scope->forcedStoreId() && $scope->allowsStore($operator->storeId());
    }

    private function visible(string $id, CashierV3DataScopeContext $scope, bool $lock = false): bool
    {
        if (preg_match('/^[1-9][0-9]*$/D', $id) !== 1) {
            return false;
        }
        $query = Db::name('cashier_v3_entitlement_service_fact')->alias('sf')
            ->leftJoin(
                'cashier_v3_service_record_void_operation vo',
                "vo.tenant_id=sf.tenant_id AND vo.service_fact_id=sf.id AND vo.status='succeeded'"
            )
            ->where('sf.id', (int)$id)->where('sf.tenant_id', $scope->tenantId())
            ->where('sf.store_id', $scope->forcedStoreId())->where('sf.service_status', 'completed')
            ->whereNull('vo.id');
        if ($lock) {
            $query->lock(true);
        }
        return (bool)$query->find();
    }

    private function version(string $id, CashierV3DataScopeContext $scope): int
    {
        return 1 + (int)Db::name(CashierV3ServiceRecordCraftsmanAdjustmentServices::OPERATION_TABLE)
            ->where('tenant_id', $scope->tenantId())->where('service_fact_id', (int)$id)
            ->where('status', 'succeeded')->count();
    }
}
