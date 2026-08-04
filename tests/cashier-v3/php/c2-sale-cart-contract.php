<?php
/**
 * C2 sale catalog/cart contract. Database-free; MySQL locking is covered by the
 * dedicated matrix when the shared Docker window is available.
 */

$backendRoot = getenv('C2_SALE_BACKEND_ROOT');
$backendRoot = is_string($backendRoot) && $backendRoot !== ''
    ? rtrim($backendRoot, '/')
    : __DIR__ . '/../../../后端代码';

require_once $backendRoot . '/app/services/cashier/v3/CashierV3ResultCode.php';
require_once $backendRoot . '/app/services/cashier/v3/CashierV3CommandException.php';
require_once $backendRoot . '/app/services/cashier/v3/CashierV3ResourceScope.php';
require_once $backendRoot . '/app/services/cashier/v3/CashierV3ResourceKindCatalog.php';
require_once $backendRoot . '/app/services/cashier/v3/CashierV3OperatorScope.php';
require_once $backendRoot . '/app/services/cashier/v3/CashierV3DataScopeContext.php';
require_once $backendRoot . '/app/services/cashier/v3/cashier/CashierV3CashierReadinessGuard.php';
require_once $backendRoot . '/app/services/cashier/v3/cashier/CashierV3SaleCatalogAuthority.php';
require_once $backendRoot . '/app/services/cashier/v3/cashier/ThinkPhpCashierV3SaleCatalogAuthority.php';
require_once $backendRoot . '/app/services/cashier/v3/cashier/CashierV3SaleCatalogServices.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\cashier\CashierV3SaleCatalogAuthority;
use app\services\cashier\v3\cashier\CashierV3SaleCatalogServices;

$passed = 0;
$failed = 0;

function saleAssert(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    echo "FAIL {$name}" . ($detail === '' ? '' : ': ' . $detail) . "\n";
}

function saleRawItem(
    int $productId,
    int $skuId,
    int $productType,
    string $name,
    string $price,
    string $stock = '10.0000',
    int $isInventory = 0,
    int $allowNegative = 1,
    int $pid = 0
): array {
    return [
        'product_id' => $productId,
        'product_pid' => $pid,
        'product_type' => 1,
        'product_relation_id' => 7,
        'product_product_type' => $productType,
        'product_store_name' => $name,
        'product_cate_id' => '10',
        'product_keyword' => '',
        'product_unit_name' => '件',
        'product_is_show' => 1,
        'product_is_del' => 0,
        'product_is_verify' => 1,
        'product_is_inventory' => $isInventory,
        'product_allow_negative_stock' => $allowNegative,
        'product_card_num' => 0,
        'product_card_num_type' => 0,
        'product_card_rule_type' => '',
        'product_card_rule_version' => 0,
        'product_card_choice_limit' => 0,
        'product_card_shared_times' => 0,
        'sku_id' => $skuId,
        'sku_product_id' => $productId,
        'sku_product_type' => $productType,
        'sku_unique' => 'SKU-' . $skuId,
        'sku_suk' => '默认',
        'sku_price' => $price,
        'sku_ot_price' => $price,
        'sku_stock' => $stock,
        'sku_code' => 'CODE-' . $skuId,
        'sku_bar_code' => '',
        'sku_is_show' => 1,
        'sku_type' => 0,
        'sku_write_times' => $productType === 4 ? 10 : 0,
        'sku_write_valid' => in_array($productType, [4, 5], true) ? 1 : 0,
        'sku_write_days' => 0,
        'sku_write_start' => 0,
        'sku_write_end' => 0,
        'category_names' => ['护理'],
        'card_components' => [],
    ];
}

final class SaleFakeAuthority implements CashierV3SaleCatalogAuthority
{
    private $rows;

    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    public function listStoreItems(int $storeId): array
    {
        return $storeId === 7 ? $this->rows : [];
    }

    public function lockStoreItemBySkuId(int $storeId, int $skuId)
    {
        foreach ($this->rows as $row) {
            if ($storeId === 7 && (int)$row['sku_id'] === $skuId) {
                return $row;
            }
        }
        return null;
    }

