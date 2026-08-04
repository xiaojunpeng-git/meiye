<?php
/** Real ThinkPHP + MySQL 5.6 sale catalog authority integration. */

require '/tests/lib/boot-env.php';
require '/var/www/html/vendor/autoload.php';
require '/tests/lib/_lib.php';

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\cashier\CashierV3SaleCatalogServices;
use app\services\cashier\v3\cashier\ThinkPhpCashierV3SaleCatalogAuthority;
use think\facade\Db;

c1aBootThinkApp('/var/www/html/');

function saleAdapterReason(callable $callback): string
{
    try {
        $callback();
    } catch (CashierV3CommandException $exception) {
        return (string)($exception->getDetail()['reason'] ?? '');
    } catch (\Throwable $throwable) {
        return 'UNEXPECTED:' . get_class($throwable) . ':' . $throwable->getMessage();
    }
    return '';
}

function saleAdapterNormalize(
    ReflectionMethod $normalize,
    CashierV3SaleCatalogServices $service,
    array $row
): array {
    return $normalize->invoke($service, $row, true);
}

function saleAdapterLockedRow(ThinkPhpCashierV3SaleCatalogAuthority $authority, int $skuId): array
{
    return Db::transaction(static function () use ($authority, $skuId): array {
        $row = $authority->lockStoreItemBySkuId(7, $skuId);
        return is_array($row) ? $row : [];
    });
}

function saleAdapterContendedUpdate(string $sql): bool
{
    $pdo = new PDO(
        sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            getenv('DB_HOST') ?: '127.0.0.1',
            getenv('DB_PORT') ?: '3306',
            getenv('DB_DATABASE') ?: 'c2_sale_cart_fresh'
        ),
        getenv('DB_USERNAME') ?: 'root',
        getenv('DB_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    try {
        $pdo->exec('SET SESSION innodb_lock_wait_timeout=1');
        $pdo->beginTransaction();
        $pdo->exec($sql);
        $pdo->rollBack();
        return false;
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return (int)($exception->errorInfo[1] ?? 0) === 1205;
    }
}

$authority = new ThinkPhpCashierV3SaleCatalogAuthority();
$service = new CashierV3SaleCatalogServices($authority);
$normalize = new ReflectionMethod(CashierV3SaleCatalogServices::class, 'normalizeAuthorityRow');
$normalize->setAccessible(true);

