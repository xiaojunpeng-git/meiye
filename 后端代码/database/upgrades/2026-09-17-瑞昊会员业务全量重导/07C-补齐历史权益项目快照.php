<?php
declare(strict_types=1);

/**
 * 历史 JSJ 权益的项目名称来自 cart_info.productInfo.store_name。
 * product_id 已指向正确的门店项目，但旧导入只保存了 source_id/project_name，
 * 收银权益选择器因此回退展示为“项目”。本修复仅补齐不可变展示快照，不改变
 * 项目关联、次数、金额、有效期或卡规则。
 */
$args = array_fill_keys(array_slice($argv, 1), true);
if (!isset($args['--execute'], $args['--allow-ruihao'], $args['--after-import'])) {
    fwrite(STDERR, "Refusing to write without --execute --allow-ruihao --after-import\n");
    exit(2);
}

$env = parse_ini_file(getenv('RH_ENV_FILE') ?: '/www/wwwroot/rh.cc3798.com/.env', true, INI_SCANNER_RAW);
$db = $env['DATABASE'] ?? [];
$target = getenv('RH_TARGET_DATABASE') ?: (string)($db['DATABASE'] ?? '');
if ($target !== 'ruihao') {
    throw new RuntimeException("target guard failed: {$target}");
}

$pdo = new PDO(
    'mysql:host=' . $db['HOSTNAME'] . ';port=' . $db['HOSTPORT'] . ';dbname=ruihao;charset=utf8mb4',
    $db['USERNAME'],
    $db['PASSWORD'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_general_ci');

$scope = "(c.cart_id LIKE 'RH-LINE-%' OR c.cart_id LIKE 'RH-PG-%' OR c.cart_id LIKE 'RH-TIME-PROJECT-%')";
$before = $pdo->query(
    "SELECT COUNT(*) AS rows,
            SUM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(c.cart_info, '$.productInfo.store_name')), '') = '') AS missing_name,
            SUM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(c.cart_info, '$.productInfo.store_name')), '') <> p.store_name) AS wrong_name,
            SUM(p.id IS NULL OR p.is_del <> 0 OR p.type <> 1 OR p.product_type <> 6) AS invalid_link
     FROM eb_store_order_cart_info c
     LEFT JOIN eb_store_product p ON p.id=c.product_id
     WHERE {$scope}"
)->fetch();

if ((int)$before['invalid_link'] !== 0) {
    throw new RuntimeException('refusing snapshot repair: project linkage is invalid');
}

$pdo->beginTransaction();
try {
    $updated = $pdo->exec(
        "UPDATE eb_store_order_cart_info c
         JOIN eb_store_product p ON p.id=c.product_id
         SET c.cart_info=JSON_SET(
             CASE WHEN JSON_VALID(c.cart_info) THEN c.cart_info ELSE JSON_OBJECT() END,
             '$.productInfo',
             JSON_OBJECT(
                 'id',c.product_id,
                 'product_id',c.product_id,
                 'store_name',LEFT(p.store_name,255),
                 'product_type',6,
                 'attrInfo',JSON_OBJECT('unique',c.sku_unique)
             ),
             '$.product_id',c.product_id,
             '$.product_type',6,
             '$.product_attr_unique',c.sku_unique
         )
         WHERE {$scope}
           AND (
             COALESCE(JSON_UNQUOTE(JSON_EXTRACT(c.cart_info, '$.productInfo.store_name')), '') = ''
             OR JSON_UNQUOTE(JSON_EXTRACT(c.cart_info, '$.productInfo.store_name')) <> p.store_name
           )"
    );
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}

$after = $pdo->query(
    "SELECT COUNT(*) AS rows,
            SUM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(c.cart_info, '$.productInfo.store_name')), '') = '') AS missing_name,
            SUM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(c.cart_info, '$.productInfo.store_name')), '') <> p.store_name) AS wrong_name,
            SUM(p.id IS NULL OR p.is_del <> 0 OR p.type <> 1 OR p.product_type <> 6) AS invalid_link
     FROM eb_store_order_cart_info c
     LEFT JOIN eb_store_product p ON p.id=c.product_id
     WHERE {$scope}"
)->fetch();

if ((int)$after['missing_name'] !== 0 || (int)$after['wrong_name'] !== 0 || (int)$after['invalid_link'] !== 0) {
    throw new RuntimeException('snapshot repair verification failed');
}

echo json_encode([
    'before' => $before,
    'updated' => $updated,
    'after' => $after,
], JSON_UNESCAPED_UNICODE) . PHP_EOL;
