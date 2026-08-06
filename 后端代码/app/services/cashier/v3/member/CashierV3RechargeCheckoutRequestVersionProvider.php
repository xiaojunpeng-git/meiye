<?php
declare(strict_types=1);

namespace app\services\cashier\v3\member;

use app\services\cashier\v3\CashierV3DataScopedVersionProvider;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResourceScope;
use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\cashier\v3\CashierV3TransactionGuard;
use think\facade\Db;

/** Version source for the eventless recharge checkout aggregate. */
final class CashierV3RechargeCheckoutRequestVersionProvider implements CashierV3DataScopedVersionProvider
{
    public const KIND = 'recharge_checkout_request';
    private const TABLE = 'cashier_v3_recharge_checkout_request';

    public function resolveScopeWithDataScope(string $kind, string $id, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope)
    {
        if ($kind !== self::KIND || !$this->validId($id) || !$this->validScope($operator, $scope)) return null;
        $row = Db::name(self::TABLE)->where('request_id', $id)->field('tenant_id,store_id,request_version')->find();
        if (!$row || (string)$row['tenant_id'] !== $scope->tenantId() || (int)$row['store_id'] !== $operator->storeId()) return null;
        if ((int)$row['request_version'] <= 0) throw CashierV3ScopeResolver::notFound($kind, $id);
        return CashierV3ResourceScope::of(CashierV3ResourceScope::TYPE_STORE, (string)$operator->storeId());
    }

    public function lockAndReadVersionWithDataScope(CashierV3ResourceScope $resourceScope, string $kind, string $id, CashierV3DataScopeContext $scope)
    {
        CashierV3TransactionGuard::assertInTransaction('rechargeCheckoutRequestVersionLock');
        if ($kind !== self::KIND || !$this->validId($id) || $resourceScope->type() !== CashierV3ResourceScope::TYPE_STORE || (int)$resourceScope->id() !== $scope->forcedStoreId()) return null;
        $row = Db::name(self::TABLE)->where('request_id', $id)->where('tenant_id', $scope->tenantId())->where('store_id', $scope->forcedStoreId())->lock(true)->find();
        if (!$row || (int)$row['request_version'] <= 0) return null;
        return (int)$row['request_version'];
    }

    public function bumpVersionWithDataScope(CashierV3ResourceScope $resourceScope, string $kind, string $id, string $action, CashierV3DataScopeContext $scope): int
    {
        // The domain CAS already advanced this version in its own write. Gateway observes it only.
        $current = $this->lockAndReadVersionWithDataScope($resourceScope, $kind, $id, $scope);
        if ($current === null) throw CashierV3ScopeResolver::notFound($kind, $id);
        return $current;
    }

    private function validId(string $id): bool { return preg_match('/^RCR-[0-9a-f]{40}$/D', $id) === 1; }
    private function validScope(CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): bool
    {
        return $operator->storeId() === $scope->forcedStoreId() && $operator->operatorId() === $scope->operatorId()
            && $operator->tenantId() !== '' && $operator->tenantId() === $scope->tenantId() && $scope->allowsStore($operator->storeId());
    }
}
