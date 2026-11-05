<?php
/** 与基线的真实只读卡汇总逐会员对比，保护本轮SQL聚合提取前后的历史分精度和次数。 */
require '/var/www/html/vendor/autoload.php';(new think\App('/var/www/html/'))->initialize();
use think\facade\Db;
if(strpos((string)config('database.connections.mysql.hostname'),'mysql')===false)throw new Exception('local only');
$baseline=getenv('R10_BASELINE_PROVIDER');if(!$baseline||!is_file($baseline))throw new Exception('require immutable baseline fixture');require $baseline;
Db::execute('SET TRANSACTION READ ONLY');Db::startTrans();
try {
 foreach([['100.00',6,3],['10.00',7,7],['1.00',3,1000000]] as [$price,$times,$remaining]){
  $expected=bcmul(bcdiv($price,(string)$times,8),(string)$remaining,2);
  $actual=Db::query('SELECT TRUNCATE(TRUNCATE(CAST(? AS DECIMAL(40,12)) / CAST(? AS UNSIGNED),8) * CAST(? AS UNSIGNED),2) amount',[$price,$times,$remaining])[0]['amount'];
  if(bccomp((string)$actual,$expected,2)!==0)throw new Exception('division precision differs from baseline');
 }
 $rt=app\services\query\UnifiedQueryRuntime::runtime();$new=app\services\query\UnifiedQueryRuntime::service('providers')->resolve('member_list');
 $old=(new ReflectionClass(app\services\query\provider\R10BaselineMemberUnifiedQueryProvider::class))->newInstanceWithoutConstructor();
 $ids=array_map('intval',Db::name('user_card_holder')->where('write_surplus_times','>',0)->where('is_del',0)->distinct(true)->limit(100)->column('uid'));
 $a=new ReflectionMethod($new,'cardSummaries');$a->setAccessible(true);$b=new ReflectionMethod($old,'cardSummaries');$b->setAccessible(true);
 foreach([false,true] as $all){$ctx=['tenant_id'=>'0','store_id'=>1];$actual=$a->invoke($new,$ids,[1],$all,$ctx);$expected=$b->invoke($old,$ids,[1],$all,$ctx);ksort($actual);ksort($expected);foreach($actual as &$v)ksort($v);unset($v);foreach($expected as &$v)ksort($v);unset($v);
  if($actual!==$expected)throw new Exception('baseline card projection changed: '.json_encode(['actual'=>array_slice($actual,0,2,true),'expected'=>array_slice($expected,0,2,true)]));
 }
 echo json_encode(['result'=>'PASS','members'=>count($ids),'scopes'=>['store','all'],'amount_precision'=>'unchanged','writes'=>0]),"\n";
}finally{Db::rollback();}
