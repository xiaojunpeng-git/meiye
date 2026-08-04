<?php

namespace app\services\cashier\v3\cashier;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopedVersionProvider;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResourceScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use think\facade\Db;

/** Exact product/SKU/card-definition version provider for Gateway locking. */
final class CashierV3SaleCatalogResourceVersionProvider implements CashierV3DataScopedVersionProvider
{
    public const CONTRACT_VERSION = 'cashier-sale-catalog-resource-v1';
    public const KINDS = ['catalog_card_definition', 'catalog_product', 'catalog_sku'];

    /** @var CashierV3CashierReadinessGuard */
    private $readiness;

    /** @var CashierV3SaleCatalogAuthority */
    private $authority;

    /** @var CashierV3SaleCatalogServices */
    private $catalog;

    public function __construct(
        CashierV3CashierReadinessGuard $readiness,
        CashierV3SaleCatalogAuthority $authority = null
    ) {
        $this->readiness = $readiness;
        $this->authority = $authority ?: new ThinkPhpCashierV3SaleCatalogAuthority();
        $this->catalog = new CashierV3SaleCatalogServices($this->authority, $readiness);
    }

    public function resolveScopeWithDataScope(
        string $kind,
        string $resourceId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ) {
        if (!$this->supported($kind, $resourceId)
            || !$this->allowsStore($operatorScope->storeId(), $dataScope)
            || $this->representativeSkuId($operatorScope->storeId(), $kind, (int)$resourceId) <= 0) {
            return null;
        }
        return CashierV3ResourceScope::of(
            CashierV3ResourceScope::TYPE_STORE,
            (string)$operatorScope->storeId()
        );
    }

    public function lockAndReadVersionWithDataScope(
        CashierV3ResourceScope $scope,
        string $kind,
        string $resourceId,
        CashierV3DataScopeContext $dataScope
    ) {
        CashierV3TransactionGuard::assertInTransaction('saleCatalogVersion:' . $kind);
        $storeId = (int)$scope->id();
        if ($scope->type() !== CashierV3ResourceScope::TYPE_STORE
            || !$this->supported($kind, $resourceId)
            || !$this->allowsStore($storeId, $dataScope)) {
            return null;
        }
        $this->readiness->assertSaleCatalogReady();
        $resourceIdInt = (int)$resourceId;
        $skuId = $this->representativeSkuId($storeId, $kind, $resourceIdInt);
        if ($skuId <= 0 || !$this->authority->lockStoreResourceRow($storeId, $kind, $resourceIdInt)) {
            return null;
        }
        $operatorScope = new CashierV3OperatorScope(
            $storeId,
            $dataScope->operatorId(),
            $dataScope->organizationId(),
            $dataScope->tenantId()
        );
        return $this->catalog->readResourceVersion(
            $kind,
            $resourceIdInt,
            $skuId,
            $operatorScope,
            $dataScope
        );
    }

    public function bumpVersionWithDataScope(
        CashierV3ResourceScope $scope,
        string $kind,
        string $resourceId,
        string $action,
        CashierV3DataScopeContext $dataScope
    ): int {
        CashierV3TransactionGuard::assertInTransaction('saleCatalogVersionBump:' . $kind);
        throw new CashierV3CommandException(
            CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
            '商品权威只能由产品资料写入口修改。',
            CashierV3ResultCode::STATUS_FAILED,
            ['kind' => $kind, 'action' => $action, 'reason' => 'sale_catalog_provider_read_only']
        );
    }

    private function representativeSkuId(int $storeId, string $kind, int $resourceId): int
    {
        if ($storeId <= 0 || $resourceId <= 0) {
            return 0;
        }
        if ($kind === 'catalog_sku') {
            $row = Db::name('store_product_attr_value')->alias('sku')
                ->join('store_product p', 'p.id=sku.product_id')
                ->where('sku.id', $resourceId)
                ->where('sku.type', 0)
                ->where('p.type', 1)
                ->where('p.relation_id', $storeId)
                ->field('sku.id')
                ->find();
            return (int)($row['id'] ?? 0);
        }
        $product = Db::name('store_product')
            ->where('id', $resourceId)
            ->where('type', 1)
            ->where('relation_id', $storeId)
            ->field('id,product_type,pid,is_del,is_verify')
            ->find();
        // The old custom-card host is a hidden project replica (pid=8154), not
        // a product_type=5 card. It remains an exact version anchor only when
        // it is a live, verified store-owned host; ordinary definitions remain
        // strictly product_type=5.
        $isCustomCardHost = (int)($product['pid'] ?? 0) === 8154;
        if (!$product
            || (int)($product['is_del'] ?? 1) !== 0
            || (int)($product['is_verify'] ?? 0) !== 1
            || ($kind === 'catalog_card_definition'
                && (int)$product['product_type'] !== 5
                && !$isCustomCardHost)) {
            return 0;
        }
        $sku = Db::name('store_product_attr_value')
            ->where('product_id', $resourceId)
            ->where('type', 0)
            ->order('id asc')
            ->field('id')
            ->find();
        return (int)($sku['id'] ?? 0);
    }

    private function supported(string $kind, string $resourceId): bool
    {
        return in_array($kind, self::KINDS, true)
            && preg_match('/^[1-9][0-9]*$/D', $resourceId) === 1
            && (string)(int)$resourceId === $resourceId;
    }

    private function allowsStore(int $storeId, CashierV3DataScopeContext $dataScope): bool
    {
        return $storeId > 0
            && $dataScope->forcedStoreId() === $storeId
            && ($dataScope->allowsStore($storeId) || $dataScope->isSuperAdmin());
    }
}
