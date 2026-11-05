<?php
declare(strict_types=1);
/** Production catalog/selection paths with synthetic authority rows; no product, wallet or order writes. */
$root = getenv('BACKEND_ROOT') ?: '/var/www/html';
require $root . '/vendor/autoload.php';
(new think\App($root . '/'))->initialize();
use think\facade\Db;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\cashier\CashierV3SaleCatalogAuthority;
use app\services\cashier\v3\cashier\CashierV3SaleCatalogServices;
set_exception_handler(static function(Throwable $e):void {file_put_contents('php://stderr',(string)$e);exit(1);});
if (config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao') throw new RuntimeException('Local test DB only');
$checks = 0;
function r8Check(bool $ok, string $label): void {global $checks;if (!$ok) throw new RuntimeException($label);$checks++;echo "PASS $label\n";}
/** Row identity and prices are fabricated; only the invalid group shape reproduces the reported card. */
function r8CatalogRow(int $id, int $type = 6): array {
    return ['product_id'=>$id,'product_pid'=>0,'product_type'=>1,'product_relation_id'=>23,
        'product_product_type'=>$type,'product_store_name'=>'R8 test '.$id,'product_cate_id'=>'10',
        'product_keyword'=>'','product_unit_name'=>'次','product_is_show'=>1,'product_is_del'=>0,
        'product_is_verify'=>1,'product_is_inventory'=>0,'product_allow_negative_stock'=>0,
        'product_card_num'=>0,'product_card_num_type'=>0,'product_card_rule_type'=>$type===5?'combination':'',
        'product_card_rule_version'=>$type===5?1:0,'product_card_choice_limit'=>0,'product_card_shared_times'=>0,
        'sku_id'=>$id+1000,'sku_product_id'=>$id,'sku_product_type'=>$type,'sku_unique'=>'R8-'.$id,
        'sku_suk'=>'默认','sku_price'=>'100.00','sku_ot_price'=>'100.00','sku_cost'=>'0.00','sku_stock'=>'0',
        'sku_code'=>'R8-'.$id,'sku_bar_code'=>'','sku_is_show'=>1,'sku_type'=>0,'sku_write_times'=>1,
        'sku_write_valid'=>1,'sku_write_days'=>0,'sku_write_start'=>0,'sku_write_end'=>0,
        'category_names'=>['R8 test category']];
}
final class R8CatalogAuthority implements CashierV3SaleCatalogAuthority {
    public $rows; public $technicalFailure=false;
    public function __construct(array $rows) {$this->rows=$rows;}
    public function listStoreItems(int $storeId):array {if($this->technicalFailure)throw new RuntimeException('R8 technical failure');return $this->rows;}
    public function listReservationProjects(int $storeId):array {return [];}
    public function listCustomCardProjects(int $storeId):array {return [];}
    public function readStoreItemBySkuId(int $storeId,int $skuId) {foreach($this->rows as $r)if($r['sku_id']===$skuId&&$r['product_relation_id']===$storeId)return $r;return null;}
    public function lockStoreItemBySkuId(int $storeId,int $skuId) {return $this->readStoreItemBySkuId($storeId,$skuId);}
    public function readStoreItemAvailabilityBySkuId(int $storeId,int $skuId) {return $this->readStoreItemBySkuId($storeId,$skuId);}
    public function lockStoreResourceRow(int $storeId,string $kind,int $resourceId):bool {return true;}
}
$card=r8CatalogRow(103,5);$card['card_components']=[];
foreach([1,2] as $i){$component=r8CatalogRow(200+$i);$card['card_components'][]=['relation'=>[
    'id'=>$i,'card_product_id'=>103,'product_id'=>$component['product_id'],'product_type'=>6,
    'product_attr_unique'=>$component['sku_unique'],'cost'=>'0.00','price'=>'100.00','write_times'=>1,
    'writeoff_amount'=>'0.00','combination_group_no'=>$i,'combination_group_name'=>'组'.$i,
    'combination_group_rule'=>'choice_kind','combination_choice_limit'=>1,'combination_shared_times'=>0,'status'=>1],
    'item'=>$component];}
$good=[r8CatalogRow(101),r8CatalogRow(102,0),$card];
$op=new CashierV3OperatorScope(23,4796,'1','0');
$scope=new CashierV3DataScopeContext(4796,4796,23,'0','1',[23],CashierV3DataScopeContext::MODE_STORES,[],false,'','r8-local',['cashier.v3.cashier'],['name'=>'R8 local test']);
$authority=new R8CatalogAuthority($good);$service=new CashierV3SaleCatalogServices($authority);
$baseline=$service->catalog($op,$scope);r8Check(count($baseline['items'])===3,'healthy catalog baseline');
r8Check(!$baseline['items'][2]['disabled']&&count($baseline['items'][2]['cardPurchaseSnapshot']['components'])===2,'healthy combination card remains purchasable with full definition');
$broken=$card;$broken['product_id']=104;$broken['sku_id']=1104;$broken['sku_product_id']=104;$broken['product_store_name']='R8 broken combination';
foreach($broken['card_components'] as &$wrapper){$wrapper['relation']['card_product_id']=104;$wrapper['relation']['combination_group_no']++;}unset($wrapper);
$authority->rows=array_merge([$broken],$good);$before=$authority->rows;
$result=$service->catalog($op,$scope);$bad=$result['items'][0];
r8Check(count($result['items'])===4&&$result['complete']===true,'one bad card does not empty complete catalog');
r8Check(array_slice($result['items'],1)===$baseline['items'],'healthy cards prices previews and products unchanged');
r8Check($bad['disabled']===true&&$bad['catalogUnavailable']===true,'invalid item is explicitly disabled');
r8Check($bad['price']===null,'invalid item does not invent zero price');
r8Check(strpos($bad['disabledReason'],'分组必须连续')!==false,'invalid item retains business reason');
r8Check($bad['name']==='R8 broken combination'&&$bad['category']==='R8 test category','invalid item remains identifiable and filterable');
foreach(['cardPurchaseSnapshot','cardPreview','authoritySnapshot','productVersion','skuVersion','skuUnique'] as $key)r8Check(!array_key_exists($key,$bad),'no usable checkout authority '.$key);
r8Check($authority->rows===$before,'catalog read leaves invalid configuration untouched');
Db::startTrans();try{try{$service->selectSaleLineInTx(1104,'R8-reject-invalid-item',$op,$scope);throw new RuntimeException('bad card accepted');}catch(CashierV3CommandException $e){r8Check(($e->getDetail()['reason']??'')==='card_combination_group_sequence_invalid','authoritative add still rejects invalid card');}}finally{Db::rollback();}
$badHeader=$broken;$badHeader['product_card_rule_version']=0;$authority->rows=[$badHeader];r8Check($service->catalog($op,$scope)['items'][0]['disabled'],'invalid card header is isolated too');
$badPrice=r8CatalogRow(105);$badPrice['sku_price']='not-money';$authority->rows=[$badPrice,$good[0]];$priceResult=$service->catalog($op,$scope);r8Check(count($priceResult['items'])===2&&$priceResult['items'][0]['price']===null,'malformed business amount is not coerced into sale value');
$foreign=$broken;$foreign['product_relation_id']=24;$authority->rows=[$foreign];try{$service->catalog($op,$scope);throw new RuntimeException('foreign item exposed');}catch(CashierV3CommandException $e){r8Check(true,'invalid foreign-store identity is never projected');}
$authority->rows=$good;$authority->technicalFailure=true;try{$service->catalog($op,$scope);throw new RuntimeException('technical failure hidden');}catch(RuntimeException $e){r8Check($e->getMessage()==='R8 technical failure','technical failures are not swallowed as item issues');}
$authority->technicalFailure=false;$none=new CashierV3DataScopeContext(4796,4796,23,'0','1',[],CashierV3DataScopeContext::MODE_NONE,[],false,'','r8-local',[],[]);try{$service->catalog($op,$none);throw new RuntimeException('no-scope catalog exposed');}catch(CashierV3CommandException $e){r8Check($e->getResultCode()==='PERMISSION_DENIED','store permission guard retained');}
echo "PASS R8 CATALOG ISOLATION checks=$checks\n";
