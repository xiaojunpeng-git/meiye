<?php
declare(strict_types=1);

/** 本地真实服务回滚测试：奖励随补交保存、读回、重放、导出与作废；不保留任何还款或余额变动。 */
if (getenv('R13_LOCAL_TEST') !== '1') throw new RuntimeException('Local test must be explicit');
require '/var/www/html/vendor/autoload.php';
use think\facade\Db;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;
use app\services\cashier\v3\settlement\CashierV3DebtRepaymentServices;
use app\services\cashier\v3\settlement\CashierV3RepaymentReward;
use app\services\cashier\v3\config\CashierV3BusinessConfigServices;
use app\services\cashier\v3\order\CashierV3OrderCenterVoidServices;
use app\services\report\StoreUnifiedReportServices;
$app = new think\App('/var/www/html/'); $app->initialize();
if (Db::query('SELECT DATABASE() db')[0]['db'] !== 'ruihao') throw new RuntimeException('Unexpected local database');
date_default_timezone_set('Asia/Shanghai');
$passed = 0;
function checkReward(bool $ok, string $message): void { global $passed; if (!$ok) throw new RuntimeException('FAIL '.$message); $passed++; echo 'PASS '.$message.PHP_EOL; }
function rejectsReward(callable $action, string $label): void {
    $rejected = false; try { $action(); } catch (CashierV3CommandException $e) { $rejected = true; }
    checkReward($rejected, $label);
}
foreach ([-1, 1.2, '1.2', '1e3', true, [], '100000000001'] as $invalid) rejectsReward(static function () use ($invalid): void { CashierV3RepaymentReward::cents($invalid); }, 'invalid cents rejected '.json_encode($invalid));
checkReward(CashierV3RepaymentReward::cents(0) === 0 && CashierV3RepaymentReward::cents('1234') === 1234, 'zero and integer cents accepted');
rejectsReward(static function (): void { CashierV3RepaymentReward::forSource(1234, 'A老客首护'); }, 'non-G authoritative source rejects reward');
$sourceId = (int)Db::name('cashier_v3_business_source')->where('parent_id',0)->where('status',1)->where('name','like','G%')->value('id');
$source = (new CashierV3BusinessConfigServices())->resolveSourceSnapshot($sourceId,0,true);
$source['rewardAmountCents'] = 1234;
$salesDebt = (array)Db::name('store_debt')->where('debt_no','RHDc413a2dee27aa18d1631b41c8448f')->find();
$rechargeDebt = (array)Db::name('cashier_v3_recharge_debt_authority')->alias('a')->join('store_debt d','d.id=a.debt_id')
    ->where('d.status',0)->whereRaw('d.total_debt-d.repaid_debt >= 2')->field('d.*')->find();
