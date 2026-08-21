<?php
namespace app\services\cashier\v3\order;

use app\services\cashier\v3\CashierV3DataScopedVersionProvider;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResourceScope;
use app\services\cashier\v3\CashierV3TransactionGuard;
use think\facade\Db;

/** Version is the immutable repayment plus successful adjustment count. */
final class CashierV3SupplementSalespersonAdjustmentVersionProvider implements CashierV3DataScopedVersionProvider
{
    public const KIND = 'debt_repayment';
    public const CONTRACT_VERSION = 'cashier-v3-debt-repayment-resource-v1';

    public function resolveScopeWithDataScope(string $kind, string $id, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope)
    {
        if ($kind !== self::KIND || !$this->visible($id, $operator, $scope)) return null;
        return CashierV3ResourceScope::of(CashierV3ResourceScope::TYPE_STORE, (string)$operator->storeId());
    }
    public function lockAndReadVersionWithDataScope(CashierV3ResourceScope $resourceScope, string $kind, string $id, CashierV3DataScopeContext $scope)
    {
        CashierV3TransactionGuard::assertInTransaction('supplementSalespersonVersion.lock');
        if ($kind !== self::KIND || $resourceScope->type() !== CashierV3ResourceScope::TYPE_STORE || (int)$resourceScope->id() !== (int)$scope->forcedStoreId() || !$this->visibleInScope($id, $scope)) return null;
        return $this->version($id, $scope);
    }
    public function bumpVersionWithDataScope(CashierV3ResourceScope $resourceScope, string $kind, string $id, string $action, CashierV3DataScopeContext $scope): int
    {
        CashierV3TransactionGuard::assertInTransaction('supplementSalespersonVersion.bump');
        return $this->lockAndReadVersionWithDataScope($resourceScope, $kind, $id, $scope) ?? 0;
    }
    private function visible(string $id, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): bool
    {
        foreach (['cashier_v3_debt_repayment', 'cashier_v3_recharge_debt_repayment'] as $table) {
            if (Db::name($table)->where('tenant_id', $scope->tenantId())->where('store_id', $operator->storeId())->where('repayment_id', $id)->whereIn('status', ['succeeded', 'voided'])->find()) return true;
        }
        return false;
    }
    private function version(string $id, CashierV3DataScopeContext $scope): int
    {
        $version = 1 + (int)Db::name(CashierV3OrderLifecycleServices::OPERATION_TABLE)->where('tenant_id', $scope->tenantId())->where('source_type', 'debt_repayment')->where('source_order_id', $id)->where('operation_type', 'personnel_adjustment')->count();
        // Cancellation is a terminal mutation. Keep one additional version
        // for the terminal state so the command gateway can advance the
        // resource after the handler changes the authority status to voided.
        foreach (['cashier_v3_debt_repayment', 'cashier_v3_recharge_debt_repayment'] as $table) {
            $status = Db::name($table)->where('tenant_id', $scope->tenantId())->where('repayment_id', $id)->value('status');
            if ((string)$status === 'voided') return $version + 1;
        }
        return $version;
    }

    private function visibleInScope(string $id, CashierV3DataScopeContext $scope): bool
    {
        foreach (['cashier_v3_debt_repayment', 'cashier_v3_recharge_debt_repayment'] as $table) {
            if (Db::name($table)->where('tenant_id', $scope->tenantId())->where('store_id', $scope->forcedStoreId())->where('repayment_id', $id)->whereIn('status', ['succeeded', 'voided'])->lock(true)->find()) return true;
        }
        return false;
    }
}
