<?php

namespace app\services\cashier\v3\card;

use app\services\cashier\v3\CashierV3DataScopedVersionProvider;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResourceScope;
use app\services\cashier\v3\CashierV3TransactionGuard;
use think\facade\Db;

/** Transactional version source for an already-created custom-card configuration. */
final class CashierV3CustomCardConfigurationVersionProvider implements CashierV3DataScopedVersionProvider
{
    public const KIND = 'custom_card_configuration';
    public const TABLE = 'cashier_v3_custom_card_configuration';

    public function resolveScopeWithDataScope(
        string $kind,
        string $resourceId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ) {
        if (!$this->supported($kind, $resourceId) || !$this->allows($operatorScope, $dataScope)) {
            return null;
        }
        $row = Db::name(self::TABLE)
            ->where('configuration_id', $resourceId)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('store_id', $operatorScope->storeId())
            ->field('configuration_id')
            ->find();
        return $row ? CashierV3ResourceScope::of(CashierV3ResourceScope::TYPE_STORE, (string)$operatorScope->storeId()) : null;
    }

    public function lockAndReadVersionWithDataScope(
        CashierV3ResourceScope $scope,
        string $kind,
        string $resourceId,
        CashierV3DataScopeContext $dataScope
    ) {
        CashierV3TransactionGuard::assertInTransaction('customCardConfigurationVersion');
        if ($scope->type() !== CashierV3ResourceScope::TYPE_STORE || !$this->supported($kind, $resourceId)) {
            return null;
        }
        $storeId = (int)$scope->id();
        if ($storeId <= 0 || $dataScope->forcedStoreId() !== $storeId
            || (!$dataScope->allowsStore($storeId) && !$dataScope->isSuperAdmin())) {
            return null;
        }
        $row = Db::name(self::TABLE)
            ->where('configuration_id', $resourceId)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('store_id', $storeId)
            ->where('status', 'in_cart')
            ->lock(true)
            ->field('resource_version')
            ->find();
        $version = (int)($row['resource_version'] ?? 0);
        return $version > 0 ? $version : null;
    }

    public function bumpVersionWithDataScope(
        CashierV3ResourceScope $scope,
        string $kind,
        string $resourceId,
        string $action,
        CashierV3DataScopeContext $dataScope
    ): int {
        CashierV3TransactionGuard::assertInTransaction('customCardConfigurationVersionBump');
        $current = $this->lockAndReadVersionWithDataScope($scope, $kind, $resourceId, $dataScope);
        if ($current === null) {
            return 0;
        }
        $updated = Db::name(self::TABLE)
            ->where('configuration_id', $resourceId)
            ->where('resource_version', $current)
            ->update(['resource_version' => $current + 1, 'update_time' => time()]);
        return (int)$updated === 1 ? $current + 1 : 0;
    }

    private function supported(string $kind, string $resourceId): bool
    {
        return $kind === self::KIND && preg_match('/^CCD-[A-F0-9]{40}$/D', $resourceId) === 1;
    }

    private function allows(CashierV3OperatorScope $scope, CashierV3DataScopeContext $dataScope): bool
    {
        return $scope->storeId() > 0 && $dataScope->forcedStoreId() === $scope->storeId()
            && ($dataScope->allowsStore($scope->storeId()) || $dataScope->isSuperAdmin());
    }
}
