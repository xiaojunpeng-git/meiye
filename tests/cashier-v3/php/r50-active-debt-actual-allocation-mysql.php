<?php
/** R50普通欠款回归：实际成交事实优先于配置价0；只读及事务回滚，不生成业务记录。 */
require '/var/www/html/vendor/autoload.php';
use think\facade\Db;
use app\services\cashier\v3\cashier\CashierV3CardOriginDebtResolver;
use app\services\cashier\v3\card\CashierV3CardSaleActualAmountServices;
use app\services\order\store\WriteOffOrderServices;
$app = new \think\App('/var/www/html/'); $app->initialize();
function active50(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    echo "PASS $label\n";
}
// 指定用户报告的本地卡仅作真实数据断言，不静默改库或核销。
$holder=(array)Db::name('user_card_holder')->where('card_no','2423506')->find();
$order=(array)Db::name('store_order')->where('id',$holder['oid']??0)->find();
$rows=Db::name('store_order_cart_info')->where('oid',$order['id']??0)->where('cart_type',2)->where('product_type',6)->select()->toArray();
if (!$order || count($rows)!==1) throw new RuntimeException('reported local card fixture missing');
$cart=$rows[0]; $writeoff=app()->make(WriteOffOrderServices::class);
$pending=(new CashierV3CardOriginDebtResolver())->pendingV3CardDebt($order,(int)$order['uid']);
$amounts=(new CashierV3CardSaleActualAmountServices())->forOrders([(int)$order['id']],$rows);
active50($pending==='1500.00' && ($amounts[(int)$cart['id']]??0)===298000,'reported card has actual2980 and active debt1500');
active50($writeoff->usableEntitlementAmount($cart,$order,'2980.00',(float)$pending,$rows)==='1480.00','actual available1480 despite compatibility price0');
active50($writeoff->calcEffectiveWriteSurplusTimes($cart,(float)$pending,$order,'2980.00',$rows)===4,'only4 whole uses funded by1480');
active50($writeoff->usableEntitlementAmount($cart,$order,'2980.00',1500)==='1480.00','database-loaded allocation rows use same actual facts');
active50($writeoff->usableEntitlementAmount($cart,$order,'1000.00',1500,$rows)==='0.00','unpaid remainder clamps available amount to zero');
// 将真实事实临时置为已还款再查询，验证归还后恢复，而非永久减原购买额。
$base=(array)Db::name('store_order_cart_info')->where('oid',$order['id'])->where('cart_type',0)->find();
$snapshot=json_decode($base['cart_info'],true);
$authority=(array)Db::name('cashier_v3_debt_authority')->where('sales_order_id',$snapshot['salesOrderId'])->find();
$before=(array)Db::name('store_debt')->where('id',$authority['debt_id'])->find();
Db::startTrans();
try {
    Db::name('store_debt')->where('id',$authority['debt_id'])->update(['status'=>1]);
    $paid=(new CashierV3CardOriginDebtResolver())->pendingV3CardDebt($order,(int)$order['uid']);
    active50($paid==='0.00' && $writeoff->usableEntitlementAmount($cart,$order,'2980.00',(float)$paid,$rows)==='2980.00','settled debt restores usable original remaining2980');
} finally { Db::rollback(); }
active50((array)Db::name('store_debt')->where('id',$authority['debt_id'])->find()===$before,'temporary repayment state rolled back');
echo "R50 ACTIVE DEBT ACTUAL ALLOCATION PASS\n";