    public function readStoreItemBySkuId(int $storeId, int $skuId)
    {
        return $this->lockStoreItemBySkuId($storeId, $skuId);
    }

    public function lockStoreResourceRow(int $storeId, string $kind, int $resourceId): bool
    {
        return $storeId === 7
            && in_array($kind, ['catalog_card_definition', 'catalog_product', 'catalog_sku'], true)
            && $resourceId > 0;
    }
}

$scope = new CashierV3OperatorScope(7, 99, 'org-1', 'tenant-1');
$dataScope = new CashierV3DataScopeContext(
    99,
    88,
    7,
    'tenant-1',
    'org-1',
    [7],
    CashierV3DataScopeContext::MODE_STORES,
    [],
    false,
    '',
    'permission-v1',
    ['cashier.v3.cashier'],
    ['name' => 'tester']
);
$timeCardRow = saleRawItem(106, 1007, 5, '季度护理时间卡', '1280.00');
$timeCardRow['product_card_rule_type'] = 'time';
$timeCardRow['product_card_rule_version'] = 1;
$timeCardRow['sku_write_valid'] = 2;
$timeCardRow['sku_write_days'] = 90;
$rows = [
    saleRawItem(101, 1001, 6, '护理项目', '128.50'),
    saleRawItem(102, 1002, 0, '家居产品', '39.90', '0.0000', 1, 0),
    saleRawItem(103, 1003, 0, '允许负库存产品', '59.00', '0.0000', 1, 1),
    saleRawItem(104, 1004, 4, '十次护理卡', '980.00'),
    // 名称含“时间卡”也不能代替不存在的权威子类型列。
    saleRawItem(105, 1005, 5, '时间卡展示名', '1680.00'),
    $timeCardRow,
    saleRawItem(8154, 1006, 5, '定制卡', '0.00'),
];
$service = new CashierV3SaleCatalogServices(new SaleFakeAuthority($rows));
$catalog = $service->catalog($scope, $dataScope);
$byId = [];
foreach ($catalog['items'] as $item) {
    $byId[(int)$item['id']] = $item;
}

saleAssert('SALE-CAT-01 contract and complete catalog',
    $catalog['contractVersion'] === CashierV3SaleCatalogServices::CONTRACT_VERSION
    && $catalog['complete'] === true && $catalog['itemCount'] === 6);
saleAssert('SALE-CAT-02 item identity is authoritative SKU id',
    $byId[1001]['id'] === 1001 && $byId[1001]['productId'] === 101);
saleAssert('SALE-CAT-03 server SKU price is exposed without client input',
    $byId[1001]['price'] === '128.50' && $catalog['priceAuthority'] === 'store_product_attr_value.price');
saleAssert('SALE-CAT-04 product/project/count-card categories are deterministic',
    $byId[1001]['kindCode'] === 'project'
    && $byId[1003]['kindCode'] === 'product'
    && $byId[1004]['kindCode'] === 'count_card'
    && $byId[1004]['kind'] === '卡项'
    && $catalog['types'] === ['项目', '产品', '卡项', '定制卡']);
saleAssert('SALE-CAT-05 unknown card subtype is not guessed from name',
    $byId[1005]['kindCode'] === 'card_package' && $byId[1005]['kind'] === '卡项');
saleAssert('SALE-CAT-05A time-card subtype is explicit and remains under card packages',
    $byId[1007]['kindCode'] === 'card_package'
    && $byId[1007]['cardRuleType'] === 'time'
    && $byId[1007]['cardRuleLabel'] === '时间卡');
saleAssert('SALE-CAT-06 custom-card shell is hidden from normal catalog sales',
    !isset($byId[1006]));
saleAssert('SALE-CAT-07 tracked stock blocks only when negative stock is forbidden',
    $byId[1002]['disabled'] === true && $byId[1003]['disabled'] === false);

