<?php
declare(strict_types=1);

/** 仅本地 Docker ruihao：真实项目目录/行锁/定制卡配置回滚测试，不提交订单或款项。 */
if (getenv('R10_CUSTOM_CARD_LOCAL_TEST') !== '1') throw new RuntimeException('Explicit local test opt-in required');
require '/var/www/html/vendor/autoload.php';
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\cashier\CashierV3SaleCatalogServices;
use app\services\cashier\v3\cashier\ThinkPhpCashierV3SaleCatalogAuthority;
use app\services\cashier\v3\card\CashierV3CustomCardConfigurationServices;
use think\facade\Db;

$app = new think\App('/var/www/html/');
$app->initialize();
set_exception_handler(static function ($e): void { file_put_contents('php://stderr', $e->getMessage() . "\n"); exit(1); });
if (config('database.connections.mysql.hostname') !== 'mysql' || config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('Local Docker ruihao only');
}
$checks = [];
$check = static function (bool $ok, string $name) use (&$checks): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $name);
    $checks[] = $name;
};
$deny = static function (callable $fn, string $name) use ($check): void {
    try { $fn(); } catch (CashierV3CommandException $e) { $check(true, $name); return; }
    throw new RuntimeException('Expected denial: ' . $name);
};
$product = (array)Db::name('store_product')->where('type',1)->where('product_type',6)
    ->where('is_verify',1)->where('is_del',0)->where('pid','<>',8154)->order('id')->find();
