<?php
declare(strict_types=1);
/** Imports balances, residual ordinary-card rights and project gifts directly from JSJ. */
$args=array_fill_keys(array_slice($argv,1),true);
if(!isset($args['--execute'],$args['--allow-ruihao'],$args['--after-cleanup'],$args['--direct-jsj'])){fwrite(STDERR,"Refusing to write without --execute --allow-ruihao --after-cleanup --direct-jsj\n");exit(2);}
$env=parse_ini_file(getenv('RH_ENV_FILE')?:'/www/wwwroot/rh.cc3798.com/.env',true,INI_SCANNER_RAW);$db=$env['DATABASE']??[];
$target=getenv('RH_TARGET_DATABASE')?:($db['DATABASE']??'');if($target!=='ruihao')throw new RuntimeException("target guard failed: $target");
$pdo=new PDO('mysql:host='.$db['HOSTNAME'].';port='.$db['HOSTPORT'].';dbname=ruihao;charset=utf8mb4',$db['USERNAME'],$db['PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_general_ci');
foreach(['mig_jsj_20260917_member_resolution','map_rh_product'] as $t)if((int)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn()===0)throw new RuntimeException("missing prerequisite $t");
if((int)$pdo->query("SELECT COUNT(*) FROM mig_jsj_20260917_product_resolution WHERE status<>'MAPPED'")->fetchColumn()!==0)throw new RuntimeException('blocked product resolution');

// Allocate card numbers once for every normal/long card. Legal 7-digit source numbers are retained only when globally unique.
$pdo->exec("CREATE TABLE IF NOT EXISTS mig_rh_card_no_map(id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,source_card_id varchar(64) NOT NULL UNIQUE,card_no char(7) CHARACTER SET ascii COLLATE ascii_bin NULL UNIQUE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec('DROP TEMPORARY TABLE IF EXISTS tmp_rh_all_card');
$pdo->exec("CREATE TEMPORARY TABLE tmp_rh_all_card(source_card_id varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL PRIMARY KEY,source_card_no varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("INSERT IGNORE INTO tmp_rh_all_card
 SELECT DISTINCT CONVERT(cd.Id USING utf8mb4),CONVERT(COALESCE(cd.CardNo,'') USING utf8mb4)
 FROM jsj.bd_CardDetail cd JOIN jsj.bd_ProjectAndTimes pt ON pt.CardDetailId=cd.Id JOIN jsj.bd_ProjectSet ps ON ps.Id=pt.ProjectId
 WHERE COALESCE(cd.fstate,0)<>-1 AND (cd.EffectiveDate>='2026-09-17' OR cd.EffectiveDate IS NULL) AND cd.pflag<>'0'
   AND COALESCE(pt.ShiFouUse,'')='' AND pt.Quantity-COALESCE(pt.UseQuantity,0)>0 AND cd.CardClassId<>'0091000112'
 UNION SELECT CONVERT(cd.Id USING utf8mb4),CONVERT(COALESCE(cd.CardNo,'') USING utf8mb4) FROM jsj.bd_CardDetail cd WHERE cd.CardClassId='0091000112'");
$pdo->exec("INSERT IGNORE INTO mig_rh_card_no_map(source_card_id) SELECT source_card_id FROM tmp_rh_all_card");
$pdo->exec('DROP TEMPORARY TABLE IF EXISTS tmp_rh_safe_card_no');
$pdo->exec("CREATE TEMPORARY TABLE tmp_rh_safe_card_no AS SELECT source_card_no FROM tmp_rh_all_card WHERE source_card_no REGEXP '^[0-9]{7}$' GROUP BY source_card_no HAVING COUNT(*)=1");
$pdo->exec('ALTER TABLE tmp_rh_safe_card_no ADD PRIMARY KEY(source_card_no)');
$pdo->exec("UPDATE mig_rh_card_no_map m JOIN tmp_rh_all_card s ON s.source_card_id=m.source_card_id JOIN tmp_rh_safe_card_no n ON n.source_card_no=s.source_card_no SET m.card_no=s.source_card_no WHERE m.card_no IS NULL");
$pdo->exec("UPDATE mig_rh_card_no_map SET card_no=CONCAT('z',LPAD(CONV(id,10,36),6,'0')) WHERE card_no IS NULL");
$pdo->exec("CREATE TABLE IF NOT EXISTS mig_jsj_20260917_negative_balance_audit(target_uid int unsigned NOT NULL PRIMARY KEY,target_store_id int unsigned NOT NULL,negative_amount decimal(14,0) NOT NULL,created_at datetime NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec('DROP TEMPORARY TABLE IF EXISTS tmp_rh_member_map,tmp_rh_card_product,tmp_rh_project_product,tmp_rh_card_map,tmp_rh_project_child');
$pdo->exec("CREATE TEMPORARY TABLE tmp_rh_member_map AS SELECT CONVERT(source_customer_id USING utf8mb4) COLLATE utf8mb4_general_ci source_customer_id,target_uid,target_store_id FROM mig_jsj_20260917_member_resolution");
$pdo->exec('ALTER TABLE tmp_rh_member_map ADD PRIMARY KEY(source_customer_id)');
$pdo->exec("CREATE TEMPORARY TABLE tmp_rh_card_product AS SELECT CONVERT(source_id USING utf8mb4) COLLATE utf8mb4_general_ci source_id,target_product_id FROM map_rh_product WHERE source_table='bd_CardClass'");
$pdo->exec('ALTER TABLE tmp_rh_card_product ADD PRIMARY KEY(source_id)');
$pdo->exec("CREATE TEMPORARY TABLE tmp_rh_project_product AS SELECT CONVERT(source_id USING utf8mb4) COLLATE utf8mb4_general_ci source_id,target_product_id FROM map_rh_product WHERE source_table='bd_ProjectSet'");
$pdo->exec('ALTER TABLE tmp_rh_project_product ADD PRIMARY KEY(source_id)');
$pdo->exec("CREATE TEMPORARY TABLE tmp_rh_card_map AS SELECT CONVERT(source_card_id USING utf8mb4) COLLATE utf8mb4_general_ci source_card_id,card_no FROM mig_rh_card_no_map");
$pdo->exec('ALTER TABLE tmp_rh_card_map ADD PRIMARY KEY(source_card_id)');
$pdo->exec("CREATE TEMPORARY TABLE tmp_rh_project_child AS SELECT CONVERT(SUBSTRING_INDEX(SUBSTRING_INDEX(keyword,':STORE:',1),'RH_PROJECT:',-1) USING utf8mb4) COLLATE utf8mb4_general_ci source_project_id,relation_id target_store_id,MIN(id) product_id FROM eb_store_product WHERE keyword LIKE 'RH_PROJECT:%:STORE:%' AND type=1 AND product_type=6 AND is_del=0 GROUP BY source_project_id,target_store_id");
$pdo->exec('ALTER TABLE tmp_rh_project_child ADD PRIMARY KEY(source_project_id,target_store_id)');
$pdo->exec('DROP TEMPORARY TABLE IF EXISTS tmp_rh_existing_card_order,tmp_rh_existing_project_gift_order');
// Historical carriers already used by ruihao services must retain their stable
// primary keys.  Direct rows with the same source key are therefore left in
// place; only missing source keys are inserted in this pass.
$pdo->exec("CREATE TEMPORARY TABLE tmp_rh_existing_card_order AS SELECT order_id FROM eb_store_order WHERE order_id LIKE 'RH-RELOAD-102-CARD-%'");
$pdo->exec('ALTER TABLE tmp_rh_existing_card_order ADD PRIMARY KEY(order_id)');
$pdo->exec("CREATE TEMPORARY TABLE tmp_rh_existing_project_gift_order AS SELECT order_id FROM eb_store_order WHERE order_id LIKE 'RH-RELOAD-102-GIFT-PROJECT-%'");
$pdo->exec('ALTER TABLE tmp_rh_existing_project_gift_order ADD PRIMARY KEY(order_id)');
$pdo->exec('DROP TEMPORARY TABLE IF EXISTS tmp_rh_balance');
$pdo->exec("CREATE TEMPORARY TABLE tmp_rh_balance AS SELECT source_customer_id,SUM(branch_amount) balance_amount FROM (
 SELECT CONVERT(cd.CustomerId USING utf8mb4) source_customer_id,ROUND(SUM(COALESCE(cd.Balance,0)),0) branch_amount FROM jsj.bd_CardDetail cd JOIN jsj.bd_CardClass cc ON cc.Id=cd.CardClassId
 WHERE COALESCE(cd.fstate,0)<>-1 AND (cd.EffectiveDate>='2026-09-17' OR cd.EffectiveDate IS NULL) AND cd.pflag='0' AND LEFT(cc.CardKindNo,3)<>'003' GROUP BY cd.CustomerId
 UNION ALL SELECT CONVERT(cd.CustomerId USING utf8mb4),ROUND(SUM(COALESCE(cd.Balance,0)),0) FROM jsj.bd_CardDetail cd JOIN jsj.bd_CardClass cc ON cc.Id=cd.CardClassId
 WHERE COALESCE(cd.fstate,0)<>-1 AND (cd.EffectiveDate>='2026-09-17' OR cd.EffectiveDate IS NULL) AND cd.pflag='0' AND LEFT(cc.CardKindNo,3)='003' AND COALESCE(cd.Balance,0)<>0 GROUP BY cd.CustomerId
 ) b GROUP BY source_customer_id");
$pdo->exec('ALTER TABLE tmp_rh_balance ADD PRIMARY KEY(source_customer_id)');

$pdo->beginTransaction();
try {
  // A phone merge is represented as one target uid; all source balances add together.
  $pdo->exec("INSERT INTO mig_jsj_20260917_negative_balance_audit(target_uid,target_store_id,negative_amount,created_at) SELECT m.target_uid,MAX(m.target_store_id),SUM(b.balance_amount),NOW() FROM tmp_rh_balance b JOIN mig_jsj_20260917_member_resolution m ON m.source_customer_id=b.source_customer_id GROUP BY m.target_uid HAVING SUM(b.balance_amount)<0 ON DUPLICATE KEY UPDATE target_store_id=VALUES(target_store_id),negative_amount=VALUES(negative_amount),created_at=NOW()");
  // JSJ only supplies one aggregate balance.  It has no source field that can
  // distinguish principal from bonus, so preserve the entire authoritative
  // amount as principal.  This keeps eb_user's required invariant
  // now_money = ben_money + give_money true for every imported member.
  $pdo->exec("UPDATE eb_user u JOIN (SELECT m.target_uid,SUM(b.balance_amount) amount FROM tmp_rh_balance b JOIN mig_jsj_20260917_member_resolution m ON m.source_customer_id=b.source_customer_id GROUP BY m.target_uid) x ON x.target_uid=u.uid SET u.now_money=GREATEST(x.amount,0),u.give_money=0,u.ben_money=GREATEST(x.amount,0)");
  $pdo->exec('DROP TEMPORARY TABLE IF EXISTS tmp_rh_right');
  $pdo->exec("CREATE TEMPORARY TABLE tmp_rh_right AS
    SELECT CONVERT(pt.Id USING utf8mb4) source_line_id,CONVERT(cd.Id USING utf8mb4) source_card_id,CONVERT(cd.CustomerId USING utf8mb4) source_customer_id,
      CONVERT(COALESCE(cd.ShopId,'') USING utf8mb4) source_shop_id,CONVERT(COALESCE(cd.CardNo,'') USING utf8mb4) source_card_no,
      CONVERT(COALESCE(cd.CardClassId,'') USING utf8mb4) source_card_class_id,CONVERT(pt.ProjectId USING utf8mb4) source_project_id,
      LEFT(COALESCE(ps.ProjectName,''),255) project_name,ROUND(COALESCE(pt.Quantity,0),0) total_qty_target,
      ROUND(pt.Quantity-COALESCE(pt.UseQuantity,0),0) remain_qty_target,COALESCE(pt.UseCardPrice,0) unit_price,cd.EffectiveDate effective_date,cd.IssueDate issue_date,
      m.target_uid,m.target_store_id,cp.target_product_id card_product_id,pp.target_product_id project_root_id,cm.card_no,
      csku.`unique` card_sku_unique,child.product_id component_product_id,psku.`unique` component_sku_unique,
      COALESCE(UNIX_TIMESTAMP(cd.IssueDate),UNIX_TIMESTAMP()) occurred_at
    FROM jsj.bd_CardDetail cd JOIN jsj.bd_ProjectAndTimes pt ON pt.CardDetailId=cd.Id JOIN jsj.bd_ProjectSet ps ON ps.Id=pt.ProjectId
    JOIN tmp_rh_member_map m ON m.source_customer_id=CONVERT(cd.CustomerId USING utf8mb4) COLLATE utf8mb4_general_ci
    JOIN tmp_rh_card_product cp ON cp.source_id=CONVERT(cd.CardClassId USING utf8mb4) COLLATE utf8mb4_general_ci
    JOIN tmp_rh_project_product pp ON pp.source_id=CONVERT(pt.ProjectId USING utf8mb4) COLLATE utf8mb4_general_ci
    JOIN tmp_rh_card_map cm ON cm.source_card_id=CONVERT(cd.Id USING utf8mb4) COLLATE utf8mb4_general_ci
    JOIN eb_store_product_attr_value csku ON csku.product_id=cp.target_product_id AND csku.product_type=5
    JOIN tmp_rh_project_child child ON child.source_project_id=CONVERT(pt.ProjectId USING utf8mb4) COLLATE utf8mb4_general_ci AND child.target_store_id=m.target_store_id
    JOIN eb_store_product_attr_value psku ON psku.product_id=child.product_id AND psku.product_type=6
    WHERE COALESCE(cd.fstate,0)<>-1 AND (cd.EffectiveDate>='2026-09-17' OR cd.EffectiveDate IS NULL) AND cd.pflag<>'0'
      AND COALESCE(pt.ShiFouUse,'')='' AND pt.Quantity-COALESCE(pt.UseQuantity,0)>0 AND cd.CardClassId<>'0091000112'");
  $pdo->exec('ALTER TABLE tmp_rh_right ADD PRIMARY KEY(source_line_id),ADD KEY idx_card(source_card_id)');
  $source=(int)$pdo->query("SELECT COUNT(*) FROM jsj.bd_CardDetail cd JOIN jsj.bd_ProjectAndTimes pt ON pt.CardDetailId=cd.Id JOIN jsj.bd_ProjectSet ps ON ps.Id=pt.ProjectId WHERE COALESCE(cd.fstate,0)<>-1 AND (cd.EffectiveDate>='2026-09-17' OR cd.EffectiveDate IS NULL) AND cd.pflag<>'0' AND COALESCE(pt.ShiFouUse,'')='' AND pt.Quantity-COALESCE(pt.UseQuantity,0)>0 AND cd.CardClassId<>'0091000112'")->fetchColumn();$resolved=(int)$pdo->query('SELECT COUNT(*) FROM tmp_rh_right')->fetchColumn();if($source!==$resolved)throw new RuntimeException("ordinary rights preflight failed: $resolved/$source resolved");
  $pdo->exec('DROP TEMPORARY TABLE IF EXISTS tmp_rh_card_scope');
  $pdo->exec("CREATE TEMPORARY TABLE tmp_rh_card_scope AS SELECT source_card_id,MAX(source_customer_id) source_customer_id,MAX(target_uid) target_uid,MAX(target_store_id) target_store_id,MAX(card_product_id) card_product_id,MAX(card_sku_unique) card_sku_unique,MAX(card_no) card_no,MAX(occurred_at) occurred_at,MAX(effective_date) effective_date,COUNT(*) line_count,SUM(total_qty_target) write_times,SUM(remain_qty_target) remain_times,SUM(ROUND(unit_price*total_qty_target,0)) total_amount FROM tmp_rh_right GROUP BY source_card_id");
  $pdo->exec('ALTER TABLE tmp_rh_card_scope ADD PRIMARY KEY(source_card_id)');
  $pdo->exec("INSERT INTO eb_store_order(type,order_id,store_id,uid,real_name,user_phone,total_num,total_price,pay_price,paid,pay_type,status,is_del,add_time,pay_time,mark,remark,`unique`,product_type,shipping_type,channel_type)
    SELECT 11,CONCAT('RH-RELOAD-102-CARD-',s.source_card_id),s.target_store_id,s.target_uid,LEFT(COALESCE(u.real_name,''),32),LEFT(COALESCE(u.phone,''),18),s.line_count,s.total_amount,s.total_amount,1,'migration',0,0,s.occurred_at,s.occurred_at,'RH-RELOAD-102-CARD',CONCAT('source_card_id=',s.source_card_id),MD5(CONCAT('RH-RELOAD-102-CARD-',s.source_card_id)),5,4,'migration' FROM tmp_rh_card_scope s JOIN eb_user u ON u.uid=s.target_uid LEFT JOIN tmp_rh_existing_card_order x ON x.order_id=CONCAT('RH-RELOAD-102-CARD-',s.source_card_id) WHERE x.order_id IS NULL");
  $pdo->exec("INSERT INTO eb_store_order_cart_info(uid,oid,cart_id,cart_type,type,relation_id,product_id,product_type,sku_unique,is_card,cart_num,write_times,write_surplus_times,surplus_num,is_writeoff,write_start,write_end,cart_info,`unique`,add_time,source_type)
    SELECT o.uid,o.id,CONCAT('RH-MAIN-',s.source_card_id),0,0,o.store_id,s.card_product_id,5,s.card_sku_unique,1,1,1,1,1,0,0,0,JSON_OBJECT('source_type','rh_jsj_reload','source_card_id',s.source_card_id),MD5(CONCAT('RH-MAIN-',s.source_card_id)),s.occurred_at,'migration' FROM tmp_rh_card_scope s JOIN eb_store_order o ON o.order_id=CONCAT('RH-RELOAD-102-CARD-',s.source_card_id) LEFT JOIN tmp_rh_existing_card_order x ON x.order_id=o.order_id WHERE x.order_id IS NULL");
  $pdo->exec("INSERT INTO eb_store_order_cart_info(uid,oid,cart_id,cart_type,type,relation_id,product_id,product_type,sku_unique,is_card,cart_num,total_price,pay_price,write_times,write_surplus_times,surplus_num,is_writeoff,write_start,write_end,cart_info,`unique`,add_time,source_type)
    SELECT o.uid,o.id,CONCAT('RH-LINE-',r.source_line_id),2,0,o.store_id,r.component_product_id,6,r.component_sku_unique,1,1,ROUND(r.unit_price*r.total_qty_target,0),ROUND(r.unit_price*r.total_qty_target,0),r.total_qty_target,r.remain_qty_target,r.remain_qty_target,0,0,0,JSON_OBJECT('source_type','rh_jsj_reload','source_card_id',r.source_card_id,'source_line_id',r.source_line_id,'project_name',r.project_name,'productInfo',JSON_OBJECT('id',r.component_product_id,'product_id',r.component_product_id,'store_name',LEFT(p.store_name,255),'product_type',6,'attrInfo',JSON_OBJECT('unique',r.component_sku_unique))),MD5(CONCAT('RH-LINE-',r.source_line_id)),r.occurred_at,'migration' FROM tmp_rh_right r JOIN eb_store_product p ON p.id=r.component_product_id JOIN eb_store_order o ON o.order_id=CONCAT('RH-RELOAD-102-CARD-',r.source_card_id) LEFT JOIN tmp_rh_existing_card_order x ON x.order_id=o.order_id WHERE x.order_id IS NULL");
  $pdo->exec("INSERT INTO eb_user_card_holder(uid,oid,card_name,card_no,store_id,product_id,product_type,verify_code,write_valid,write_days,write_start,write_end,write_times,write_surplus_times,is_del,add_time)
    SELECT o.uid,o.id,LEFT(p.store_name,255),s.card_no,o.store_id,s.card_product_id,5,LEFT(MD5(CONCAT('RH-VERIFY-',s.source_card_id)),12),1,0,0,COALESCE(UNIX_TIMESTAMP(s.effective_date),0),s.write_times,s.remain_times,0,s.occurred_at FROM tmp_rh_card_scope s JOIN eb_store_order o ON o.order_id=CONCAT('RH-RELOAD-102-CARD-',s.source_card_id) JOIN eb_store_product p ON p.id=s.card_product_id LEFT JOIN tmp_rh_existing_card_order x ON x.order_id=o.order_id WHERE x.order_id IS NULL");
  $pdo->exec('DROP TEMPORARY TABLE IF EXISTS tmp_rh_project_gift');
  $pdo->exec("CREATE TEMPORARY TABLE tmp_rh_project_gift AS SELECT CONVERT(g.id USING utf8mb4) source_id,CONVERT(g.CustomerId USING utf8mb4) source_customer_id,CONVERT(COALESCE(g.Shop,'') USING utf8mb4) source_shop_id,CONVERT(g.ProjectId USING utf8mb4) source_project_id,LEFT(COALESCE(ps.ProjectName,''),255) project_name,ROUND(COALESCE(g.Times,0),0) total_qty_target,ROUND(g.Times-COALESCE(g.ConsumeQuantity,0),0) remain_qty_target,COALESCE(g.Price,0) unit_price,COALESCE(UNIX_TIMESTAMP(g.SendDate),UNIX_TIMESTAMP('2026-09-17 00:00:00')) occurred_at,DATE(g.EffectiveDate) effective_date,m.target_uid,m.target_store_id,child.product_id,sku.`unique` sku_unique,COALESCE(UNIX_TIMESTAMP(g.SendDate),UNIX_TIMESTAMP()) occurred_ts FROM jsj.SS_SendProject g JOIN jsj.bd_ProjectSet ps ON ps.Id=g.ProjectId JOIN tmp_rh_member_map m ON m.source_customer_id=CONVERT(g.CustomerId USING utf8mb4) COLLATE utf8mb4_general_ci JOIN tmp_rh_project_child child ON child.source_project_id=CONVERT(g.ProjectId USING utf8mb4) COLLATE utf8mb4_general_ci AND child.target_store_id=m.target_store_id JOIN eb_store_product_attr_value sku ON sku.product_id=child.product_id AND sku.product_type=6 WHERE g.StartDate<='2026-09-17' AND g.EffectiveDate>='2026-09-17' AND g.Times-COALESCE(g.ConsumeQuantity,0)>0 AND g.Fstate='0'");
  $pdo->exec('ALTER TABLE tmp_rh_project_gift ADD PRIMARY KEY(source_id)');$giftSource=(int)$pdo->query("SELECT COUNT(*) FROM jsj.SS_SendProject g JOIN jsj.bd_ProjectSet ps ON ps.Id=g.ProjectId WHERE g.StartDate<='2026-09-17' AND g.EffectiveDate>='2026-09-17' AND g.Times-COALESCE(g.ConsumeQuantity,0)>0 AND g.Fstate='0'")->fetchColumn();$giftResolved=(int)$pdo->query('SELECT COUNT(*) FROM tmp_rh_project_gift')->fetchColumn();if($giftSource!==$giftResolved)throw new RuntimeException("project gifts preflight failed: $giftResolved/$giftSource resolved");
  $pdo->exec("INSERT INTO eb_store_order(type,order_id,store_id,uid,real_name,user_phone,total_num,total_price,pay_price,paid,pay_type,status,is_del,add_time,pay_time,mark,remark,`unique`,product_type,shipping_type,channel_type)
    SELECT 11,CONCAT('RH-RELOAD-102-GIFT-PROJECT-',source_id),target_store_id,target_uid,LEFT(COALESCE(u.real_name,''),32),LEFT(COALESCE(u.phone,''),18),1,0,0,1,'migration',0,0,occurred_ts,occurred_ts,'RH-RELOAD-102-GIFT-PROJECT',CONCAT('source_id=',source_id),MD5(CONCAT('RH-RELOAD-102-GIFT-PROJECT-',source_id)),6,4,'migration' FROM tmp_rh_project_gift g JOIN eb_user u ON u.uid=g.target_uid LEFT JOIN tmp_rh_existing_project_gift_order x ON x.order_id=CONCAT('RH-RELOAD-102-GIFT-PROJECT-',g.source_id) WHERE x.order_id IS NULL");
  $pdo->exec("INSERT INTO eb_store_order_cart_info(uid,oid,cart_id,cart_type,type,relation_id,product_id,product_type,sku_unique,is_gift,cart_num,write_times,write_surplus_times,surplus_num,is_writeoff,total_price,pay_price,cart_info,`unique`,add_time,source_type)
    SELECT o.uid,o.id,CONCAT('RH-PG-',g.source_id),2,0,o.store_id,g.product_id,6,g.sku_unique,1,1,g.total_qty_target,g.remain_qty_target,g.remain_qty_target,0,0,0,JSON_OBJECT('source_type','rh_jsj_reload','source_id',g.source_id,'project_name',g.project_name,'productInfo',JSON_OBJECT('id',g.product_id,'product_id',g.product_id,'store_name',LEFT(p.store_name,255),'product_type',6,'attrInfo',JSON_OBJECT('unique',g.sku_unique))),MD5(CONCAT('RH-PG-',g.source_id)),g.occurred_ts,'migration' FROM tmp_rh_project_gift g JOIN eb_store_product p ON p.id=g.product_id JOIN eb_store_order o ON o.order_id=CONCAT('RH-RELOAD-102-GIFT-PROJECT-',g.source_id) LEFT JOIN tmp_rh_existing_project_gift_order x ON x.order_id=o.order_id WHERE x.order_id IS NULL");
  $pdo->commit();
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
echo json_encode(['balances'=>(int)$pdo->query('SELECT COUNT(*) FROM tmp_rh_balance')->fetchColumn(),'rights_lines'=>(int)$pdo->query("SELECT COUNT(*) FROM eb_store_order_cart_info WHERE cart_id LIKE 'RH-LINE-%'")->fetchColumn(),'holders'=>(int)$pdo->query("SELECT COUNT(*) FROM eb_user_card_holder")->fetchColumn(),'project_gifts'=>(int)$pdo->query("SELECT COUNT(*) FROM eb_store_order_cart_info WHERE cart_id LIKE 'RH-PG-%'")->fetchColumn()],JSON_UNESCAPED_UNICODE).PHP_EOL;
