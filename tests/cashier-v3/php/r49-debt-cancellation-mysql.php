<?php
/** R49 本地数据库自测：所有业务写入由外层事务回滚，不取消用户真实欠款。 */
require '/var/www/html/vendor/autoload.php';
use think\facade\Db;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;
use app\services\cashier\v3\order\CashierV3DebtCancellationServices;
use app\services\cashier\v3\order\CashierV3OrderCenterRecordQueryServices;
use app\services\cashier\v3\cashier\CashierV3MemberDebtProjectionServices;
$app = new \think\App('/var/www/html/'); $app->initialize();
function ok49(bool $passed, string $message): void { if (!$passed) throw new RuntimeException($message); echo "PASS $message\n"; }
$debt = (array)Db::name('store_debt')->where('status',0)->where('total_debt','>',0)->find();
if (!$debt) throw new RuntimeException('local outstanding debt fixture missing');
$store = (int)$debt['store_id']; $id = (int)$debt['id']; $uid = (int)$debt['uid'];
$org = (string)Db::name('organization_store')->where('store_id',$store)->value('org_id');
$operator = new CashierV3OperatorScope($store,1,$org,'0');
$scope = new CashierV3DataScopeContext(1,1,$store,'0',$org,[$store],CashierV3DataScopeContext::MODE_STORES,[],false,'','1',['cashier.v3.order.void','cashier.v3.order_center'],['id'=>1,'name'=>'R49事务回滚测试']);
$service = new CashierV3DebtCancellationServices(); $recorder = new CashierV3BusinessEventRecorder();
$counts = []; foreach (['cashier_v3_payment_fact','cashier_v3_performance_fact','cashier_v3_balance_fact'] as $table) $counts[$table] = Db::name($table)->count();
$key = 'r49-test-' . bin2hex(random_bytes(8));
$command = ['operator_scope'=>$operator,'data_scope'=>$scope,'event_recorder'=>$recorder,
    'event_execution'=>$recorder->newExecution('cancel-order-center-debt',$key,$operator,$scope,'r49-test'),
    'event_contract'=>CashierV3ActionManifest::eventContractFor('cancel-order-center-debt'),
    'idempotency_key'=>$key,'payload'=>['debtId'=>$id,'remainingAmount'=>'700.00','reason'=>'R49事务回滚自测']];
