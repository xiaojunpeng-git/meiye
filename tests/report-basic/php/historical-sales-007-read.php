<?php
declare(strict_types=1);
namespace think\facade {
 final class Db {public static $sql=[];public static function name($n){return new class($n){private $n;public function __construct($n){$this->n=$n;}public function getTable(){return 'eb_'.$this->n;}};}public static function query($sql){self::$sql[]=$sql;return[];}}
}
namespace {
$root=getenv('BACKEND_ROOT')?:dirname(__DIR__,3).'/后端代码';require$root.'/app/services/report/ReportLegacyArchiveProofServices.php';require$root.'/app/services/report/HistoricalSales007ReadServices.php';
function ck($x,$m){if(!$x)throw new \RuntimeException($m);}
$r=new \app\services\report\HistoricalSales007ReadServices();$range=['start'=>'2026-10-01','end'=>'2026-10-30'];
foreach(['store_items','products','store_quantities']as$kind){\think\facade\Db::$sql=[];$result=$r->read($kind,[4],$range,$kind==='products'?25:0);ck($result['complete'],'verified empty is complete');[$main,$unknown]=\think\facade\Db::$sql;
ck(strpos($main,"o.store_id=4")!==false,'server-authorized exact store');ck(strpos($main,"COALESCE(h.business_date,DATE(DATE_ADD('1970-01-01 00:00:00',INTERVAL (NULLIF(o.pay_time,0)+28800) SECOND))) BETWEEN '2026-10-01' AND '2026-10-30'")!==false,'approved business day');ck(strpos($main,'o.add_time >=')===false,'creation time is not report date');ck(strpos($main,"h.order_id=CONCAT('IMP007-',o.id,'-sale')")!==false,'unique imported identity');ck(strpos($main,'h.member_id=o.uid')!==false,'technical archive member proof');ck(strpos($main,"nh.source_document_type IN('cashier_snapshot','sales_order')")!==false,'native receipt not counted twice');ck(strpos($unknown,'h.id IS NULL AND IFNULL(o.pay_time,0)<=0')!==false,'unassigned date explicitly audited');ck(strpos($unknown,'ORDER BY `日期`')===false,'unknown-date outer query retains valid columns');
// 数量资格跟随007现金原单；其余报表保留各自已验收的金额来源。
if($kind==='store_quantities'){
ck(strpos($main,'IFNULL(o.cash_choose, 0) NOT IN (9, 10)')!==false,'纯欠款与旧卡录入不计销售项目');
ck(strpos($main,"co2.cash_choose NOT IN (9, 10) AND (co2.pay_sub_type IS NULL OR co2.pay_sub_type <> 'debt') AND co2.active_pay <> 3")!==false,'混合支付只需一笔有效非欠款支付且不放大数量');
}else ck(strpos($main,'NOT IN (9, 10)')===false,'数量修复不改其他报表口径');
if($kind==='products'){ck(strpos($main,'es.employee_id=25')!==false,'employee filter does not redivide');ck(strpos($main,'SUM(IFNULL(deduct_card_yeji, 0))')!==false,'original per-person balance, no inferred division');}elseif($kind==='store_items') ck(strpos($main,'o.pay_price - IFNULL(o.yue_pay_price, 0)')!==false,'007 combined-payment ceiling retained');else {ck(strpos($main,'SUM(IFNULL(c.cart_num,0))')!==false,'数量直接聚合');ck(strpos($main,'AS `现金业绩`')===false,'数量不计算现金金额');}}
try{$r->read('products',[4],['start'=>"2026-10-01' OR 1=1",'end'=>'2026-10-30']);throw new \RuntimeException('unsafe date accepted');}catch(\InvalidArgumentException$ok){}
echo "historical-sales-007-read: PASS\n";
}
