<?php
/** R50 展示契约：使用本地既有关系，所有金额/状态/审计测试变更一律事务回滚。 */
require '/var/www/html/vendor/autoload.php';
use think\facade\Db;
use app\services\cashier\v3\cashier\CashierV3EntitlementProjectionServices;
use app\services\cashier\v3\cashier\CashierV3CardOriginDebtResolver;
$app = new \think\App('/var/www/html/'); $app->initialize();
function assert50($condition, string $label): void {
    if (!$condition) throw new RuntimeException($label);
    echo "PASS $label\n";
}
// 私有投影方法直接调用只读解析，不启动结账或生成业务事件。
$projection = (new ReflectionClass(CashierV3EntitlementProjectionServices::class))->newInstanceWithoutConstructor();
$cancelled = new ReflectionMethod($projection, 'cancelledDebt'); $cancelled->setAccessible(true);
$pending = new ReflectionMethod($projection, 'pendingDebt'); $pending->setAccessible(true);
$order=[]; $debt=[];
foreach (Db::name('store_debt')->where('order_id','>',0)->where('status',0)->limit(200)->select()->toArray() as $candidate) {
    $candidateOrder=(array)Db::name('store_order')->where('id',$candidate['order_id'])->find();
    if ($candidateOrder && !Db::name('store_order_cart_info')->where('oid',$candidateOrder['id'])->where('source_type','cashier_v3')->where('product_type',5)->count()) {
        $order=$candidateOrder; $debt=$candidate; break;
    }
}
if (!$order) throw new RuntimeException('historical card fixture missing');
$originalDebt=$debt;
$key = 'r50-' . bin2hex(random_bytes(8));
function audit50(array $debt, string $key): array {
    return ['cancellation_id'=>$key,'debt_id'=>$debt['id'],'tenant_id'=>'0','store_id'=>$debt['store_id'],
        'member_id'=>$debt['uid'],'debt_no_snapshot'=>$debt['debt_no'],'source_order_no_snapshot'=>$debt['order_sn'],
        'original_debt_cents'=>100000,'repaid_debt_cents'=>30000,'cancelled_debt_cents'=>70000,
        'reason_snapshot'=>'R50回滚展示测试','operator_id'=>1,'operator_name_snapshot'=>'测试','store_name_snapshot'=>'测试',
        'command_idempotency_key'=>$key,'business_event_no'=>$key,'business_date'=>date('Y-m-d'),
        'occurred_at'=>time(),'settled_at'=>time(),'recorded_at'=>time()];
}
Db::startTrans();
try {
    assert50($cancelled->invoke($projection,$order,$debt,'0')==='0.00','active debt has no cancelled amount');
    $debt['status']=3; $debt['total_debt']='1000.00'; $debt['repaid_debt']='300.00';
    Db::name('store_debt')->where('id',$debt['id'])->update(['status'=>3,'total_debt'=>'1000.00','repaid_debt'=>'300.00']);
    Db::name('cashier_v3_debt_cancellation')->insert(audit50($debt,$key));
    assert50($cancelled->invoke($projection,$order,$debt,'0')==='700.00','historical card shows audited cancellation 700, not original 1000');
    assert50($pending->invoke($projection,$order,$debt)==='0.00','cancelled card current debt is zero');
    $rejected=false;
    try { $cancelled->invoke($projection,$order,$debt,'another-tenant'); } catch (LogicException $e) { $rejected=true; }
    assert50($rejected,'another tenant cannot read cancellation');
    $badOrder=$order; $badOrder['uid']=(int)$order['uid']+1; $rejected=false;
    try { $cancelled->invoke($projection,$badOrder,$debt,'0'); } catch (LogicException $e) { $rejected=true; }
    assert50($rejected,'another member cannot read cancellation');
    $debt['status']=2;
    Db::name('store_debt')->where('id',$debt['id'])->update(['status'=>2]);
    assert50($cancelled->invoke($projection,$order,$debt,'0')==='0.00','void state is not cancellation');
    Db::name('store_debt')->where('id',$debt['id'])->update(['status'=>3]);
    $writeoff=app()->make(\app\services\order\store\WriteOffOrderServices::class);
    // 取消700分摊到两个500项目，各350；购买总额仍1000，可用金额只能为300。
    $a=['id'=>90000001,'oid'=>$order['id'],'pay_price'=>'500.00','write_times'=>5,'write_surplus_times'=>5,'debt_amount'=>'0.00','repaid_debt_amount'=>'0.00','is_gift'=>0];
    $b=$a; $b['id']=90000002; $rows=[$a,$b];
    $usableA=$writeoff->usableEntitlementAmount($a,$order,'500.00',0,$rows);
    $usableB=$writeoff->usableEntitlementAmount($b,$order,'500.00',0,$rows);
    assert50(bcadd($usableA,$usableB,2)==='300.00','1000 less cancelled700 gives available300 across projects');
    assert50($writeoff->calcEffectiveWriteSurplusTimes($a,0,$order,'500.00',$rows)===1,'cancelled amount remains blocked after current debt becomes zero');
    assert50($writeoff->usableEntitlementAmount($a,$order,'300.00',0,$rows)==='0.00'
        && $writeoff->calcEffectiveWriteSurplusTimes($a,0,$order,'300.00',$rows)===0,'cannot consume cancelled portion when paid usable amount exhausted');
    $gift=$a; $gift['is_gift']=1;
    assert50($writeoff->usableEntitlementAmount($gift,$order,'500.00',0,$rows)==='500.00','separate gifted benefit is not unpaid debt');

    // V3 销售欠款按快照关联到销售明细；让明细剩余200、整单取消700，验证不串用整单金额。
    $cart=[]; $authority=[];
    foreach (Db::name('store_order_cart_info')->where('source_type','cashier_v3')->where('product_type',5)->where('cart_type',0)->limit(200)->select()->toArray() as $candidateCart) {
        $candidateSnapshot=json_decode($candidateCart['cart_info'],true);
        if (empty($candidateSnapshot['salesOrderId'])) continue;
        $candidateAuthority=(array)Db::name('cashier_v3_debt_authority')->where('sales_order_id',$candidateSnapshot['salesOrderId'])->find();
        if ($candidateAuthority) { $cart=$candidateCart; $authority=$candidateAuthority; break; }
    }
    if (!$cart) throw new RuntimeException('V3 issued-card fixture missing');
    $snapshot=json_decode($cart['cart_info'],true);
    $v3Order=(array)Db::name('store_order')->where('id',$cart['oid'])->find();
    $v3Debt=(array)Db::name('store_debt')->where('id',$authority['debt_id'])->find();
    $item=(array)Db::name('cashier_v3_debt_item_personnel_authority')->where('debt_id',$v3Debt['id'])->where('order_line_id',$snapshot['salesOrderLineId'])->find();
    if (!$item) throw new RuntimeException('V3 card debt item fixture missing');
    Db::name('store_debt')->where('id',$v3Debt['id'])->update(['status'=>3]);
    Db::name('store_debt_item')->where('id',$item['debt_item_id'])->update(['debt_amount'=>'500.00','repaid_debt'=>'300.00']);
    Db::name('cashier_v3_debt_cancellation')->insert(audit50($v3Debt,$key.'-v3'));
    $resolver=new CashierV3CardOriginDebtResolver();
    assert50($resolver->cancelledV3CardDebt($v3Order,(int)$v3Order['uid'],'0')==='200.00','V3 card shows its line remaining 200, not whole debt 700');
    assert50($resolver->pendingV3CardDebt($v3Order,(int)$v3Order['uid'])==='0.00','V3 cancelled card pending remains zero');
    $rejected=false;
    try { $resolver->cancelledV3CardDebt($v3Order,(int)$v3Order['uid'],'another-tenant'); } catch(LogicException $e) { $rejected=true; }
    assert50($rejected,'V3 cancellation enforces tenant audit boundary');
} finally { Db::rollback(); }
assert50(!Db::name('cashier_v3_debt_cancellation')->whereLike('cancellation_id',$key.'%')->count(),'test cancellation records rolled back');
assert50((array)Db::name('store_debt')->where('id',$debt['id'])->find() === $originalDebt,'temporary financial changes not persisted');
echo "R50_ENTITLEMENT_CANCELLED_DEBT_MYSQL_OK\n";