if (!$product) throw new RuntimeException('Missing project fixture seed');
$sku = (array)Db::name('store_product_attr_value')->where('product_id',(int)$product['id'])->where('type',0)->where('is_show',1)->find();
if (!$sku) throw new RuntimeException('Missing SKU fixture seed');
$store = (int)$product['relation_id'];
$operator = new CashierV3OperatorScope($store, 1, '', '0');
$scope = new CashierV3DataScopeContext(1,1,$store,'0','',[$store],CashierV3DataScopeContext::MODE_STORES,[],false,'','r10-test',['cashier.v3.cashier'],[]);
$authority = new ThinkPhpCashierV3SaleCatalogAuthority();
$catalog = new CashierV3SaleCatalogServices($authority);
$configuration = new CashierV3CustomCardConfigurationServices($catalog);
$token = 'r10-' . bin2hex(random_bytes(8));
$createdIds = [];
Db::startTrans();
try {
    // 克隆隔离项目及 SKU，不修改现有真实项目；所有临时行随外层事务回滚。
    unset($product['id'], $sku['id']);
    $product['store_name'] = $token; $product['keyword'] = ''; $product['pid'] = 0;
    $product['is_show'] = 0; $product['is_verify'] = 1; $product['is_del'] = 0;
    $id = (int)Db::name('store_product')->insertGetId($product); $createdIds[] = $id;
    $sku['product_id'] = $id; $sku['unique'] = $token; $sku['price'] = '100.00'; $sku['ot_price'] = '100.00'; $sku['cost'] = '0.00';
    $sid = (int)Db::name('store_product_attr_value')->insertGetId($sku);
    $ids = static function (array $rows): array { return array_map('intval', array_column($rows,'sku_id')); };
    $check(!in_array($sid,$ids($authority->listStoreItems($store)),true), 'warehouse project excluded from normal sale directory');
    $check(in_array($sid,$ids($authority->listCustomCardProjects($store)),true), 'warehouse project included in custom-card directory');
    $check(!in_array($sid,$ids($authority->listCustomCardProjects($store + 10000)),true), 'custom candidates remain store scoped');
    $dto = $catalog->catalog($operator,$scope);
    $match = array_values(array_filter($dto['customCardProjects'],static function ($row) use ($sid): bool { return (int)$row['id'] === $sid; }));
    $check(count($match)===1 && $match[0]['saleStatus']==='warehouse' && !$match[0]['disabled'], 'service exposes selectable warehouse status separately');
    $catalog->discoverCustomCardComponentResources($sid,$operator,$scope);
    $line = $catalog->selectCustomCardComponentAfterGatewayLocksInTx($sid,$token,$operator,$scope);
    $check((int)$line['catalog_sku_id']===$sid && (int)$line['catalog_product_type']===6, 'warehouse component locks and builds authoritative line');
    $deny(static function () use ($catalog,$sid,$operator,$scope): void { $catalog->selectSaleLineInTx($sid,'normal-sale',$operator,$scope); }, 'warehouse project still denied for ordinary new sale');
    $payload = ['cardName'=>'R10 rollback card','validityEnd'=>'2099-10-11','activateOnPurchase'=>true,'components'=>[['skuId'=>$sid,'times'=>3,'amount'=>'398']]];
    $deny(static function () use ($catalog,$sid,$scope,$store): void { $catalog->discoverCustomCardComponentResources($sid,new CashierV3OperatorScope($store+10000,1,'','0'),$scope); }, 'forged store context denied');
    foreach ([['is_del'=>1],['is_verify'=>0],['is_show'=>2],['product_type'=>0],['keyword'=>'RH_PROJECT:x:STORE:'.$store],['pid'=>8154]] as $invalid) {
        Db::name('store_product')->where('id',$id)->update($invalid);
        $check(!in_array($sid,$ids($authority->listCustomCardProjects($store)),true), 'SQL excludes invalid component '.json_encode($invalid));
        $deny(static function () use ($catalog,$sid,$operator,$scope): void { $catalog->selectCustomCardComponentAfterGatewayLocksInTx($sid,'invalid',$operator,$scope); }, 'locked component rejects '.json_encode($invalid));
        Db::name('store_product')->where('id',$id)->update(['is_del'=>0,'is_verify'=>1,'is_show'=>0,'product_type'=>6,'keyword'=>'','pid'=>0]);
    }
    Db::name('store_product_attr_value')->where('id',$sid)->update(['is_show'=>0]);
    $check(!in_array($sid,$ids($authority->listCustomCardProjects($store)),true), 'disabled SKU excluded from candidates');
    $deny(static function () use ($catalog,$sid,$operator,$scope): void { $catalog->selectCustomCardComponentAfterGatewayLocksInTx($sid,'disabled',$operator,$scope); }, 'disabled SKU denied after lock');
    Db::name('store_product_attr_value')->where('id',$sid)->update(['is_show'=>1]);
    Db::name('store_product')->where('id',$id)->update(['is_show'=>1]);
    $check(in_array($sid,$ids($authority->listStoreItems($store)),true), 'selling project remains in normal sale directory');
    Db::name('store_product')->where('id',$id)->update(['is_show'=>0]);
    // 使用已有本店定制卡壳完成配置落库/重放；缺少壳时显式失败，不冒充完整业务验证。
    $sale = $configuration->createSaleLineAfterGatewayLocksInTx($payload,$token,$token,$token,[],$operator,$scope,1,true,$token);
    $row = (array)Db::name(CashierV3CustomCardConfigurationServices::TABLE)->where('created_command_idempotency_key',$token)->find();
    $snapshot = json_decode((string)$row['configuration_snapshot_json'],true);
    $check((int)$row['total_amount_cents']===39800 && (int)$snapshot['components'][0]['writeTimes']===3, 'warehouse custom-card configuration persists correct amount and times');
    $again = $configuration->createSaleLineAfterGatewayLocksInTx($payload,$token,$token,$token,[],$operator,$scope,1,true,$token);
    $check($sale===$again && Db::name(CashierV3CustomCardConfigurationServices::TABLE)->where('created_command_idempotency_key',$token)->count()===1, 'configuration replay is idempotent');
    $payload['components'][0]['times']=4;
    $deny(static function () use ($configuration,$payload,$token,$operator,$scope): void { $configuration->createSaleLineAfterGatewayLocksInTx($payload,$token,$token,$token,[],$operator,$scope,1,true,$token); }, 'changed configuration cannot reuse idempotency key');
} finally { Db::rollback(); }
$check(Db::name('store_product')->whereIn('id',$createdIds)->count()===0, 'temporary products rolled back');
$check(Db::name('store_product_attr_value')->where('unique',$token)->count()===0, 'temporary SKU rolled back');
$check(Db::name(CashierV3CustomCardConfigurationServices::TABLE)->where('created_command_idempotency_key',$token)->count()===0, 'custom-card configuration rolled back');
echo json_encode(['passed'=>count($checks),'checks'=>$checks,'rollback_verified'=>true],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) . "\n";
