<?php
declare(strict_types=1);

/** Local-only card-group regression: zero catalog stock must not block a group place. */
require (getenv('BACKEND_ROOT') ?: __DIR__ . '/../../后端代码') . '/vendor/autoload.php';

use app\services\activity\combination\StoreCombinationServices;
use app\services\order\StoreCartServices;
use think\facade\Db;

$root = getenv('BACKEND_ROOT') ?: realpath(__DIR__ . '/../../后端代码');
$app = new think\App($root . '/');
$app->initialize();
if (config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('Only the local Docker ruihao database is allowed');
}
$app->instance('queue', new class { public function __call($name, $args) { return 1; } });

$assert = static function (bool $condition, string $label): void {
    if (!$condition) throw new RuntimeException('FAIL ' . $label);
    echo 'PASS ' . $label . "\n";
};
$cartPolicy = new ReflectionMethod(StoreCartServices::class, 'requiresCombinationCatalogStock');
$createPolicy = new ReflectionMethod(StoreCombinationServices::class, 'requiresCatalogStockCap');
$cartPolicy->setAccessible(true);
$createPolicy->setAccessible(true);
$cartService = (new ReflectionClass(StoreCartServices::class))->newInstanceWithoutConstructor();
$createService = (new ReflectionClass(StoreCombinationServices::class))->newInstanceWithoutConstructor();
$cases = [
    ['card with zero catalog stock', ['product_type' => 5, 'is_inventory' => 0, 'allow_negative_stock' => 1], false],
    ['project with zero catalog stock', ['product_type' => 6, 'is_inventory' => 0, 'allow_negative_stock' => 1], false],
    ['ordinary product without inventory management', ['product_type' => 0, 'is_inventory' => 0, 'allow_negative_stock' => 0], false],
    ['ordinary product allowing negative stock', ['product_type' => 0, 'is_inventory' => 1, 'allow_negative_stock' => 1], false],
    ['ordinary product with protected inventory', ['product_type' => 0, 'is_inventory' => 1, 'allow_negative_stock' => 0], true],
];
foreach ($cases as [$label, $product, $expected]) {
    $cart = $cartPolicy->invoke($cartService, $product);
    $create = $createPolicy->invoke($createService, $product);
    $assert($cart === $expected && $create === $expected, $label . ' uses the same stock policy in cart and activity');
}

/** Seed only local synthetic activity rows; transaction rollback restores the database. */
$seed = static function (string $table, array $data): int {
    foreach (Db::query('SHOW COLUMNS FROM eb_' . $table) as $field) {
        $key = $field['Field'];
        if (array_key_exists($key, $data) || $field['Default'] !== null || $field['Null'] === 'YES'
            || strpos($field['Extra'], 'auto_increment') !== false) continue;
        $data[$key] = preg_match('/int|decimal|float|double/', $field['Type']) ? 0 : '';
    }
    return (int)Db::name($table)->insertGetId($data);
};

Db::startTrans();
try {
    $tag = 'R63-' . bin2hex(random_bytes(5));
    $unique = substr(hash('sha256', $tag), 0, 8);
    $productId = $seed('store_product', [
        'store_name' => $tag, 'product_type' => 5, 'is_inventory' => 0,
        'allow_negative_stock' => 1, 'stock' => 0, 'is_show' => 1, 'is_del' => 0,
    ]);
    $groupId = $seed('store_combination', [
        'title' => $tag, 'product_id' => $productId, 'product_type' => 5,
        'stock' => 2, 'quota' => 2, 'quota_show' => 2, 'is_show' => 1,
    ]);
    $seed('store_product_attr_value', [
        'product_id' => $groupId, 'type' => 3, 'unique' => $unique,
        'suk' => '默认', 'stock' => 2, 'quota' => 2, 'sum_stock' => 2,
    ]);
    $order = (new ReflectionClass(app\services\order\StoreOrderCreateServices::class))->newInstanceWithoutConstructor();
    $cart = [[
        'cart_num' => 1, 'product_type' => 5, 'type' => 3, 'cart_type' => 0,
        'product_id' => $productId, 'pay_price' => 399,
        'productInfo' => ['id' => $groupId, 'product_type' => 5, 'attrInfo' => ['unique' => $unique]],
    ]];
    $order->decGoodsStock($cart, 3, $groupId);
    $assert((int)Db::name('store_combination')->where('id', $groupId)->value('quota') === 1,
        'card group order reserves one activity place');
    $assert((int)Db::name('store_product_attr_value')->where('product_id', $groupId)->where('type', 3)->value('quota') === 1,
        'card group SKU activity place is reserved');
    $assert((int)Db::name('store_product')->where('id', $productId)->value('stock') === 0,
        'card catalog stock remains zero');
} finally {
    Db::rollback();
}

echo "R63 CARD GROUP CART PASS\n";
