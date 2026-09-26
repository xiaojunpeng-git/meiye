<?php
/** 本地只读回归：八类字段进入真实统一执行器；不创建订单、不调整人员、不作废。 */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';
$app = new \think\App($backend . '/'); $app->initialize();
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\order\CashierV3OrderCenterUnifiedQueryContract as Contract;
use app\services\cashier\v3\order\CashierV3OrderCenterQueryExecutionServices as Executor;
use app\services\cashier\v3\order\CashierV3OrderQueryModule;
use app\services\cashier\v3\query\UnifiedQueryModule;
use think\facade\Db;
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$operator = new CashierV3OperatorScope(133,71,'organization:test','0');
$scope = new CashierV3DataScopeContext(71,701,133,'0','organization:test',[133],CashierV3DataScopeContext::MODE_STORES,[],false,'','permission-v2',['cashier.v3.order_center','cashier.v3.order.service_detail'],[]);
$runtime = UnifiedQueryModule::runtime();
$execution = new Executor($runtime['registry'],$runtime['validator'],$runtime['evaluator']);
$method = new ReflectionMethod(CashierV3OrderQueryModule::class,'unifiedPage'); $method->setAccessible(true);
$query = function($type,$extra=[]) use($operator,$scope,$method) {
    return $method->invoke(null,['operator_scope'=>$operator,'data_scope'=>$scope,'payload'=>array_replace([
        'recordType'=>$type,'page'=>1,'pageSize'=>100,'dateFrom'=>'2026-09-26','dateTo'=>'2026-09-26','dataScope'=>'all',
    ],$extra)],$type);
};
$fieldCount=0;
foreach (Contract::PAGE_BY_TYPE as $type=>$pageCode) {
    $context=$runtime['contextFactory']->make($operator,$scope,['pageCode'=>$pageCode]);
    $definition=Contract::definition($pageCode);
    // 所有可配置字段逐项验证正反例，空业务分类不能用“返回零条”冒充通过。
    foreach($definition['fields'] as $field) {
        if(!empty($field['hidden']))continue;
        $key=$field['key'];$kind=$field['type'];
        $yes=in_array($kind,['amount','integer','decimal'],true)?'12':($kind==='date'?'2026-09-26':($kind==='datetime'?'2026-09-26 12:00:00':'命中'));
        $no=in_array($kind,['amount','integer','decimal'],true)?'99':($kind==='date'?'2026-09-25':($kind==='datetime'?'2026-09-25 12:00:00':'不匹配'));
        $result=$execution->execute($pageCode,[['record_id'=>'a',$key=>$yes],['record_id'=>'b',$key=>$no]],[],[
            'topFilters'=>[['field'=>$key,'operator'=>'eq','value'=>$yes]]
        ],$context,static fn()=>true);
        check(array_column($result['rows'],'record_id')===['a'], $type.'.'.$key.' ignored');$fieldCount++;
    }
    $key=$definition['keywordFields'][0];
    $result=$query($type,['topFilters'=>[['field'=>$key,'operator'=>'eq','value'=>'__R37_NEVER_EXISTS__']]]);
    check($result['total']===0,$type.' live filter ignored');
    echo $type." live impossible filter=0\n";
}
$staff=(int)Db::name('system_store_staff')->where('store_id',133)->where('staff_name','汤静静')->value('id');
check($staff>0,'missing local staff fixture');
$filter=[['field'=>'salesperson','operator'=>'eq','value'=>$staff]];
$selected=$query('sales',['topFilters'=>$filter]);
check($selected['total']===1 && $selected['records'][0]['salesOrderNo']==='XS26092600006','salesperson identity mismatch');
$base=$query('sales');
$negative=$query('sales',['topFilters'=>[['field'=>'salesperson','operator'=>'neq','value'=>$staff]]]);
check($negative['total']===$base['total']-1,'person negative relation wrong');
$missing=$query('sales',['topFilters'=>[['field'=>'salesperson','operator'=>'eq','value'=>'999999999']]]);
check($missing['total']===0,'unknown identity fell back to all');
$p1=$query('sales',['pageSize'=>2,'sorts'=>[['field'=>'sales_order_no','direction'=>'asc']]]);
$p2=$query('sales',['page'=>2,'pageSize'=>2,'sorts'=>[['field'=>'sales_order_no','direction'=>'asc']]]);
check(count($p1['records'])===2 && count($p2['records'])===2 && !array_intersect(array_column($p1['records'],'id'),array_column($p2['records'],'id')),'filtered pagination overlap');
$combo=$query('sales',['filters'=>[['field'=>'sales_order_no','operator'=>'eq','value'=>'XS26092600006'],['field'=>'sales_order_no','operator'=>'eq','value'=>'XS26092600007']],'filterRelation'=>'any']);
check($combo['total']===2,'OR combination ignored');
$both=$query('sales',['filters'=>[['field'=>'receivable_amount','operator'=>'gte','value'=>'3000']],'topFilters'=>$filter]);
check($both['total']===1,'combined amount and person failed');
$denied=$query('sales',['storeIds'=>[999999999]]);check($denied['total']===0,'scope broadened');
// 真实授权候选的过滤结果与完整导出结果使用同一个 run，不依赖页面再筛一遍。
$provider=clone $runtime['providers']->resolve(Contract::PAGE_BY_TYPE['sales']);
$context=$runtime['contextFactory']->make($operator,$scope,['pageCode'=>Contract::PAGE_BY_TYPE['sales']]);
$property=new ReflectionProperty(get_parent_class($provider),'liveScopes');$property->setAccessible(true);$property->setValue($provider,[$operator,$scope]);
$run=new ReflectionMethod($provider,'run');$run->setAccessible(true);
$export=$run->invoke($provider,$context,['topFilters'=>array_merge($filter,[['field'=>'business_date','operator'=>'eq','value'=>'2026-09-26']]),'dataScope'=>'all','export'=>['scope'=>'query','fields'=>['sales_order_no','salesperson']]],[]);
check($export['pagination']['total']===1,'export and list differ');
$plan=$runtime['execution']->validatedPlan(Contract::PAGE_BY_TYPE['sales'],[],[
    'topFilters'=>array_merge($filter,[['field'=>'business_date','operator'=>'eq','value'=>'2026-09-26'],
        ['field'=>'payment_completed_at','operator'=>'eq','value'=>'2026-09-26']]),'dataScope'=>'all'
],$context);
$frozen=$provider->executeFrozenPlan($context,$plan,'query',['sales_order_no','salesperson']);
check(count($frozen['exportRows'])===1 && $frozen['exportRows'][0]['sales_order_no']==='XS26092600006','frozen export differs from selected person/date');
foreach(['2026-09-26','2026-09-26T17:08'] as $time) {
    $dateResult=$execution->execute(Contract::PAGE_BY_TYPE['sales'],[['record_id'=>'a','payment_completed_at'=>'2026-09-26 17:08:00']],[],
        ['topFilters'=>[['field'=>'payment_completed_at','operator'=>'eq','value'=>$time]]],$context,static fn()=>true);
    check($dateResult['pagination']['total']===1,'date control representation rejected');
}
echo 'PASS fields='.$fieldCount.'; salesperson=XS26092600006; pagination/OR/amount/permissions/export=PASS'.PHP_EOL;
