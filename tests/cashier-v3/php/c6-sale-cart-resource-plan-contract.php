<?php
/**
 * C6 card add-to-cart resource-plan contract.
 *
 * Card definitionVersion already fingerprints the active component relations
 * and each component's product/SKU version. The command therefore locks the
 * parent product, parent SKU and aggregate card definition only; child rows
 * must not be duplicated in the server resource plan.
 */

$backendRoot = getenv('C6_SALE_BACKEND_ROOT');
$backendRoot = is_string($backendRoot) && $backendRoot !== ''
    ? rtrim($backendRoot, '/')
    : __DIR__ . '/../../../后端代码';

require_once $backendRoot . '/app/services/cashier/v3/cashier/CashierV3SaleCatalogServices.php';

use app\services\cashier\v3\cashier\CashierV3SaleCatalogServices;

$passed = 0;
$failed = 0;

function c6Assert(string $name, bool $condition, string $detail = ''): void
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

$service = (new ReflectionClass(CashierV3SaleCatalogServices::class))->newInstanceWithoutConstructor();
$serverResources = new ReflectionMethod(CashierV3SaleCatalogServices::class, 'serverResources');
$serverResources->setAccessible(true);

$card = [
    'kindCode' => 'card_package',
    'productId' => 100,
    'skuId' => 1000,
    'authoritySnapshot' => [
        'resourceSources' => [
            ['kind' => 'catalog_product', 'id' => '100', 'version' => 11],
            ['kind' => 'catalog_sku', 'id' => '1000', 'version' => 12],
            ['kind' => 'catalog_product', 'id' => '201', 'version' => 21],
            ['kind' => 'catalog_sku', 'id' => '2001', 'version' => 22],
            ['kind' => 'catalog_card_definition', 'id' => '100', 'version' => 31],
        ],
    ],
];
$resources = $serverResources->invoke($service, $card);
$keys = array_map(static function (array $resource): string {
    return $resource['kind'] . ':' . $resource['id'];
}, $resources);
c6Assert(
    'C6-RES-01 card plan keeps parent product/SKU and aggregate definition',
    $keys === ['catalog_product:100', 'catalog_sku:1000', 'catalog_card_definition:100'],
    json_encode($keys, JSON_UNESCAPED_UNICODE)
);

$project = $card;
$project['kindCode'] = 'project';
$project['authoritySnapshot']['resourceSources'] = [
    ['kind' => 'catalog_product', 'id' => '100', 'version' => 11],
    ['kind' => 'catalog_sku', 'id' => '1000', 'version' => 12],
];
$serviceSource = file_get_contents(
    $backendRoot . '/app/services/cashier/v3/cashier/CashierV3SaleCatalogServices.php'
);
c6Assert(
    'C6-RES-02 ordinary project discovery keeps only purchasable precheck',
    is_string($serviceSource)
        && strpos($serviceSource, 'return [];') !== false
        && strpos($serviceSource, '$normalized = $this->lockedActiveItem(') !== false
);

$authoritySource = file_get_contents(
    $backendRoot . '/app/services/cashier/v3/cashier/ThinkPhpCashierV3SaleCatalogAuthority.php'
);
c6Assert(
    'C6-RES-03 non-card add uses one joined locked catalog row',
    is_string($authoritySource)
        && strpos($authoritySource, 'if ($plannedProductType !== 5)') !== false
        && strpos($authoritySource, "->join('store_product p', 'p.id=sku.product_id')") !== false
        && strpos($authoritySource, '->lock(true)') !== false
        && strpos($authoritySource, "\$row['card_components'] = []") !== false
);

$moduleSource = file_get_contents(
    $backendRoot . '/app/services/cashier/v3/cashier/CashierV3CashierModule.php'
);
$workbenchSource = file_get_contents(
    dirname($backendRoot) . '/前端代码/cashier-v3/src/views/CashierWorkbenchView.vue'
);
c6Assert(
    'C6-RES-04 ordinary catalog hint skips preflight discovery but card hint retains it',
    is_string($moduleSource)
        && strpos($moduleSource, '$catalogKindHint = trim((string)($payload[\'catalogKind\'] ?? \'\'));') !== false
        && strpos($moduleSource, "!in_array(\$catalogKindHint, ['卡项', '定制卡'], true)") !== false
        && is_string($workbenchSource)
        && strpos($workbenchSource, "requestAction('choose-catalog-item', { itemId, catalogKind })") !== false
);
c6Assert(
    'C6-RES-05 card confirmation keeps catalog kind hint',
    is_string($workbenchSource)
        && strpos($workbenchSource, 'const result = await appendCatalogItemToDraft(item)') !== false
        && strpos($workbenchSource, 'appendCatalogItemToDraft(item.id)') === false
);

echo "{$passed}/" . ($passed + $failed) . " PASS\n";
exit($failed === 0 ? 0 : 1);
