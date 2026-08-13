<?php
declare(strict_types=1);

require '/var/www/html/vendor/autoload.php';

use app\services\cashier\v3\settlement\CashierV3DebtRepaymentServices;
use app\services\cashier\v3\settlement\CashierV3CheckoutPaymentDraftServices;
use app\services\cashier\v3\settlement\CashierV3CheckoutProjectionServices;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use think\facade\Config;
use think\facade\Db;

$backend = '/var/www/html';
$app = new \think\App($backend . '/');
$app->env->load($backend . '/.env');
foreach ([
    'cache.driver'=>'file','CACHE_DRIVER'=>'file',
    'database.hostname'=>getenv('DB_HOST') ?: 'mysql','DATABASE_HOSTNAME'=>getenv('DB_HOST') ?: 'mysql',
    'database.hostport'=>getenv('DB_PORT') ?: '3306','DATABASE_HOSTPORT'=>getenv('DB_PORT') ?: '3306',
    'database.database'=>getenv('DB_DATABASE') ?: 'ruihao_test_recovered_20260801','DATABASE_DATABASE'=>getenv('DB_DATABASE') ?: 'ruihao_test_recovered_20260801',
    'database.username'=>getenv('DB_USERNAME') ?: 'root','DATABASE_USERNAME'=>getenv('DB_USERNAME') ?: 'root',
    'database.password'=>getenv('DB_PASSWORD') ?: 'localdev123','DATABASE_PASSWORD'=>getenv('DB_PASSWORD') ?: 'localdev123',
] as $key=>$value) $app->env->set($key,$value);
$envName=new ReflectionProperty($app,'envName');$envName->setAccessible(true);$envName->setValue($app,'sales_debt_repayment_test_skip_reload_dotenv');
$app->initialize();Config::set(['default'=>'file'],'cache');Db::connect('mysql',true)->query('SELECT 1');

$failed=0;$check=static function(string $name,bool $condition)use(&$failed):void{if($condition){echo "PASS {$name}\n";return;}$failed++;echo "FAIL {$name}\n";};
$tables=['cashier_v3_debt_authority','cashier_v3_debt_item_personnel_authority','cashier_v3_debt_repayment_draft','cashier_v3_debt_repayment','cashier_v3_debt_repayment_collection'];
foreach($tables as $table)$check($table.' exists',(int)Db::query("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_{$table}'")[0]['c']===1);
$rollbackCountTables=[
    'cashier_v3_debt_item_personnel_authority','cashier_v3_checkout_request','cashier_v3_checkout_line_draft',
    'cashier_v3_checkout_payment_draft','cashier_v3_checkout_source_reference','cashier_v3_debt_repayment_draft',
    'cashier_v3_debt_repayment','cashier_v3_debt_repayment_collection','cashier_v3_business_event',
    'cashier_v3_payment_fact','cashier_v3_performance_fact','cashier_v3_business_document_no',
];
$beforeCounts=[];foreach($rollbackCountTables as $table)$beforeCounts[$table]=(int)Db::name($table)->count();
$beforeSequences=Db::name('cashier_v3_business_document_sequence')->order('tenant_id asc,document_type asc,business_date asc')->select()->toArray();
$debtBefore=[];$itemsBefore=[];

