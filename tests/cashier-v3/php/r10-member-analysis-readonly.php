<?php
/** 本地恢复库只读业务核对：双端、组织裁剪、分页、导出、零值、无到店及资产投影。 */
$backend=getenv('BACKEND_ROOT')?:'/var/www/html';require $backend.'/vendor/autoload.php';
use think\facade\Db;
$app=new think\App($backend.'/');$app->initialize();
if(strpos((string)config('database.connections.mysql.hostname'),'mysql')===false)throw new Exception('local-only test');
Db::execute('SET TRANSACTION READ ONLY');Db::startTrans();$checks=[];
try {
 $admin=(int)Db::name('system_admin')->where('status',1)->where('is_del',0)->where('admin_type',0)->order('id')->value('id');
 $scope=(new app\services\user\PlatformMemberScopeServices())->build($admin);
 $rt=app\services\query\UnifiedQueryRuntime::runtime();
 $payload=['page'=>1,'limit'=>20,'dataScope'=>'normal','memberAnalysis'=>['cashPerformance'=>['min'=>'100','max'=>'']]];
 $ctx=(new app\services\query\provider\MemberUnifiedQueryContextFactory($rt['contextFactory']))->make($scope['operator'],$scope['data'],$payload);
 $store=(int)$scope['operator']->storeId();$ctx['visible_store_ids']=[$store];$ctx['all_stores']=false;$ctx['query_cutoff_date']='2026-11-05';
 $providers=app\services\query\UnifiedQueryRuntime::service('providers');$lists=[];
 foreach(['member_list','platform_member_list'] as $code){
  $provider=$providers->resolve($code);$q=$payload+['pageCode'=>$code];
  $list=$provider->query($ctx,$q);$lists[$code]=$list;
  $plan=$rt['execution']->validatedPlan($code,[],$q,$ctx);
  $taskService=app\services\query\UnifiedQueryRuntime::service('exports');
  $revalidate=new ReflectionMethod($taskService,'validatedFrozenPlan');$revalidate->setAccessible(true);
  if($revalidate->invoke($taskService,$ctx,$code,$plan,[])!==$plan)throw new Exception('worker plan changed');
  $export=$provider->executeFrozenPlan($ctx,$plan,'query',['member_name','phone']);
  if($export['exportRows'] instanceof \Traversable)$export['exportRows']=iterator_to_array($export['exportRows']);
  if((int)$list['total']!==count($export['exportRows']) || array_column($list['records'],'phone')!==array_column(array_slice($export['exportRows'],0,20),'phone'))throw new Exception('list/export mismatch '.$code);
  if($list['total']>20){$q['page']=2;$page2=$provider->query($ctx,$q);if(count($page2['records'])!==min(20,$list['total']-20)||array_intersect(array_column($page2['records'],'id'),array_column($list['records'],'id')))throw new Exception('pagination mismatch');}
  $checks[$code]=['total'=>$list['total'],'export'=>count($export['exportRows'])];
 }
 if($lists['member_list']['records']!==$lists['platform_member_list']['records'])throw new Exception('platform/store difference');
 $provider=$providers->resolve('member_list');$method=new ReflectionMethod($provider,'authorizedUserQuery');$method->setAccessible(true);
 $ids=function(array $analysis)use($provider,$method,$ctx):array{[$q]=$method->invoke($provider,$ctx,['memberAnalysis'=>$analysis],true);return array_map('intval',$q->order('u.uid')->column('u.uid'));};
 $all=$ids([]);$period=['min'=>'2026-10-01','max'=>'2026-10-31'];$yes=$ids(['visitPeriod'=>$period]);$no=$ids(['noVisitPeriod'=>$period]);
 if(array_intersect($yes,$no)||count($all)!==count($yes)+count($no))throw new Exception('absence does not partition authorized population');
 if($ids(['visitPeriod'=>$period,'noVisitPeriod'=>$period])!==[])throw new Exception('contradicting ranges did not return empty');
 foreach(['cashPerformance','consumeCount','visitCount','remainingTimes','remainingAmount','totalAssets'] as $field){
  $zero=$ids([$field=>['min'=>'0','max'=>'0']]);$positive=$ids([$field=>['min'=>$field==='remainingAmount'||$field==='totalAssets'||$field==='cashPerformance'?'0.01':'1','max'=>'']]);
  if(array_intersect($zero,$positive)||count($zero)+count($positive)!==count($all))throw new Exception('zero/positive partition '.$field);
  $checks[$field]=['zero'=>count($zero),'positive'=>count($positive)];
 }
 // 数值筛选命中的卡金额/次数必须能由同一批列表投影解释，而不是另一套估算值。
 foreach(['remainingAmount'=>'remainingProjectAmount','remainingTimes'=>'remainingProjectTimes','totalAssets'=>'totalAssets'] as $field=>$recordKey){
  $list=$provider->query($ctx,['memberAnalysis'=>[$field=>['min'=>'1','max'=>'1000000000']],'limit'=>20]);
  foreach($list['records'] as $row){$value=$field==='totalAssets'?bcadd($row['accountBalance'],$row['remainingProjectAmount'],2):$row[$recordKey];if(bccomp((string)$value,'1',2)<0)throw new Exception('projection differs from asset predicate');}
 }
 // 切换门店后只能得到该门店会员，空授权范围不能借分析条件扩大权限。
 $empty=$ctx;$empty['visible_store_ids']=[];$empty['all_stores']=false;
 if($provider->query($empty,$payload)['total']!==0)throw new Exception('empty scope escaped');
 $checks['period']=['visited'=>count($yes),'notVisited'=>count($no),'authorized'=>count($all)];
 echo json_encode(['result'=>'PASS','database'=>Db::query('SELECT DATABASE() db')[0]['db'],'store'=>$store,'checks'=>$checks,'writes'=>0],JSON_UNESCAPED_UNICODE),"\n";
}finally{Db::rollback();}
