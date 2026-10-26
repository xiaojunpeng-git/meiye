<?php
declare(strict_types=1);

/** Creates only the card-class carrier products expressly approved for this reload. */
$args = array_fill_keys(array_slice($argv, 1), true);
if (!isset($args['--execute'], $args['--allow-ruihao'], $args['--direct-jsj'], $args['--create-hidden-card-carriers'])) {
    fwrite(STDERR, "Refusing to write without --execute --allow-ruihao --direct-jsj --create-hidden-card-carriers\n");
    exit(2);
}
$env = parse_ini_file(getenv('RH_ENV_FILE') ?: '/www/wwwroot/rh.cc3798.com/.env', true, INI_SCANNER_RAW);
$db = $env['DATABASE'] ?? [];
$target = getenv('RH_TARGET_DATABASE') ?: (string)($db['DATABASE'] ?? '');
if ($target !== 'ruihao') throw new RuntimeException("target guard failed: {$target}");
$pdo = new PDO('mysql:host='.$db['HOSTNAME'].';port='.$db['HOSTPORT'].';dbname=ruihao;charset=utf8mb4', $db['USERNAME'], $db['PASSWORD'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

$blocked = $pdo->query("SELECT source_id FROM mig_jsj_20260917_product_resolution WHERE source_table='bd_CardClass' AND status='BLOCKED' ORDER BY source_id")->fetchAll(PDO::FETCH_COLUMN);
if (!$blocked) {
    echo json_encode(['created' => 0, 'message' => 'no missing historical card carriers'], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}
$in = implode(',', array_fill(0, count($blocked), '?'));
$source = $pdo->prepare("SELECT CONVERT(Id USING utf8mb4) source_id,LEFT(COALESCE(CardKindName,'历史疗程卡'),128) card_name,COALESCE(SellMoney,0) sell_price FROM jsj.bd_CardClass WHERE CONVERT(Id USING utf8mb4) IN ($in)");
$source->execute($blocked);
$rows = $source->fetchAll();
if (count($rows) !== count($blocked)) {
    throw new RuntimeException('some blocked card classes no longer exist in the direct JSJ source');
}
$insert = $pdo->prepare("INSERT INTO eb_store_product
    (pid,type,product_type,is_inventory,allow_negative_stock,salon_stock_enabled,relation_id,image,store_name,store_info,keyword,price,settle_price,vip_price,ot_price,unit_name,star,is_show,is_verify,is_del,add_time,is_support_refund,is_brokerage,card_rule_type)
    VALUES(0,0,5,0,1,0,0,'https://rh.cc3798.com/static/images/rh-blank.png',?,'RH 2026-09-17 historical card carrier; hidden',?,0,0,0,0,'张',5,0,1,0,UNIX_TIMESTAMP(),1,0,'')");
$pdo->beginTransaction();
try {
    foreach ($rows as $row) {
        $sourceId = (string)$row['source_id'];
        $keyword = 'RH_CARDCLASS:' . $sourceId;
        $find = $pdo->prepare("SELECT id FROM eb_store_product WHERE type=0 AND product_type=5 AND is_del=0 AND keyword=? ORDER BY id LIMIT 1");
        $find->execute([$keyword]);
        $productId = (int)$find->fetchColumn();
        if ($productId <= 0) {
            $insert->execute([(string)$row['card_name'], $keyword]);
            $productId = (int)$pdo->lastInsertId();
        }
        $attr = $pdo->prepare("INSERT INTO eb_store_product_attr(product_id,attr_name,attr_values,type)
            SELECT ?,'规格','默认',0 FROM DUAL WHERE NOT EXISTS(SELECT 1 FROM eb_store_product_attr WHERE product_id=? AND attr_name='规格' AND attr_values='默认')");
        $attr->execute([$productId, $productId]);
        $sku = $pdo->prepare("INSERT INTO eb_store_product_attr_value
            (type,product_id,product_type,suk,stock,sum_stock,price,image,`unique`,cost,ot_price,write_times,is_default_select,is_show,stock_unit,sale_unit,unit_convert,decimal_scale)
            SELECT 0,?,5,'默认',0,0,0,'',RIGHT(CONCAT('00000000',CONV(?,10,36)),8),0,0,1,1,0,'张','张',1,0 FROM DUAL
            WHERE NOT EXISTS(SELECT 1 FROM eb_store_product_attr_value WHERE product_id=? AND product_type=5)");
        $sku->execute([$productId, $productId, $productId]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}
echo json_encode(['created_or_reused' => count($rows), 'source_card_classes' => $blocked], JSON_UNESCAPED_UNICODE) . PHP_EOL;