$catalog = $authority->listStoreItems(7);
$listedCard = null;
foreach ($catalog as $row) {
    if ((int)($row['sku_id'] ?? 0) === 1000) {
        $listedCard = $row;
        break;
    }
}
ok(
    'real list reads write_days and the server SKU price',
    is_array($listedCard)
        && (int)$listedCard['sku_write_valid'] === 2
        && (int)$listedCard['sku_write_days'] === 365
        && (string)$listedCard['sku_price'] === '100.00',
    json_encode($listedCard, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    'SALE-ADAPTER-01'
);

$locked = saleAdapterLockedRow($authority, 1000);
$normalized = saleAdapterNormalize($normalize, $service, $locked);
$components = $normalized['authoritySnapshot']['cardPurchase']['components'];
ok(
    'real locked DTO freezes validity and both component grants',
    $normalized['authoritySnapshot']['cardPurchase']['validity']['writeDays'] === 365
        && count($components) === 2
        && $components[0]['configuredPriceCents'] === 1000
        && $components[0]['writeTimes'] === 1
        && $components[1]['configuredPriceCents'] === 2000,
    json_encode($components, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    'SALE-ADAPTER-02'
);

$heldLocks = Db::transaction(static function () use ($authority): array {
    $row = $authority->lockStoreItemBySkuId(7, 1000);
    return [
        'row' => is_array($row),
        'definition' => saleAdapterContendedUpdate(
            'UPDATE eb_store_product SET store_name=store_name WHERE id=100'
        ),
        'componentProduct' => saleAdapterContendedUpdate(
            'UPDATE eb_store_product SET store_name=store_name WHERE id=2'
        ),
        'componentSku' => saleAdapterContendedUpdate(
            'UPDATE eb_store_product_attr_value SET suk=suk WHERE id=2'
        ),
    ];
});
ok(
    'real adapter holds exact definition product and SKU locks until commit',
    $heldLocks === [
        'row' => true,
        'definition' => true,
        'componentProduct' => true,
        'componentSku' => true,
    ],
    json_encode($heldLocks),
    'SALE-ADAPTER-03'
);

$baselineDefinition = $normalized['authoritySnapshot']['cardPurchase']['definitionVersion'];
Db::name('store_card_related')->where('id', 1)->update([
    'cost' => '1.25',
    'price' => '11.50',
    'write_times' => 3,
]);
$drifted = saleAdapterNormalize($normalize, $service, saleAdapterLockedRow($authority, 1000));
ok(
    'relation cost price and write-times drift changes the definition fingerprint',
    $drifted['authoritySnapshot']['cardPurchase']['definitionVersion'] !== $baselineDefinition
        && $drifted['authoritySnapshot']['cardPurchase']['components'][0]['configuredCostCents'] === 125
        && $drifted['authoritySnapshot']['cardPurchase']['components'][0]['configuredPriceCents'] === 1150
        && $drifted['authoritySnapshot']['cardPurchase']['components'][0]['writeTimes'] === 3,
    '',
    'SALE-ADAPTER-04'
);
Db::name('store_card_related')->where('id', 1)->update([
    'cost' => '0.00',
    'price' => '10.00',
    'write_times' => 1,
]);

Db::name('store_card_related')->where('id', 2)->update(['status' => 0]);
$statusChanged = saleAdapterNormalize($normalize, $service, saleAdapterLockedRow($authority, 1000));
ok(
    'relation status drift removes the disabled component from the frozen definition',
    count($statusChanged['authoritySnapshot']['cardPurchase']['components']) === 1
        && $statusChanged['authoritySnapshot']['cardPurchase']['definitionVersion'] !== $baselineDefinition,
    '',
    'SALE-ADAPTER-05'
);
Db::name('store_card_related')->where('id', 2)->update(['status' => 1]);

Db::name('store_card_related')->insert([
    'id' => 5,
    'card_product_id' => 100,
    'product_id' => 2,
    'product_type' => 6,
    'product_attr_unique' => 'sku-2',
    'cost' => '0.00',
    'price' => '12.00',
    'write_times' => 2,
    'status' => 1,
]);
$added = saleAdapterNormalize($normalize, $service, saleAdapterLockedRow($authority, 1000));
ok(
    'relation addition enters the frozen component set and changes its fingerprint',
    count($added['authoritySnapshot']['cardPurchase']['components']) === 3
        && $added['authoritySnapshot']['cardPurchase']['definitionVersion'] !== $baselineDefinition,
    '',
    'SALE-ADAPTER-06'
);
Db::name('store_card_related')->where('id', 5)->delete();

Db::name('store_product')->insert([
    'id' => 20,
    'type' => 1,
    'relation_id' => 8,
    'product_type' => 6,
    'store_name' => 'cross-store-component',
]);
Db::name('store_product_attr_value')->insert([
    'id' => 20,
    'product_id' => 20,
    'product_type' => 6,
    'unique' => 'sku-20',
    'suk' => 'default',
    'price' => '30.00',
    'ot_price' => '30.00',
]);
Db::name('store_card_related')->insert([
    'id' => 6,
    'card_product_id' => 100,
    'product_id' => 20,
    'product_type' => 6,
    'product_attr_unique' => 'sku-20',
    'cost' => '0.00',
    'price' => '30.00',
    'write_times' => 1,
    'status' => 1,
]);
$crossStoreReason = saleAdapterReason(static function () use ($authority, $normalize, $service): void {
    saleAdapterNormalize($normalize, $service, saleAdapterLockedRow($authority, 1000));
});
ok(
    'cross-store component fails closed',
    $crossStoreReason === 'card_component_authority_missing',
    $crossStoreReason,
    'SALE-ADAPTER-07'
);
Db::name('store_card_related')->where('id', 6)->delete();
Db::name('store_product_attr_value')->where('id', 20)->delete();
Db::name('store_product')->where('id', 20)->delete();

Db::name('store_product_attr_value')->insert([
    'id' => 11,
    'product_id' => 10,
    'product_type' => 6,
    'unique' => 'sku-10',
    'suk' => 'duplicate',
    'price' => '20.00',
    'ot_price' => '20.00',
]);
$duplicateReason = saleAdapterReason(static function () use ($authority, $normalize, $service): void {
    saleAdapterNormalize($normalize, $service, saleAdapterLockedRow($authority, 1000));
});
ok(
    'duplicate product plus unique SKU identity fails closed',
    $duplicateReason === 'card_component_authority_missing',
    $duplicateReason,
    'SALE-ADAPTER-08'
);
Db::name('store_product_attr_value')->where('id', 11)->delete();

$restored = saleAdapterNormalize($normalize, $service, saleAdapterLockedRow($authority, 1000));
ok(
    'fixture restoration returns the original authority fingerprint',
    $restored['authoritySnapshot']['cardPurchase']['definitionVersion'] === $baselineDefinition
        && count($restored['authoritySnapshot']['cardPurchase']['components']) === 2,
    '',
    'SALE-ADAPTER-09'
);

finish('C2_SALE_CATALOG_ADAPTER_INTEGRATION');