$legacyCustomShell = saleRawItem(8155, 1007, 6, '定制卡', '0.00', '10.0000', 0, 1, 8154);
$legacyCustomShell['product_is_show'] = 0;
$legacyCustomShell['sku_write_valid'] = 1;
$legacyCustomShell['sku_write_days'] = 1;
$legacyCustomService = new CashierV3SaleCatalogServices(new SaleFakeAuthority([$legacyCustomShell]));
$legacyCustomCatalog = $legacyCustomService->catalog($scope, $dataScope);
$legacyCustomResources = $legacyCustomService->discoverCustomCardShellResources($scope, $dataScope);
saleAssert('SALE-CAT-08 hidden legacy custom-card shell stays out of normal catalog',
    $legacyCustomCatalog['items'] === []);
saleAssert('SALE-CAT-09 hidden legacy custom-card shell remains version-lockable for configuration',
    in_array('catalog_card_definition', array_column($legacyCustomResources, 'kind'), true)
    && in_array('catalog_product', array_column($legacyCustomResources, 'kind'), true)
    && in_array('catalog_sku', array_column($legacyCustomResources, 'kind'), true),
    json_encode($legacyCustomResources));
saleAssert('SALE-CAT-08 categories are sourced from authority',
    $catalog['categories'] === ['全部', '护理']);

$normalize = new ReflectionMethod(CashierV3SaleCatalogServices::class, 'normalizeAuthorityRow');
$normalize->setAccessible(true);
$legacyCardSkuType = saleRawItem(109, 1010, 5, '历史普通卡项', '680.00');
$legacyCardSkuType['sku_product_type'] = 0;
$normalizedLegacyCardSkuType = $normalize->invoke($service, $legacyCardSkuType, false);
saleAssert('SALE-CAT-09 legacy card SKU type=0 remains a valid card-package authority row',
    $normalizedLegacyCardSkuType['kindCode'] === 'card_package'
    && $normalizedLegacyCardSkuType['productId'] === 109
    && $normalizedLegacyCardSkuType['skuId'] === 1010);
$componentRow = saleRawItem(107, 1008, 6, '卡内护理项目', '168.00');
$cardRow = saleRawItem(106, 1007, 5, '护理组合卡', '1280.00');
$cardRow['card_components'] = [[
    'relation' => [
        'id' => 501,
        'card_product_id' => 106,
        'product_id' => 107,
        'product_type' => 6,
        'product_attr_unique' => 'SKU-1008',
        'cost' => '80.00',
        'price' => '168.00',
        'write_times' => 6,
        'writeoff_amount' => '0.00',
        'status' => 1,
    ],
    'item' => $componentRow,
]];
$normalizedCard = $normalize->invoke($service, $cardRow, true);
$cardResources = array_map(static function (array $resource): string {
    return (string)$resource['kind'] . ':' . (string)$resource['id'];
}, $normalizedCard['authoritySnapshot']['resourceSources']);
saleAssert('SALE-CARD-03 card package keeps an explicit source kind',
    $normalizedCard['authoritySnapshot']['cardPurchase']['sourceKind'] === 'card_package');
saleAssert('SALE-CARD-04 card resources are stable and de-duplicated',
    $cardResources === [
        'catalog_product:106',
        'catalog_sku:1007',
        'catalog_product:107',
        'catalog_sku:1008',
        'catalog_card_definition:106',
    ] && count($cardResources) === count(array_unique($cardResources)));
$changedCardRow = $cardRow;
$changedCardRow['card_components'][0]['relation']['write_times'] = 7;
$changedCard = $normalize->invoke($service, $changedCardRow, true);
saleAssert('SALE-CARD-05 card definition changes when a component grant changes',
    $changedCard['authoritySnapshot']['cardPurchase']['definitionVersion']
        !== $normalizedCard['authoritySnapshot']['cardPurchase']['definitionVersion']
    && $changedCard['authorityFingerprint'] !== $normalizedCard['authorityFingerprint']);

$choiceKindRow = $cardRow;
$choiceKindRow['product_card_rule_type'] = 'choice_kind';
$choiceKindRow['product_card_rule_version'] = 1;
$choiceKindRow['product_card_choice_limit'] = 1;
$choiceKindCard = $normalize->invoke($service, $choiceKindRow, true);
saleAssert('SALE-CARD-RULE-01 choice-kind header and component grants enter immutable authority',
    $choiceKindCard['authoritySnapshot']['cardPurchase']['ruleType'] === 'choice_kind'
    && $choiceKindCard['authoritySnapshot']['cardPurchase']['ruleVersion'] === 1
    && $choiceKindCard['authoritySnapshot']['cardPurchase']['choiceLimit'] === 1
    && $choiceKindCard['authoritySnapshot']['cardPurchase']['components'][0]['writeTimes'] === 6);

