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

final class CashierV3RechargeOrderLifecycleVersionProvider implements CashierV3DataScopedVersionProvider
{
    public const KIND = 'recharge_order';
    public const CONTRACT_VERSION = 'cashier-v3-recharge-order-lifecycle-v1';

    public function resolveScopeWithDataScope(string $kind, string $id, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope)
    {
        if ($kind !== self::KIND || !$this->base($operator, $scope) || !$this->visible($id, $scope)) return null;
        return CashierV3ResourceScope::of(CashierV3ResourceScope::TYPE_STORE, (string)$scope->forcedStoreId());
    }

    public function lockAndReadVersionWithDataScope(CashierV3ResourceScope $resourceScope, string $kind, string $id, CashierV3DataScopeContext $scope)
    {
        CashierV3TransactionGuard::assertInTransaction('rechargeOrderLifecycleVersion.lock');
        if ($kind !== self::KIND || $resourceScope->type() !== CashierV3ResourceScope::TYPE_STORE
            || (int)$resourceScope->id() !== $scope->forcedStoreId() || !$this->visible($id, $scope, true)) return null;
        return $this->version($id, $scope);
    }

    public function bumpVersionWithDataScope(CashierV3ResourceScope $resourceScope, string $kind, string $id, string $action, CashierV3DataScopeContext $scope): int
    {
        CashierV3TransactionGuard::assertInTransaction('rechargeOrderLifecycleVersion.bump');
        $version = $this->lockAndReadVersionWithDataScope($resourceScope, $kind, $id, $scope);
        if ($version === null) throw CashierV3ScopeResolver::notFound($kind, $id);
        return $version;
    }

    private function base(CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): bool
    {
        return $operator->tenantId() !== '' && $operator->tenantId() === $scope->tenantId()
            && $operator->storeId() === $scope->forcedStoreId() && $scope->allowsStore($operator->storeId());
    }

    private function visible(string $id, CashierV3DataScopeContext $scope, bool $lock = false): bool
    {
        if (preg_match('/^[1-9][0-9]*$/D', $id) !== 1) return false;
        $query = Db::name('user_recharge')->where('id', (int)$id)->where('store_id', $scope->forcedStoreId())->where('paid', 1);
        if ($lock) $query->lock(true);
        if (!$query->find()) return false;
        return Db::name('cashier_v3_balance_fact')->where('tenant_id', $scope->tenantId())
            ->where('order_id', 'RCH:' . (int)$id)->where('source_document_type', 'recharge')
            ->where('fact_direction', 'forward')->where('status', 'effective')->count() > 0;
    }

    private function version(string $id, CashierV3DataScopeContext $scope): int
    {
        return 1 + (int)Db::name(CashierV3OrderLifecycleServices::OPERATION_TABLE)
            ->where('tenant_id', $scope->tenantId())->where('source_type', 'recharge')
            ->where('source_order_id', $id)->count();
    }
}
