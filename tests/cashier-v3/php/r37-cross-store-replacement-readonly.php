<?php
/** 本地真实来源读取测试：锁定后回滚，不替换、不扣权益，不产生业务记录。 */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';
$app = new \think\App($backend . '/'); $app->initialize();
use think\facade\Db;
use app\services\cashier\v3\CashierV3DataScopeContext as Scope;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\card\CashierV3CardOperationAuthorityServices;
use app\services\cashier\v3\card\CashierV3CardOperationKernel as Kernel;
$row = Db::name('user_card_holder')->alias('h')
    ->join('store_order o', 'o.id=h.oid AND o.store_id=h.store_id')
    ->join('store_order_cart_info d', 'd.oid=o.id')
    ->join('cashier_v3_entitlement_resource_version v', "v.resource_id=d.id AND v.resource_kind='member_benefit_pool' AND v.current_version>0")
    ->where('h.is_del',0)->where('h.store_id','>',0)->where('h.uid','>',0)
    ->where('o.paid',1)->where('o.is_del',0)->where('o.is_system_del',0)->where('o.is_user_del',0)
    ->where('o.refund_status',0)->where('o.terminal_action',0)->where('o.card_upgrade_use_oid',0)
    ->where('d.cart_type',2)->where('d.product_type',6)->where('d.write_surplus_times','>',0)->where('d.is_writeoff',0)
    ->field('h.id,h.store_id,d.id AS detail_id')->find();
if (!$row) throw new RuntimeException('missing valid source fixture');
$store = (int)$row['store_id'] === 133 ? 134 : 133;
$operator = new CashierV3OperatorScope($store,71,'test','0');
$reflection = new ReflectionClass(CashierV3CardOperationAuthorityServices::class);
$writer = $reflection->newInstanceWithoutConstructor();
$method = $reflection->getMethod('loadSourceCardForUpdate'); $method->setAccessible(true);
Db::startTrans();
try {
    // 两种操作均不要求来源门店相同；权限由命令网关负责，不在来源读取重复加跨店门禁。
    $scope = new Scope(71,701,$store,'0','test',[$store],Scope::MODE_STORES,[],false,'','test',[],[]);
    foreach ([Kernel::TYPE_PROJECT_REPLACEMENT, Kernel::TYPE_PROJECT_UPGRADE] as $type) {
            $source = $method->invoke($writer,(int)$row['id'],$operator,$type,
                ['projectLines'=>[['sourceDetailId'=>(int)$row['detail_id'],'quantity'=>1]]],$scope);
            if ($source['holderId'] !== (int)$row['id'] || !$source['projects']) throw new RuntimeException('source mismatch');
            echo "PASS cross-store source: {$type}\n";
    }
} finally { Db::rollback(); }
echo "PASS rollback; no business writes\n";
