<?php
/** R50共享次数取消额回归：使用本地既有卡关联，所有夹具和扣次均事务回滚。 */
require '/var/www/html/vendor/autoload.php';
use think\facade\Db;
use app\services\cashier\v3\card\CashierV3CardRuleEntitlementAuthorityServices as Rules;
use app\services\cashier\v3\cashier\CashierV3CardOriginDebtResolver;
use app\services\order\store\WriteOffOrderServices;
$app = new \think\App('/var/www/html/'); $app->initialize();
function shared50(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    echo "PASS $label\n";
}
// 纯公式不使用浮点；用完23次后还剩物理7次，但这7次属于取消部分，不能再用。
shared50(Rules::sharedTimesAfterCancellation(30,30,698000,150000)===23,'5480 of 6980 permits 23 whole shared uses');
shared50(Rules::sharedTimesAfterCancellation(30,20,698000,150000)===13,'subtract already consumed10, not reapply proportion to remaining20');
shared50(Rules::sharedTimesAfterCancellation(30,7,698000,150000)===0,'cancelled remainder cannot be consumed after23 uses');
shared50(Rules::sharedTimesAfterCancellation(30,30,698000,0)===30,'no cancellation keeps original shared capacity');
shared50(Rules::sharedTimesAfterCancellation(30,30,698000,698000)===0,'fully cancelled purchase has no usable shared times');
$rules = new Rules(); $fixture = null;
foreach (Db::name('cashier_v3_card_rule_state')->where('rule_type','choice_count')->select()->toArray() as $state) {
    $holder=(array)Db::name('user_card_holder')->where('id',$state['card_holder_id'])->find();
    $order=(array)Db::name('store_order')->where('id',$holder['oid']??0)->find();
    if ($order && (int)$state['shared_total_times']===30
        && (new CashierV3CardOriginDebtResolver())->cancelledCardDebt($order,(int)$order['uid'],(string)$state['tenant_id'])==='1500.00') {
        $fixture=[$state,$holder,$order]; break;
    }
}
if (!$fixture) throw new RuntimeException('local cancelled shared30 fixture missing');
[$state,$holder,$order]=$fixture;
$before=(array)Db::name('cashier_v3_card_rule_state')->where('id',$state['id'])->find();
Db::startTrans();
try {
    Db::name('cashier_v3_card_rule_state')->where('id',$state['id'])->update(['shared_remaining_times'=>30,'status'=>'active']);
    $snapshot=$rules->snapshotForHolder((string)$state['tenant_id'],(int)$holder['id'],true);
    $rows=Db::name('store_order_cart_info')->where('oid',$order['id'])->where('cart_type',2)->where('product_type',6)->select()->toArray();
    $writeoff=app()->make(WriteOffOrderServices::class); $zero=null; $positive=null;
    foreach ($snapshot['components'] as $component) {
        $detail=(array)Db::name('store_order_cart_info')->where('id',$component['legacy_detail_id'])->find();
        $detail['write_surplus_times']=30;
        if (bccomp((string)$detail['pay_price'],'0',2)===0) $zero=[$detail,$component]; else $positive=[$detail,$component];
    }
    if (!$zero || !$positive) throw new RuntimeException('zero and positive shared components required');
    shared50($writeoff->calcEffectiveWriteSurplusTimes($zero[0],0,$order,'0.00',$rows)===23,'real zero-price component cannot escape shared cancellation cap');
    $context=['tenant_id'=>$state['tenant_id'],'store_id'=>$holder['store_id'],'member_id'=>$holder['uid'],'occurred_at'=>time()];
    $deduction=static function(array $pair,int $qty) use($holder): array {
        return ['holder_id'=>(int)$holder['id'],'source_detail_id'=>(int)$pair[0]['id'],
            'project_id'=>(int)$pair[1]['project_product_id'],'deduct_physical_times'=>$qty,'expected_physical_remaining_times'=>30];
    };
    $rejected=false;
    try { $rules->applyDeductionsInTx([$deduction($zero,30)],$context); } catch (\app\services\cashier\v3\CashierV3CommandException $e) { $rejected=strpos(json_encode($e->getDetail()),'card_rule_shared_times_insufficient')!==false; }
    shared50($rejected,'final locked writer rejects zero-price30 request');
    shared50((int)Db::name('cashier_v3_card_rule_state')->where('id',$state['id'])->value('shared_remaining_times')===30,'rejected30 does not deduct shared pool');
    $rejected=false;
    try { $rules->applyDeductionsInTx([$deduction($zero,15),$deduction($positive,15)],$context); } catch (\app\services\cashier\v3\CashierV3CommandException $e) { $rejected=strpos(json_encode($e->getDetail()),'card_rule_shared_times_insufficient')!==false; }
    shared50($rejected,'different projects15 plus15 cannot bypass aggregate23 cap');
    $rules->applyDeductionsInTx([$deduction($zero,23)],$context);
    shared50((int)Db::name('cashier_v3_card_rule_state')->where('id',$state['id'])->value('shared_remaining_times')===7,'23 allowed uses retain physical7 audit remainder');
    shared50($rules->cancellationLimitForDetail($zero[0],$order)===0,'remaining physical7 is unavailable after23 uses');
} finally { Db::rollback(); }
shared50((array)Db::name('cashier_v3_card_rule_state')->where('id',$state['id'])->find()===$before,'all fixture and successful23 deductions rolled back');
echo "R50 SHARED CANCELLATION PASS\n";
