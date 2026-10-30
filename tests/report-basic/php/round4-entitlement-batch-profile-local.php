<?php
/** 本地只读批量会员权益性能抽样，只输出计数和耗时，不输出身份或 SQL 参数。 */
declare(strict_types=1);
$root = getenv('BACKEND_ROOT') ?: getcwd();
require $root.'/vendor/autoload.php';
(new think\App($root.'/'))->initialize();
use think\facade\Db;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\cashier\v3\member\CashierV3MemberDetailQueryServices;
$connection=config('database.connections.mysql');
if(!in_array($connection['hostname']??'', ['mysql','mohe-mysql'],true))throw new RuntimeException('Local database only');
Db::execute('SET SESSION MAX_EXECUTION_TIME=8000');
Db::execute('SET SESSION TRANSACTION READ ONLY');
$store=(int)(getenv('REPORT_TEST_STORE')?:105);
$limit=min(100,max(1,(int)(getenv('REPORT_BENCH_MEMBERS')?:20)));
$ids=array_values(array_unique(array_map('intval',Db::name('user_card_holder')->alias('h')
    ->join('user u','u.uid=h.uid')
    ->join('store_user su','su.uid=u.uid AND su.store_id=u.belong_store_id')
    ->where('u.belong_store_id',$store)->where('u.status',1)->where('u.is_del',0)
    ->where('su.status',1)->where('h.is_del',0)
    ->group('h.uid')->order('h.uid asc')->limit($limit)->column('h.uid'))));
$tenant=CashierV3ScopeResolver::TENANT_SCOPE_ID;
$scope=new CashierV3DataScopeContext(999901,0,$store,$tenant,'',[$store],'stores',[],false,'','',[],[],true);
$operator=new CashierV3OperatorScope($store,999901,'',$tenant);
$groups=[];$templates=[];
Db::listen(static function($query,$seconds)use(&$groups,&$templates):void{
    $statement=strtolower((string)$query);$group='other';
    foreach(['cashier_v3_debt_authority','cashier_v3_card_rule','cashier_v3_card_purchase_receipt',
        'cashier_v3_refund_adjustment','store_order_cart_info','store_order','user_card_holder',
        'cashier_v3_sales_order_line'] as $table){if(strpos($statement,$table)!==false){$group=$table;break;}}
    if(!isset($groups[$group]))$groups[$group]=['queries'=>0,'ms'=>0];
    ++$groups[$group]['queries'];$groups[$group]['ms']+=(int)round((float)$seconds*1000);
    $normalized=preg_replace('/\x27(?:\x27\x27|[^\x27])*\x27|\b[0-9]+\b/','?',$statement);
    $normalized=preg_replace('/\s+/',' ',$normalized);
    $fingerprint=substr(hash('sha256',$normalized),0,12);
    if(!isset($templates[$fingerprint]))$templates[$fingerprint]=['group'=>$group,'queries'=>0,'ms'=>0,
        'shape'=>substr($normalized,0,150)];
    ++$templates[$fingerprint]['queries'];$templates[$fingerprint]['ms']+=(int)round((float)$seconds*1000);
});
$started=microtime(true);
$read=(new CashierV3MemberDetailQueryServices())->readAiRightsSet($ids,$operator,$scope);
$cards=0;$projects=0;
foreach($read as $member){$cards+=count($member['cards']??[]);foreach($member['cards']??[] as $card)$projects+=count($card['projects']??[]);}
uasort($groups,static function($a,$b){return $b['ms']<=>$a['ms'];});
uasort($templates,static function($a,$b){return $b['queries']<=>$a['queries'];});
echo json_encode(['local_only'=>true,'business_writes'=>0,'store_id'=>$store,
    'members'=>count($ids),'cards'=>$cards,'projects'=>$projects,
    'elapsed_ms'=>(int)((microtime(true)-$started)*1000),'query_groups'=>$groups,
    'top_templates'=>array_slice($templates,0,10,true)],
    JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),PHP_EOL;
Db::execute('SET SESSION TRANSACTION READ WRITE');
