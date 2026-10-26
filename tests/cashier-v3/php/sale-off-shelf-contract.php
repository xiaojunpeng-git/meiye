<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/后端代码/vendor/autoload.php';

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\cashier\CashierV3SaleCatalogServices;

$assertAvailability = new ReflectionMethod(CashierV3SaleCatalogServices::class, 'assertNewSaleAvailability');
$active = [
    'product_store_name' => '测试护理项目',
    'product_is_del' => 0,
    'product_is_show' => 1,
    'product_is_verify' => 1,
    'sku_is_show' => 1,
];
$assertAvailability->invoke(null, $active, 101);
$passed = 1;
foreach (['product_is_del' => 1, 'product_is_show' => 0, 'sku_is_show' => 0] as $field => $value) {
    $row = $active;
    $row[$field] = $value;
    try {
        $assertAvailability->invoke(null, $row, 101);
        throw new RuntimeException($field . ' must block a new sale');
    } catch (CashierV3CommandException $exception) {
        if ($exception->getMessage() !== '商品“测试护理项目”已被下架，请重新选择。'
            || ($exception->getDetail()['reason'] ?? '') !== 'cashier_catalog_item_off_shelf') {
            throw new RuntimeException($field . ' returned an incorrect error');
        }
    }
    ++$passed;
}
$both = $active;
$both['product_is_del'] = 1;
$both['product_is_verify'] = 0;
try {
    $assertAvailability->invoke(null, $both, 101);
    throw new RuntimeException('deleted unverified product must block a new sale');
} catch (CashierV3CommandException $exception) {
    if (($exception->getDetail()['reason'] ?? '') !== 'cashier_catalog_item_off_shelf') {
        throw new RuntimeException('deleted product must retain its named off-shelf reason');
    }
}
++ $passed;

// A missing store-scoped display name must never be replaced with a name
// from an unscoped product lookup.
$unnamed = $active;
$unnamed['product_store_name'] = '';
$unnamed['product_is_del'] = 1;
try {
    $assertAvailability->invoke(null, $unnamed, 101);
    throw new RuntimeException('unnamed off-shelf product must block a new sale');
} catch (CashierV3CommandException $exception) {
    if (($exception->getDetail()['reason'] ?? '') !== 'cashier_catalog_item_not_available') {
        throw new RuntimeException('unnamed product returned an incorrect error');
    }
}
++ $passed;

$unverified = $active;
$unverified['product_is_verify'] = 0;
try {
    $assertAvailability->invoke(null, $unverified, 101);
    throw new RuntimeException('unverified product must block a new sale');
} catch (CashierV3CommandException $exception) {
    if (($exception->getDetail()['reason'] ?? '') !== 'cashier_catalog_item_not_available'
        || str_contains($exception->getMessage(), '已被下架')) {
        throw new RuntimeException('unverified product was mislabeled as off shelf');
    }
}
++ $passed;

$source = file_get_contents(dirname(__DIR__, 3) . '/后端代码/app/services/cashier/v3/cashier/CashierV3SaleCatalogServices.php');
foreach (['discoverSnapshotSaleResources', 'lockedSnapshotItem', 'readSnapshotItem', 'selectCheckoutSaleLineInTx'] as $method) {
    $start = strpos($source, 'function ' . $method . '(');
    if ($start === false || strpos(substr($source, $start, 2400), 'self::assertNewSaleAvailability($row, $skuId);') === false) {
        throw new RuntimeException($method . ' is missing the final saleability gate');
    }
    ++$passed;
}
$port = file_get_contents(dirname(__DIR__, 3) . '/后端代码/app/services/cashier/v3/settlement/ThinkPhpCashierV3CheckoutSubmissionExecutionPort.php');
if (!str_contains($port, 'CashierV3SaleCatalogServices::assertNewSaleLinesAvailableInTx(')
    || !str_contains($source, "(string)(\$line['line_role'] ?? '') !== 'sale'")
    || !str_contains($source, "->where('relation_id', \$dataScope->forcedStoreId())")
    || !str_contains($source, "->where('product_id', \$productId)")
    || !str_contains($source, "'product_is_show' => \$customCardShell ? 1 : \$product['is_show']")) {
    throw new RuntimeException('shared final checkout gate or store-scoped catalog check is missing');
}
++ $passed;
echo 'sale-off-shelf-contract: ' . $passed . ' passed' . PHP_EOL;
