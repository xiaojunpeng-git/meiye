<?php
declare(strict_types=1);

/** Creates hidden root carriers only for direct-JSJ source masters that 03 cannot map. */
$args = array_fill_keys(array_slice($argv, 1), true);
if (!isset($args['--execute'], $args['--allow-ruihao'], $args['--direct-jsj'], $args['--create-hidden-carriers'])) {
    fwrite(STDERR, "Refusing to write without --execute --allow-ruihao --direct-jsj --create-hidden-carriers\n");
    exit(2);
}
$env = parse_ini_file(getenv('RH_ENV_FILE') ?: '/www/wwwroot/rh.cc3798.com/.env', true, INI_SCANNER_RAW);
$db = $env['DATABASE'] ?? [];
if ((getenv('RH_TARGET_DATABASE') ?: (string)($db['DATABASE'] ?? '')) !== 'ruihao') throw new RuntimeException('target guard failed');
$pdo = new PDO('mysql:host='.$db['HOSTNAME'].';port='.$db['HOSTPORT'].';dbname=ruihao;charset=utf8mb4', $db['USERNAME'], $db['PASSWORD'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_general_ci');

$sources = [
    'bd_ProjectSet' => ['type'=>6, 'unit'=>'次', 'prefix'=>'RH_PROJECT:', 'sql'=>"SELECT CONVERT(Id USING utf8mb4) source_id,LEFT(COALESCE(ProjectName,'历史项目'),128) name FROM jsj.bd_ProjectSet WHERE CONVERT(Id USING utf8mb4) IN (%s)"],
    'bd_goods' => ['type'=>0, 'unit'=>'件', 'prefix'=>'RH_GOODS:', 'sql'=>"SELECT CONVERT(Id USING utf8mb4) source_id,LEFT(COALESCE(GoodsName,'历史商品'),128) name FROM jsj.bd_goods WHERE CONVERT(Id USING utf8mb4) IN (%s)"],
];
$insertProduct = $pdo->prepare("INSERT INTO eb_store_product (pid,type,product_type,is_inventory,allow_negative_stock,salon_stock_enabled,relation_id,image,store_name,store_info,keyword,price,settle_price,vip_price,ot_price,unit_name,star,is_show,is_verify,is_del,add_time,is_support_refund,is_brokerage,card_rule_type) VALUES(0,0,?,0,1,0,0,'https://rh.cc3798.com/static/images/rh-blank.png',?,'RH direct-JSJ historical carrier; hidden',?,0,0,0,0,?,5,0,1,0,UNIX_TIMESTAMP(),1,0,'')");
$insertAttr = $pdo->prepare("INSERT INTO eb_store_product_attr(product_id,attr_name,attr_values,type) SELECT ?,'规格','默认',0 FROM DUAL WHERE NOT EXISTS(SELECT 1 FROM eb_store_product_attr WHERE product_id=? AND attr_name='规格' AND attr_values='默认')");
$insertSku = $pdo->prepare("INSERT INTO eb_store_product_attr_value(type,product_id,product_type,suk,stock,sum_stock,price,image,`unique`,cost,ot_price,write_times,is_default_select,is_show,stock_unit,sale_unit,unit_convert,decimal_scale) SELECT 0,?,?, '默认',0,0,0,'',RIGHT(CONCAT('00000000',CONV(?,10,36)),8),0,0,1,1,0,?,?,1,0 FROM DUAL WHERE NOT EXISTS(SELECT 1 FROM eb_store_product_attr_value WHERE product_id=? AND product_type=?)");
$upsertMap = $pdo->prepare("INSERT INTO map_rh_product(source_table,source_id,target_product_id,batch_no,resolution_method) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE target_product_id=VALUES(target_product_id),batch_no=VALUES(batch_no),resolution_method=VALUES(resolution_method)");
$upsertResolution = $pdo->prepare("UPDATE mig_jsj_20260917_product_resolution SET target_product_id=?,status='MAPPED',method='direct_hidden_carrier',candidates=?,updated_at=NOW() WHERE source_table=? AND source_id=?");
$created = [];
$pdo->beginTransaction();
try {
    foreach ($sources as $table => $cfg) {
        $ids = $pdo->prepare("SELECT source_id FROM mig_jsj_20260917_product_resolution WHERE source_table=? AND status='BLOCKED' ORDER BY source_id");
        $ids->execute([$table]); $ids = $ids->fetchAll(PDO::FETCH_COLUMN);
        if (!$ids) { $created[$table] = 0; continue; }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $source = $pdo->prepare(sprintf($cfg['sql'], $in)); $source->execute($ids); $rows = $source->fetchAll();
        if (count($rows) !== count($ids)) throw new RuntimeException("direct source disappeared for {$table}");
        foreach ($rows as $row) {
            $keyword = $cfg['prefix'].$row['source_id'];
            $find = $pdo->prepare('SELECT id FROM eb_store_product WHERE type=0 AND product_type=? AND is_del=0 AND keyword=? LIMIT 1');
            $find->execute([$cfg['type'], $keyword]); $id = (int)$find->fetchColumn();
            if ($id <= 0) { $insertProduct->execute([$cfg['type'], $row['name'], $keyword, $cfg['unit']]); $id = (int)$pdo->lastInsertId(); }
            $insertAttr->execute([$id, $id]);
            $insertSku->execute([$id, $cfg['type'], $id, $cfg['unit'], $cfg['unit'], $id, $cfg['type']]);
            $upsertMap->execute([$table, $row['source_id'], $id, 'JSJ-DIRECT-20260920-R2', 'direct_hidden_carrier']);
            $upsertResolution->execute([$id, (string)$id, $table, $row['source_id']]);
        }
        $created[$table] = count($rows);
    }
    $pdo->commit();
} catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
echo json_encode(['created_or_reused'=>$created], JSON_UNESCAPED_UNICODE).PHP_EOL;
