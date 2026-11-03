<?php
declare(strict_types=1);
/** 本地连接专用：同名临时表只装合成来源证据，覆盖同号壳单、组合行和跨日原单，不写持久业务表。 */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();
use think\facade\Db;
use app\services\report\StoreReconciliationOrderNavigation as Navigation;
set_exception_handler(static function ($e): void { file_put_contents('php://stderr',(string)$e."\n"); exit(1); });
if (config('database.connections.mysql.hostname') !== 'mysql' || Db::query('SELECT DATABASE() db')[0]['db'] !== 'ruihao') throw new RuntimeException('Local ruihao only');
$tables = []; $checks = 0;
$check = static function ($ok,$label) use (&$checks): void { if (!$ok) throw new RuntimeException($label); ++$checks; };
$reject = static function ($fn,$label) use ($check): void { try { $fn(); } catch (InvalidArgumentException $e) { $check(true,$label); return; } throw new RuntimeException($label); };
try {
    foreach ([
        'store_order'=>'id INT PRIMARY KEY,order_id VARCHAR(80),store_id INT,uid INT,pid INT,link_id INT,order_type INT,is_debt_repay INT,pay_time INT',
        'cashier_v3_sales_order'=>'order_id VARCHAR(80) PRIMARY KEY,order_no VARCHAR(80),tenant_id VARCHAR(30),store_id INT,member_id INT,source_document_type VARCHAR(50),source_document_id VARCHAR(80),order_status VARCHAR(20),order_direction VARCHAR(20),business_date DATE',
        'user_recharge'=>'id INT PRIMARY KEY,order_id VARCHAR(80),store_id INT,uid INT,add_time INT',
        'store_debt_repay'=>'id INT PRIMARY KEY,repay_order_id INT,pay_store_id INT,uid INT',
        'cashier_v3_debt_repayment'=>'repayment_id VARCHAR(80),tenant_id VARCHAR(30),store_id INT,member_id INT,repayment_no VARCHAR(80)',
        'cashier_v3_recharge_debt_repayment'=>'repayment_id VARCHAR(80),tenant_id VARCHAR(30),store_id INT,member_id INT,repayment_no VARCHAR(80)',
    ] as $table=>$ddl) {
        Db::execute('CREATE TEMPORARY TABLE eb_'.$table.' ('.$ddl.') ENGINE=InnoDB'); $tables[]=$table;
    }
    $old=['id'=>11,'order_id'=>'SAME-NO','store_id'=>8,'uid'=>9,'pid'=>0,'link_id'=>0,'order_type'=>0,'is_debt_repay'=>0,'pay_time'=>1234];
    Db::name('store_order')->insert($old);
    $h=['order_id'=>'imported-11','order_no'=>'SAME-NO','tenant_id'=>'0','store_id'=>8,'member_id'=>9,'source_document_type'=>'legacy_store_order','source_document_id'=>'11','order_status'=>'settled','order_direction'=>'forward','business_date'=>'2026-10-29'];
    Db::name('cashier_v3_sales_order')->insert($h);
    $receipt=['receipt_id'=>'legacy-order:11:legacy_1','document_no'=>'SAME-NO','store_id'=>8,'member_id'=>9,'business_date'=>'2026-10-28','amount_cents'=>59800,'order_reference'=>['type'=>'legacy_store_order','id'=>'11']];
    $locator=new Navigation();
    $check($locator->recordIds([$receipt],'0')===['sales:imported-11'],'Exact imported source replaces same-number empty carrier');
    $check($receipt['business_date']==='2026-10-28' && Db::name('cashier_v3_sales_order')->value('business_date')==='2026-10-29','Source day never overwrites order business day');
    $check($locator->recordIds([$receipt,$receipt],'0')===['sales:imported-11'],'Multiple payment rows navigate to one order');
    // 同号但其他租户/门店/会员/原主键的正式头不能窃取关联，仍只定位已验证的旧主键。
    foreach (['tenant_id'=>'foreign','store_id'=>88,'member_id'=>99,'source_document_id'=>'22','order_no'=>'OTHER-NO'] as $field=>$value) {
        Db::name('cashier_v3_sales_order')->where('order_id','imported-11')->update([$field=>$value]);
        $check($locator->recordIds([$receipt],'0')===['sales:legacy-sale-11'],'Identity boundary '.$field);
        Db::name('cashier_v3_sales_order')->where('order_id','imported-11')->update([$field=>$h[$field]]);
    }
    $foreign=$receipt; $foreign['store_id']=77;
    $reject(static function () use ($locator,$foreign) { $locator->recordIds([$foreign],'0'); },'Foreign source fails closed');
    $missing=$receipt; unset($missing['order_reference']);
    $reject(static function () use ($locator,$missing) { $locator->recordIds([$missing],'0'); },'Document number alone is insufficient');
    Db::name('user_recharge')->insert(['id'=>5,'order_id'=>'CZ-5','store_id'=>8,'uid'=>9,'add_time'=>1234]);
    $r=array_replace($receipt,['document_no'=>'CZ-5','order_reference'=>['type'=>'recharge','id'=>'RCH:5']]);
    $check($locator->recordIds([$r],'0')===['recharge:recharge:5'],'Recharge uses its own stable source ID');
    $r['member_id']=90;
    $reject(static function () use ($locator,$r) { $locator->recordIds([$r],'0'); },'Recharge member binding');
    Db::name('store_order')->insert(array_replace($old,['id'=>12,'pid'=>-2,'link_id'=>5,'order_type'=>1,'order_id'=>'OLD-CARRIER']));
    $r=array_replace($receipt,['document_no'=>'OLD-CARRIER','order_reference'=>['type'=>'legacy_store_order','id'=>'12']]);
    $check($locator->recordIds([$r],'0')===['recharge:recharge:5'],'Old recharge carrier resolves through explicit link and payment window');
    foreach (['debt_repayment'=>'v3-sales-supplement:','recharge_debt_repayment'=>'v3-supplement:'] as $type=>$prefix) {
        Db::name('cashier_v3_'.$type)->insert(['repayment_id'=>'PAY-1','tenant_id'=>'0','store_id'=>8,'member_id'=>9,'repayment_no'=>'BJ-1']);
        $r=array_replace($receipt,['document_no'=>'BJ-1','order_reference'=>['type'=>$type,'id'=>'PAY-1']]);
        $check($locator->recordIds([$r],'0')===['supplement:'.$prefix.'PAY-1'],'Independent supplement '.$type);
    }
    Db::name('store_order')->insert(array_replace($old,['id'=>13,'is_debt_repay'=>1,'order_id'=>'BJ-OLD']));
    Db::name('store_debt_repay')->insert(['id'=>7,'repay_order_id'=>13,'pay_store_id'=>8,'uid'=>9]);
    $r=array_replace($receipt,['document_no'=>'BJ-OLD','order_reference'=>['type'=>'legacy_store_order','id'=>'13']]);
    $check($locator->recordIds([$r],'0')===['supplement:legacy-supplement:7'],'Old supplement uses explicit repayment order link');
    $reject(static function () use ($locator) { $locator->selection(['visible_store_ids'=>[8]],['storeId'=>99,'methodCode'=>'legacy_1','from'=>'2026-10-28','to'=>'2026-10-28']); },'Unauthorized store cannot select receipts');
    $reject(static function () use ($locator) { $locator->selection(['visible_store_ids'=>[8]],['storeId'=>8,'methodCode'=>'brush_douyin','from'=>'2026-10-28','to'=>'2026-10-28']); },'Manual brush has no order navigation');
    echo 'PASS R9 ORDER IDENTITY checks='.$checks."\n";
} finally { foreach (array_reverse($tables) as $table) Db::execute('DROP TEMPORARY TABLE IF EXISTS eb_'.$table); }