$choiceCountRow = $cardRow;
$choiceCountRow['product_card_rule_type'] = 'choice_count';
$choiceCountRow['product_card_rule_version'] = 1;
$choiceCountRow['product_card_shared_times'] = 12;
$choiceCountRow['card_components'][0]['relation']['write_times'] = 0;
$choiceCountCard = $normalize->invoke($service, $choiceCountRow, true);
saleAssert('SALE-CARD-RULE-02 choice-count freezes one shared pool and zero component counters',
    $choiceCountCard['authoritySnapshot']['cardPurchase']['sharedTimes'] === 12
    && $choiceCountCard['authoritySnapshot']['cardPurchase']['components'][0]['writeTimes'] === 0);

$timeRow = $cardRow;
$timeRow['product_card_rule_type'] = 'time';
$timeRow['product_card_rule_version'] = 1;
$timeRow['sku_write_valid'] = 2;
$timeRow['sku_write_days'] = 365;
$timeRow['card_components'][0]['relation']['write_times'] = 0;
$timeRow['card_components'][0]['relation']['writeoff_amount'] = '88.50';
$timeCard = $normalize->invoke($service, $timeRow, true);
saleAssert('SALE-CARD-RULE-03 time card freezes per-service write-off amount in integer cents',
    $timeCard['authoritySnapshot']['cardPurchase']['ruleType'] === 'time'
    && $timeCard['authoritySnapshot']['cardPurchase']['components'][0]['writeoffAmountCents'] === 8850);
$changedTimeRow = $timeRow;
$changedTimeRow['card_components'][0]['relation']['writeoff_amount'] = '99.00';
$changedTimeCard = $normalize->invoke($service, $changedTimeRow, true);
saleAssert('SALE-CARD-RULE-04 write-off amount drift changes definition and authority fingerprints',
    $changedTimeCard['authoritySnapshot']['cardPurchase']['definitionVersion']
        !== $timeCard['authoritySnapshot']['cardPurchase']['definitionVersion']
    && $changedTimeCard['authorityFingerprint'] !== $timeCard['authorityFingerprint']);
$permanentTimeRow = $timeRow;
$permanentTimeRow['sku_write_valid'] = 1;
$permanentTimeReason = '';
try {
    $normalize->invoke($service, $permanentTimeRow, true);
} catch (\app\services\cashier\v3\CashierV3CommandException $exception) {
    $permanentTimeReason = (string)($exception->getDetail()['reason'] ?? '');
}
saleAssert('SALE-CARD-RULE-05 time card rejects permanent validity in cashier authority',
    $permanentTimeReason === 'card_validity_invalid');

$invalidCountCard = saleRawItem(108, 1009, 4, '无次数次卡', '100.00');
$invalidCountCard['sku_write_times'] = 0;
$invalidCountReason = '';
try {
    $normalize->invoke($service, $invalidCountCard, true);
} catch (\app\services\cashier\v3\CashierV3CommandException $exception) {
    $invalidCountReason = (string)($exception->getDetail()['reason'] ?? '');
}
saleAssert('SALE-CARD-06 count card requires a positive authoritative writeTimes',
    $invalidCountReason === 'count_card_write_times_invalid');

$daysCard = saleRawItem(112, 1013, 5, '购买后有效卡项', '680.00');
$daysCard['sku_write_valid'] = 2;
$daysCard['sku_write_days'] = 365;
$normalizedDaysCard = $normalize->invoke($service, $daysCard, false);
saleAssert('SALE-CARD-07 write_valid=2 reads positive write_days from the production column',
    $normalizedDaysCard['authoritySnapshot']['cardPurchase']['validity'] === [
        'writeValid' => 2,
        'writeDays' => 365,
        'writeStart' => 0,
        'writeEnd' => 0,
        'writeTimes' => 0,
    ]);
