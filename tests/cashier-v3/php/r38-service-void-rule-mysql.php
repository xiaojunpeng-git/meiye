<?php
/** R38 本地事务回滚测试：复用问题记录结构，隔离结账标识，不补改历史作废数据。 */
require '/var/www/html/vendor/autoload.php';
use think\facade\Db;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\order\CashierV3ServiceRecordVoidServices;
use app\services\cashier\v3\order\CashierV3OrderLifecycleServices;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;
$app = new \think\App('/var/www/html/'); $app->initialize();
function verifyR38($condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS $message\n";
}
$template = Db::name('cashier_v3_entitlement_service_fact')->where('id',2055)->find();
if (!$template) throw new RuntimeException('local R38 fixture missing');
$wf = Db::name('cashier_v3_entitlement_writeoff_fact')->where('checkout_request_id',$template['checkout_request_id'])->where('source_line_id',$template['source_line_id'])->find();
$holder = (int)$wf['holder_id']; $detail = (int)$wf['source_detail_id'];
$tenant = (string)$template['tenant_id']; $store = (int)$template['store_id'];
$org = (string)Db::name('organization_store')->where('store_id',$store)->value('org_id');
$scope = new CashierV3DataScopeContext(71,701,$store,$tenant,$org,[$store],CashierV3DataScopeContext::MODE_ALL,[],true,'test-super','permission-v2',['cashier.v3.order_center','cashier.v3.order.service_void'],['id'=>71,'name'=>'回滚测试']);
$operator = new CashierV3OperatorScope($store,71,$org,$tenant);
$recorder = new CashierV3BusinessEventRecorder(); $writer = new CashierV3ServiceRecordVoidServices();
$original = Db::name('cashier_v3_card_rule_component')->where('legacy_detail_id',$detail)->find();
foreach (['mixed','pure'] as $mode) {
    $request = 'r38-void-' . $mode . '-' . bin2hex(random_bytes(6));
    Db::startTrans();
    try {
        // 在事务内模拟两次核销后的状态，回滚后用户的原始余额及作废账本不变。
        Db::name('cashier_v3_card_rule_component')->where('id',$original['id'])->update(['remaining_times'=>8,'status'=>'active']);
        Db::name('store_order_cart_info')->where('id',$detail)->update(['write_surplus_times'=>8]);
        Db::name('user_card_holder')->where('id',$holder)->update(['write_surplus_times'=>8]);
        $ids = [];
        for ($i=0; $i<2; $i++) {
            $service = $template; unset($service['id']);
            $service['checkout_request_id']=$request; $service['source_line_id']=$request.'-'.$i;
            $service['service_fact_id']=$request.'-sf-'.$i; $service['service_record_no']=$request.'-'.$i;
            $service['natural_key']=$request.'-sf-'.$i;
            $ids[] = Db::name('cashier_v3_entitlement_service_fact')->insertGetId($service);
            $writeoff = $wf; unset($writeoff['id']);
            $writeoff['checkout_request_id']=$request; $writeoff['source_line_id']=$service['source_line_id'];
            $writeoff['writeoff_id']=$request.'-wf-'.$i;
            $writeoff['natural_key']=$request.'-wf-'.$i;
            Db::name('cashier_v3_entitlement_writeoff_fact')->insert($writeoff);
            // 查询与冲销均依赖冻结人员事实，连同正向业绩复制，避免不完整夹具。
            $facts = Db::name('cashier_v3_performance_fact')->where('checkout_request_id',$template['checkout_request_id'])
                ->where('source_line_id',$template['source_line_id'])->where('fact_direction','forward')->select()->toArray();
            foreach ($facts as $j=>$fact) {
                unset($fact['id']);
                $fact['checkout_request_id']=$request; $fact['source_line_id']=$service['source_line_id'];
                $fact['fact_id']=$request.'-pf-'.$i.'-'.$j; $fact['natural_key']=$fact['fact_id'];
                Db::name('cashier_v3_performance_fact')->insert($fact);
            }
        }
        $input = ['operator_scope'=>$operator,'data_scope'=>$scope,'event_recorder'=>$recorder,
            'event_execution'=>$recorder->newExecution('void-service-record',$request,$operator,$scope,'r38-test'),
            'event_contract'=>CashierV3ActionManifest::eventContractFor('void-service-record'),
            'idempotency_key'=>$request,'payload'=>['checkoutRequestId'=>$request,'reason'=>'R38回滚测试']];
        if ($mode === 'mixed') {
            // 精确执行整单作废的服务级联；不模拟资金退款，不触碰真实支付。
            $cascade = new ReflectionMethod(CashierV3OrderLifecycleServices::class,'voidCompletedServiceFactsInTx');
            $cascade->setAccessible(true);
            $cascade->invoke(new CashierV3OrderLifecycleServices(),['storeId'=>$store,'checkoutRequestId'=>$request,'sourceNo'=>'R38'],$request,$operator,$scope,$recorder,'r38-test');
        } else {
            // 先单条作废，再通过订单中心纯权益整组入口处理剩余一条。
            $single=$input; $single['payload']=['serviceFactId'=>$ids[0],'reason'=>'R38先作废一条'];
            $writer->executeInTx('void-service-record',$single);
            $result=$writer->executeInTx('void-service-record',$input);
            verifyR38(count($result['records'])===1,'pure skips already voided service');
        }
        foreach (['cashier_v3_card_rule_component'=>['id',$original['id'],'remaining_times'],
            'store_order_cart_info'=>['id',$detail,'write_surplus_times'],
            'user_card_holder'=>['id',$holder,'write_surplus_times']] as $table=>$fields) {
            verifyR38((int)Db::name($table)->where($fields[0],$fields[1])->value($fields[2])===10,"$mode restores $table 8 -> 10");
        }
        $single=$input; $single['payload']=['serviceFactId'=>$ids[0],'reason'=>'重复'];
        $writer->executeInTx('void-service-record',$single);
        verifyR38((int)Db::name('cashier_v3_card_rule_component')->where('id',$original['id'])->value('remaining_times')===10,"$mode retry does not inflate rights");
        verifyR38((int)Db::name('cashier_v3_entitlement_reversal_fact')->whereIn('service_fact_id',$ids)->sum('quantity')===2,"$mode reversal ledger quantity=2");
        verifyR38((int)Db::name('cashier_v3_performance_fact')->where('checkout_request_id',$request)->sum('amount_cents')===0,"$mode performance reversal balances");
    } finally { Db::rollback(); }
    verifyR38(Db::name('cashier_v3_entitlement_service_fact')->where('checkout_request_id',$request)->count()===0,"$mode rollback removes fixtures");
}
verifyR38(Db::name('cashier_v3_card_rule_component')->where('id',$original['id'])->find()===$original,'original rule balance unchanged');
