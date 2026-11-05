<?php
/** 会员分别达标与分期，使用同一后端投影；验证身份、金额与退款隔离。 */
require '/var/www/html/vendor/autoload.php';
use app\services\report\StoreUnifiedReportPhaseTwoServices as Report;
use app\services\report\StoreLargeOrderInstallmentServices as Due;
$m=new ReflectionMethod(Report::class,'beautyLargeOrderMonthlyAwards');$m->setAccessible(true);
$base=['tenant_id'=>'0','store_id'=>1,'role_snapshot'=>'salesperson','employee_id'=>1,'member_id'=>101,'member_name_snapshot'=>'甲','business_date'=>'2026-10-01','rule_type'=>'beauty','beauty_category'=>'面部','award_category_id'=>1];
$rows=function(array $facts,string $month='2026-10')use($m):array{return Due::dueRows($facts,$m->invoke(null,$facts),$month);};
$n=0;$ok=function(bool $v,string $why)use(&$n){if(!$v)throw new RuntimeException($why);$n++;};
$a=$base+['amount_cents'=>2000000];$b=array_replace($a,['member_id'=>102,'member_name_snapshot'=>'乙']);
$ok($rows([$a,$b])===[],'不同会员两笔2万不能凑3万门槛');
$a['amount_cents']=3500000;$b['amount_cents']=3100000;$r=$rows([$a,$b]);
$ok(count($r)===2,'两名会员各自达标各一行');$ok(array_sum(array_column($r,'due_amount_cents'))===2640000,'两行应计合计正确');
$ok(count(array_unique(array_column($r,'row_key')))===2,'会员行键独立');$ok(array_sum(array_column($rows([$a,$b],'2026-11'),'due_amount_cents'))===1980000,'次月按各自3期计算');
$c=array_replace($a,['store_id'=>2]);$r=$rows([$a,$c]);$ok(count($r)===2&&count(array_unique(array_column($r,'row_key')))===2,'跨门店身份独立');
$c=array_replace($b,['amount_cents'=>-10000]);$r=$rows([$a,$b,$c]);$by=array_column($r,null,'member_id');$ok($by[101]['refund_amount_cents']===0&&$by[102]['refund_amount_cents']===10000,'退款仅归属该会员');$ok($by[102]['due_amount_cents']===1240000,'退款展示不改正向分期本金');
$c=array_replace($a,['role_snapshot'=>'sales_manager']);$ok(count($rows([$a,$c]))===2,'销售人与经理独立');
$c=array_replace($a,['rule_type'=>'partner','amount_cents'=>4900000]);$ok($rows([$c])===[],'合作方门槛保留5万');
$c['amount_cents']=10000000;$r=$rows([$c]);$ok($r[0]['installment_count']===6,'合作方高档6期保留');
// 未达标退款依然按会员显示，不能被同销售人的另一名达标会员吞掉。
$refundMethod = new ReflectionMethod(Report::class, 'beautyLargeOrderUnqualifiedRefundRows');
$refundMethod->setAccessible(true);
$refundA = array_replace($a, ['amount_cents' => -10000]);
$refundB = array_replace($b, ['amount_cents' => -20000]);
$refunds = $refundMethod->invoke(null, [$refundA, $refundB], [], '2026-10');
$ok(count($refunds) === 2, '未达标退款会员分开显示');
$ok(count(array_unique(array_column($refunds, 'row_key'))) === 2, '退款会员行键不串行');
$awards = $m->invoke(null, [$a, $refundB]);
$refunds = $refundMethod->invoke(null, [$a, $refundB], $awards, '2026-10');
$ok(count($refunds) === 1 && $refunds[0]['member_id'] === 102, '另一会员达标不能覆盖本会员退款');
echo json_encode(['passed'=>true,'checks'=>$n]),PHP_EOL;
