<?php
declare(strict_types=1);

/** Adds only source project lines absent from the target; it never replaces a serviced card holder. */
$a=array_fill_keys(array_slice($argv,1),true);
if(!isset($a['--execute'],$a['--allow-ruihao'],$a['--direct-jsj'])){fwrite(STDERR,"Refusing to write without --execute --allow-ruihao --direct-jsj\n");exit(2);}
$e=parse_ini_file(getenv('RH_ENV_FILE')?:'/www/wwwroot/rh.cc3798.com/.env',true,INI_SCANNER_RAW);$d=$e['DATABASE']??[];
if((getenv('RH_TARGET_DATABASE')?:($d['DATABASE']??''))!=='ruihao')throw new RuntimeException('target guard failed');
$p=new PDO('mysql:host='.$d['HOSTNAME'].';port='.$d['HOSTPORT'].';dbname=ruihao;charset=utf8mb4',$d['USERNAME'],$d['PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$p->exec('SET NAMES utf8mb4 COLLATE utf8mb4_general_ci');
$p->exec('DROP TEMPORARY TABLE IF EXISTS tmp_rh_missing_right,tmp_rh_direct_project_child');
$p->exec("CREATE TEMPORARY TABLE tmp_rh_direct_project_child AS SELECT CONVERT(SUBSTRING_INDEX(SUBSTRING_INDEX(keyword,':STORE:',1),'RH_PROJECT:',-1) USING utf8mb4) COLLATE utf8mb4_general_ci source_project_id,relation_id target_store_id,MIN(id) product_id FROM eb_store_product WHERE keyword LIKE 'RH_PROJECT:%:STORE:%' AND type=1 AND product_type=6 AND is_del=0 GROUP BY source_project_id,target_store_id");
$p->exec('ALTER TABLE tmp_rh_direct_project_child ADD PRIMARY KEY(source_project_id,target_store_id)');
$p->exec("CREATE TEMPORARY TABLE tmp_rh_missing_right AS
 SELECT CONVERT(pt.Id USING utf8mb4) source_line_id,CONVERT(cd.Id USING utf8mb4) source_card_id,LEFT(COALESCE(ps.ProjectName,''),255) project_name,ROUND(COALESCE(pt.Quantity,0),0) total_qty_target,ROUND(pt.Quantity-COALESCE(pt.UseQuantity,0),0) remain_qty_target,COALESCE(pt.UseCardPrice,0) unit_price,m.target_store_id,child.product_id,sku.`unique` sku_unique,COALESCE(UNIX_TIMESTAMP(cd.IssueDate),UNIX_TIMESTAMP()) occurred_at,o.id oid,o.uid
 FROM jsj.bd_CardDetail cd JOIN jsj.bd_ProjectAndTimes pt ON pt.CardDetailId=cd.Id JOIN jsj.bd_ProjectSet ps ON ps.Id=pt.ProjectId
 JOIN mig_jsj_20260917_member_resolution m ON m.source_customer_id=CONVERT(cd.CustomerId USING utf8mb4) COLLATE utf8mb4_general_ci
 JOIN tmp_rh_direct_project_child child ON child.source_project_id=CONVERT(pt.ProjectId USING utf8mb4) COLLATE utf8mb4_general_ci AND child.target_store_id=m.target_store_id
 JOIN eb_store_product_attr_value sku ON sku.product_id=child.product_id AND sku.product_type=6
 JOIN eb_store_order o ON o.order_id=CONCAT('RH-RELOAD-102-CARD-',CONVERT(cd.Id USING utf8mb4))
 LEFT JOIN eb_store_order_cart_info existing ON existing.cart_id=CONCAT('RH-LINE-',CONVERT(pt.Id USING utf8mb4))
 WHERE COALESCE(cd.fstate,0)<>-1 AND (cd.EffectiveDate>='2026-09-17' OR cd.EffectiveDate IS NULL) AND cd.pflag<>'0' AND COALESCE(pt.ShiFouUse,'')='' AND pt.Quantity-COALESCE(pt.UseQuantity,0)>0 AND cd.CardClassId<>'0091000112' AND existing.id IS NULL");
$p->exec('ALTER TABLE tmp_rh_missing_right ADD PRIMARY KEY(source_line_id)');
$missing=(int)$p->query('SELECT COUNT(*) FROM tmp_rh_missing_right')->fetchColumn();
$p->beginTransaction();
try {
 $p->exec("INSERT INTO eb_store_order_cart_info(uid,oid,cart_id,cart_type,type,relation_id,product_id,product_type,sku_unique,is_card,cart_num,total_price,pay_price,write_times,write_surplus_times,surplus_num,is_writeoff,write_start,write_end,cart_info,`unique`,add_time,source_type)
 SELECT r.uid,r.oid,CONCAT('RH-LINE-',r.source_line_id),2,0,r.target_store_id,r.product_id,6,r.sku_unique,1,1,ROUND(r.unit_price*r.total_qty_target,0),ROUND(r.unit_price*r.total_qty_target,0),r.total_qty_target,r.remain_qty_target,r.remain_qty_target,0,0,0,JSON_OBJECT('source_type','rh_jsj_reload','source_card_id',r.source_card_id,'source_line_id',r.source_line_id,'project_name',r.project_name,'productInfo',JSON_OBJECT('id',r.product_id,'product_id',r.product_id,'store_name',LEFT(p.store_name,255),'product_type',6,'attrInfo',JSON_OBJECT('unique',r.sku_unique))),MD5(CONCAT('RH-LINE-',r.source_line_id)),r.occurred_at,'migration' FROM tmp_rh_missing_right r JOIN eb_store_product p ON p.id=r.product_id");
 $p->commit();
}catch(Throwable $x){if($p->inTransaction())$p->rollBack();throw $x;}
echo json_encode(['inserted'=>$missing,'target_lines'=>(int)$p->query("SELECT COUNT(*) FROM eb_store_order_cart_info WHERE cart_id LIKE 'RH-LINE-%'")->fetchColumn()],JSON_UNESCAPED_UNICODE).PHP_EOL;
