<?php
/**
 * Isolated MySQL contract for the 008 catalogue migration transformations.
 *
 * Requires a disposable database with the 008 catalogue schemas only. The test
 * rolls back its transaction, so no fixture survives. It never opens production.
 */

declare(strict_types=1);

require __DIR__ . '/../../../scripts/import/customer_008_morestore_catalog_apply.php';

$dsn = (string)(getenv('CATALOG_TEST_DSN') ?: '');
$user = (string)(getenv('CATALOG_TEST_USER') ?: '');
$password = (string)(getenv('CATALOG_TEST_PASSWORD') ?: '');
if ($dsn === '' || strpos($dsn, 'dbname=catalog_test') === false) {
    throw new RuntimeException('Disposable catalog_test DSN required');
}
$db = new PDO($dsn, $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
if ((string)$db->query('SELECT DATABASE()')->fetchColumn() !== 'catalog_test') {
    throw new RuntimeException('Refusing non-test database');
}

/** Fixture mirrors one ordinary product, single project, normal/choice cards and a shell. */
function catalogFixture(): array
{
    $roots = [
        ['old_id' => 10, 'kind' => 'project', 'target_type' => 6],
        ['old_id' => 20, 'kind' => 'card', 'target_type' => 5,
            'card_rule' => 'normal', 'related' => [['old_project_id' => 10]]],
        ['old_id' => 30, 'kind' => 'product', 'target_type' => 0],
        ['old_id' => 40, 'kind' => 'shell', 'target_type' => 5],
        ['old_id' => 50, 'kind' => 'card', 'target_type' => 5,
            'card_rule' => 'choice_count', 'related' => [
                ['old_project_id' => 10], ['new_tag_project_name' => '标签新项目'],
            ]],
    ];
    $products = [];
    $attrs = [];
    $skus = [];
    $results = [];
    $details = [];
    foreach ($roots as $root) {
        $id = $root['old_id'];
        foreach ([0, 1] as $child) {
            $productId = $id + $child;
            $oldType = $root['kind'] === 'product' ? 0 : 4;
            $times = $root['kind'] === 'product' ? 0 : 10;
            $type = $child ? 1 : 0;
            $products[] = [
                'id' => $productId, 'pid' => $child ? $id : 0,
                'type' => $type, 'relation_id' => $child ? 17 : 0,
                'product_type' => $oldType, 'store_name' => '商品' . $id,
                'store_info' => '保留简介', 'image' => 'https://myqiniu.cc3798.com/main.jpg',
                'slider_image' => '["https://myqiniu.cc3798.com/slide.jpg"]',
                'price' => '980.00', 'stock' => 1000, 'sales' => 9,
                'ficti' => 8, 'browse' => 7, 'is_show' => 1, 'is_del' => 0,
            ];
            $attrs[] = ['id' => $productId, 'product_id' => $productId,
                'attr_name' => '规格', 'attr_values' => '默认', 'type' => $type];
            $skus[] = ['id' => $productId, 'type' => $type,
                'product_id' => $productId, 'product_type' => $oldType,
                'suk' => '默认', 'unique' => str_pad((string)$productId, 8, '0', STR_PAD_LEFT),
                'stock' => 1000, 'sum_stock' => 1000, 'sales' => 9,
                'quota' => 1000, 'quota_show' => 1000,
                'price' => '980.00', 'image' => 'https://myqiniu.cc3798.com/sku.jpg',
                'write_times' => $times, 'write_valid' => 1];
            $editor = ['attr' => [], 'value' => [[
                'pic' => 'https://myqiniu.cc3798.com/sku.jpg',
                'stock' => '1000', 'product_type' => $oldType,
            ]]];
            $results[] = ['id' => $productId, 'product_id' => $productId,
                'result' => json_encode($editor, JSON_UNESCAPED_SLASHES),
                'change_time' => 0, 'type' => $type];
            $details[] = ['product_id' => $productId, 'type' => $type,
                'description' => '&lt;img src=&quot;https://myqiniu.cc3798.com/detail.jpg&quot;&gt;'];
        }
    }
    return [
        'plan' => ['roots' => $roots, 'store_map' => ['17' => 101]],
        'bundle' => [
            'category' => [['id' => 1, 'pid' => 0, 'type' => 0,
                'relation_id' => 0, 'cate_name' => '美容项目'],
                ['id' => 2, 'pid' => 1, 'type' => 0,
                    'relation_id' => 0, 'cate_name' => '面部']],
            'brand' => [], 'label' => [], 'unit' => [],
            'label_category' => ['id' => 1, 'pid' => 0, 'type' => 1,
                'name' => '默认', 'group' => 2],
            'product' => $products, 'attr' => $attrs, 'sku' => $skus,
            'attr_result' => $results, 'description' => $details,
            'category_brand' => [],
        ],
    ];
}

function catalogAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$fixture = catalogFixture();
$GLOBALS['catalog_plan_path'] = __FILE__;
$db->beginTransaction();
try {
    // The category label row is a single source row, as in the live loader.
    $fixture['bundle']['label_category'] = [$fixture['bundle']['label_category']];
    $counts = catalogApply($db, $fixture['bundle'], $fixture['plan'],
        str_repeat('a', 64), str_repeat('b', 64), str_repeat('c', 40));
    $product = $db->query('SELECT product_type,image,slider_image,stock,sales,is_inventory,allow_negative_stock FROM eb_store_product WHERE id=30')->fetch();
    catalogAssert((int)$product['product_type'] === 0 && (int)$product['stock'] === 0
        && (int)$product['sales'] === 0 && (int)$product['is_inventory'] === 0
        && (int)$product['allow_negative_stock'] === 1
        && $product['image'] === 'https://myqiniu.cc3798.com/main.jpg'
        && strpos($product['slider_image'], 'slide.jpg') !== false,
        'Ordinary product image or inventory boundary failed');
    $detail = $db->query('SELECT description FROM eb_store_product_description WHERE product_id=30')->fetchColumn();
    catalogAssert($detail === '&lt;img src=&quot;https://myqiniu.cc3798.com/detail.jpg&quot;&gt;',
        'Original detail HTML changed');
    $sku = $db->query('SELECT product_type,stock,sum_stock,sales,image FROM eb_store_product_attr_value WHERE id=10')->fetch();
    catalogAssert((int)$sku['product_type'] === 6 && (int)$sku['stock'] === 0
        && (int)$sku['sum_stock'] === 0 && (int)$sku['sales'] === 0
        && $sku['image'] === 'https://myqiniu.cc3798.com/sku.jpg',
        'Project SKU transform failed');
    $result = json_decode((string)$db->query('SELECT result FROM eb_store_product_attr_result WHERE id=10')->fetchColumn(), true);
    catalogAssert(($result['value'][0]['stock'] ?? '') === '0'
        && ($result['value'][0]['product_type'] ?? 0) === 6
        && strpos($result['value'][0]['pic'], 'sku.jpg') !== false,
        'SKU editor cache did not match transformed SKU');
    $normal = $db->query('SELECT card_rule_type,card_rule_version,card_shared_times FROM eb_store_product WHERE id=20')->fetch();
    $choice = $db->query('SELECT card_rule_type,card_shared_times FROM eb_store_product WHERE id=50')->fetch();
    $shell = $db->query('SELECT product_type,is_show,card_rule_type FROM eb_store_product WHERE id=40')->fetch();
    catalogAssert($normal['card_rule_type'] === 'normal' && (int)$normal['card_rule_version'] === 1
        && (int)$normal['card_shared_times'] === 0
        && $choice['card_rule_type'] === 'choice_count' && (int)$choice['card_shared_times'] === 10
        && (int)$shell['product_type'] === 5 && (int)$shell['is_show'] === 0
        && $shell['card_rule_type'] === '', 'Card rule or shell state failed');
    $relations = $db->query('SELECT card_product_id,product_id,write_times FROM eb_store_card_related ORDER BY card_product_id,product_id')->fetchAll();
    catalogAssert(count($relations) === 6 && (int)$relations[0]['write_times'] === 10,
        'Card relation count or normal times failed');
    foreach ($relations as $relation) {
        if (in_array((int)$relation['card_product_id'], [50, 51], true)) {
            catalogAssert((int)$relation['write_times'] === 0,
                'Choice card duplicated shared times into a component');
        }
    }
    catalogAssert((int)$db->query("SELECT COUNT(*) FROM eb_store_product WHERE store_name='标签新项目' AND is_show=0 AND price=0")->fetchColumn() === 2,
        'Missing hidden parent/store tag project');
    catalogAssert((int)$db->query('SELECT level FROM eb_store_product_category WHERE id=2')->fetchColumn() === 1,
        'Imported category level is wrong');
    catalogAssert($counts['original_products'] === 10 && $counts['card_relations'] === 6,
        'Business object counts do not reconcile');
    echo json_encode(['ok' => true, 'counts' => $counts], JSON_UNESCAPED_UNICODE) . PHP_EOL;
} finally {
    $db->rollBack();
}
