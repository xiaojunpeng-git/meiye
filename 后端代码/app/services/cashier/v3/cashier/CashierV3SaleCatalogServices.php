<?php

namespace app\services\cashier\v3\cashier;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResourceVersionServices;
use think\facade\Db;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;

/** 门店销售目录、购物车 sale 行及结账来源的统一服务端口径。 */
final class CashierV3SaleCatalogServices
{
    public const CONTRACT_VERSION = 'cashier-sale-catalog-v1';
    public const CHECKOUT_SOURCE_CONTRACT_VERSION = 'cashier-sale-checkout-source-v1';

    private const CUSTOM_CARD_SHELL_PRODUCT_ID = 8154;
    private const MAX_CATALOG_ITEMS = 5000;
    private const MAX_QUANTITY = 1000000;

    /** @var CashierV3SaleCatalogAuthority */
    private $authority;

    /** @var CashierV3CashierReadinessGuard|null */
    private $readiness;

    public function __construct(
        CashierV3SaleCatalogAuthority $authority = null,
        CashierV3CashierReadinessGuard $readiness = null
    ) {
        $this->authority = $authority ?: new ThinkPhpCashierV3SaleCatalogAuthority();
        $this->readiness = $readiness;
    }

    public function catalog(
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        $this->assertStoreScope($operatorScope, $dataScope);
        $rows = $this->authority->listStoreItems($operatorScope->storeId());
        if (count($rows) > self::MAX_CATALOG_ITEMS) {
            throw self::failure(
                CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
                '当前门店可售品项过多，收银目录暂时无法完整加载，请联系管理员优化分类。',
                'cashier_catalog_item_limit_exceeded',
                ['limit' => self::MAX_CATALOG_ITEMS]
            );
        }

        $catalogItems = [];
        $categories = [];
        foreach ($rows as $row) {
            // The browser owns the only checkout snapshot, so every card
            // directory item must already contain its complete component
            // definition before it can be selected. Final confirmation must
            // never have to reload that definition from the catalogue.
            $normalized = $this->normalizeAuthorityRow($row, true);
            // 定制卡壳是隐藏的配置依赖，不是普通目录商品。页面只提供一个
            // “新建定制卡”入口，避免把价格为零的旧壳再次展示给收银员。
            if ($normalized['kindCode'] === 'custom_card') {
                continue;
            }
            $catalogItem = $normalized;
            $catalogItems[] = [
                'item' => $catalogItem,
            ];
            foreach ($normalized['categoryNames'] as $name) {
                $categories[$name] = true;
            }
        }

        // Retail products use V3 stock and batch balances at checkout. The
        // catalog must project that same source instead of the retired SKU
        // stock column; otherwise a visible positive stock can still fail on
        // the first add-to-cart command.
        $inventoryAvailability = $this->catalogInventoryAvailability($catalogItems, $dataScope);
        $items = [];
        foreach ($catalogItems as $entry) {
            $catalogItem = $entry['item'];
            $inventory = $inventoryAvailability[self::inventoryItemKey($catalogItem)] ?? null;
            $publicItem = $this->publicCatalogItem($catalogItem, $inventory);
            // 目录点击只写入收银草稿，不在这里拦截库存、上下架或卡内余次。
            // 最终可售性、库存和权益状态统一在确认收款事务内复核。
            $items[] = $publicItem;
        }
        return [
            'contractVersion' => self::CONTRACT_VERSION,
            // 销售目录按用户可理解的结算品类展示。历史次卡、时间规则卡
            // 仍保留各自的 kindCode，不能据此改写既有订单／权益事实；它们
            // 在收银入口统一归入“卡项”。
            'types' => ['项目', '产品', '卡项', '定制卡'],
            'categories' => array_merge(['全部'], array_keys($categories)),
            'items' => $items,
            'itemCount' => count($items),
            'complete' => true,
            'priceAuthority' => 'store_product_attr_value.price',
            'dataScope' => ['storeId' => $operatorScope->storeId()],
        ];
    }

    public function selectSaleLineInTx(
        $itemId,
        string $idempotencyKey,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        CashierV3TransactionGuard::assertInTransaction('cashierSaleCatalogSelect');
        $this->assertStoreScope($operatorScope, $dataScope);
        $skuId = self::positiveId($itemId, 'itemId');
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 128 || strpos($idempotencyKey, "\0") !== false) {
            throw self::failure(
                CashierV3ResultCode::INVALID_IDEMPOTENCY_KEY,
                '本次添加请求标识无效，请重新操作。',
                'cashier_sale_idempotency_key_invalid'
            );
        }
        $normalized = $this->lockedActiveItem($operatorScope->storeId(), $skuId);
        $this->assertPurchasable($normalized, 1);