$adapter = new \app\services\cashier\v3\cashier\ThinkPhpCashierV3SaleCatalogAuthority();
$lockedComponents = new ReflectionMethod($adapter, 'lockedCardComponents');
$lockedComponents->setAccessible(true);
$duplicateComponentResult = $lockedComponents->invoke(
    $adapter,
    7,
    [$cardRow['card_components'][0]['relation']],
    [107 => ['id' => 107, 'type' => 1, 'relation_id' => 7]],
    [
        1008 => ['id' => 1008, 'product_id' => 107, 'unique' => 'SKU-1008'],
        1014 => ['id' => 1014, 'product_id' => 107, 'unique' => 'SKU-1008'],
    ]
);
saleAssert('SALE-CARD-08 duplicate component SKU identities fail closed instead of overwriting',
    count($duplicateComponentResult) === 1
    && $duplicateComponentResult[0]['item'] === null);

$unavailableComponent = $componentRow;
$unavailableComponent['product_is_show'] = 0;
$unavailableCard = $cardRow;
$unavailableCard['card_components'][0]['item'] = $unavailableComponent;
$unavailableCatalog = (new CashierV3SaleCatalogServices(new SaleFakeAuthority([$unavailableCard])))
    ->catalog($scope, $dataScope);
$unavailablePublicItem = $unavailableCatalog['items'][0] ?? [];
saleAssert('SALE-CAT-10 parent-card availability does not depend on a hidden card component',
    ($unavailablePublicItem['disabled'] ?? true) === false
    && (($unavailablePublicItem['kind'] ?? '') === '卡项'));

$hiddenComponentVersion = (new CashierV3SaleCatalogServices(
    new SaleFakeAuthority([$unavailableComponent])
))->readResourceVersion(
    'catalog_product',
    107,
    1008,
    $scope,
    $dataScope
);
saleAssert('SALE-CARD-09 hidden card component remains version-lockable for an active parent card',
    $hiddenComponentVersion > 0);

$priceA = $normalize->invoke($service, saleRawItem(109, 1010, 6, '价格项目', '128.50'), false);
$priceB = $normalize->invoke($service, saleRawItem(109, 1010, 6, '价格项目', '129.00'), false);
saleAssert('SALE-PRICE-01 server money is frozen as exact integer cents',
    $priceA['unitPriceCents'] === 12850 && $priceA['originalUnitPriceCents'] === 12850);
saleAssert('SALE-PRICE-02 authoritative price drift changes SKU version and line fingerprint',
    $priceA['skuVersion'] !== $priceB['skuVersion']
    && $priceA['authorityFingerprint'] !== $priceB['authorityFingerprint']);

$assertPurchasable = new ReflectionMethod(CashierV3SaleCatalogServices::class, 'assertPurchasable');
$assertPurchasable->setAccessible(true);
$blockedStock = $normalize->invoke(
    $service,
    saleRawItem(110, 1011, 0, '库存产品', '39.90', '0.0000', 1, 0),
    false
);
$legacyDisplayStockResult = true;
try {
    $assertPurchasable->invoke($service, $blockedStock, 1);
} catch (Throwable $exception) {
    $legacyDisplayStockResult = false;
}
$allowedNegativeStock = $normalize->invoke(
    $service,
    saleRawItem(111, 1012, 0, '允许负库存产品', '39.90', '0.0000', 1, 1),
    false
);
$allowedNegativeResult = true;
try {
    $assertPurchasable->invoke($service, $allowedNegativeStock, 1);
} catch (Throwable $exception) {
    $allowedNegativeResult = false;
}
saleAssert('SALE-STOCK-01 legacy display stock does not override V3 batch authority',
    $legacyDisplayStockResult === true);
saleAssert('SALE-STOCK-02 authoritative allow-negative policy permits draft validation',
    $allowedNegativeResult === true);

