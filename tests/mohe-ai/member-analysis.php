<?php
// Synthetic contract coverage only. It never boots a framework or reads a real member.
require_once __DIR__.'/fixture-autoload.php';

use app\services\query\metric\AnalysisObjectCatalog;
use app\services\query\metric\GroupPerformanceMetricReadServices;
use app\services\query\metric\MemberAnalysisObjectServices;
use app\services\query\metric\MetricReadViewServices;
use app\services\query\metric\MetricReadViewStore;

$checks=0;
function maCheck($ok,$label){global $checks;if(!$ok)throw new RuntimeException($label);++$checks;}
function maReject(callable $call,string $code):void {try{$call();}catch(Throwable $e){$actual=method_exists($e,'getErrorCode')?$e->getErrorCode():$e->getMessage();maCheck($actual===$code,'expected '.$code.', got '.$actual);return;}throw new RuntimeException('expected '.$code);}

final class MemberFixtureQuery {
    public static $calls=[]; public static $lastTermCandidates=[]; private $table;
    public function __construct(string $table){$this->table=$table;self::$calls[]=['table',$table];}
    public function __call($name,$args){self::$calls[]=[$this->table,$name,$args];if($name==='whereIn'&&is_array($args[1]??null))self::$lastTermCandidates=$args[1];foreach($args as $arg)if(is_callable($arg))$arg($this);return $this;}
    public function find(){return $this->table==='user'?['uid'=>19,'real_name'=>'王湘英','nickname'=>'香香']:['amount_cents'=>'12345'];}
    public function select(){return $this;}
    public function toArray(){return $this->table==='user'?[['uid'=>19,'real_name'=>'王湘英','nickname'=>'香香']]:[];}
}

$scope=['member_authorized'=>true,'store_ids'=>[1,2],'scope_mode'=>'stores','permission_version'=>'member-fixture-v1'];
$factory=static function(string $table){return new MemberFixtureQuery($table);};
$members=new MemberAnalysisObjectServices($factory,static function()use(&$scope){return $scope;});
$numericMentions=$members->mentioned('本月累计实际收款销售额达到1000元的客户有多少？',['member_period_spend_threshold']);
maCheck($numericMentions['objects']===[]&&array_intersect(['10','00','100','1000'],MemberFixtureQuery::$lastTermCandidates)===[],'pure numeric amount fragments never become named-member candidates');
$mentions=$members->mentioned('王湘英今天花了多少钱？',['sales_collected_amount']);
maCheck(count($mentions['objects'])===1&&$mentions['objects'][0]['ref']==='member:19','only exact authorized question fragment becomes a member reference');
maCheck($mentions['objects'][0]['aliases']===['香香'],'the alternate authoritative label is masked too');
MemberFixtureQuery::$calls=[];
$conversationMentions=$members->mentionedConversation(['昨天没有点名会员','王湘英今天花了多少钱？','香香呢？'],['sales_collected_amount']);
$userQueries=count(array_filter(MemberFixtureQuery::$calls,static function(array $call):bool {
    return ($call[0]??null)==='table'&&($call[1]??null)==='user';
}));
maCheck(count($conversationMentions['objects'])===1&&$userQueries===1,
    'bounded conversation labels use one permission-scoped member query instead of one scan per turn');
$catalog=new AnalysisObjectCatalog($mentions['objects'],static function(){return true;});
maCheck(($catalog->resolve('香香','member','sales_collected_amount')['objects'][0]['ref']??null)==='member:19','nickname resolves only through the local exact catalogue');
maCheck($members->selection('member:19')['member_id']===19,'selection rechecks current member relation before a fact read');
$scope['scope_mode']='self_participant';maReject(fn()=>$members->selection('member:19'),'AI_MEMBER_PERMISSION_REQUIRED');$scope['scope_mode']='stores';

$reader=new GroupPerformanceMetricReadServices($factory,static function($query,$tenant,$order){$query->normalScope($tenant,$order);});
$range=['start'=>'2026-09-18','end'=>'2026-09-18'];
maCheck($reader->dimensionSelectionTotal('sales_collected_amount','member_selection','0',[1],$range,19)===12345,'selected member uses the registered sales collection fact in cents');
$calls=json_encode(MemberFixtureQuery::$calls,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
foreach(['cashier_v3_payment_sale_allocation_fact','cashier_v3_sale_fact','s.member_id','p.tenant_id','normalScope'] as $needle)maCheck(strpos($calls,$needle)!==false,'selected-member reader contract '.$needle);
maReject(fn()=>$reader->dimensionSelectionTotal('cash_performance','member_selection','0',[1],$range,19),'METRIC_QUERY_SHAPE_UNAVAILABLE');

$binding=['instance_id'=>'member-fixture','subject_ref'=>'member-fixture','terminal'=>'platform','tenant_id'=>'0','permission_version'=>'member-fixture-v1',
    'report_capability_code'=>'group_management_dashboard','scope_provider_code'=>'current_report_scope_v1','scope_mode'=>'stores','store_ids'=>[1]];
$directory=sys_get_temp_dir().'/mohe-member-fixture-'.bin2hex(random_bytes(8));
$store=new MetricReadViewStore($directory,str_repeat('member-fixture-',4));
$views=new MetricReadViewServices($store,static function()use(&$binding){return $binding;},static function($callback)use($reader){return $callback($reader);},null,null,$members);
$query=['query_shape'=>'summary','metric_codes'=>['sales_collected_amount'],'start_date'=>'2026-09-18','end_date'=>'2026-09-18',
    'compare_range'=>null,'store_ids'=>[],'business_filters'=>['object_kind'=>'member','selection_ref'=>'member:19'],'ranking'=>null,'aggregate_condition'=>null];
try {
    $view=$views->create([],$query);
    maCheck(($view['results'][0]['object_label']??null)==='王湘英'&&$view['results'][0]['amount_cents']===12345,'immutable view records the checked member label and exact registered amount');
    maCheck(($view['member_selection_label']??null)==='王湘英','selection evidence is bound into the signed read view');
    maCheck($views->replay([],$query,$view['read_consistency_ref'])['result_hash']===$view['result_hash'],'replay rebuilds the member binding without a new data read');
    $forged=$query;$forged['business_filters']['selection_ref']='member:0';maReject(fn()=>$views->create([],$forged),'METRIC_QUERY_SHAPE_UNAVAILABLE');
    $badShape=$query;$badShape['query_shape']='ranking';$badShape['ranking']=['direction'=>'top','limit'=>1];maReject(fn()=>$views->create([],$badShape),'METRIC_QUERY_SHAPE_UNAVAILABLE');
} finally {foreach(new DirectoryIterator($directory) as $file)if($file->isFile()&&!$file->isLink())unlink($file->getPathname());rmdir($directory);}
echo "PASS member analysis: $checks checks (synthetic data only)\n";