        return $this->saleLineFromItem($normalized, $idempotencyKey);
    }

    /**
     * Read-only server discovery used before Gateway locks any business row.
     * Resource identities and versions never come from HTTP contexts.
     */
    public function discoverItemResources(
        $itemId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        $this->assertStoreScope($operatorScope, $dataScope);
        $skuId = self::positiveId($itemId, 'itemId');
        $availability = $this->authority->readStoreItemAvailabilityBySkuId(
            $operatorScope->storeId(),
            $skuId
        );
        if (!is_array($availability)
            || (int)($availability['product_type'] ?? 0) !== 1
            || (int)($availability['product_is_del'] ?? 1) !== 0
            || (int)($availability['product_is_show'] ?? 0) !== 1
            || (int)($availability['product_is_verify'] ?? 0) !== 1
            || (int)($availability['sku_type'] ?? -1) !== 0
            || (int)($availability['sku_is_show'] ?? 0) !== 1) {
            throw self::notAvailable($skuId);
        }
        $productType = (int)($availability['product_product_type'] ?? -1);
        $isCardDiscovery = in_array($productType, [4, 5], true)
            || (int)($availability['product_pid'] ?? 0) === 8154;
        if ($isCardDiscovery) {
            $normalized = $this->readActiveItem($operatorScope->storeId(), $skuId);
            $this->assertPurchasable($normalized, 1);
        }
        // 普通产品/项目只有门店可售状态这一项目录前置判断；它们没有
        // 卡内关系，也不需要把商品/SKU版本资源扩成 Gateway 锁集合。
        // 事务内会再次用同一权威 joined 行加锁，避免把同一条目录资料
        // 在版本锁和资源重检阶段重复读取。
        if (!$isCardDiscovery) {
            return [];
        }
        return $this->serverResources($normalized);
    }

    /**
     * Snapshot checkout discovery deliberately does not apply product
     * lifecycle/show/verify predicates. The final preparation transaction
     * locks the SKU and reconstructs its authority row; only entitlement,
     * balance and inventory providers decide final availability.
     */
    public function discoverSnapshotSaleResources(
        $itemId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        $this->assertStoreScope($operatorScope, $dataScope);
        $skuId = self::positiveId($itemId, 'itemId');
        // A browser snapshot is not allowed to trust the display row, but it
        // also must not be rejected here because a product lifecycle flag
        // changed after the item was added. Read the authoritative joined
        // product/SKU row only to derive stable resource identities; the
        // final settlement transaction performs the inventory/entitlement/
        // balance checks and any product-specific business validation.
        $row = $this->authority->readStoreItemBySkuId($operatorScope->storeId(), $skuId);
        if (!is_array($row)) {
            throw self::notAvailable($skuId);
        }
        $item = $this->normalizeAuthorityRow($row, false);
        $resources = $this->serverResources($item);
        // Snapshot checkout owns the browser values, but resource locking must
        // use the central current version at the final boundary. A catalog
        // authority row can legitimately lag that registry after a catalog
        // update; carrying its display-era version would reject an otherwise
        // valid checkout before inventory, entitlement, or balance checks.
        foreach ($resources as &$resource) {
            $kind = (string)($resource['kind'] ?? '');
            $id = (string)($resource['id'] ?? '');
            if ($kind === '' || $id === '') {
                continue;
            }
            $current = (int)Db::name(CashierV3ResourceVersionServices::TABLE)
                ->where('scope_type', 'store')
                ->where('scope_id', (string)$operatorScope->storeId())
                ->where('resource_kind', $kind)
                ->where('resource_id', $id)
                ->value('current_version');
            if ($current > 0) {
                $resource['expectedVersion'] = $current;
                $resource['authorityFingerprint'] = hash('sha256', $kind . '|' . $id . '|' . $current);
            }
        }
        unset($resource);
        return $resources;
    }

    /** Resources for the configured-card host. The host itself is never directly sellable. */
    public function discoverCustomCardShellResources(
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        return $this->serverResources($this->readCustomCardShell($operatorScope, $dataScope, false));
    }

    /**
     * Build a priced custom-card sale line only after the Gateway has locked
     * the shell and every selected project resource. $configuration is already
     * persisted in the same transaction and is the immutable sales source.
     */
    public function customCardSaleLineAfterGatewayLocksInTx(
        array $configuration,
        string $idempotencyKey,
        array $lockedContexts,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        bool $directSnapshot = false
    ): array {
        CashierV3TransactionGuard::assertInTransaction('cashierCustomCardSelectAfterGatewayLocks');
        // The legacy custom-card shell is a hidden configuration host, not a
        // sellable SKU or an inventory authority.  A browser snapshot must
        // therefore not fail because that hidden host is off shelf.  The
        // configuration and its actual project components are materialized
        // inside this final transaction; entitlement/balance/inventory checks
        // remain in their respective final authorities.
        $shell = $this->readCustomCardShell($operatorScope, $dataScope, !$directSnapshot);
        $current = $this->customCardCurrentFromConfiguration($shell, $configuration);
        if (!$directSnapshot) {
            // Legacy mutable-workspace command path only.
            $this->assertLockedContextsCover($current, $lockedContexts, ['custom_card_configuration']);
        }
        if ((int)($configuration['total_amount_cents'] ?? 0) <= 0) {
            throw self::failure(CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE, '定制卡金额必须大于 0。', 'custom_card_total_invalid');
        }
        return $this->saleLineFromItem($current, $idempotencyKey);
    }

    /** Final issuer re-read for a custom-card configuration already Gateway-locked by checkout. */
    public function customCardIssuanceLineInTx(
        array $configuration,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        CashierV3TransactionGuard::assertInTransaction('cashierCustomCardIssuanceLine');
        // See customCardSaleLineAfterGatewayLocksInTx(): the host is only a
        // configuration template and is not an eligible product resource.
        $shell = $this->readCustomCardShell($operatorScope, $dataScope, false);
        return $this->saleLineFromItem($this->customCardCurrentFromConfiguration($shell, $configuration), 'custom-card-issue:' . (string)$configuration['configuration_id']);
    }

    /** Read-only discovery for a persisted sale line. */
    public function discoverStoredLineResources(
        array $storedLine,
        int $quantity,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        $current = $this->currentForStoredLine(
            $storedLine,
            $quantity,
            $operatorScope,
            $dataScope,
            false
        );
        return $this->serverResources($current);
    }

    public function readResourceVersion(
        string $kind,
        int $resourceId,
        int $representativeSkuId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): int {
        if (!in_array($kind, ['catalog_card_definition', 'catalog_product', 'catalog_sku'], true)
            || $resourceId <= 0 || $representativeSkuId <= 0) {
            throw self::failure(
                CashierV3ResultCode::INVALID_COMMAND_CONTEXT,
                '商品资源身份无效，请刷新后重试。',
                'cashier_sale_resource_identity_invalid'
            );
        }
        $this->assertStoreScope($operatorScope, $dataScope);
        // Gateway 已先精确锁定资源行。卡项会把卡内项目与规格一起纳入资源
        // 集合；这些项目随后下架时仍必须能参与版本校验，否则父卡明明在售却
        // 会在加购前被错误拒绝。真正的“可售”判定仍在目录发现及加购最终重读
        // 父卡时执行，不能由这里把组件当作独立销售品再判一次上架状态。
        $row = $this->authority->readStoreItemBySkuId($operatorScope->storeId(), $representativeSkuId);
        if (!is_array($row)) {
            throw self::notAvailable($representativeSkuId);
        }
        $item = $this->normalizeAuthorityRow(
            $row,
            $kind === 'catalog_card_definition'
        );
        foreach ((array)$item['authoritySnapshot']['resourceSources'] as $resource) {
            if ((string)($resource['kind'] ?? '') === $kind
                && (string)($resource['id'] ?? '') === (string)$resourceId
                && (int)($resource['version'] ?? 0) > 0) {
                return (int)$resource['version'];
            }
        }
        throw self::notAvailable($representativeSkuId);
    }

    public function selectSaleLineAfterGatewayLocksInTx(
        $itemId,
        string $idempotencyKey,
        array $lockedContexts,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        CashierV3TransactionGuard::assertInTransaction('cashierSaleCatalogSelectAfterGatewayLocks');
        $this->assertStoreScope($operatorScope, $dataScope);
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 128 || strpos($idempotencyKey, "\0") !== false) {
            throw self::failure(
                CashierV3ResultCode::INVALID_IDEMPOTENCY_KEY,
                '本次添加请求标识无效，请重新操作。',
                'cashier_sale_idempotency_key_invalid'
            );
        }
        $normalized = $this->lockedActiveItem(
            $operatorScope->storeId(),
            self::positiveId($itemId, 'itemId')
        );
        $this->assertLockedContextsCover($normalized, $lockedContexts);
        $this->assertPurchasable($normalized, 1);
        $this->assertInventoryAvailableForSale($normalized, 1, $dataScope);
        return $this->saleLineFromItem($normalized, $idempotencyKey);
    }

    /**
     * Draft-only catalog selection.  This deliberately records the current
     * catalog snapshot without applying sale, price or inventory rules.  The
     * checkout preparation/submit transaction is the only place that turns a
     * draft line into a business fact.
     */
    public function selectDraftSaleLineAfterGatewayLocksInTx(
        $itemId,
        string $idempotencyKey,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        CashierV3TransactionGuard::assertInTransaction('cashierSaleCatalogDraftSelect');
        $this->assertStoreScope($operatorScope, $dataScope);
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 128 || strpos($idempotencyKey, "\0") !== false) {
            throw self::failure(
                CashierV3ResultCode::INVALID_IDEMPOTENCY_KEY,
                '本次添加请求标识无效，请重新操作。',
                'cashier_sale_idempotency_key_invalid'
            );
        }
        $skuId = self::positiveId($itemId, 'itemId');
        $row = $this->authority->lockStoreItemBySkuId($operatorScope->storeId(), $skuId);
        if (!is_array($row)) {
            throw self::notAvailable($skuId);
        }
        // No active/stock/card-availability assertion here.  This is a draft
        // snapshot only; checkout re-reads and locks the authoritative rows.
        return $this->saleLineFromItem($this->normalizeAuthorityRow($row, false), $idempotencyKey);
    }

    /**
     * Final card issuance consumes the browser's checkout snapshot, not the
     * mutable catalogue lifecycle flags. The SKU identity remains locked in
     * this transaction; stock, balance and entitlement checks remain at their
     * dedicated final authorities.
     */
    public function selectCheckoutSaleLineInTx(
        $itemId,
        string $idempotencyKey,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        CashierV3TransactionGuard::assertInTransaction('cashierSaleCatalogCheckoutSelect');
        $this->assertStoreScope($operatorScope, $dataScope);
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 128 || strpos($idempotencyKey, "\0") !== false) {
            throw self::failure(
                CashierV3ResultCode::INVALID_IDEMPOTENCY_KEY,
                '本次签发请求标识无效，请重新操作。',
                'cashier_sale_idempotency_key_invalid'
            );
        }
        $skuId = self::positiveId($itemId, 'itemId');
        $row = $this->authority->lockStoreItemBySkuId($operatorScope->storeId(), $skuId);
        if (!is_array($row)) {
            throw self::notAvailable($skuId);
        }
        // The final card definition comes from the one browser snapshot. This
        // lock supplies SKU identity only; it must not reload card components.
        return $this->saleLineFromItem($this->normalizeAuthorityRow($row, false), $idempotencyKey);
    }

    /**
     * Build the one server-managed sale line for a pending card/project
     * upgrade. The target's normal catalogue price remains the original
     * amount; the consumed old-right value is represented as a frozen discount
     * and the payable amount is the calculated settlement delta.
     */
    public function cardOperationUpgradeSaleLineAfterGatewayLocksInTx(
        array $plan,
        string $idempotencyKey,
        array $lockedContexts,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        bool $directSnapshot = false
    ): array {
        CashierV3TransactionGuard::assertInTransaction('cashierCardOperationUpgradeSaleLine');
        $this->assertStoreScope($operatorScope, $dataScope);
        $operationId = trim((string)($plan['operationId'] ?? ''));
        $operationType = trim((string)($plan['operationType'] ?? ''));
        $target = is_array($plan['target'] ?? null) ? $plan['target'] : [];
        $settlement = is_array($plan['checkoutSettlement'] ?? null) ? $plan['checkoutSettlement'] : [];
        $source = is_array($plan['sourceCard'] ?? null) ? $plan['sourceCard'] : [];
        $targetSkuId = (int)($target['skuId'] ?? 0);
        $targetProductId = (int)($target['catalogId'] ?? 0);
        $targetPriceCents = $settlement['targetPriceCents'] ?? null;
        $sourceValueCents = $settlement['sourceRemainingValueCents'] ?? null;
        $deltaCents = $settlement['settlementDeltaCents'] ?? null;
        if (preg_match('/^COP-[A-F0-9]{40}$/D', $operationId) !== 1
            || !in_array($operationType, ['card_upgrade', 'project_upgrade'], true)
            || $targetSkuId <= 0 || $targetProductId <= 0
            || !is_int($targetPriceCents) || !is_int($sourceValueCents) || !is_int($deltaCents)
            || $targetPriceCents < 0 || $sourceValueCents < 0 || $deltaCents < 0
            || max(0, $targetPriceCents - $sourceValueCents) !== $deltaCents
            || (int)($source['holderId'] ?? 0) <= 0
            || (int)($source['holderVersion'] ?? 0) <= 0
            || !preg_match('/^[a-f0-9]{64}$/D', (string)($plan['immutableFingerprint'] ?? ''))) {
            throw self::failure(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '升级结算资料不完整，请重新打开后办理。',
                'card_operation_upgrade_plan_invalid'
            );
        }
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 128 || strpos($idempotencyKey, "\0") !== false) {
            throw self::failure(
                CashierV3ResultCode::INVALID_IDEMPOTENCY_KEY,
                '本次升级请求标识无效，请重新操作。',
                'card_operation_upgrade_idempotency_invalid'
            );
        }
        $current = $directSnapshot
            ? $this->lockedSnapshotItem($operatorScope->storeId(), $targetSkuId)
            : $this->lockedActiveItem($operatorScope->storeId(), $targetSkuId);
        // A browser checkout snapshot is the only authority for the selected
        // target and its transaction price. The final command locks current
        // inventory only; it must not turn an earlier catalogue projection,
        // price edit, or product-state change into a stale-version rejection.
        // Legacy direct card-operation commands retain their old contract.
        if (!$directSnapshot) {
            $this->assertLockedContextsCover($current, $lockedContexts);
            $requiredProductType = $operationType === 'card_upgrade' ? 5 : 6;
            if ((int)$current['productId'] !== $targetProductId
                || (int)$current['productType'] !== $requiredProductType
                || (int)$current['unitPriceCents'] !== $targetPriceCents) {
                throw CashierV3CommandException::versionConflict(
                    '目标项目或卡项的资料已经变化，请重新打开后办理。',
                    ['reason' => 'card_operation_upgrade_target_changed']
                );
            }
            $this->assertPurchasable($current, 1);
        }
        $this->assertInventoryAvailableForSale($current, 1, $dataScope);

        $extraResources = [[
            'kind' => 'card_holder',
            'id' => (string)(int)$source['holderId'],
            'version' => (int)$source['holderVersion'],
        ]];
        $projectMutations = [];
        foreach ((array)($plan['stateMutation']['projectMutations'] ?? []) as $mutation) {
            if (!is_array($mutation)) {
                throw self::failure(CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE, '项目升级来源资料不完整，请重新打开后办理。', 'card_operation_project_upgrade_mutation_invalid');
            }
            $detailId = (int)($mutation['sourceDetailId'] ?? 0);
            $detailVersion = (int)($mutation['sourceDetailVersion'] ?? 0);
            if ($operationType !== 'project_upgrade' || $detailId <= 0 || $detailVersion <= 0) {
                throw self::failure(CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE, '项目升级来源资料不完整，请重新打开后办理。', 'card_operation_project_upgrade_mutation_missing');
            }
            $projectMutations[] = $mutation;
            $extraResources[] = [
                'kind' => 'member_benefit_pool',
                'id' => (string)$detailId,
                'version' => $detailVersion,
            ];
        }
        if ($operationType === 'project_upgrade' && $projectMutations === []) {
            throw self::failure(CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE, '项目升级来源资料不完整，请重新打开后办理。', 'card_operation_project_upgrade_sources_missing');
        }
        $targetEntitlementQuantity = 1;
        if ($operationType === 'project_upgrade') {
            $targetEntitlementQuantity = (int)($plan['stateMutation']['targetEntitlementQuantity'] ?? 1);
            if ($targetEntitlementQuantity <= 0) {
                throw self::failure(CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE, '项目升级次数无效，请重新打开后办理。', 'card_operation_project_upgrade_quantity_invalid');
            }
        }
        $binding = [
            'contractVersion' => 'cashier-v3-card-operation-upgrade-sale-v1',
            'operationId' => $operationId,
            'operationType' => $operationType,
            'operationFingerprint' => (string)$plan['immutableFingerprint'],
            'sourceCardHolderId' => (int)$source['holderId'],
            'sourceCardHolderVersion' => (int)$source['holderVersion'],
            'originOrderId' => (int)($source['originOrderId'] ?? 0),
            'memberId' => (int)($source['currentMemberId'] ?? 0),
            'sourceCardName' => trim((string)($source['cardName'] ?? '')) ?: '原卡',
            'sourceCardNo' => trim((string)($source['cardNo'] ?? '')),
            'targetProductId' => $targetProductId,
            'targetSkuId' => $targetSkuId,
            'targetPriceCents' => $targetPriceCents,
            'sourceRemainingValueCents' => $sourceValueCents,
            'settlementDeltaCents' => $deltaCents,
            // This is the same selected source quantity that the checkout
            // settlement writes as the new target right's write_times.
            'targetEntitlementQuantity' => $targetEntitlementQuantity,
            'projectMutations' => $projectMutations,
            'sourceResources' => $extraResources,
        ];
        $snapshot = (array)$current['authoritySnapshot'];
        $snapshot['resourceSources'] = self::uniqueResources(array_merge(
            (array)($snapshot['resourceSources'] ?? []),
            $extraResources
        ));
        $snapshot['cardOperationUpgrade'] = $binding;
        $line = $current;
        $line['unitPriceCents'] = $deltaCents;
        $line['originalUnitPriceCents'] = $targetPriceCents;
        $line['authoritySnapshot'] = $snapshot;
        $line['authorityFingerprint'] = hash('sha256', self::canonicalJson($snapshot));
        $line['displaySnapshot'] = $this->displaySnapshot($line);
        return $this->saleLineFromItem($line, $idempotencyKey);
    }

    public function assertStoredSaleQuantityAfterGatewayLocksInTx(
        array $storedLine,
        int $quantity,
        array $lockedContexts,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): void {
        $current = $this->currentForStoredLine(
            $storedLine,
            $quantity,
            $operatorScope,
            $dataScope,
            false
        );
        $this->assertLockedContextsCover($current, $lockedContexts);
    }

    public function checkoutSourceLineAfterGatewayLocksInTx(
        array $storedLine,
        array $lockedContexts,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        $quantity = (int)($storedLine['quantity'] ?? 0);
        $current = $this->currentForStoredLine(
            $storedLine,
            $quantity,
            $operatorScope,
            $dataScope,
            false
        );
        $this->assertLockedContextsCover($current, $lockedContexts);
        return $this->checkoutSourceFromCurrent($storedLine, $current, $quantity);
    }

    private function saleLineFromItem(array $normalized, string $idempotencyKey): array
    {
        $isServiceProject = $normalized['productType'] === 6
            && (string)($normalized['kindCode'] ?? '') !== 'custom_card';
        return [
            'line_key' => 'sale:' . substr(hash('sha256', $idempotencyKey), 0, 48),
            'line_role' => 'sale',
            'catalog_product_id' => $normalized['productId'],
            'catalog_sku_id' => $normalized['skuId'],
            'catalog_product_type' => $normalized['productType'],
            'kind_code' => $normalized['kindCode'],
            'category_id_snapshot' => (int)($normalized['categoryId'] ?? 0),
            'category_name_snapshot' => (string)($normalized['categoryName'] ?? ''),
            'project_id' => $isServiceProject ? $normalized['productId'] : 0,
            'quantity' => 1,
            'source_version' => $normalized['productVersion'],
            'detail_version' => $normalized['skuVersion'],
            'unit_price_cents' => $normalized['unitPriceCents'],
            'original_unit_price_cents' => $normalized['originalUnitPriceCents'],
            'configured_cost_cents' => $normalized['configuredCostCents'],
            'authority_fingerprint' => $normalized['authorityFingerprint'],
            'authority_snapshot' => $normalized['authoritySnapshot'],
            'display_snapshot' => $this->displaySnapshot($normalized),
            'service_object' => $isServiceProject ? 'self' : '',
            'is_experience' => 0,
        ];
    }

    public function assertStoredSaleQuantityInTx(
        array $storedLine,
        int $quantity,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): void {
        $this->lockedCurrentForStoredLine($storedLine, $quantity, $operatorScope, $dataScope);
    }

    /**
     * 快照提交可直接消费的单行 DTO。该方法只重验并返回来源，不写正式事实。
     */
    public function checkoutSourceLineInTx(
        array $storedLine,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        $quantity = (int)($storedLine['quantity'] ?? 0);
        $current = $this->lockedCurrentForStoredLine(
            $storedLine,
            $quantity,
            $operatorScope,
            $dataScope
        );
        return $this->checkoutSourceFromCurrent($storedLine, $current, $quantity);
    }

    private function checkoutSourceFromCurrent(array $storedLine, array $current, int $quantity): array
    {
        $unitPriceCents = (int)($storedLine['unit_price_cents'] ?? -1);
        $lineAmountCents = self::multiplyCents($unitPriceCents, $quantity);
        $isCustomCard = (string)($current['authoritySnapshot']['cardPurchase']['sourceKind'] ?? '') === 'custom_card';
        // The settlement kernel models discounts as non-negative. A custom
        // card may be priced above its configured display total, so its gross
        // settlement amount is the confirmed line amount; the configured
        // display price remains separately available in configuredPriceCents
        // and the immutable authority snapshot for audit.
        // 草稿中的成交价就是本次结算价。为保持统一结算方程，原价快照
        // 至少覆盖成交价，目录后来改价不改变本单金额。
        $settlementOriginalUnitPriceCents = max(
            (int)($storedLine['original_unit_price_cents'] ?? 0),
            $unitPriceCents
        );
        $originalLineAmountCents = self::multiplyCents($settlementOriginalUnitPriceCents, $quantity);
        return [
            'contractVersion' => self::CHECKOUT_SOURCE_CONTRACT_VERSION,
            'lineId' => (string)($storedLine['line_key'] ?? ''),
            'sortNo' => (int)($storedLine['sort_no'] ?? 0),
            'lineRole' => 'sale',
            'memberId' => (int)($storedLine['member_id'] ?? 0),
            'catalogItemId' => $current['skuId'],
            'productId' => $current['productId'],
            'skuId' => $current['skuId'],
            'skuUnique' => $current['skuUnique'],
            'productType' => $current['productType'],
            'kindCode' => $current['kindCode'],
            'kind' => $current['kind'],
            'nameSnapshot' => $current['name'],
            'skuNameSnapshot' => $current['specification'],
            'categoryIdSnapshot' => $current['categoryId'],
            'categoryNameSnapshot' => $current['categoryName'],
            'productVersion' => $current['productVersion'],
            'skuVersion' => $current['skuVersion'],
            'definitionFingerprint' => $current['authorityFingerprint'],
            'quantity' => $quantity,
            'unitPriceCents' => $unitPriceCents,
            'originalUnitPriceCents' => $settlementOriginalUnitPriceCents,
            'configuredPriceCents' => $current['unitPriceCents'],
            'configuredCostCents' => $current['configuredCostCents'],
            'priceChangeReason' => (string)($storedLine['price_change_reason'] ?? ''),
            'priceChangedBy' => (int)($storedLine['price_changed_by'] ?? 0),
            'priceChangedByNameSnapshot' => (string)($storedLine['price_changed_by_name_snapshot'] ?? ''),
            'priceChangedAt' => (int)($storedLine['price_changed_at'] ?? 0),
            'debtAmountCents' => (int)($storedLine['debt_amount_cents'] ?? 0),
            'lineAmountCents' => $lineAmountCents,
            'originalLineAmountCents' => $originalLineAmountCents,
            'unitPrice' => self::centsToMoney($unitPriceCents),
            'originalUnitPrice' => self::centsToMoney($settlementOriginalUnitPriceCents),
            'lineAmount' => self::centsToMoney($lineAmountCents),
            'originalLineAmount' => self::centsToMoney($originalLineAmountCents),
            'serviceObject' => (string)($storedLine['service_object'] ?? ''),
            'friendCountsAsCustomer' => (int)($storedLine['friend_counts_as_customer'] ?? 1) === 1,
            'craftsmen' => self::decodeStoredList((string)($storedLine['craftsmen_json'] ?? ''), 'craftsmen_json'),
            'isExperience' => (int)($storedLine['is_experience'] ?? 0) === 1,
            'isPresale' => (int)($storedLine['is_presale'] ?? 0) === 1,
            'inventoryOutboundRequired' => (int)($storedLine['inventory_outbound_required'] ?? 1) === 1,
            'cardPurchaseSnapshot' => $current['authoritySnapshot']['cardPurchase'],
            'resourceSources' => $current['authoritySnapshot']['resourceSources'],
        ];
    }

    public static function itemId($value): int
    {
        return self::positiveId($value, 'itemId');
    }

    private function lockedCurrentForStoredLine(
        array $storedLine,
        int $quantity,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        return $this->currentForStoredLine(
            $storedLine,
            $quantity,
            $operatorScope,
            $dataScope,
            true
        );
    }

    private function currentForStoredLine(
        array $storedLine,
        int $quantity,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        bool $lock
    ): array {
        CashierV3TransactionGuard::assertInTransaction('cashierSaleCatalogRevalidate');
        $this->assertStoreScope($operatorScope, $dataScope);
        if ((string)($storedLine['line_role'] ?? '') !== 'sale' || $quantity <= 0 || $quantity > self::MAX_QUANTITY) {
            throw self::failure(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '购物车商品资料不完整，请删除后重新选择。',
                'cashier_sale_line_contract_invalid'
            );
        }
        $storedSnapshot = self::decodeStoredObject(
            (string)($storedLine['authority_snapshot_json'] ?? ''),
            'authority_snapshot_json'
        );
        $storedPurchase = is_array($storedSnapshot['cardPurchase'] ?? null)
            ? $storedSnapshot['cardPurchase'] : [];
        if ((string)($storedPurchase['sourceKind'] ?? '') === 'custom_card') {
            if ($quantity !== 1) {
                throw self::failure(CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE, '定制卡数量固定为 1。', 'custom_card_quantity_invalid');
            }
            $configuration = $this->readStoredCustomCardConfiguration($storedSnapshot, $operatorScope, $dataScope, $lock);
            $shell = $this->readCustomCardShell($operatorScope, $dataScope, $lock);
            return $this->customCardCurrentFromConfiguration($shell, $configuration);
        }
        $skuId = (int)($storedLine['catalog_sku_id'] ?? 0);
        // A submitted checkout snapshot owns its product name, price and
        // line-level business settings. Re-read the row only to obtain the
        // final inventory authority; do not re-apply a mutable catalogue
        // availability/price/version decision to an already formed snapshot.
        $current = $lock
            ? $this->lockedSnapshotItem($operatorScope->storeId(), $skuId)
            : $this->readSnapshotItem($operatorScope->storeId(), $skuId);
        if (is_array($storedSnapshot['cardOperationUpgrade'] ?? null)) {
            $current = $this->cardOperationUpgradeCurrentForStoredLine(
                $storedLine,
                $current,
                (array)$storedSnapshot['cardOperationUpgrade']
            );
        }
        // Products explicitly marked as presale or non-outbound are still
        // saleable without an inbound stock record. Inventory is only checked
        // and deducted for the immutable outbound path at final settlement.
        // Workspace rows do not expose source_type; the locked catalog
        // authority's productType is the canonical item classification.
        $isProduct = (int)($current['productType'] ?? -1) === 0;
        $isPresale = (int)($storedLine['is_presale'] ?? 0) === 1;
        $requiresOutbound = (int)($storedLine['inventory_outbound_required'] ?? 1) === 1;
        if (!$isProduct || (!$isPresale && $requiresOutbound)) {
            $this->assertInventoryAvailableForSale($current, $quantity, $dataScope);
        }
        return $current;
    }

    private function cardOperationUpgradeCurrentForStoredLine(
        array $storedLine,
        array $current,
        array $binding
    ): array {
        $operationId = trim((string)($binding['operationId'] ?? ''));
        $operationType = trim((string)($binding['operationType'] ?? ''));
        $targetPrice = $binding['targetPriceCents'] ?? null;
        $sourceValue = $binding['sourceRemainingValueCents'] ?? null;
        $delta = $binding['settlementDeltaCents'] ?? null;
        $sourceResources = is_array($binding['sourceResources'] ?? null) ? $binding['sourceResources'] : [];
        $couponDiscount = (int)($storedLine['coupon_discount_cents'] ?? 0);
        if (preg_match('/^COP-[A-F0-9]{40}$/D', $operationId) !== 1
            || !in_array($operationType, ['card_upgrade', 'project_upgrade'], true)
            || !preg_match('/^[a-f0-9]{64}$/D', (string)($binding['operationFingerprint'] ?? ''))
            || !is_int($targetPrice) || !is_int($sourceValue) || !is_int($delta)
            || $targetPrice < 0 || $sourceValue < 0 || $delta < 0
            || max(0, $targetPrice - $sourceValue) !== $delta
            // 行券只降低本次应收，绝不能改写升级操作冻结的原始补价。
            || $couponDiscount < 0 || $couponDiscount > $delta
            || (int)$storedLine['unit_price_cents'] !== $delta - $couponDiscount
            || (int)$storedLine['original_unit_price_cents'] !== $targetPrice
            || $sourceResources === []) {
            throw CashierV3CommandException::versionConflict(
                '升级结算资料已经变化，请删除后重新办理。',
                ['reason' => 'card_operation_upgrade_line_changed']
            );
        }
        $snapshot = (array)$current['authoritySnapshot'];
        $snapshot['resourceSources'] = self::uniqueResources(array_merge(
            (array)($snapshot['resourceSources'] ?? []),
            $sourceResources
        ));
        $snapshot['cardOperationUpgrade'] = $binding;
        $result = $current;
        $result['unitPriceCents'] = $delta;
        $result['originalUnitPriceCents'] = $targetPrice;
        $result['authoritySnapshot'] = $snapshot;
        $result['authorityFingerprint'] = hash('sha256', self::canonicalJson($snapshot));
        return $result;
    }

    private function readCustomCardShell(
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        bool $lock
    ): array {
        $this->assertStoreScope($operatorScope, $dataScope);
        $rows = $this->authority->listStoreItems($operatorScope->storeId());
        foreach ($rows as $row) {
            $item = $this->normalizeAuthorityRow($row, false);
            if ($item['kindCode'] !== 'custom_card') {
                continue;
            }
            $raw = $lock
                ? $this->authority->lockStoreItemBySkuId($operatorScope->storeId(), (int)$item['skuId'])
                : $this->authority->readStoreItemBySkuId($operatorScope->storeId(), (int)$item['skuId']);
            if (!is_array($raw)) {
                continue;
            }
            $current = $this->normalizeAuthorityRow($raw, false);
            if ($current['kindCode'] !== 'custom_card'
                || (int)($current['authoritySnapshot']['product']['isDeleted'] ?? 1) !== 0
                || (int)($current['authoritySnapshot']['product']['isVerified'] ?? 0) !== 1) {
                continue;
            }
            return $current;
        }
        throw self::failure(CashierV3ResultCode::RESOURCE_NOT_FOUND, '当前门店未配置可用的定制卡入口。', 'custom_card_shell_not_available');
    }

    private function readStoredCustomCardConfiguration(
        array $storedSnapshot,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        bool $lock
    ): array {
        $meta = is_array($storedSnapshot['customCardConfiguration'] ?? null)
            ? $storedSnapshot['customCardConfiguration'] : [];
        $configurationId = trim((string)($meta['id'] ?? ''));
        if (preg_match('/^CCD-[A-F0-9]{40}$/D', $configurationId) !== 1) {
            throw self::failure(CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE, '定制卡配置已失效，请重新配置。', 'custom_card_configuration_identity_invalid');
        }
        $query = \think\facade\Db::name('cashier_v3_custom_card_configuration')
            ->where('configuration_id', $configurationId)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('store_id', $operatorScope->storeId())
            ->where('status', 'in_cart');
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();
        $row = is_object($row) && method_exists($row, 'toArray') ? $row->toArray() : (array)$row;
        if (!$row || (int)($row['resource_version'] ?? 0) <= 0
            || !hash_equals((string)($meta['fingerprint'] ?? ''), (string)($row['immutable_fingerprint'] ?? ''))) {
            throw self::failure(CashierV3ResultCode::RESOURCE_VERSION_CONFLICT, '定制卡配置已经变化，请重新配置。', 'custom_card_configuration_changed');
        }
        $snapshot = json_decode((string)($row['configuration_snapshot_json'] ?? ''), true);
        if (!is_array($snapshot) || (int)($snapshot['totalAmountCents'] ?? 0) <= 0) {
            throw self::failure(CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE, '定制卡配置内容不完整，请重新配置。', 'custom_card_configuration_snapshot_invalid');
        }
        $row['configuration_snapshot'] = $snapshot;
        return $row;
    }

    private function customCardCurrentFromConfiguration(array $shell, array $configuration): array
    {
        $snapshot = is_array($configuration['configuration_snapshot'] ?? null)
            ? $configuration['configuration_snapshot']
            : (is_array($configuration['snapshot'] ?? null) ? $configuration['snapshot'] : []);
        $components = is_array($snapshot['components'] ?? null) ? $snapshot['components'] : [];
        $total = (int)($snapshot['totalAmountCents'] ?? $configuration['total_amount_cents'] ?? 0);
        $end = (int)($snapshot['validityEnd'] ?? $configuration['validity_end_at'] ?? 0);
        if ($total <= 0 || $end <= time() || !$components) {
            throw self::failure(CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE, '定制卡配置已失效，请重新配置。', 'custom_card_configuration_expired_or_invalid');
        }
        $resourceSources = (array)($shell['authoritySnapshot']['resourceSources'] ?? []);
        $configuredCostCents = 0;
        foreach ($components as $component) {
            $componentCostCents = (int)($component['configuredCostCents'] ?? -1);
            $componentQuantity = (int)($component['writeTimes'] ?? 0);
            if ($componentCostCents < 0 || $componentQuantity <= 0
                || $componentCostCents > intdiv(PHP_INT_MAX, $componentQuantity)) {
                throw self::failure(
                    CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                    '定制卡卡内项目成本资料不完整，请重新配置。',
                    'custom_card_component_cost_invalid'
                );
            }
            $componentTotalCostCents = $componentCostCents * $componentQuantity;
            if ($componentTotalCostCents > PHP_INT_MAX - $configuredCostCents) {
                throw self::failure(
                    CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                    '定制卡卡内项目成本资料不完整，请重新配置。',
                    'custom_card_component_cost_overflow'
                );
            }
            $configuredCostCents += $componentTotalCostCents;
            foreach ((array)($component['resourceSources'] ?? []) as $resource) {
                $resourceSources[] = $resource;
            }
        }
        $resourceSources[] = [
            'kind' => 'custom_card_configuration',
            'id' => (string)$configuration['configuration_id'],
            'version' => (int)$configuration['resource_version'],
        ];
        $resourceSources = self::uniqueResources($resourceSources);
        $purchase = [
            'sourceKind' => 'custom_card',
            'productId' => $shell['productId'],
            // A configuration is created before checkout.  Its authority
            // snapshot must therefore not contain the read-time clock: that
            // would alter the fingerprint between cart and checkout.  The
            // issuer resolves this sentinel to the successful settlement time.
            'validity' => ['writeValid' => 3, 'writeDays' => 0, 'writeStart' => 0, 'writeEnd' => $end],
            'components' => $components,
            'definitionVersion' => self::fingerprintVersion($snapshot),
            'cardNameSnapshot' => (string)($snapshot['cardName'] ?? ''),
            'activateOnPurchase' => !empty($snapshot['activateOnPurchase']),
        ];
        $authoritySnapshot = $shell['authoritySnapshot'];
        $authoritySnapshot['sku']['priceCents'] = $total;
        $authoritySnapshot['sku']['originalPriceCents'] = $total;
        $authoritySnapshot['sku']['costCents'] = $configuredCostCents;
        $authoritySnapshot['cardPurchase'] = $purchase;
        $authoritySnapshot['resourceSources'] = $resourceSources;
        $authoritySnapshot['customCardConfiguration'] = [
            'id' => (string)$configuration['configuration_id'],
            'version' => (int)$configuration['resource_version'],
            'fingerprint' => (string)$configuration['immutable_fingerprint'],
        ];
        $result = $shell;
        $result['unitPriceCents'] = $total;
        $result['originalUnitPriceCents'] = $total;
        $result['configuredCostCents'] = $configuredCostCents;
        $result['name'] = (string)($snapshot['cardName'] ?? $shell['name']);
        $result['authoritySnapshot'] = $authoritySnapshot;
        $result['authorityFingerprint'] = hash('sha256', self::canonicalJson($authoritySnapshot));
        return $result;
    }

    private function lockedActiveItem(int $storeId, int $skuId): array
    {
        if ($this->readiness !== null) {
            $this->readiness->assertSaleCatalogReady();
        }
        $row = $this->authority->lockStoreItemBySkuId($storeId, $skuId);
        if (!is_array($row)) {
            throw self::notAvailable($skuId);
        }
        $normalized = $this->normalizeAuthorityRow($row, true);
        if (!$normalized['active']) {
            throw self::notAvailable($skuId);
        }
        return $normalized;
    }

    /** Lock a catalogue row for final inventory use without treating current
     * product visibility, verification, price or resource version as a
     * validation rule for a browser-owned checkout snapshot. */
    private function lockedSnapshotItem(int $storeId, int $skuId): array
    {
        if ($this->readiness !== null) {
            $this->readiness->assertSaleCatalogReady();
        }
        $row = $this->authority->lockStoreItemBySkuId($storeId, $skuId);
        if (!is_array($row)) {
            throw self::notAvailable($skuId);
        }
        return $this->normalizeAuthorityRow($row, false);
    }

    private function readActiveItem(int $storeId, int $skuId): array
    {
        if ($this->readiness !== null) {
            $this->readiness->assertSaleCatalogReady();
        }
        $row = $this->authority->readStoreItemBySkuId($storeId, $skuId);
        if (!is_array($row)) {
            throw self::notAvailable($skuId);
        }
        $normalized = $this->normalizeAuthorityRow($row, true);
        if (!$normalized['active']) {
            throw self::notAvailable($skuId);
        }
        return $normalized;
    }

    private function readSnapshotItem(int $storeId, int $skuId): array
    {
        if ($this->readiness !== null) {
            $this->readiness->assertSaleCatalogReady();
        }
        $row = $this->authority->readStoreItemBySkuId($storeId, $skuId);
        if (!is_array($row)) {
            throw self::notAvailable($skuId);
        }
        return $this->normalizeAuthorityRow($row, false);
    }

    /** @return array<int,array> */
    private function serverResources(array $item): array
    {
        $resources = [];
        $mainProductId = (string)(int)($item['productId'] ?? 0);
        $mainSkuId = (string)(int)($item['skuId'] ?? 0);
        $isCard = in_array((string)($item['kindCode'] ?? ''), ['count_card', 'card_package', 'custom_card'], true);
        foreach ((array)($item['authoritySnapshot']['resourceSources'] ?? []) as $source) {
            $kind = trim((string)($source['kind'] ?? ''));
            $id = trim((string)($source['id'] ?? ''));
            $version = (int)($source['version'] ?? 0);
            if ($kind === '' || $id === '' || $version <= 0) {
                throw self::failure(
                    CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                    '商品资源快照不完整，请重新选择。',
                    'cashier_sale_resource_snapshot_invalid'
                );
            }
            // A card definition version is an aggregate fingerprint of the
            // card rule, active relations, and every component product/SKU
            // snapshot. Locking those child rows again here only repeats the
            // same reads and can make a simple card add take several seconds.
            // Keep the parent product/SKU plus the aggregate definition lock;
            // any child change still changes the definition version and is
            // rejected by the normal optimistic-version check.
            if ($isCard
                && (($kind === 'catalog_product' && $id !== $mainProductId)
                    || ($kind === 'catalog_sku' && $id !== $mainSkuId))) {
                continue;
            }
            $resources[] = [
                'kind' => $kind,
                'id' => $id,
                'expectedVersion' => $version,
                'roles' => [$kind . ':' . $id],
                'accessMode' => 'read',
                'providerContractVersion' => 'cashier-sale-catalog-resource-v1',
                'authorityFingerprint' => hash('sha256', $kind . '|' . $id . '|' . $version),
            ];
        }
        return $resources;
    }

    private function assertLockedContextsCover(array $item, array $contexts, array $ignoreKinds = []): void
    {
        // 普通产品/项目不依赖目录资源版本上下文；它们已经在当前事务内
        // 通过 lockedActiveItem() 锁住商品与 SKU，并在该权威行上完成可售
        // 与价格快照校验。卡项仍走完整父卡/组成资源覆盖检查。
        if (!in_array((string)($item['kindCode'] ?? ''), ['count_card', 'card_package', 'custom_card'], true)) {
            return;
        }
        $contextMap = [];
        foreach ($contexts as $context) {
            $kind = trim((string)($context['kind'] ?? ''));
            $id = trim((string)($context['id'] ?? ''));
            $version = (int)($context['expected_version'] ?? $context['expectedVersion'] ?? 0);
            if ($kind !== '' && $id !== '') {
                $contextMap[$kind . ':' . $id] = $version;
            }
        }
        foreach ($this->serverResources($item) as $resource) {
            if (in_array($resource['kind'], $ignoreKinds, true)) {
                continue;
            }
            $key = $resource['kind'] . ':' . $resource['id'];
            if (($contextMap[$key] ?? 0) !== $resource['expectedVersion']) {
                throw CashierV3CommandException::invalidContext(
                    '商品资源版本已经变化，请刷新后重新选择。',
                    ['reason' => 'cashier_sale_locked_context_mismatch', 'resource' => $key]
                );
            }
        }
    }

    private function assertStoredAuthorityMatches(array $stored, array $current): void
    {
        $storedUnitPrice = (int)($stored['unit_price_cents'] ?? -1);
        $storedCost = (int)($stored['configured_cost_cents'] ?? 0);
        $changedAt = (int)($stored['price_changed_at'] ?? 0);
        $changedReason = trim((string)($stored['price_change_reason'] ?? ''));
        $changedBy = (int)($stored['price_changed_by'] ?? 0);
        $changedByName = trim((string)($stored['price_changed_by_name_snapshot'] ?? ''));
        $priceAuditValid = $changedAt === 0
            ? ($storedUnitPrice >= 0 && $storedCost >= 0
                && $changedReason === '' && $changedBy === 0 && $changedByName === '')
            : ($storedCost >= 0 && $storedUnitPrice >= $storedCost
                && $changedReason !== '' && $changedBy > 0 && $changedByName !== '');
        $matches = (int)($stored['catalog_product_id'] ?? 0) === $current['productId']
            && (int)($stored['catalog_sku_id'] ?? 0) === $current['skuId']
            && (int)($stored['catalog_product_type'] ?? -1) === $current['productType']
            && $priceAuditValid;
        $fingerprint = (string)($stored['authority_fingerprint'] ?? '');
        $snapshot = self::decodeStoredObject(
            (string)($stored['authority_snapshot_json'] ?? ''),
            'authority_snapshot_json'
        );
        $storedFingerprint = hash('sha256', self::canonicalJson($snapshot));
        if (!$matches
            || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1
            || !hash_equals($fingerprint, $storedFingerprint)
            ) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::RESOURCE_VERSION_CONFLICT,
                '商品资料已经失效，请删除该行后重新选择。',
                CashierV3ResultCode::STATUS_CONFLICT,
                [
                    'line_id' => (string)($stored['line_key'] ?? ''),
                    'reason' => 'cashier_sale_authority_changed',
                    'current_product_version' => $current['productVersion'],
                    'current_sku_version' => $current['skuVersion'],
                ]
            );
        }
    }

    private function assertPurchasable(array $item, int $quantity): void
    {
        if ($quantity <= 0 || $quantity > self::MAX_QUANTITY) {
            throw self::failure(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '购物车数量无效，请重新操作。',
                'cashier_sale_quantity_invalid'
            );
        }
        if ($item['kindCode'] === 'custom_card') {
            throw self::failure(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '定制卡需要先完成卡内项目配置，不能直接加入购物车。',
                'custom_card_configuration_required'
            );
        }
        if ($item['productType'] === 5 && empty($item['authoritySnapshot']['cardPurchase']['components'])) {
            throw self::failure(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '该卡项没有有效的卡内项目配置，请联系管理员核对。',
                'card_components_missing'
            );
        }
        // `stock` is the legacy SKU display value. Inventory-managed retail
        // products must be validated against the V3 default-store stock row
        // and active batch balances in assertInventoryAvailableForSale().
    }

    /**
     * Legacy SKU stock is a display-era value. A V3 inventory-managed retail
     * sale must have a real default-store stock row and an active batch balance
     * before it can enter the cart; final checkout re-locks these rows again.
     */
    private function assertInventoryAvailableForSale(
        array $item,
        int $quantity,
        CashierV3DataScopeContext $dataScope
    ): void {
        if ((int)($item['productType'] ?? -1) !== 0 || empty($item['isInventory'])) {
            return;
        }
        $storeId = (int)($item['storeId'] ?? 0);
        $productId = (int)($item['productId'] ?? 0);
        $skuId = (int)($item['skuId'] ?? 0);
        if ($storeId <= 0 || $productId <= 0 || $skuId <= 0 || $quantity <= 0
            || $storeId !== $dataScope->forcedStoreId()) {
            throw self::failure(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '当前门店库存资料不完整，不能销售该产品。',
                'cashier_sale_inventory_scope_invalid'
            );
        }

        $locations = \think\facade\Db::name('inventory_location')
            ->where('tenant_id', $dataScope->tenantId())
            ->where('store_id', $storeId)
            ->where('location_type', 'STORE')
            ->where('is_default', 1)
            ->where('location_status', 'ACTIVE')
            ->field('id')
            ->order('id asc')
            ->limit(2)
            ->select()
            ->toArray();
        if (count($locations) !== 1 || (int)($locations[0]['id'] ?? 0) <= 0) {
            throw self::inventoryUnavailable(
                'cashier_sale_inventory_location_not_ready',
                '当前门店尚未建立可用库存位置，不能销售该产品。'
            );
        }

        $stocks = \think\facade\Db::name('inventory_stock')
            ->where('tenant_id', $dataScope->tenantId())
            ->where('store_id', $storeId)
            ->where('location_id', (int)$locations[0]['id'])
            ->where('consumable_product_id', $productId)
            ->where('sku_id', $skuId)
            ->where('stock_status', 'GOOD')
            ->field('id,quantity_scale,available_quantity_units')
            ->order('id asc')
            ->limit(2)
            ->select()
            ->toArray();
        if (count($stocks) !== 1) {
            throw self::inventoryUnavailable(
                'cashier_sale_inventory_stock_not_ready',
                '当前门店尚未入库，不能销售该产品。'
            );
        }
        $scale = (int)($stocks[0]['quantity_scale'] ?? -1);
        $factor = 1;
        for ($index = 0; $index < $scale; $index++) {
            $factor *= 10;
        }
        if ($scale < 0 || $scale > 4 || $quantity > intdiv(PHP_INT_MAX, $factor)
            || (int)($stocks[0]['available_quantity_units'] ?? -1) < $quantity * $factor) {
            $availableUnits = max(0, (int)($stocks[0]['available_quantity_units'] ?? 0));
            $availableText = self::inventoryUnitsToDecimal($availableUnits, max(0, min(4, $scale)));
            throw self::inventoryUnavailable(
                'cashier_sale_inventory_shortage',
                sprintf('库存不足，当前可用库存为 %s，不能销售 %s 件，请修改数量后再试。', $availableText, $quantity)
            );
        }
        $batchAvailable = (int)\think\facade\Db::name('inventory_batch')
            ->where('stock_id', (int)$stocks[0]['id'])
            ->where('batch_status', 'ACTIVE')
            ->sum('available_quantity_units');
        if ($batchAvailable < $quantity * $factor
            || $batchAvailable !== (int)$stocks[0]['available_quantity_units']) {
            throw self::inventoryUnavailable(
                'cashier_sale_inventory_batch_not_ready',
                '当前批次库存与库存余额不一致，请刷新后重试。'
            );
        }
    }

    private static function inventoryUnavailable(string $reason, ?string $message = null): CashierV3CommandException
    {
        return self::failure(
            CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
            $message ?: '当前门店库存不可用，不能销售该产品。',
            $reason
        );
    }

    private function normalizeAuthorityRow(array $row, bool $withComponents): array
    {
        $productId = (int)($row['product_id'] ?? 0);
        // 卡项和服务项目通常只有一个默认 SKU，前端不需要让收银员选择
        // 规格；后端仍保留 SKU 作为价格、门店范围、版本和订单快照的
        // 技术身份，不能因为业务上“看不到 SKU”就省略这层校验。
        $skuId = (int)($row['sku_id'] ?? 0);
        $skuProductId = (int)($row['sku_product_id'] ?? 0);
        $productType = (int)($row['product_product_type'] ?? -1);
        $skuProductType = (int)($row['sku_product_type'] ?? -1);
        $storeId = (int)($row['product_relation_id'] ?? 0);
        $name = trim((string)($row['product_store_name'] ?? ''));
        $skuUnique = trim((string)($row['sku_unique'] ?? ''));
        $specification = trim((string)($row['sku_suk'] ?? ''));
        // 历史普通卡项（product_type=5）的一部分 SKU 仍沿用 product_type=0。
        // 它不是跨商品或跨门店关系，主商品、SKU、门店和 SKU 唯一标识仍须完整一致；
        // 因此只兼容这一种已验证的旧列形态，其他类型不一致继续拒绝。
        $isLegacyCardSkuType = in_array($productType, [5, 6], true) && $skuProductType === 0;
        if ($productId <= 0 || $skuId <= 0 || $skuProductId !== $productId || $storeId <= 0
            || !in_array($productType, [0, 4, 5, 6], true)
            || ($skuProductType !== $productType && !$isLegacyCardSkuType) || $name === '' || $skuUnique === '') {
            throw self::failure(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '门店商品资料不完整，请联系管理员核对。',
                'cashier_catalog_authority_invalid',
                ['product_id' => $productId, 'sku_id' => $skuId]
            );
        }
        $unitPriceCents = self::moneyToCents($row['sku_price'] ?? null, 'price');
        $originalUnitPriceCents = self::moneyToCents($row['sku_ot_price'] ?? null, 'ot_price');
        $configuredCostCents = self::moneyToCents($row['sku_cost'] ?? 0, 'cost');
        if ($originalUnitPriceCents < $unitPriceCents) {
            $originalUnitPriceCents = $unitPriceCents;
        }
        $categoryNames = [];
        foreach ((array)($row['category_names'] ?? []) as $categoryName) {
            $categoryName = trim((string)$categoryName);
            if ($categoryName !== '' && !in_array($categoryName, $categoryNames, true)) {
                $categoryNames[] = $categoryName;
            }
        }
        $categoryIds = self::positiveIds((string)($row['product_cate_id'] ?? ''));
        $kind = self::kind($productType, $productId, (int)($row['product_pid'] ?? 0));
        $stock = self::nonnegativeDecimal($row['sku_stock'] ?? 0, 'stock', true);
        $active = (int)($row['product_type'] ?? 0) === 1
            && (int)($row['product_is_del'] ?? 1) === 0
            && (int)($row['product_is_show'] ?? 0) === 1
            && (int)($row['product_is_verify'] ?? 0) === 1
            && (int)($row['sku_type'] ?? -1) === 0
            && (int)($row['sku_is_show'] ?? 0) === 1;

        $productVersionPayload = [
            'id' => $productId,
            'pid' => (int)($row['product_pid'] ?? 0),
            'storeId' => $storeId,
            'productType' => $productType,
            'name' => $name,
            'categoryIds' => $categoryIds,
            'isShow' => (int)($row['product_is_show'] ?? 0),
            'isDeleted' => (int)($row['product_is_del'] ?? 0),
            'isVerified' => (int)($row['product_is_verify'] ?? 0),
            'isInventory' => (int)($row['product_is_inventory'] ?? 0),
            'allowNegativeStock' => (int)($row['product_allow_negative_stock'] ?? 0),
            'cardNum' => (int)($row['product_card_num'] ?? 0),
            'cardNumType' => (int)($row['product_card_num_type'] ?? 0),
            'cardRuleType' => trim((string)($row['product_card_rule_type'] ?? '')),
            'cardRuleVersion' => (int)($row['product_card_rule_version'] ?? 0),
            'cardChoiceLimit' => (int)($row['product_card_choice_limit'] ?? 0),
            'cardSharedTimes' => (int)($row['product_card_shared_times'] ?? 0),
        ];
        $skuVersionPayload = [
            'id' => $skuId,
            'productId' => $productId,
            'productType' => $skuProductType,
            'unique' => $skuUnique,
            'name' => $specification,
            'code' => trim((string)($row['sku_code'] ?? '')),
            'barCode' => trim((string)($row['sku_bar_code'] ?? '')),
            'priceCents' => $unitPriceCents,
            'originalPriceCents' => $originalUnitPriceCents,
            'costCents' => $configuredCostCents,
            'isShow' => (int)($row['sku_is_show'] ?? 0),
            'writeTimes' => (int)($row['sku_write_times'] ?? 0),
            'writeValid' => (int)($row['sku_write_valid'] ?? 0),
            'writeDays' => (int)($row['sku_write_days'] ?? 0),
            'writeStart' => (int)($row['sku_write_start'] ?? 0),
            'writeEnd' => (int)($row['sku_write_end'] ?? 0),
        ];
        $productVersion = self::fingerprintVersion($productVersionPayload);
        $skuVersion = self::fingerprintVersion($skuVersionPayload);
        $resourceSources = [
            ['kind' => 'catalog_product', 'id' => (string)$productId, 'version' => $productVersion],
            ['kind' => 'catalog_sku', 'id' => (string)$skuId, 'version' => $skuVersion],
        ];
        $cardPurchase = $this->cardPurchaseSnapshot(
            $row,
            $kind['code'],
            $productId,
            $withComponents,
            $resourceSources
        );
        if (!empty($cardPurchase['resourceSources'])) {
            foreach ($cardPurchase['resourceSources'] as $resource) {
                $resourceSources[] = $resource;
            }
        }
        $resourceSources = self::uniqueResources($resourceSources);
        unset($cardPurchase['resourceSources']);
        $authoritySnapshot = [
            'contractVersion' => self::CHECKOUT_SOURCE_CONTRACT_VERSION,
            'storeId' => $storeId,
            'product' => $productVersionPayload,
            'sku' => $skuVersionPayload,
            'productVersion' => $productVersion,
            'skuVersion' => $skuVersion,
            'cardPurchase' => $cardPurchase,
            'resourceSources' => $resourceSources,
        ];
        $authorityFingerprint = hash('sha256', self::canonicalJson($authoritySnapshot));

        return [
            'active' => $active,
            'storeId' => $storeId,
            'productId' => $productId,
            'skuId' => $skuId,
            'skuUnique' => $skuUnique,
            'productType' => $productType,
            'kindCode' => $kind['code'],
            'kind' => $kind['label'],
            'cardRuleType' => trim((string)($row['product_card_rule_type'] ?? '')),
            'name' => $name,
            'specification' => $specification === '默认' ? '' : $specification,
            'code' => trim((string)($row['sku_code'] ?? $row['sku_bar_code'] ?? '')),
            'categoryId' => $categoryIds[0] ?? 0,
            'categoryName' => $categoryNames[0] ?? '全部',
            'categoryNames' => $categoryNames,
            'unitPriceCents' => $unitPriceCents,
            'originalUnitPriceCents' => $originalUnitPriceCents,
            'configuredCostCents' => $configuredCostCents,
            'stock' => $stock,
            'isInventory' => (int)($row['product_is_inventory'] ?? 0) === 1,
            'allowNegativeStock' => (int)($row['product_allow_negative_stock'] ?? 0) === 1,
            'productVersion' => $productVersion,
            'skuVersion' => $skuVersion,
            'authoritySnapshot' => $authoritySnapshot,
            'authorityFingerprint' => $authorityFingerprint,
        ];
    }

    private function cardPurchaseSnapshot(
        array $row,
        string $kindCode,
        int $productId,
        bool $withComponents,
        array $mainResources
    ): array {
        $isCard = in_array($kindCode, ['count_card', 'card_package', 'custom_card'], true);
        if (!$isCard) {
            return [
                'sourceKind' => 'none',
                'validity' => null,
                'components' => [],
                'definitionVersion' => 0,
                'resourceSources' => [],
            ];
        }
        $ruleType = trim((string)($row['product_card_rule_type'] ?? ''));
        $ruleVersion = (int)($row['product_card_rule_version'] ?? 0);
        $choiceLimit = (int)($row['product_card_choice_limit'] ?? 0);
        $sharedTimes = (int)($row['product_card_shared_times'] ?? 0);
        $this->assertCardRuleHeader($kindCode, $ruleType, $ruleVersion, $choiceLimit, $sharedTimes);
        $validity = self::validitySnapshot($row, $kindCode, $ruleType);
        $components = [];
        $resources = [];
        if ($withComponents && $kindCode === 'card_package') {
            foreach ((array)($row['card_components'] ?? []) as $wrapper) {
                $relation = is_array($wrapper['relation'] ?? null) ? $wrapper['relation'] : [];
                $item = is_array($wrapper['item'] ?? null) ? $wrapper['item'] : null;
                if (!$item || (int)($relation['card_product_id'] ?? 0) !== $productId
                    || (int)($relation['status'] ?? 0) !== 1) {
                    throw self::failure(
                        CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                        '卡内项目配置不完整，请联系管理员核对。',
                        'card_component_authority_missing',
                        ['relation_id' => (int)($relation['id'] ?? 0)]
                    );
                }
                $component = $this->normalizeAuthorityRow($item, false);
                // The checkout snapshot owns the selected card definition.
                // A component's current catalogue visibility, verification or
                // deletion flag must never turn an already selected card into
                // a failed checkout.  Keep only structural identity checks
                // here; final entitlement and inventory authorities validate
                // their own mutable balances in the settlement transaction.
                if ((int)($relation['product_id'] ?? 0) !== $component['productId']
                    || (int)($relation['product_type'] ?? -1) !== $component['productType']
                    || !in_array($component['productType'], [0, 6], true)
                    || trim((string)($relation['product_attr_unique'] ?? '')) !== $component['skuUnique']) {
                    throw self::failure(
                        CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                        '卡内项目资料不完整或不属于当前门店，请联系管理员核对。',
                        'card_component_not_available',
                        ['relation_id' => (int)($relation['id'] ?? 0)]
                    );
                }
                $writeTimes = (int)($relation['write_times'] ?? 0);
                $writeoffAmountCents = self::moneyToCents(
                    $relation['writeoff_amount'] ?? '0.00',
                    'card_component_writeoff_amount'
                );
                if (($ruleType === '' || in_array($ruleType, ['normal', 'choice_kind'], true))
                    && $writeTimes <= 0) {
                    throw self::failure(
                        CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                        '卡内项目次数无效，请联系管理员核对。',
                        'card_component_write_times_invalid',
                        ['relation_id' => (int)($relation['id'] ?? 0)]
                    );
                }
                if (in_array($ruleType, ['choice_count', 'time'], true) && $writeTimes !== 0) {
                    throw self::failure(
                        CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                        '卡内项目次数与卡项规则不一致，请联系管理员核对。',
                        'card_component_write_times_rule_mismatch',
                        ['relation_id' => (int)($relation['id'] ?? 0)]
                    );
                }
                if ($ruleType !== '' && $ruleType !== 'time' && $writeoffAmountCents !== 0) {
                    throw self::failure(
                        CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                        '卡内项目核销金额与卡项规则不一致，请联系管理员核对。',
                        'card_component_writeoff_amount_rule_mismatch',
                        ['relation_id' => (int)($relation['id'] ?? 0)]
                    );
                }
                $components[] = [
                    'relationId' => (int)$relation['id'],
                    'productId' => $component['productId'],
                    'skuId' => $component['skuId'],
                    'skuUnique' => $component['skuUnique'],
                    'productType' => $component['productType'],
                    'nameSnapshot' => $component['name'],
                    'skuNameSnapshot' => $component['specification'],
                    'categoryIdSnapshot' => $component['categoryId'],
                    'categoryNameSnapshot' => $component['categoryName'],
                    'writeTimes' => $writeTimes,
                    'writeoffAmountCents' => $writeoffAmountCents,
                    'configuredPriceCents' => self::moneyToCents($relation['price'] ?? null, 'card_component_price'),
                    'configuredCostCents' => self::moneyToCents($relation['cost'] ?? null, 'card_component_cost'),
                    'productVersion' => $component['productVersion'],
                    'skuVersion' => $component['skuVersion'],
                ];
                foreach ($component['authoritySnapshot']['resourceSources'] as $resource) {
                    $resources[] = $resource;
                }
            }
        }
        if ($withComponents && $ruleType === 'choice_kind' && $choiceLimit > count($components)) {
            throw self::failure(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '任选项目种数超过卡内项目总数，请联系管理员核对。',
                'card_choice_limit_exceeds_components'
            );
        }
        // Validity is SKU authority and already participates in skuVersion.
        // The product-level definition guard must have one stable version even
        // when a card product has multiple sale SKUs.
        $definitionPayload = [
            'sourceKind' => $kindCode,
            'ruleType' => $ruleType,
            'ruleVersion' => $ruleVersion,
            'choiceLimit' => $choiceLimit,
            'sharedTimes' => $sharedTimes,
            'components' => $components,
        ];
        $definitionVersion = self::fingerprintVersion($definitionPayload);
        $resources[] = [
            'kind' => 'catalog_card_definition',
            'id' => (string)$productId,
            'version' => $definitionVersion,
        ];
        return [
            'sourceKind' => $kindCode,
            'productId' => $productId,
            'cardNameSnapshot' => trim((string)($row['product_store_name'] ?? '')),
            'ruleType' => $ruleType,
            'ruleVersion' => $ruleVersion,
            'choiceLimit' => $choiceLimit,
            'sharedTimes' => $sharedTimes,
            'validity' => $validity,
            'components' => $components,
            'definitionVersion' => $definitionVersion,
            'resourceSources' => self::uniqueResources(array_merge($mainResources, $resources)),
        ];
    }

    private function assertCardRuleHeader(
        string $kindCode,
        string $ruleType,
        int $ruleVersion,
        int $choiceLimit,
        int $sharedTimes
    ): void {
        if ($kindCode !== 'card_package' || $ruleType === '') {
            return;
        }
        if (!in_array($ruleType, ['normal', 'choice_kind', 'choice_count', 'time'], true)
            || $ruleVersion <= 0
            || ($ruleType === 'choice_kind' ? $choiceLimit <= 0 : $choiceLimit !== 0)
            || ($ruleType === 'choice_count' ? $sharedTimes <= 0 : $sharedTimes !== 0)) {
            throw self::failure(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '卡项规则配置不完整，请联系管理员核对。',
                'card_rule_header_invalid'
            );
        }
    }

    /**
     * 保存“购买后有效期规则”，不在加购阶段判断卡是否已经过期。
     *
     * writeValid=2 只保存有效天数，实际起止时间由成功结账后的签发服务
     * 按可信成交时间计算；writeValid=3 保存配置的固定区间，是否允许销售
     * 属于商品可售策略，不应被误写成“已购买权益有效期”的判断。
     */
    private static function validitySnapshot(array $row, string $kindCode, string $ruleType = ''): array
    {
        $mode = (int)($row['sku_write_valid'] ?? 0);
        $days = max(0, (int)($row['sku_write_days'] ?? 0));
        $start = max(0, (int)($row['sku_write_start'] ?? 0));
        $end = max(0, (int)($row['sku_write_end'] ?? 0));
        $times = max(0, (int)($row['sku_write_times'] ?? 0));
        if ($kindCode === 'count_card' && $times <= 0) {
            throw self::failure(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '次卡可用次数配置无效，请联系管理员核对。',
                'count_card_write_times_invalid'
            );
        }
        if (!in_array($mode, [1, 2, 3], true)
            || ($ruleType === 'time' && $mode === 1)
            || ($mode === 2 && $days <= 0)
            || ($mode === 3 && ($start <= 0 || $end <= $start))) {
            throw self::failure(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '卡项有效期配置无效，请联系管理员核对。',
                'card_validity_invalid'
            );
        }
        return [
            'writeValid' => $mode,
            'writeDays' => $mode === 2 ? $days : 0,
            'writeStart' => $mode === 3 ? $start : 0,
            'writeEnd' => $mode === 3 ? $end : 0,
            'writeTimes' => $times,
        ];
    }

    /**
     * Builds the catalog stock projection from the same V3 default-location
     * stock and active batches used by add-to-cart and final settlement.
     *
     * @param array<int,array{item:array,cardUnavailableReason:string}> $catalogItems
     * @return array<string,array{available:bool,quantity:string}>
     */
    private function catalogInventoryAvailability(array $catalogItems, CashierV3DataScopeContext $dataScope): array
    {
        $managed = [];
        foreach ($catalogItems as $entry) {
            $item = is_array($entry['item'] ?? null) ? $entry['item'] : [];
            if ((int)($item['productType'] ?? -1) !== 0 || empty($item['isInventory'])) {
                continue;
            }
            if ((int)($item['storeId'] ?? 0) !== $dataScope->forcedStoreId()) {
                continue;
            }
            $key = self::inventoryItemKey($item);
            if ($key !== '') {
                $managed[$key] = $item;
            }
        }
        if (!$managed) {
            return [];
        }

        $unavailable = [];
        foreach ($managed as $key => $_item) {
            $unavailable[$key] = ['available' => false, 'quantity' => '0'];
        }
        $storeId = $dataScope->forcedStoreId();
        if ($storeId <= 0) {
            return $unavailable;
        }
        $locations = \think\facade\Db::name('inventory_location')
            ->where('tenant_id', $dataScope->tenantId())
            ->where('store_id', $storeId)
            ->where('location_type', 'STORE')
            ->where('is_default', 1)
            ->where('location_status', 'ACTIVE')
            ->field('id')
            ->order('id asc')
            ->limit(2)
            ->select()
            ->toArray();
        if (count($locations) !== 1 || (int)($locations[0]['id'] ?? 0) <= 0) {
            return $unavailable;
        }

        $productIds = [];
        $skuIds = [];
        foreach ($managed as $item) {
            $productIds[] = (int)$item['productId'];
            $skuIds[] = (int)$item['skuId'];
        }
        $rows = \think\facade\Db::name('inventory_stock')->alias('stock')
            ->leftJoin('inventory_batch batch', 'batch.stock_id = stock.id AND batch.batch_status = "ACTIVE"')
            ->where('stock.tenant_id', $dataScope->tenantId())
            ->where('stock.store_id', $storeId)
            ->where('stock.location_id', (int)$locations[0]['id'])
            ->where('stock.stock_status', 'GOOD')
            ->whereIn('stock.consumable_product_id', array_values(array_unique($productIds)))
            ->whereIn('stock.sku_id', array_values(array_unique($skuIds)))
            ->field('stock.id,stock.consumable_product_id,stock.sku_id,stock.quantity_scale,stock.available_quantity_units,'
                . 'COALESCE(SUM(batch.available_quantity_units), 0) AS batch_available_quantity_units')
            ->group('stock.id,stock.consumable_product_id,stock.sku_id,stock.quantity_scale,stock.available_quantity_units')
            ->select()
            ->toArray();

        $byItem = [];
        foreach ($rows as $row) {
            $key = (int)($row['consumable_product_id'] ?? 0) . '|' . (int)($row['sku_id'] ?? 0);
            if (!isset($managed[$key])) {
                continue;
            }
            if (isset($byItem[$key])) {
                $byItem[$key] = null;
                continue;
            }
            $byItem[$key] = $row;
        }
        foreach ($managed as $key => $_item) {
            $row = $byItem[$key] ?? null;
            if (!is_array($row)) {
                continue;
            }
            $scale = (int)($row['quantity_scale'] ?? -1);
            $availableUnits = (int)($row['available_quantity_units'] ?? -1);
            $batchUnits = (int)($row['batch_available_quantity_units'] ?? -1);
            if ($scale < 0 || $scale > 4 || $availableUnits < 0 || $batchUnits !== $availableUnits) {
                continue;
            }
            $unavailable[$key] = [
                'available' => $availableUnits > 0,
                'quantity' => self::inventoryUnitsToDecimal($availableUnits, $scale),
            ];
        }
        return $unavailable;
    }

    private static function inventoryItemKey(array $item): string
    {
        $productId = (int)($item['productId'] ?? 0);
        $skuId = (int)($item['skuId'] ?? 0);
        return $productId > 0 && $skuId > 0 ? $productId . '|' . $skuId : '';
    }

    private static function inventoryUnitsToDecimal(int $units, int $scale): string
    {
        if ($units < 0 || $scale < 0 || $scale > 4) {
            return '0';
        }
        if ($scale === 0) {
            return (string)$units;
        }
        $digits = str_pad((string)$units, $scale + 1, '0', STR_PAD_LEFT);
        $integer = substr($digits, 0, -$scale);
        $decimal = rtrim(substr($digits, -$scale), '0');
        return $decimal === '' ? $integer : $integer . '.' . $decimal;
    }

    private function publicCatalogItem(array $item, ?array $inventory = null): array
    {
        $isInventoryProduct = $item['productType'] === 0 && $item['isInventory'];
        $inventoryAvailable = !$isInventoryProduct || !empty($inventory['available']);
        // 目录阶段只创建草稿，库存与可售状态统一在结账事务中判断。
        $disabled = $item['kindCode'] === 'custom_card';
        $reason = '';
        if ($item['kindCode'] === 'custom_card') {
            $reason = '定制卡需先配置卡内项目';
        }
        return [
            'id' => $item['skuId'],
            'catalogItemId' => $item['skuId'],
            'productId' => $item['productId'],
            'skuId' => $item['skuId'],
            // Snapshot settlement uses this immutable SKU identity to create
            // inventory movement facts. Keep it in the server display DTO so
            // it is not lost before final submission.
            'skuUnique' => $item['skuUnique'],
            'productType' => $item['productType'],
            'kindCode' => $item['kindCode'],
            'kind' => $item['kind'],
            'cardRuleType' => $item['cardRuleType'],
            'cardRuleLabel' => self::cardRuleLabel($item['cardRuleType']),
            // Display fields drive the confirmation dialog. The complete card
            // business definition is also kept in the browser cart so clicking
            // checkout can freeze the only issuance snapshot.
            'cardPreview' => self::publicCardPreview($item),
            'cardPurchaseSnapshot' => in_array($item['kindCode'], ['count_card', 'card_package'], true)
                ? $item['authoritySnapshot']['cardPurchase'] : null,
            'name' => $item['name'],
            'specification' => $item['specification'],
            'code' => $item['code'],
            'category' => $item['categoryName'],
            'categoryId' => $item['categoryId'],
            'price' => self::centsToMoney($item['unitPriceCents']),
            'originalPrice' => self::centsToMoney($item['originalUnitPriceCents']),
            'productVersion' => $item['productVersion'],
            'skuVersion' => $item['skuVersion'],
            'stockText' => $isInventoryProduct
                ? '库存 ' . (string)($inventory['quantity'] ?? '0')
                : '',
            'stockWarning' => $disabled && $reason === '库存不足',
            'disabled' => $disabled,
            'disabledReason' => $reason,
        ];
    }

    private static function publicCardPreview(array $item): ?array
    {
        if (($item['kindCode'] ?? '') !== 'card_package'
            || !in_array($item['cardRuleType'] ?? '', ['normal', 'choice_kind', 'choice_count', 'time'], true)) {
            return null;
        }

        $purchase = (array)($item['authoritySnapshot']['cardPurchase'] ?? []);
        $validity = (array)($purchase['validity'] ?? []);
        $components = [];
        foreach ((array)($purchase['components'] ?? []) as $component) {
            $components[] = [
                'name' => (string)($component['nameSnapshot'] ?? ''),
                'specification' => (string)($component['skuNameSnapshot'] ?? ''),
                'writeTimes' => (int)($component['writeTimes'] ?? 0),
                'writeoffAmountCents' => (int)($component['writeoffAmountCents'] ?? 0),
            ];
        }

        return [
            'ruleType' => (string)($purchase['ruleType'] ?? ''),
            'ruleVersion' => (int)($purchase['ruleVersion'] ?? 0),
            'choiceLimit' => (int)($purchase['choiceLimit'] ?? 0),
            'sharedTimes' => (int)($purchase['sharedTimes'] ?? 0),
            'validity' => [
                'writeValid' => (int)($validity['writeValid'] ?? 0),
                'writeDays' => (int)($validity['writeDays'] ?? 0),
                'writeStart' => (int)($validity['writeStart'] ?? 0),
                'writeEnd' => (int)($validity['writeEnd'] ?? 0),
            ],
            'components' => $components,
        ];
    }

    private static function cardRuleLabel(string $ruleType): string
    {
        $labels = [
            'normal' => '普通卡',
            'choice_kind' => '任选种数卡',
            'choice_count' => '任选次数卡',
            'time' => '时间卡',
        ];
        return $labels[$ruleType] ?? '';
    }

    private function displaySnapshot(array $item): array
    {
        return [
            'catalogItemId' => $item['skuId'],
            'productId' => $item['productId'],
            'skuId' => $item['skuId'],
            // Keep the immutable inventory identity in the line snapshot.
            // The final settlement must use the same SKU that was shown when
            // the browser added the item; dropping it here produces an empty
            // inventory lookup at submit time.
            'skuUnique' => $item['skuUnique'],
            'productType' => $item['productType'],
            'kindCode' => $item['kindCode'],
            'kind' => $item['kind'],
            'name' => $item['name'],
            'specification' => $item['specification'],
            'code' => $item['code'],
            'categoryId' => $item['categoryId'],
            'category' => $item['categoryName'],
            'unitPrice' => self::centsToMoney($item['unitPriceCents']),
            'originalUnitPrice' => self::centsToMoney($item['originalUnitPriceCents']),
            'serviceSource' => '本次购买',
        ];
    }

    private static function kind(int $productType, int $productId, int $pid): array
    {
        // 历史定制卡壳是 pid=8154 的隐藏项目副本。必须在“项目”分支前
        // 识别它，才能让定制配置走卡项签发而非项目服务流程。
        if ($productId === self::CUSTOM_CARD_SHELL_PRODUCT_ID || $pid === self::CUSTOM_CARD_SHELL_PRODUCT_ID) {
            return ['code' => 'custom_card', 'label' => '定制卡'];
        }
        if ($productType === 6) {
            return ['code' => 'project', 'label' => '项目'];
        }
        if ($productType === 0) {
            return ['code' => 'product', 'label' => '产品'];
        }
        if ($productType === 4) {
            return ['code' => 'count_card', 'label' => '卡项'];
        }
        // 旧商品表没有时间卡/普通卡项的权威子类型列，不能按名称猜测。
        return ['code' => 'card_package', 'label' => '卡项'];
    }

    private function assertStoreScope(
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): void {
        $storeId = $operatorScope->storeId();
        if ($storeId <= 0 || (!$dataScope->allowsStore($storeId) && !$dataScope->isSuperAdmin())) {
            throw self::failure(
                CashierV3ResultCode::PERMISSION_DENIED,
                '当前账号没有在本店查看或选择商品的权限。',
                'cashier_catalog_store_scope_denied'
            );
        }
    }

    private static function positiveId($value, string $field): int
    {
        if (is_bool($value) || is_array($value) || is_object($value) || $value === null) {
            throw self::invalidField($field);
        }
        $raw = trim((string)$value);
        if (preg_match('/^[1-9][0-9]*$/D', $raw) !== 1 || (string)(int)$raw !== $raw) {
            throw self::invalidField($field);
        }
        return (int)$raw;
    }

    private static function positiveIds(string $csv): array
    {
        $ids = [];
        foreach (explode(',', $csv) as $raw) {
            $raw = trim($raw);
            if (preg_match('/^[1-9][0-9]*$/D', $raw) === 1) {
                $ids[(int)$raw] = true;
            }
        }
        return array_keys($ids);
    }

    private static function moneyToCents($value, string $field): int
    {
        if (is_bool($value) || is_array($value) || is_object($value) || $value === null) {
            throw self::invalidAuthorityField($field);
        }
        $raw = trim((string)$value);
        if (preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/D', $raw) !== 1) {
            throw self::invalidAuthorityField($field);
        }
        $parts = explode('.', $raw, 2);
        $whole = $parts[0];
        $fraction = str_pad($parts[1] ?? '', 2, '0');
        $cents = $whole . substr($fraction, 0, 2);
        $cents = ltrim($cents, '0');
        $cents = $cents === '' ? '0' : $cents;
        if (strlen($cents) > 18 || (strlen($cents) === 18 && strcmp($cents, (string)PHP_INT_MAX) > 0)) {
            throw self::invalidAuthorityField($field);
        }
        return (int)$cents;
    }

    private static function nonnegativeDecimal($value, string $field, bool $allowNegative): string
    {
        if (is_bool($value) || is_array($value) || is_object($value) || $value === null) {
            throw self::invalidAuthorityField($field);
        }
        $raw = trim((string)$value);
        $pattern = $allowNegative
            ? '/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]{1,4})?$/D'
            : '/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,4})?$/D';
        if (preg_match($pattern, $raw) !== 1) {
            throw self::invalidAuthorityField($field);
        }
        return bcadd($raw, '0', 4);
    }

    private static function fingerprintVersion(array $value): int
    {
        $hex = substr(hash('sha256', self::canonicalJson($value)), 0, 15);
        $version = intval($hex, 16);
        return $version > 0 ? $version : 1;
    }

    private static function canonicalJson(array $value): string
    {
        $canonical = self::canonicalize($value);
        $json = json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw self::invalidAuthorityField('authority_json');
        }
        return $json;
    }

    private static function canonicalize($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_keys($value) === ($value ? range(0, count($value) - 1) : [])) {
            return array_map([self::class, 'canonicalize'], $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }
        return $value;
    }

    private static function multiplyCents(int $unit, int $quantity): int
    {
        if ($unit < 0 || $quantity <= 0 || ($unit > 0 && $quantity > intdiv(PHP_INT_MAX, $unit))) {
            throw self::invalidAuthorityField('line_amount');
        }
        return $unit * $quantity;
    }

    private static function centsToMoney(int $cents): string
    {
        return bcdiv((string)$cents, '100', 2);
    }

    private static function trimDecimal(string $value): string
    {
        $value = rtrim(rtrim($value, '0'), '.');
        return $value === '' || $value === '-0' ? '0' : $value;
    }

    private static function decodeStoredObject(string $json, string $field): array
    {
        $decoded = $json !== '' ? json_decode($json, true) : null;
        if (!is_array($decoded) || ($decoded !== [] && array_keys($decoded) === range(0, count($decoded) - 1))) {
            throw self::invalidAuthorityField($field);
        }
        return $decoded;
    }

    private static function decodeStoredList(string $json, string $field): array
    {
        $decoded = $json === '' ? [] : json_decode($json, true);
        if (!is_array($decoded) || array_keys($decoded) !== ($decoded ? range(0, count($decoded) - 1) : [])) {
            throw self::invalidAuthorityField($field);
        }
        return $decoded;
    }

    private static function uniqueResources(array $resources): array
    {
        $result = [];
        $seen = [];
        foreach ($resources as $resource) {
            $key = (string)($resource['kind'] ?? '') . ':' . (string)($resource['id'] ?? '');
            if ($key === ':') {
                continue;
            }
            $version = (int)($resource['version'] ?? 0);
            if (isset($seen[$key])) {
                if ($seen[$key] !== $version) {
                    throw self::invalidAuthorityField('resource_version_conflict');
                }
                continue;
            }
            $seen[$key] = $version;
            $result[] = $resource;
        }
        return $result;
    }

    private static function invalidField(string $field): CashierV3CommandException
    {
        return self::failure(
            CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
            '请选择有效商品后再加入购物车。',
            'cashier_catalog_input_invalid',
            ['field' => $field]
        );
    }

    private static function invalidAuthorityField(string $field): CashierV3CommandException
    {
        return self::failure(
            CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
            '门店商品资料不完整，请联系管理员核对。',
            'cashier_catalog_authority_field_invalid',
            ['field' => $field]
        );
    }

    private static function notAvailable(int $skuId): CashierV3CommandException
    {
        return self::failure(
            CashierV3ResultCode::RESOURCE_NOT_FOUND,
            '该商品已下架、不属于当前门店或规格已失效，请重新选择。',
            'cashier_catalog_item_not_available',
            ['item_id' => $skuId]
        );
    }

    private static function failure(
        string $code,
        string $message,
        string $reason,
        array $extra = []
    ): CashierV3CommandException {
        return new CashierV3CommandException(
            $code,
            $message,
            CashierV3ResultCode::STATUS_FAILED,
            ['reason' => $reason] + $extra
        );
    }
}
