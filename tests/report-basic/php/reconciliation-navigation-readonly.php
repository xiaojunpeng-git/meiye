<?php
declare(strict_types=1);
/** 显式指定单实例、只读事务验证实际数据；可在加载固定候选类后include，不写账号、订单、事实或导出任务。 */
if (!class_exists(think\facade\Db::class)) {
    $root=getenv('BACKEND_ROOT') ?: '/var/www/html'; require $root.'/vendor/autoload.php'; (new think\App($root.'/'))->initialize();
}
use think\facade\Db;
use app\services\query\UnifiedQueryRuntime;
use app\services\order\PlatformOrderCenterReadServices;
set_exception_handler(static function ($e): void { file_put_contents('php://stderr',(string)$e."\n"); exit(1); });
$identity=Db::query('SELECT DATABASE() db,@@port port')[0];
if (!getenv('R9_EXPECT_DB') || $identity['db']!==getenv('R9_EXPECT_DB') || (int)$identity['port']!==(int)getenv('R9_EXPECT_PORT')) throw new RuntimeException('Explicit read-only instance identity required');
$selection=['storeId'=>(int)getenv('R9_STORE'),'methodCode'=>getenv('R9_METHOD') ?: 'legacy_1','from'=>getenv('R9_FROM'),'to'=>getenv('R9_TO')];
$checks=0; $check=static function ($ok,$label) use (&$checks): void { if (!$ok) throw new RuntimeException($label); ++$checks; };
Db::execute('SET TRANSACTION READ ONLY'); Db::startTrans();
try {
    $admin=Db::name('system_admin')->where('account',getenv('R9_ADMIN') ?: 'admin')->where('status',1)->where('is_del',0)->find();
    if (!$admin) throw new RuntimeException('Existing platform account required');
    [$operator,$scope,$context]=app()->make(PlatformOrderCenterReadServices::class)->exportScopes((int)$admin['id'],'order_center_all');
    $runtime=UnifiedQueryRuntime::runtime();$provider=$runtime['providers']->resolve('order_center_all');
    $query=['page'=>1,'pageSize'=>3,'dataScope'=>'normal','storeIds'=>[$selection['storeId']],'reconciliationDrilldown'=>$selection];
    $rows=[]; $total=null;
    for ($page=1;$page<=5;$page++) {
        $result=$provider->queryPage($context,array_replace($query,['page'=>$page]),$operator,$scope);
        if ($total===null) $total=$result['total'];
        $check($result['total']===$total,'Stable full matching count across pages');
        if (!$result['records']) break;
        $rows=array_merge($rows,$result['records']);
        if (count($rows)>100) throw new RuntimeException('Unexpected navigation population');
    }
    $check(count($rows)===$total,'Pagination does not lose source orders');
    $check(count(array_unique(array_column($rows,'record_id')))===$total,'No duplicated record identity');
    if (getenv('R9_EXPECT_COUNT')!==false) $check($total===(int)getenv('R9_EXPECT_COUNT'),'Expected actual source order count');
    $reportContext=$context;$reportContext['basic_scope_mode']='stores';$reportContext['visible_store_ids']=[$selection['storeId']];
    $report=(new app\services\report\StoreReconciliationReportServices())->query($reportContext,[
        'start_date'=>$selection['from'],'end_date'=>$selection['to'],'reconciliation_mode'=>'stores',
        'detail'=>['method_code'=>$selection['methodCode'],'column_key'=>'store_'.$selection['storeId']],
    ]);
    $ids=array_column($rows,'record_id');$expected=$report['navigation_record_ids'];sort($ids);sort($expected);
    $check($ids===$expected,'Order population exactly matches verified receipt identities');
    if (getenv('R9_EXPECT_CENTS')!==false) $check((int)bcmul((string)($report['summaries']['amount:sum']??0),'100',0)===(int)getenv('R9_EXPECT_CENTS'),'Report clicked amount unchanged');
    foreach ($rows as $row) $check((int)$row['storeId']===$selection['storeId'],'Store authorization preserved');
    if (getenv('R9_EXPECT_NAME')) foreach ($rows as $row) $check($row['payment_method']===getenv('R9_EXPECT_NAME'),'Configured method name agrees across sales and recharge');
    $empty=$selection;$empty['from']=$empty['to']='2000-01-01';
    $emptyResult=$provider->queryPage($context,array_replace($query,['reconciliationDrilldown'=>$empty]),$operator,$scope);
    $check($emptyResult['total']===0 && $emptyResult['records']===[],'Changed source period recomputes population instead of retaining old IDs');
    if ($rows) {
        $narrow=$provider->queryPage($context,array_replace($query,['filters'=>[['field'=>'order_no','operator'=>'eq','value'=>$rows[0]['order_no']]]]),$operator,$scope);
        $check($narrow['total']===1 && $narrow['records'][0]['record_id']===$rows[0]['record_id'],'Additional filter narrows exact source population');
    }
    // 统一导出冻结/重建完整保留同一来源范围；不创建异步任务或物理导出文件。
    $execution=$runtime['execution'];
    $normalized=$execution->normalizeUiQuery('order_center_all',['reconciliationDrilldown'=>$selection,'limit'=>3]);
    $check($normalized['reconciliationDrilldown']===$selection,'UI normalization retains receipt scope');
    // 与导出任务创建入口相同：空排序先冻结订单默认完成时间/稳定身份倒序。
    $plan=$execution->validatedPlan('order_center_all',[],['reconciliationDrilldown'=>$selection,'limit'=>3,
        'sorts'=>app\services\cashier\v3\order\AbstractCashierV3OrderCenterUnifiedQueryProvider::defaultOrderSorts('order_center_all')],$context);
    $check($plan['domain_scope']['reconciliation_drilldown']===$selection,'Frozen export includes receipt scope');
    $method=new ReflectionMethod($runtime['exports'],'validatedFrozenPlan');$method->setAccessible(true);
    $check($method->invoke($runtime['exports'],$context,'order_center_all',$plan,[])===$plan,'Worker revalidates identical domain plan');
    foreach (['query','page'] as $mode) {
        $export=$provider->executeFrozenPlan($context,$plan,$mode,['order_no','payment_method']);
        $exportRows=is_array($export['exportRows'])?$export['exportRows']:iterator_to_array($export['exportRows'],false);
        $actualNos=array_values(array_unique(array_column($exportRows,'order_no')));sort($actualNos);
        $want=array_values(array_unique(array_column($mode==='query'?$rows:array_slice($rows,0,3),'order_no')));sort($want);
        $check($actualNos===$want,'Frozen '.$mode.' export equals selected order population');
    }
    $bad=$selection;$bad['methodCode']='brush_douyin';$failed=false;
    try { $provider->queryPage($context,array_replace($query,['reconciliationDrilldown'=>$bad]),$operator,$scope); } catch (InvalidArgumentException $e) { $failed=true; }
    $check($failed,'Manual registrations cannot masquerade as orders');
    echo json_encode(['status'=>'PASS','checks'=>$checks,'db'=>$identity,'selection'=>$selection,'total'=>$total,
        'record_ids'=>$ids,'business_dates'=>array_values(array_unique(array_column($rows,'business_date'))),
        'payment_names'=>array_values(array_unique(array_column($rows,'payment_method')))],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";
} finally { Db::rollback(); }
