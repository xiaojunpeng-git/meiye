<?php
declare(strict_types=1);
require (getenv('BACKEND_ROOT') ?: dirname(__DIR__,3).'/后端代码').'/vendor/autoload.php';
use app\services\report\HistoricalPartnerSales007ReadServices as Reader;
/** Original personnel amounts are read once, and mapped V3 duplicates must agree exactly. */
$p=['id'=>11,'type'=>2,'store_id'=>4,'staff_id'=>141,'order_id'=>8,'link_id'=>8,'yeji'=>'98.50','employee_id'=>141,'is_hezuofang'=>1];
$o=[8=>['store_id'=>4,'order_type'=>0,'business_date'=>'2026-10-02','migrated_order_id'=>null,'pay_time'=>1]];
$r=new Reader();$range=['start'=>'2026-10-01','end'=>'2026-10-30'];
$a=$r->project([$p],$o,[],[4],$range);
if(!$a['complete']||$a['rows'][0]['partner_amount_cents']!==9850||$a['rows'][0]['order_id']!=='historical:order:8')throw new RuntimeException('original exact cents/source');
$o[8]['migrated_order_id']='IMPORTED-8';
$f=['IMP007-Y11-sales'=>['certified'=>1,'store_id'=>4,'order_id'=>'IMPORTED-8','employee_id'=>141,'amount_cents'=>9850,'employee_type_snapshot'=>'partner']];
$a=$r->project([$p],$o,$f,[4],$range);
if(!$a['complete']||$a['rows']!==[])throw new RuntimeException('V3 duplicate should not add');
$terminal=$f;$terminal['IMP007-Y11-sales']['status']='reversed';
if($r->project([$p],$o,$terminal,[4],$range)['complete'])throw new RuntimeException('terminal V3 allocation must never revive original');
$f['IMP007-Y11-sales']['amount_cents']=9800;
if($r->project([$p],$o,$f,[4],$range)['complete'])throw new RuntimeException('V3 contradiction cannot fall back');
$z=$p;$z['is_hezuofang']=null;
if($r->project([$z],$o,[],[4],$range)['complete'])throw new RuntimeException('missing personnel identity');
$z=$p;$z['type']=1;$z['link_id']=900;$o[8]['order_type']=1;
if(!$r->project([$z],$o,[],[4],$range)['complete'])throw new RuntimeException('recharge allocation links separate recharge identity');
$o[8]['business_date']='2026-09-30';
if($r->project([$z],$o,[],[4],$range)['rows']!==[])throw new RuntimeException('business date must precede allocation timestamp');
echo "historical-partner-sales-007 PASS\n";