$workspaceSource = file_get_contents($backendRoot . '/app/services/cashier/v3/cashier/CashierV3CashierWorkspaceServices.php');
$moduleSource = file_get_contents($backendRoot . '/app/services/cashier/v3/cashier/CashierV3CashierModule.php');
$catalogSource = file_get_contents($backendRoot . '/app/services/cashier/v3/cashier/CashierV3SaleCatalogServices.php');
$catalogAuthoritySource = file_get_contents($backendRoot . '/app/services/cashier/v3/cashier/ThinkPhpCashierV3SaleCatalogAuthority.php');
$catalogVersionProviderSource = file_get_contents($backendRoot . '/app/services/cashier/v3/cashier/CashierV3SaleCatalogResourceVersionProvider.php');
$providerSource = file_get_contents($backendRoot . '/app/services/cashier/v3/cashier/CashierV3CashierPartitionProvider.php');
$migration = file_get_contents($backendRoot . '/database/upgrades/2026-07-29-收银V3销售购物车权威行/02-正式升级.sql');
$migrationManifest = file_get_contents($backendRoot . '/database/upgrades/2026-07-29-收银V3销售购物车权威行/00-升级清单.md');

saleAssert('SALE-CART-01 each click appends an intent-keyed independent line',
    strpos($catalogSource, "'sale:' . substr(hash('sha256', \$idempotencyKey), 0, 48)") !== false
    && strpos($workspaceSource, 'appendSaleLineInTx') !== false);
saleAssert('SALE-CART-02 quantity change revalidates sale authority',
    strpos($moduleSource, 'assertStoredSaleQuantityAfterGatewayLocksInTx') !== false
    && strpos($catalogSource, 'assertInventoryAvailableForSale') !== false);
saleAssert('SALE-CART-03 sale totals are calculated from persisted integer cents',
    strpos($workspaceSource, "'unitPriceCents'") !== false
    && strpos($workspaceSource, "'lineAmountCents'") !== false
    && strpos($workspaceSource, "'sale_receivable'") !== false);
saleAssert('SALE-CART-04 prepare-checkout source DTO is directly available',
    strpos($workspaceSource, 'checkoutSaleSourceSetInTx') !== false
    && strpos($catalogSource, 'checkoutSourceLineInTx') !== false
    && strpos($catalogSource, "'resourceSources'") !== false);
saleAssert('SALE-CARD-01 card creation snapshot freezes validity and components',
    strpos($catalogSource, "'writeValid'") !== false
    && strpos($catalogSource, "'writeDays'") !== false
    && strpos($catalogSource, "sku_write_days") !== false
    && strpos($catalogSource, "'writeStart'") !== false
    && strpos($catalogSource, "'writeEnd'") !== false
    && strpos($catalogSource, "'components'") !== false
    && strpos($catalogSource, "'writeTimes'") !== false);
saleAssert('SALE-CARD-02 product SKU and card definition resources enter source set',
    strpos($catalogSource, "'catalog_product'") !== false
    && strpos($catalogSource, "'catalog_sku'") !== false
    && strpos($catalogSource, "'catalog_card_definition'") !== false);
saleAssert('SALE-CARD-02A hidden legacy custom-card host remains a scoped version anchor only',
    strpos($catalogVersionProviderSource, "->field('id,product_type,pid,is_del,is_verify')") !== false
    && strpos($catalogVersionProviderSource, "(int)(\$product['pid'] ?? 0) === 8154") !== false
    && strpos($catalogVersionProviderSource, "(int)(\$product['is_del'] ?? 1) !== 0") !== false
    && strpos($catalogVersionProviderSource, "(int)(\$product['is_verify'] ?? 0) !== 1") !== false
    && strpos($catalogVersionProviderSource, "(int)\$product['product_type'] !== 5") !== false);
$productLockPosition = strpos($catalogAuthoritySource, '$lockedProducts = $this->lockProductsById');
$skuLockPosition = strpos($catalogAuthoritySource, '$lockedSkus = $this->lockSkusById');
$relationLockPosition = strpos(
    $catalogAuthoritySource,
    '$this->lockCardDefinitionProduct($productId)'
);
saleAssert('SALE-LOCK-00 canonical decimal IDs use numeric order without integer comparator overflow',
    \app\services\cashier\v3\CashierV3ResourceKindCatalog::compareResourceIds('2', '10') < 0
    && \app\services\cashier\v3\CashierV3ResourceKindCatalog::compareResourceIds('10', '2') > 0
    && \app\services\cashier\v3\CashierV3ResourceKindCatalog::compareResourceIds('02', '10') < 0);
