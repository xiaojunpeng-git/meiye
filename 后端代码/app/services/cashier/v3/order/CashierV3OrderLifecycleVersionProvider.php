<?php

namespace app\services\cashier\v3\order;

use app\services\cashier\v3\CashierV3DataScopedVersionProvider;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResourceScope;
use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\cashier\v3\CashierV3TransactionGuard;
use think\facade\Db;

/** Version is the frozen source plus the append-only lifecycle operation count. */
final class CashierV3OrderLifecycleVersionProvider implements CashierV3DataScopedVersionProvider
{
    public const KIND = 'sales_order';

    public function resolveScopeWithDataScope(string $kind, string $id, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope)
    {
        if ($kind !== self::KIND || !$this->base($operator, $scope) || !$this->visible($id, $scope)) return null;
        return CashierV3ResourceScope::of(CashierV3ResourceScope::TYPE_STORE, (string)$scope->forcedStoreId());
    }

    public function lockAndReadVersionWithDataScope(CashierV3ResourceScope $resourceScope, string $kind, string $id, CashierV3DataScopeContext $scope)
    {
        CashierV3TransactionGuard::assertInTransaction('orderLifecycleVersion.lock');
        if ($kind !== self::KIND || $resourceScope->type() !== CashierV3ResourceScope::TYPE_STORE || (int)$resourceScope->id() !== $scope->forcedStoreId()) return null;
        if (!$this->visible($id, $scope, true)) return null;
        return $this->version($id, $scope);
    }

    public function bumpVersionWithDataScope(CashierV3ResourceScope $resourceScope, string $kind, string $id, string $action, CashierV3DataScopeContext $scope): int
    {
        CashierV3TransactionGuard::assertInTransaction('orderLifecycleVersion.bump');
        $version = $this->lockAndReadVersionWithDataScope($resourceScope, $kind, $id, $scope);
        if ($version === null) throw CashierV3ScopeResolver::notFound($kind, $id);
        // The command has inserted exactly one immutable operation before this
        // call, so the count-derived version already contains the new state.
        return $version;
    }

    private function base(CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): bool { return $operator->tenantId() !== '' && $operator->tenantId() === $scope->tenantId() && $operator->storeId() === $scope->forcedStoreId() && $scope->allowsStore($operator->storeId()); }
    private function visible(string $id, CashierV3DataScopeContext $scope, bool $lock = false): bool
    {
        $q = Db::name('cashier_v3_sales_order')->where('tenant_id', $scope->tenantId())->where('organization_id', $scope->organizationId())->where('store_id', $scope->forcedStoreId())->where('order_id', $id); if ($lock) $q->lock(true); return (bool)$q->find();
    }
    private function version(string $id, CashierV3DataScopeContext $scope): int { return 1 + (int)Db::name(CashierV3OrderLifecycleServices::OPERATION_TABLE)->where('tenant_id', $scope->tenantId())->where('source_type', 'sales')->where('source_order_id', $id)->count(); }
}