Db::startTrans();
try {
    $authority=(array)Db::name('cashier_v3_debt_authority')->alias('a')->join('store_debt d','d.id=a.debt_id')
        ->where('d.status',0)->whereExp('d.total_debt','>d.repaid_debt')
        ->whereRaw('(SELECT COUNT(*) FROM eb_store_debt_item i WHERE i.debt_id=a.debt_id) >= 2')
        ->whereRaw('(SELECT COUNT(*) FROM eb_store_debt_item i WHERE i.debt_id=a.debt_id) = (SELECT COUNT(*) FROM eb_cashier_v3_sales_order_line l WHERE l.order_id=a.sales_order_id)')
        ->whereRaw('MOD(d.total_debt-d.repaid_debt,1)=0')->order('a.id desc')->lock(true)
        ->field('a.*,d.total_debt,d.repaid_debt')->find();
    $check('new V3 sales debt fixture exists',!empty($authority));
    if($authority){
        $items=Db::name('store_debt_item')->where('debt_id',(int)$authority['debt_id'])->order('id asc')->lock(true)->select()->toArray();
        $debtBefore=Db::name('store_debt')->where('id',(int)$authority['debt_id'])->find()?:[];$itemsBefore=$items;
        $total=0;foreach($items as $item)$total+=(int)round((float)$item['debt_amount']*100);
        $pending=(int)round(((float)$authority['total_debt']-(float)$authority['repaid_debt'])*100);
        $target=min($total,(int)round((float)$authority['repaid_debt']*100)+$pending);
        $allocation=CashierV3DebtRepaymentServices::proportionalCumulativeAllocation($target,$items);
        $check('locked item allocation equals cumulative repaid amount',array_sum($allocation)===$target);
        foreach($allocation as $itemId=>$cents)$check('allocation remains within source line '.$itemId,$cents>=0&&$cents<=(int)round((float)array_values(array_filter($items,static function(array $i)use($itemId):bool{return(int)$i['id']===$itemId;}))[0]['debt_amount']*100));

        $staff=(array)Db::name('system_store_staff')->alias('ss')->join('employee e','e.id=ss.employee_id')
            ->where('ss.store_id',(int)$authority['store_id'])->where('ss.status',1)->where('ss.is_del',0)
            ->where('ss.cashier_salesperson_enabled',1)->where('e.status',1)->where('e.is_del',0)
            ->field('ss.*,e.name AS employee_name,e.employment_type_code,e.employment_type_version')
            ->order('ss.id asc')->lock(true)->find();
        $organizationId=(string)Db::name('organization_store')->where('store_id',(int)$authority['store_id'])
            ->order('id asc')->value('org_id');
        $check('repayment operator fixture exists',!empty($staff)&&$organizationId!=='');
        if($staff&&$organizationId!==''){
            $operatorId=(int)$staff['id'];$storeId=(int)$authority['store_id'];$stateContextId='SC-debt-repayment-integration';
            $operator=new CashierV3OperatorScope($storeId,$operatorId,$organizationId,(string)$authority['tenant_id']);
            $dataScope=new CashierV3DataScopeContext(
                $operatorId,(int)($staff['employee_id']??0),$storeId,(string)$authority['tenant_id'],$organizationId,
                null,CashierV3DataScopeContext::MODE_ALL,[],true,'integration','debt-repayment-integration',[],
                ['staff_name'=>(string)($staff['staff_name']??('员工#'.$operatorId))]
            );
            $service=new CashierV3DebtRepaymentServices(null,str_repeat('d',64));
            $pendingYuan=(string)(int)floor((float)$authority['total_debt']-(float)$authority['repaid_debt']);
            $prepareScope=[
                'operator_scope'=>$operator,'data_scope'=>$dataScope,'state_context_id'=>$stateContextId,
                'idempotency_key'=>'CHECKOUT_PREPARE-00000000-0000-4000-8000-000000000001',
                'contexts'=>[
                    ['kind'=>'cashier_workspace','id'=>\app\services\cashier\v3\CashierV3CheckoutWorkspaceIdentity::id($storeId,$stateContextId),'expectedVersion'=>1],
                    ['kind'=>'debt_record','id'=>(string)$authority['debt_id'],'expectedVersion'=>max(1,(int)($authority['updated_at']??1))],
                ],
                'payload'=>['debtRecordId'=>(int)$authority['debt_id'],'amount'=>$pendingYuan,'salespersonAllocations'=>[['staffId'=>$operatorId,'allocationWeight'=>100,'isPreSale'=>true]]],
            ];
            $salesLines=Db::name('cashier_v3_sales_order_line')->where('order_id',(string)$authority['sales_order_id'])->order('line_no asc')->lock(true)->select()->toArray();
            $check('debt items map one-to-one to V3 sale lines',count($salesLines)===count($items));
            $personnelCount=(int)Db::name('cashier_v3_debt_item_personnel_authority')->where('debt_id',(int)$authority['debt_id'])->lock(true)->count();
            if($personnelCount===0){
                foreach($items as $index=>$item){
                    $person=[['employeeId'=>max(1,(int)($staff['employee_id']??0)),'name'=>trim((string)($staff['staff_name']??''))?:('员工#'.$operatorId),'employeeTypeCodeSnapshot'=>'internal','employeeTypeAuthorityVersion'=>1,'allocationWeight'=>100,'sequence'=>1]];
                    $row=['order_line_id'=>(string)$salesLines[$index]['order_line_id'],'checkout_line_id'=>(string)$salesLines[$index]['checkout_line_id'],'line_debt_amount_cents'=>(int)round((float)$item['debt_amount']*100),'salespeople_snapshot_json'=>json_encode($person,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'debt_item_id'=>(int)$item['id'],'debt_id'=>(int)$authority['debt_id'],'tenant_id'=>(string)$authority['tenant_id'],'store_id'=>$storeId,'member_id'=>(int)$authority['member_id']];
                    $row['snapshot_fingerprint']=hash('sha256',json_encode($row,JSON_UNESCAPED_SLASHES));$row['created_at']=time();$row['updated_at']=time();
                    Db::name('cashier_v3_debt_item_personnel_authority')->insert($row);
                }
            }
            $check('debt line personnel authority is complete',(int)Db::name('cashier_v3_debt_item_personnel_authority')->where('debt_id',(int)$authority['debt_id'])->count()===count($items));
            $prepare=$service->prepareInTx($prepareScope);
            $check('real prepare creates versioned editing checkout',
                (string)($prepare['requestStatus']??'')==='editing'
                &&(int)($prepare['checkoutRequestVersion']??0)>0
                &&!empty($prepare['eventless']));

            $requestId=(string)$prepare['checkoutRequestId'];$requestVersion=(int)$prepare['checkoutRequestVersion'];
            $request=(array)Db::name('cashier_v3_checkout_request')->where('request_id',$requestId)->lock(true)->find();
            $token='CKPT-'.hash('sha256',implode('|',[CashierV3CheckoutProjectionServices::CONTRACT_VERSION,$requestId,(string)$requestVersion,(string)$request['aggregate_fingerprint'],(string)$request['last_operation_fingerprint']]));
            try{$payment=(new CashierV3CheckoutPaymentDraftServices(null,null,str_repeat('d',64)))->mutateInTx('add-payment-method',[
                'operator_scope'=>$operator,'data_scope'=>$dataScope,'state_context_id'=>$stateContextId,
                'idempotency_key'=>'ADD_PAYMENT-00000000-0000-4000-8000-000000000002',
                'contexts'=>[['kind'=>'cashier_workspace','id'=>\app\services\cashier\v3\CashierV3CheckoutWorkspaceIdentity::id($storeId,$stateContextId),'expectedVersion'=>2],['kind'=>'checkout_request','id'=>$requestId,'expectedVersion'=>$requestVersion]],
                'payload'=>['checkoutRequestId'=>$requestId,'checkoutRequestVersion'=>$requestVersion,'preparationRequestId'=>(string)$prepare['preparationRequestId'],'preparationToken'=>$token,'paymentMethodId'=>'wechat'],
            ]);}catch(Throwable $e){throw new RuntimeException('payment mutation failed: '.json_encode(method_exists($e,'getDetail')?$e->getDetail():[]),0,$e);}
            $check('first bookkeeping method defaults to the authoritative outstanding amount',
                (int)($payment['totals']['selectedPaymentAmountCents']??-1)===$amountCents);
            $requestVersion=(int)$payment['checkoutRequestVersion'];
            $submitKey='CHECKOUT-00000000-0000-4000-8000-000000000003';$recorder=new CashierV3BusinessEventRecorder();
            $contract=['required_event_types'=>['debt.repaid'],'allowed_event_types'=>['debt.repaid'],'event_rules'=>['debt.repaid'=>['min_count'=>1,'max_count'=>1,'aggregate_type'=>'debt_repayment','source_type'=>'submit-debt-repayment','aggregate_version'=>1]],'eventless_reason'=>'','consumers'=>['debt.repaid'=>[]]];
            $execution=$recorder->newExecution('submit-debt-repayment',$submitKey,$operator,$dataScope,$stateContextId);
            $submitScope=['operator_scope'=>$operator,'data_scope'=>$dataScope,'state_context_id'=>$stateContextId,'idempotency_key'=>$submitKey,'event_recorder'=>$recorder,'event_execution'=>$execution,'event_contract'=>$contract,'payload'=>['checkoutRequestId'=>$requestId,'checkoutRequestVersion'=>$requestVersion]];
            $result=$service->submitInTx($submitScope);$recorder->assertRequiredPersistedInTx($execution,$contract);
            $repaymentId=(string)$result['data']['debtRepayment']['repaymentId'];$amountCents=(int)round(((float)$authority['total_debt']-(float)$authority['repaid_debt'])*100);
            $check('real submit succeeds',(string)$result['data']['debtRepayment']['status']==='succeeded'&&!empty($repaymentId));
            $check('debt header updated exactly',(int)round((float)Db::name('store_debt')->where('id',(int)$authority['debt_id'])->value('repaid_debt')*100)===(int)round((float)$authority['repaid_debt']*100)+$amountCents);
            $check('collection authority totals exactly',(int)Db::name('cashier_v3_debt_repayment_collection')->where('repayment_id',$repaymentId)->sum('amount_cents')===$amountCents);
            $check('payment facts total exactly',(int)Db::name('cashier_v3_payment_fact')->where('order_id',$repaymentId)->where('status','effective')->sum('amount_cents')===$amountCents);
            $check('selected salesperson facts total exactly',(int)Db::name('cashier_v3_performance_fact')->where('order_id',$repaymentId)->where('performance_type','sales_performance_allocated')->where('status','effective')->sum('amount_cents')===$amountCents);
            $check('one selected salesperson fact is written',(int)Db::name('cashier_v3_performance_fact')->where('order_id',$repaymentId)->where('performance_type','sales_performance_allocated')->count()===1);
            $snapshot=json_decode((string)Db::name('cashier_v3_debt_repayment')->where('repayment_id',$repaymentId)->value('salespeople_snapshot_json'),true);
            $check('repayment freezes operator-selected salesperson',is_array($snapshot)&&count($snapshot)===1&&(int)$snapshot[0]['staffId']===$operatorId&&(int)$snapshot[0]['allocationWeight']===100);
            $check('debt.repaid event persisted',(int)Db::name('cashier_v3_business_event')->where('aggregate_id',$repaymentId)->where('event_type','debt.repaid')->count()===1);
            $replayCountsBefore=[
                'repayment'=>(int)Db::name('cashier_v3_debt_repayment')->where('repayment_id',$repaymentId)->count(),
                'collections'=>(int)Db::name('cashier_v3_debt_repayment_collection')->where('repayment_id',$repaymentId)->count(),
                'events'=>(int)Db::name('cashier_v3_business_event')->where('aggregate_id',$repaymentId)->count(),
                'payment_facts'=>(int)Db::name('cashier_v3_payment_fact')->where('order_id',$repaymentId)->count(),
                'performance_facts'=>(int)Db::name('cashier_v3_performance_fact')->where('order_id',$repaymentId)->count(),
            ];
            $replayExecution=$recorder->newExecution('submit-debt-repayment',$submitKey,$operator,$dataScope,$stateContextId);$submitScope['event_execution']=$replayExecution;
            $replay=$service->submitInTx($submitScope);
            $check('submit replay is stable',!empty($replay['data']['debtRepayment']['replayed'])&&(string)$replay['data']['debtRepayment']['repaymentId']===$repaymentId&&(int)$replay['data']['debtRepayment']['checkoutRequestVersion']===(int)$result['data']['debtRepayment']['checkoutRequestVersion']);
            $replayCountsAfter=[
                'repayment'=>(int)Db::name('cashier_v3_debt_repayment')->where('repayment_id',$repaymentId)->count(),
                'collections'=>(int)Db::name('cashier_v3_debt_repayment_collection')->where('repayment_id',$repaymentId)->count(),
                'events'=>(int)Db::name('cashier_v3_business_event')->where('aggregate_id',$repaymentId)->count(),
                'payment_facts'=>(int)Db::name('cashier_v3_payment_fact')->where('order_id',$repaymentId)->count(),
                'performance_facts'=>(int)Db::name('cashier_v3_performance_fact')->where('order_id',$repaymentId)->count(),
            ];
            $check('submit replay creates no duplicate authority or facts',$replayCountsAfter===$replayCountsBefore);
            $query=$service->query(['originalIdempotencyKey'=>$submitKey,'checkoutRequestId'=>$requestId],$operator,$dataScope);
            $check('query returns original result',!empty($query['data']['debtRepayment']['replayed'])&&(string)$query['data']['debtRepayment']['repaymentId']===$repaymentId);
            Db::name('store_debt_item')->where('id',(int)$items[0]['id'])->update(['debt_amount'=>number_format((float)$items[0]['debt_amount']+1,2,'.','')]);
            $driftScope=$prepareScope;$driftScope['idempotency_key']='CHECKOUT_PREPARE-00000000-0000-4000-8000-000000000004';$driftScope['payload']['amount']='1';
            $driftRejected=false;try{$service->prepareInTx($driftScope);}catch(Throwable $e){$driftRejected=method_exists($e,'getDetail')&&($e->getDetail()['reason']??'')==='debt_repayment_header_item_amount_drift';}
            $check('header and line debt amount drift is rejected',$driftRejected);
        }
    }
    Db::rollback();
} catch(Throwable $e){Db::rollback();throw $e;}
$unchanged=true;foreach($beforeCounts as $table=>$count)$unchanged=$unchanged&&((int)Db::name($table)->count()===$count);
$unchanged=$unchanged&&Db::name('cashier_v3_business_document_sequence')->order('tenant_id asc,document_type asc,business_date asc')->select()->toArray()===$beforeSequences;
if($debtBefore){$unchanged=$unchanged&&(Db::name('store_debt')->where('id',(int)$debtBefore['id'])->find()?:[])===$debtBefore;}
if($itemsBefore){$unchanged=$unchanged&&Db::name('store_debt_item')->where('debt_id',(int)$itemsBefore[0]['debt_id'])->order('id asc')->select()->toArray()===$itemsBefore;}
$check('integration transaction made no writes',$unchanged);
echo "SALES_DEBT_REPAYMENT_MYSQL failed={$failed}\n";exit($failed===0?0:1);