Db::startTrans();
try {
    Db::name('store_debt')->where('id',$id)->update(['total_debt'=>'1000.00','repaid_debt'=>'300.00']);
    $memberTotalBefore = (new CashierV3MemberDebtProjectionServices())->amountForMember($uid);
    foreach (['reason'=>'','remainingAmount'=>'699.00'] as $field=>$value) {
        $bad = $command; $bad['payload'][$field]=$value; $rejected=false;
        try { $service->executeInTx($bad); } catch (CashierV3CommandException $e) { $rejected=true; }
        ok49($rejected && (int)Db::name('store_debt')->where('id',$id)->value('status')===0,"reject invalid $field without writes");
    }
    $bad=$command; $bad['operator_scope']=new CashierV3OperatorScope($store+9999,1,'0','0');
    $rejected=false; try { $service->executeInTx($bad); } catch(CashierV3CommandException $e) { $rejected=true; }
    ok49($rejected,'cross-store command rejected');
    foreach ([1,2] as $status) {
        Db::name('store_debt')->where('id',$id)->update(['status'=>$status]);
        $rejected=false; try { $service->executeInTx($command); } catch(CashierV3CommandException $e) { $rejected=true; }
        ok49($rejected,"settled/voided debt status $status rejected");
    }
    Db::name('store_debt')->where('id',$id)->update(['status'=>0]);
    $result=$service->executeInTx($command);
    $after=(array)Db::name('store_debt')->where('id',$id)->find();
    ok49((int)$after['status']===3 && $after['total_debt']==='1000.00' && $after['repaid_debt']==='300.00','partial repayment only cancels remaining 700, paid 300 preserved');
    $audit=(array)Db::name(CashierV3DebtCancellationServices::TABLE)->where('debt_id',$id)->find();
    ok49((int)$audit['cancelled_debt_cents']===70000 && $audit['reason_snapshot']==='R49事务回滚自测' && $audit['business_event_no']!=='','immutable cancellation fact with event');
    $replay=$service->executeInTx($command);
    ok49($replay['data']['debtCancellation']['replayed'] && Db::name(CashierV3DebtCancellationServices::TABLE)->where('debt_id',$id)->count()===1,'repeat cancellation never duplicates fact');
    $different=$command; $different['idempotency_key']=$key.'-different';
    $rejected=false; try { $service->executeInTx($different); } catch(CashierV3CommandException $e) { $rejected=$e->getMessage()==='该欠款已取消。'; }
    ok49($rejected,'new request cannot fabricate another successful cancellation');
    // 使用现有成功补交的结构验证终态拦截；所有关联字段修改最终回滚。
    $repayment=(array)Db::name('cashier_v3_debt_repayment')->where('status','succeeded')->find();
    if (!$repayment) throw new RuntimeException('successful sales repayment fixture missing');
    Db::name('cashier_v3_debt_repayment')->where('id',$repayment['id'])->update(['debt_id'=>$id,'tenant_id'=>'0','store_id'=>$store,'member_id'=>$uid]);
    $void=$command; $void['payload']=['recordId'=>'v3-sales-supplement:'.$repayment['repayment_id'],'reason'=>'R49拦截测试'];
    $void['idempotency_key']=$key.'-void';
    $void['event_contract']=CashierV3ActionManifest::eventContractFor('void-order-center-supplement');
    $void['event_execution']=$recorder->newExecution('void-order-center-supplement',$void['idempotency_key'],$operator,$scope,'r49-test');
    $rejected=false;
    try { (new \app\services\cashier\v3\order\CashierV3OrderCenterVoidServices())->executeInTx('void-order-center-supplement',$void); }
    catch(CashierV3CommandException $e) { $rejected=$e->getMessage()==='欠款已取消，不能作废补交记录。'; }
    ok49($rejected && Db::name('cashier_v3_debt_repayment')->where('id',$repayment['id'])->value('status')==='succeeded','cancelled debt blocks repayment void without altering receipt');
    $query=new CashierV3OrderCenterRecordQueryServices();
    $normal=$query->queryRecords(['recordType'=>'debt','keyword'=>$debt['debt_no'],'dataScope'=>'normal'],$operator,$scope);
    $all=$query->queryRecords(['recordType'=>'debt','keyword'=>$debt['debt_no'],'dataScope'=>'all'],$operator,$scope);
    $rows=array_values(array_filter($all['records'],static function($row)use($id){return $row['debtId']===$id;}));
    ok49(!in_array($id,array_column($normal['records'],'debtId'),true) && count($rows)===1 && $rows[0]['debtStatus']==='已取消' && $rows[0]['remainingAmount']==='0.00' && !$rows[0]['canRepay'],'normal hides, all retains cancelled history without repayment');
    $memberTotalAfter=(new CashierV3MemberDebtProjectionServices())->amountForMember($uid);
    ok49(bcsub($memberTotalBefore,$memberTotalAfter,2)==='700.00','member pending debt excludes waived amount');
    foreach($counts as $table=>$count) ok49(Db::name($table)->count()===$count,"no money or performance facts: $table");
} finally { Db::rollback(); }
ok49((array)Db::name('store_debt')->where('id',$id)->find()===$debt && !Db::name(CashierV3DebtCancellationServices::TABLE)->where('debt_id',$id)->count(),'all business test writes rolled back');
Db::startTrans();
try {
    Db::name('store_debt')->where('id',$id)->update(['total_debt'=>'1000.00','repaid_debt'=>'0.00']);
    $full=$command; $full['idempotency_key']=$key.'-full'; $full['payload']['remainingAmount']='1000.00';
    $full['event_execution']=$recorder->newExecution('cancel-order-center-debt',$full['idempotency_key'],$operator,$scope,'r49-full');
    $result=$service->executeInTx($full);
    ok49($result['data']['debtCancellation']['cancelledDebtCents']===100000 && Db::name('store_debt')->where('id',$id)->value('repaid_debt')==='0.00','never-repaid debt cancels full amount without pretending repayment');
} finally { Db::rollback(); }
// 空 contexts 只对该独立快照命令开放，不引入工作台/草稿依赖。
$dispatcher=\app\services\cashier\v3\bootstrap\CashierV3Bootstrap::dispatcher();
$policy=$dispatcher->policies()->requirePolicy('cancel-order-center-debt')->resolve($command['payload'],[]);
ok49(!empty($policy['allows_empty_contexts']) && empty($policy['identities']),'command policy has no workspace or draft dependency');
ok49(CashierV3DebtCancellationServices::cents('700.29')===70029,'amount snapshot retains cents precision');
echo "R49_DEBT_CANCELLATION=PASS\n";
