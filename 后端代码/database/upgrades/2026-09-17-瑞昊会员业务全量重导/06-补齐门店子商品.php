<?php
declare(strict_types=1);

/**
 * Creates only the store children/SKUs required by the direct JSJ source.
 * A child is keyed by source master + final member ownership store, so reruns are idempotent.
 */
$args = array_fill_keys(array_slice($argv, 1), true);
if (!isset($args['--execute'], $args['--allow-ruihao'], $args['--direct-jsj'])) {
    fwrite(STDERR, "Refusing to write without --execute --allow-ruihao --direct-jsj\n"); exit(2);
}
$env = parse_ini_file(getenv('RH_ENV_FILE') ?: '/www/wwwroot/rh.cc3798.com/.env', true, INI_SCANNER_RAW);
$db = $env['DATABASE'] ?? [];
$target = getenv('RH_TARGET_DATABASE') ?: (string)($db['DATABASE'] ?? '');
if ($target !== 'ruihao') throw new RuntimeException("target guard failed: {$target}");
$pdo = new PDO('mysql:host='.$db['HOSTNAME'].';port='.$db['HOSTPORT'].';dbname=ruihao;charset=utf8mb4', $db['USERNAME'], $db['PASSWORD'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

$pdo->exec('DROP TEMPORARY TABLE IF EXISTS tmp_rh_required_child');
$pdo->exec("CREATE TEMPORARY TABLE tmp_rh_required_child (
    source_table varchar(64) NOT NULL, source_id varchar(50) NOT NULL, target_store_id int unsigned NOT NULL,
    PRIMARY KEY(source_table,source_id,target_store_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

// Asset ownership follows the final member resolution, including explicit duplicate-phone merges.
$pdo->exec("INSERT IGNORE INTO tmp_rh_required_child
 SELECT 'bd_ProjectSet',CONVERT(pt.ProjectId USING utf8mb4),m.target_store_id
 FROM jsj.bd_CardDetail cd JOIN jsj.bd_ProjectAndTimes pt ON pt.CardDetailId=cd.Id JOIN jsj.bd_ProjectSet ps ON ps.Id=pt.ProjectId
 JOIN mig_jsj_20260917_member_resolution m ON m.source_customer_id=CONVERT(cd.CustomerId USING utf8mb4) COLLATE utf8mb4_general_ci
 WHERE COALESCE(cd.fstate,0)<>-1 AND (cd.EffectiveDate>='2026-09-17' OR cd.EffectiveDate IS NULL) AND cd.pflag<>'0'
   AND COALESCE(pt.ShiFouUse,'')='' AND pt.Quantity-COALESCE(pt.UseQuantity,0)>0 AND cd.CardClassId<>'0091000112' AND m.status NOT LIKE 'BLOCKED%'
 UNION SELECT 'bd_ProjectSet',CONVERT(g.ProjectId USING utf8mb4),m.target_store_id
 FROM jsj.SS_SendProject g JOIN jsj.bd_ProjectSet ps ON ps.Id=g.ProjectId
 JOIN mig_jsj_20260917_member_resolution m ON m.source_customer_id=CONVERT(g.CustomerId USING utf8mb4) COLLATE utf8mb4_general_ci
 WHERE g.StartDate<='2026-09-17' AND g.EffectiveDate>='2026-09-17' AND g.Times-COALESCE(g.ConsumeQuantity,0)>0 AND g.Fstate='0' AND m.status NOT LIKE 'BLOCKED%'
 UNION SELECT 'bd_goods',CONVERT(g.GoodsId USING utf8mb4),m.target_store_id
 FROM jsj.SS_SendGoods g JOIN jsj.bd_goods bg ON bg.Id=g.GoodsId JOIN mig_jsj_20260917_member_resolution m ON m.source_customer_id=CONVERT(g.CustomerId USING utf8mb4) COLLATE utf8mb4_general_ci
 WHERE g.EffectiveDate>='2026-09-17' AND g.Fstate='0' AND g.Quantity-COALESCE(g.ConsumeQuantity,0)>0 AND m.status NOT LIKE 'BLOCKED%'
 UNION SELECT 'bd_goods',CONVERT(g.GoodsId USING utf8mb4),m.target_store_id
 FROM jsj.SS_StoreGoods g JOIN jsj.bd_goods bg ON bg.Id=g.GoodsId JOIN mig_jsj_20260917_member_resolution m ON m.source_customer_id=CONVERT(g.CustomerId USING utf8mb4) COLLATE utf8mb4_general_ci
 WHERE g.EffectiveDate>='2026-09-17' AND g.Quantity-COALESCE(g.ConsumeQuantity,0)>0 AND g.Fstate<>'-1' AND COALESCE(g.Salefstate,'')<>'作废' AND m.status NOT LIKE 'BLOCKED%'
 UNION SELECT 'bd_goods',CONVERT(g.GoodsId USING utf8mb4),m.target_store_id
 FROM jsj.SS_PreSaleGoods g JOIN jsj.bd_goods bg ON bg.Id=g.GoodsId JOIN mig_jsj_20260917_member_resolution m ON m.source_customer_id=CONVERT(g.CustomerId USING utf8mb4) COLLATE utf8mb4_general_ci
 WHERE g.Quantity-COALESCE(g.consumequantity,0)>0 AND g.Fstate<>'-1' AND m.status NOT LIKE 'BLOCKED%'");

$pdo->beginTransaction();
try {
    // Deliberately do not create roots: 03/04 has already resolved every source master.
    $pdo->exec("INSERT INTO eb_store_product
      (store_name,price,unit_name,image,product_type,type,relation_id,pid,cate_id,store_cate_id,is_verify,is_show,is_del,add_time,recommend_image,slider_image,store_info,keyword)
      SELECT p.store_name,p.price,p.unit_name,p.image,p.product_type,1,r.target_store_id,p.id,p.cate_id,0,1,0,0,UNIX_TIMESTAMP(),p.recommend_image,p.slider_image,
       CASE WHEN r.source_table='bd_ProjectSet' THEN 'RH JSJ rights project store child' ELSE 'RH JSJ held-goods store child' END,
       CONCAT(CASE WHEN r.source_table='bd_ProjectSet' THEN 'RH_PROJECT:' ELSE 'RH_GOODS:' END,r.source_id,':STORE:',r.target_store_id)
      FROM tmp_rh_required_child r
      JOIN map_rh_product mp ON mp.source_table=r.source_table AND mp.source_id=r.source_id
      JOIN eb_store_product p ON p.id=mp.target_product_id AND p.type=0 AND p.is_del=0
      LEFT JOIN eb_store_product c ON c.keyword=CONCAT(CASE WHEN r.source_table='bd_ProjectSet' THEN 'RH_PROJECT:' ELSE 'RH_GOODS:' END,r.source_id,':STORE:',r.target_store_id)
      WHERE c.id IS NULL AND ((r.source_table='bd_ProjectSet' AND p.product_type=6) OR (r.source_table='bd_goods' AND p.product_type=0))");
    $pdo->exec("INSERT INTO eb_store_product_attr(product_id,attr_name,attr_values,type)
      SELECT c.id,'规格','默认',0 FROM eb_store_product c
      WHERE c.type=1 AND c.is_del=0 AND (c.keyword LIKE 'RH_PROJECT:%:STORE:%' OR c.keyword LIKE 'RH_GOODS:%:STORE:%')
       AND NOT EXISTS(SELECT 1 FROM eb_store_product_attr a WHERE a.product_id=c.id AND a.attr_name='规格' AND a.attr_values='默认')");
    $pdo->exec("INSERT INTO eb_store_product_attr_value
      (type,product_id,product_type,suk,stock,sum_stock,price,image,`unique`,cost,ot_price,write_times,is_default_select,is_show,stock_unit,sale_unit,unit_convert,decimal_scale)
      SELECT 0,c.id,c.product_type,'默认',999999,999999,c.price,'https://rh.cc3798.com/static/images/rh-blank.png',RIGHT(CONCAT('00000000',CONV(c.id,10,36)),8),0,c.price,1,1,1,
       CASE WHEN c.product_type=6 THEN '次' ELSE '件' END,CASE WHEN c.product_type=6 THEN '次' ELSE '件' END,1,0
      FROM eb_store_product c WHERE c.type=1 AND c.is_del=0 AND (c.keyword LIKE 'RH_PROJECT:%:STORE:%' OR c.keyword LIKE 'RH_GOODS:%:STORE:%')
       AND NOT EXISTS(SELECT 1 FROM eb_store_product_attr_value av WHERE av.product_id=c.id AND av.product_type=c.product_type)");
    // 05 clears business stock projections. These migration carriers must remain claimable after that reset.
    $pdo->exec("UPDATE eb_store_product c JOIN eb_store_product_attr_value av ON av.product_id=c.id AND av.product_type=c.product_type
      SET c.stock=999999,av.stock=999999,av.sum_stock=999999
      WHERE c.type=1 AND c.is_del=0 AND (c.keyword LIKE 'RH_PROJECT:%:STORE:%' OR c.keyword LIKE 'RH_GOODS:%:STORE:%')");
    $pdo->commit();
} catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }

$q = $pdo->query("SELECT source_table,COUNT(*) required_pairs FROM tmp_rh_required_child GROUP BY source_table")->fetchAll(PDO::FETCH_ASSOC);
$children = $pdo->query("SELECT CASE WHEN keyword LIKE 'RH_PROJECT:%' THEN 'bd_ProjectSet' ELSE 'bd_goods' END source_table,COUNT(*) children
 FROM eb_store_product WHERE type=1 AND is_del=0 AND (keyword LIKE 'RH_PROJECT:%:STORE:%' OR keyword LIKE 'RH_GOODS:%:STORE:%') GROUP BY 1")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['required'=>$q,'children'=>$children], JSON_UNESCAPED_UNICODE).PHP_EOL;