if (!$salesDebt || !$rechargeDebt) throw new RuntimeException('Required local sales/recharge debt fixtures missing');
foreach (['sales'=>$salesDebt, 'recharge'=>$rechargeDebt] as $kind=>$debt) {
    $store = (int)$debt['store_id']; $uid = (int)$debt['uid']; $id = (int)$debt['id'];
    $staff = (array)Db::name('system_store_staff')->where('store_id',$store)->where('status',1)->where('is_del',0)->find();
    $org = (string)Db::name('organization_store')->where('store_id',$store)->value('org_id');
    $operator = new CashierV3OperatorScope($store,(int)$staff['id'],$org,'0');
    $scope = new CashierV3DataScopeContext((int)$staff['id'],(int)$staff['id'],$store,'0',$org,[$store],CashierV3DataScopeContext::MODE_STORES,[],false,'','r13-reward',['cashier.v3.order_center','cashier.v3.order.void'],$staff);
    $table = $kind === 'sales' ? 'cashier_v3_debt_repayment' : 'cashier_v3_recharge_debt_repayment';
    $method = $kind === 'sales' ? 'submitInTx' : 'submitRechargeDebtCheckoutInTx';
    $action = $kind === 'sales' ? 'submit-debt-repayment' : 'submit-recharge-debt-repayment';
    $key = 'r13-reward-rollback-'.$kind.'-'.bin2hex(random_bytes(8));
    $recorder = new CashierV3BusinessEventRecorder();
    $command = ['operator_scope'=>$operator,'data_scope'=>$scope,'event_recorder'=>$recorder,
        'event_execution'=>$recorder->newExecution($action,$key,$operator,$scope,'r13-reward'),
        'event_contract'=>CashierV3ActionManifest::eventContractFor($action),'idempotency_key'=>$key,
        'payload'=>['checkoutSnapshot'=>['businessType'=>'debt_repayment','rechargeDebt'=>$kind==='recharge','memberId'=>$uid,
            'businessDate'=>'2026-10-12','repaymentAmount'=>2,'source'=>$source,'salespersonAllocations'=>[],
            'sourceDocument'=>['type'=>'debt_repayment','id'=>(string)$id],
            'lines'=>[['lineRole'=>'sale','sourceId'=>$id,'lineAmountCents'=>200,'debtAmountCents'=>0],
                ['lineRole'=>'payment','method'=>'cash','amount'=>1],['lineRole'=>'payment','method'=>'unionpay','amount'=>1]]]]];
    $userBefore = (array)Db::name('user')->where('uid',$uid)->find();
    $counts = []; foreach ([$table,'cashier_v3_payment_fact','cashier_v3_balance_fact','cashier_v3_performance_fact'] as $t) $counts[$t] = Db::name($t)->count();
    Db::startTrans();
    try {
        $service = new CashierV3DebtRepaymentServices();
        $service->$method($command);
        $saved = (array)Db::name($table)->where('command_idempotency_key',$key)->find();
        checkReward((int)$saved['reward_amount_cents']===1234 && $saved['status']==='succeeded', "$kind reward persisted as exact cents");
        checkReward((int)round(((float)Db::name('store_debt')->where('id',$id)->value('repaid_debt')-(float)$debt['repaid_debt'])*100)===200, "$kind reward does not change repaid principal");
        $beforeReplay = Db::name('cashier_v3_payment_fact')->count();
        $service->$method($command);
        checkReward(Db::name('cashier_v3_payment_fact')->count()===$beforeReplay && Db::name($table)->where('command_idempotency_key',$key)->count()===1, "$kind replay does not duplicate reward or receipt");
        $bad = $command; $bad['payload']['checkoutSnapshot']['source']['rewardAmountCents']=1235;
        rejectsReward(static function () use ($service,$method,$bad): void { $service->$method($bad); }, "$kind changed reward under same key conflicts");
        $input = ['report'=>'cross_industry_customer_detail','start_date'=>'2026-10-12','end_date'=>'2026-10-12','member_id'=>$uid,'_internal_all'=>true,'_report_scope'=>['mode'=>'stores']];
        $query = (new StoreUnifiedReportServices())->query([$store],$input);
        $rows = array_values(array_filter($query['records'],static function (array $r) use ($saved): bool { return $r['order_id']===$saved['repayment_id']; }));
        checkReward(count($rows)===1 && (string)$rows[0]['reward']==='12.34' && (float)$rows[0]['full_payment']===2.0, "$kind combined receipt gives one reward row after fresh read");
        $export = (new StoreUnifiedReportServices())->export([$store],$input);
        checkReward($export['records']===$query['records'] && $export['summary_row']===$query['summary_row'], "$kind export and totals match query");
        $outside = (new StoreUnifiedReportServices())->query([$store+99999],$input);
        checkReward(!$outside['records'], "$kind other-store report cannot see reward");
        $voidAction = 'void-order-center-supplement'; $voidKey=$key.'-void';
        $void=$command; $void['idempotency_key']=$voidKey;
        $void['event_execution']=$recorder->newExecution($voidAction,$voidKey,$operator,$scope,'r13-reward-void');
        $void['event_contract']=CashierV3ActionManifest::eventContractFor($voidAction);
        $void['payload']=['recordId'=>($kind==='sales'?'v3-sales-supplement:':'v3-supplement:').$saved['repayment_id'],'reason'=>'R13本地事务回滚测试'];
        (new CashierV3OrderCenterVoidServices())->executeInTx($voidAction,$void);
        $voided=(new StoreUnifiedReportServices())->query([$store],$input);
        checkReward(!in_array($saved['repayment_id'],array_column($voided['records'],'order_id'),true), "$kind void excludes reward from report");
        checkReward((int)Db::name($table)->where('id',$saved['id'])->value('reward_amount_cents')===1234, "$kind void preserves reward audit value");
    } finally { Db::rollback(); }
    checkReward((array)Db::name('store_debt')->where('id',$id)->find()===$debt && (array)Db::name('user')->where('uid',$uid)->find()===$userBefore, "$kind test restores debt and member balance");
    foreach ($counts as $t=>$n) checkReward(Db::name($t)->count()===$n, "$kind rollback restores $t row count");
}
echo "PASS $passed checks; all local business writes rolled back\n";
