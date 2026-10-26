<?php
declare(strict_types=1);

/**
 * Local rollback-only regression for a second member opening a shared group.
 * It proves activity places survive zero catalog stock under the saved product
 * inventory policy; no payment gateway or remote instance is touched.
 */
require '/var/www/html/vendor/autoload.php';

use think\facade\Db;
use app\services\product\sku\StoreProductAttrServices;

$app = new think\App('/var/www/html/');
$app->initialize();
if (config('database.connections.mysql.hostname') !== 'mysql' || config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('Local Docker ruihao only');
}

function r66Check(bool $ok, string $label): void
{
    if (!$ok) throw new RuntimeException('FAIL ' . $label);
    echo 'PASS ', $label, PHP_EOL;
}

function r66Seed(string $table, array $data): int
{
    foreach (Db::query('SHOW COLUMNS FROM eb_' . $table) as $field) {
        $key = $field['Field'];
        if (array_key_exists($key, $data) || $field['Default'] !== null || $field['Null'] === 'YES'
            || strpos($field['Extra'], 'auto_increment') !== false) continue;
        $data[$key] = preg_match('/int|decimal|float|double/', $field['Type']) ? 0 : '';
    }
    return (int)Db::name($table)->insertGetId($data);
}

$failure = null;
Db::startTrans();
try {
    $tag = 'R66-PARTICIPANT-' . bin2hex(random_bytes(4));
    $service = app()->make(StoreProductAttrServices::class);
    $cases = [
        ['name' => 'card rights', 'product_type' => 5, 'is_inventory' => 1, 'allow_negative_stock' => 0, 'expected' => 9],
        ['name' => 'inventory disabled product', 'product_type' => 0, 'is_inventory' => 0, 'allow_negative_stock' => 0, 'expected' => 9],
        ['name' => 'managed physical shortage', 'product_type' => 0, 'is_inventory' => 1, 'allow_negative_stock' => 0, 'expected' => 0],
    ];
    foreach ($cases as $index => $case) {
        $sku = substr(hash('sha256', $tag . $index), 0, 8);
        $productId = r66Seed('store_product', [
            'store_name' => $tag,
            'product_type' => $case['product_type'],
            'type' => 0,
            'relation_id' => 0,
            'price' => 399,
            'stock' => 0,
            'is_show' => 1,
            'is_del' => 0,
            'is_verify' => 1,
            'is_inventory' => $case['is_inventory'],
            'allow_negative_stock' => $case['allow_negative_stock'],
        ]);
        r66Seed('store_product_attr_value', [
            'product_id' => $productId, 'type' => 0, 'unique' => $sku . 'p', 'suk' => '默认',
            'price' => 399, 'stock' => 0, 'is_show' => 1,
        ]);
        $combinationId = r66Seed('store_combination', [
            'title' => $tag, 'product_id' => $productId, 'product_type' => $case['product_type'],
            'stock' => 9, 'quota' => 9, 'quota_show' => 10, 'price' => 1, 'people' => 3,
            'is_show' => 1, 'is_del' => 0, 'start_time' => time() - 60, 'stop_time' => time() + 3600,
        ]);
        r66Seed('store_product_attr_value', [
            'product_id' => $combinationId, 'type' => 3, 'unique' => $sku . 'g', 'suk' => '默认',
            'price' => 1, 'stock' => 9, 'quota' => 9, 'quota_show' => 10, 'is_show' => 1,
        ]);
        [, $values] = $service->getProductAttrDetail($combinationId, 0, 0, 3, $productId);
        r66Check(isset($values['默认']), $case['name'] . ' activity sku is returned');
        r66Check((int)$values['默认']['product_stock'] === 0, $case['name'] . ' reproduces zero catalog stock');
        r66Check((int)$values['默认']['stock'] === $case['expected'], $case['name'] . ' follows saved inventory policy');
    }

    echo "R66 PARTICIPANT STOCK COMPLETE\n";
} catch (Throwable $error) {
    $failure = $error;
    echo 'FAIL ', get_class($error), ' ', $error->getMessage(), PHP_EOL;
} finally {
    Db::rollback();
}

restore_error_handler();
exit($failure ? 1 : 0);