saleAssert('SALE-LOCK-01 product and SKU locks are exact and reuse the global resource comparator',
    $productLockPosition !== false && $skuLockPosition !== false && $relationLockPosition !== false
    && $relationLockPosition < $productLockPosition && $productLockPosition < $skuLockPosition
    && strpos($catalogAuthoritySource, "sortedResourceIds('catalog_product', \$ids)") !== false
    && strpos($catalogAuthoritySource, "sortedResourceIds('catalog_sku', \$ids)") !== false
    && strpos($catalogAuthoritySource, 'CashierV3ResourceKindCatalog::compareResources') !== false
    && strpos($catalogAuthoritySource, "->where('id', (int)\$id)") !== false);
saleAssert('SALE-LOCK-02 card relation drift is rejected after the exact parent lock',
    strpos($catalogAuthoritySource, 'sameRelationPlan($plannedRelations, $lockedRelations)') !== false
    && strpos($catalogAuthoritySource, "readActiveCardRelations(\$productId, true)") === false);
$saleReadiness = new \app\services\cashier\v3\cashier\CashierV3CashierReadinessGuard();
$saleReadiness->setInspector(static function (): array {
    return \app\services\cashier\v3\cashier\CashierV3CashierReadinessGuard::REQUIRED_TABLES;
});
$saleReadinessReady = true;
try {
    $saleReadiness->assertSaleCatalogReady();
} catch (\app\services\cashier\v3\CashierV3CommandException $exception) {
    $saleReadinessReady = false;
}
saleAssert('SALE-LOCK-03 audited writer and Gateway locks activate the sale catalog',
    $saleReadinessReady
    && \app\services\cashier\v3\cashier\CashierV3CashierReadinessGuard::CARD_DEFINITION_WRITER_LOCK_CONTRACT === 'ready-v1'
    && \app\services\cashier\v3\cashier\CashierV3CashierReadinessGuard::COMPONENT_SKU_IDENTITY_WRITER_LOCK_CONTRACT === 'ready-v1'
    && \app\services\cashier\v3\cashier\CashierV3CashierReadinessGuard::GATEWAY_CATALOG_PRELOCK_CONTRACT === 'ready-v1'
    && strpos($moduleSource, 'new CashierV3SaleCatalogServices(null, $readiness)') !== false);
saleAssert('SALE-LOCK-04 compatibility contract requires the parent lock before relation writes',
    strpos($migrationManifest, 'catalog_card_definition(41)') !== false
    && strpos($migrationManifest, 'cashier_workspace(150)') !== false
    && strpos($migrationManifest, '不对关系表做范围锁') !== false
    && strpos($migrationManifest, '先锁对应卡商品主行') !== false
    && strpos($migrationManifest, '先锁所属商品主行') !== false
    && strpos($migrationManifest, 'readiness 必须 fail-closed') !== false);
saleAssert('SALE-ROOT-01 cashier root uses workspace catalog and selected member authority',
    strpos($providerSource, "return 'cashier';") !== false
    && strpos($providerSource, 'readDraftOrSyntheticGuest') !== false
    && strpos($providerSource, "'catalog' => \$catalog") !== false);
saleAssert('SALE-MOD-01 canonical command policy and optional root wiring are installed',
    strpos($moduleSource, "registerCommand('choose-catalog-item'") !== false
    && strpos($moduleSource, 'registerChooseCatalogItemPolicy') !== false
    && strpos($moduleSource, 'CashierV3RootDomainAssembler $assembler = null') !== false);
saleAssert('SALE-MIG-01 sale authority columns are canonical migration fields',
    strpos($migration, '`catalog_product_id`') !== false
    && strpos($migration, '`catalog_sku_id`') !== false
    && strpos($migration, '`unit_price_cents`') !== false
    && strpos($migration, '`authority_snapshot_json`') !== false
    && strpos($migration, '`idx_catalog_source`') !== false
    && strpos($migration, '`idx_c2_card_definition`') !== false);

echo "SALE_CART_CONTRACT assertions=" . ($passed + $failed) . " passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
