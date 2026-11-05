<?php
declare(strict_types=1);
/** Local real-DB regression; own marked fixtures only, real catalog locks and sync bodies, no settlement. */
require '/var/www/html/vendor/autoload.php';
$app = new think\App('/var/www/html/'); $app->initialize();
set_exception_handler(static function(Throwable $error):void {file_put_contents('php://stderr',(string)$error);exit(1);});
use think\facade\Db;
use app\services\product\product\StoreProductServices;
use app\services\product\branch\StoreBranchProductServices;
use app\services\product\product\StoreCardRelatedServices;
use app\services\product\product\StoreCardRuleServices;
use app\services\product\product\StoreCatalogWriteLockGuard;
use app\services\product\product\StoreCatalogWriteLease;
use app\services\cashier\v3\cashier\CashierV3SaleCatalogServices;
use app\services\cashier\v3\cashier\ThinkPhpCashierV3SaleCatalogAuthority;
if (config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao'
    || !getenv('MOHE_LOCAL_DB_HOSTNAME')
    || Db::query('SELECT @@hostname host')[0]['host'] !== getenv('MOHE_LOCAL_DB_HOSTNAME')) throw new RuntimeException('Local DB guard failed');
$checks=0; $owned=[]; $tag='R8W-'.bin2hex(random_bytes(5)); $keepForUi=false;
/** Marker-scoped cleanup supports a later read-only browser visit to disposable local fixtures. */
function wmCleanup(array $ids,string $marker):void {
    if(!$ids)return;
    Db::startTrans();try {
        $actual=Db::name('store_product')->whereIn('id',$ids)->where('keyword',$marker)->column('id');
        if(count($actual)!==Db::name('store_product')->whereIn('id',$ids)->count())throw new RuntimeException('Fixture cleanup ownership mismatch');
        $ids=array_map('intval',$actual);if(!$ids){Db::commit();return;}
        Db::name('store_card_related')->whereIn('card_product_id',$ids)->delete();
        foreach(['store_product_attr','store_product_attr_result','store_product_attr_value','store_product_description','store_product_reservation_time'] as $table)Db::name($table)->whereIn('product_id',$ids)->delete();
        Db::name('store_product')->whereIn('id',$ids)->where('keyword',$marker)->delete();Db::commit();
    }catch(Throwable $e){Db::rollback();throw $e;}
}
if(getenv('WM_CLEANUP_TAG')){
    $tag=(string)getenv('WM_CLEANUP_TAG');if(!preg_match('/^R8W-[a-f0-9]{10}$/D',$tag))throw new RuntimeException('Invalid cleanup marker');
    wmCleanup(array_map('intval',Db::name('store_product')->where('keyword',$tag)->column('id')),$tag);echo 'CLEANUP remaining='.Db::name('store_product')->where('keyword',$tag)->count()."\n";exit(0);
}
function wmCheck(bool $ok,string $label):void {global $checks;if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";}
/** Scheduling alone is substituted: execute real sync bodies after their real transaction commits. */
final class WMQueue extends mohe\utils\Queue {
    public function __construct(){parent::__construct();}
    public function push(?array $data=null) {
        $job=$this->job;$action=$this->do;$args=$this->data;$this->clean();
        if($job!==app\jobs\product\ProductSyncStoreJob::class || !in_array($action,['syncCardRelatedProducts','syncProduct'],true))throw new RuntimeException('Unexpected fixture job');
        return (new $job())->{$action}(...$args);
    }
}
/** Reproduce a committed platform-definition change between planning read and writer lease. */
final class WMConcurrentProduct extends StoreProductServices {
    public $armed=false;public $cardId=0;public $projectId=0;
    public function getInfo(int $id) {
        $result=parent::getInfo($id);
        if($this->armed&&$id===$this->cardId){$this->armed=false;Db::name('store_card_related')->where('card_product_id',$id)->where('product_id',$this->projectId)->update(['status'=>0]);}
        return $result;
    }
}
$queueProperty=new ReflectionProperty(mohe\utils\Queue::class,'instance');$queueProperty->setAccessible(true);$oldQueue=$queueProperty->getValue();$queueProperty->setValue(null,new WMQueue());
// Skip notification/config listeners in this test process; business writers and the sync job remain real.
$app->event->remove('product.create');$app->event->remove('product.status');
/** Clone only catalog definitions and their form metadata; source products are never changed. */
function wmClone(int $template,string $name,int $pid=0,?int $type=null):array {
    global $owned,$tag;
    $p=Db::name('store_product')->where('id',$template)->find();if(!$p)throw new RuntimeException('Local source template missing');
    unset($p['id']);$p['pid']=$pid;$p['keyword']=$tag;$p['store_name']=$tag.'-'.$name;$p['is_show']=1;$p['is_del']=0;$p['is_verify']=1;
    $p['applicable_type']=2;$p['applicable_store_id']='23';if($type!==null)$p['product_type']=$type;
    $id=(int)Db::name('store_product')->insertGetId($p);$owned[]=$id;
    $sku=Db::name('store_product_attr_value')->where('product_id',$template)->where('type',0)->order('id')->find();if(!$sku)throw new RuntimeException('Local SKU template missing');
    $oldUnique=$sku['unique'];unset($sku['id']);$sku['product_id']=$id;$sku['unique']=substr(hash('sha256',$tag.$id),0,8);if($type!==null)$sku['product_type']=$type;
    $skuId=(int)Db::name('store_product_attr_value')->insertGetId($sku);
    foreach(['store_product_attr','store_product_attr_result','store_product_description'] as $table){
        foreach(Db::name($table)->where('product_id',$template)->select()->toArray() as $row){
            unset($row['id']);$row['product_id']=$id;
            foreach($row as $key=>&$v)if(is_string($v))$v=str_replace($oldUnique,$sku['unique'],$v);unset($v);
            Db::name($table)->insert($row);
        }
    }
    return ['id'=>$id,'skuId'=>$skuId,'unique'=>$sku['unique']];
}
/** Two group definitions preserve prices, times, labor and role defaults while membership changes. */
function wmRelation(array $p,int $group):array {return ['product_id'=>$p['id'],'product_attr_unique'=>$p['unique'],'unique'=>$p['unique'],'product_type'=>6,'cost'=>0,'price'=>100,'write_times'=>0,'writeoff_amount'=>0,'labor_fee'=>10,'labor_fee_cents'=>1000,'craftsman_default_mode'=>'manual_amount','craftsman_default_unit_amount'=>15,'craftsman_default_unit_amount_cents'=>1500,'combination_group_no'=>$group,'combination_group_name'=>'Group '.$group,'combination_group_rule'=>'choice_count','combination_choice_limit'=>0,'combination_shared_times'=>$group===1?3:1,'status'=>1];}
function wmRows(int $id):array {return Db::name('store_card_related')->where('card_product_id',$id)->order('product_id')->select()->toArray();}
function wmWrite(int $id,array $rows):void {app()->make(StoreCatalogWriteLockGuard::class)->withCardRelationMutation([$id],$rows,static function(StoreCatalogWriteLease $lease)use($id,$rows){app()->make(StoreCardRelatedServices::class)->handleCardRelatedInGuard($lease,$id,$rows);});}
/** The existing edit DTO supplies actual fields; only missing optional controller defaults are filled. */
function wmEditPayload(StoreProductServices $service,int $id):array {
    $data=$service->getInfo($id)['productInfo'];
    return array_replace(['coupon_ids'=>[],'ensure_id'=>[],'specs_id'=>0,'specs'=>[],'soure_link'=>'','send_config'=>[],'is_sync_show'=>1],$data);
}
try {
    Db::startTrans();
    try {
        $a=wmClone(253778,'A');$sa=wmClone(253861,'store-A',$a['id']);
        $b=wmClone(253778,'B');$sb=wmClone(253861,'store-B',$b['id']);
        $card=wmClone(624953,'card');$storeCard=wmClone(625036,'store-card',$card['id']);
        foreach([[$card,[wmRelation($a,1),wmRelation($b,2)]],[$storeCard,[wmRelation($sa,1),wmRelation($sb,2)]]] as $pair){
            foreach($pair[1] as $row){unset($row['unique'],$row['labor_fee'],$row['craftsman_default_unit_amount']);$row['card_product_id']=$pair[0]['id'];Db::name('store_card_related')->insert($row);}
        }
        Db::commit();
    }catch(Throwable $e){Db::rollback();throw $e;}
    $products=$app->make(StoreProductServices::class);$branch=$app->make(StoreBranchProductServices::class);$related=$app->make(StoreCardRelatedServices::class);
    $beforeRoot=wmRows($card['id']);$beforeStore=wmRows($storeCard['id']);
    $products->setShow([$a['id']],0);
    wmCheck((int)Db::name('store_product')->where('id',$a['id'])->value('is_show')===0,'platform project enters warehouse');
    wmCheck((int)Db::name('store_product')->where('id',$sa['id'])->value('is_show')===0,'platform visibility still syncs to store project');
    wmCheck(wmRows($card['id'])===$beforeRoot&&wmRows($storeCard['id'])===$beforeStore,'platform warehouse transition preserves exact memberships and card defaults');
    $products->setShow([$a['id']],1);$branch->setShow(23,$sa['id'],0);
    wmCheck(wmRows($storeCard['id'])===$beforeStore,'store warehouse transition preserves exact membership');
    $branch->setShow(23,$sa['id'],1);wmCheck(wmRows($storeCard['id'])===$beforeStore,'store re-listing does not rewrite membership');
    $projectEdit=wmEditPayload($products,$a['id']);$projectEdit['is_show']=0;$products->saveData($a['id'],$projectEdit,0,0,1);
    wmCheck(wmRows($card['id'])===$beforeRoot&&wmRows($storeCard['id'])===$beforeStore,'real project edit save in warehouse preserves memberships');
    // Reproduce the reported legacy stop: reading does not silently repair it; explicit card save does.
    Db::name('store_card_related')->where('card_product_id',$card['id'])->where('product_id',$a['id'])->update(['status'=>0]);
    $edit=$related->getCardRelatedProductForEdit($card['id']);wmCheck(count($edit)===2&&count($related->getCardRelatedProduct($card['id']))===1,'legacy inactive relation stays traceable until explicit save');
    $input=['product_type'=>5,'spec_type'=>0,'card_rule_type'=>'combination','attr'=>['write_valid'=>1],'related'=>[array_replace(wmRelation($a,1),['status'=>0]),wmRelation($b,2)]];
    $rules=new StoreCardRuleServices();$normalized=$rules->normalizeForSave($input);wmCheck($normalized['related'][0]['status']===1,'explicit card save includes selected legacy warehouse row');
    $locked=$rules->normalizeForSave($input,false);wmCheck($locked['related'][0]['status']===0,'inherited store definition does not gain authority to restore parent membership');
    $fractionalLocked=$input;unset($fractionalLocked['related'][0]['craftsman_default_unit_amount']);$fractionalLocked['related'][0]['craftsman_default_unit_amount_cents']=1501;$rejectedFraction=false;
    try{$rules->normalizeForSave($fractionalLocked,false);}catch(Throwable $e){$rejectedFraction=strpos($e->getMessage(),'整数元')!==false;}
    wmCheck($rejectedFraction,'inherited fractional amount is rejected instead of truncated');
    $cardEdit=wmEditPayload($products,$card['id']);$cardEdit['related']=$input['related'];$products->saveData($card['id'],$cardEdit,0,0,1);
    wmCheck(count($related->getCardRelatedProduct($card['id']))===2,'real card edit save restores both effective groups');
    $products->setShow([$a['id']],0);$branch->syncProduct($card['id'],23,0,0,1);
    $synced=$related->getCardRelatedProduct($storeCard['id']);wmCheck(count($synced)===2,'actual store sync keeps warehouse group');
    wmCheck(array_map('intval',array_column($synced,'combination_group_no'))===[1,2],'actual sync retains continuous group numbers');
    wmCheck(in_array($sa['id'],array_map('intval',array_column($synced,'product_id')),true),'sync maps warehouse source to correct store project');
    wmCheck((int)Db::name('store_product')->where('id',$sa['id'])->value('is_show')===0,'card sync does not put component on sale');
    $authority=new ThinkPhpCashierV3SaleCatalogAuthority();$sale=new CashierV3SaleCatalogServices($authority);$op=new app\services\cashier\v3\CashierV3OperatorScope(23,4796,'1','0');$scope=new app\services\cashier\v3\CashierV3DataScopeContext(4796,4796,23,'0','1',[23],app\services\cashier\v3\CashierV3DataScopeContext::MODE_STORES,[],false,'','wm-local',['cashier.v3.cashier'],[]);
    $catalog=$sale->catalog($op,$scope);$item=array_values(array_filter($catalog['items'],static function($i)use($storeCard){return (int)$i['productId']===$storeCard['id'];}))[0]??[];
    wmCheck(!empty($item['cardPurchaseSnapshot'])&&empty($item['disabled']),'cashier receives usable complete card snapshot with warehouse project');
    wmCheck(count($item['cardPurchaseSnapshot']['components'])===2,'card purchase snapshot contains both projects');
    wmCheck(!in_array($sa['id'],array_map('intval',array_column($catalog['items'],'productId')),true),'warehouse project remains hidden from standalone sale');
    // A stale deleted row must fail before replacing the existing complete relation set.
    $before=wmRows($card['id']);Db::name('store_product')->where('id',$b['id'])->update(['is_del'=>1]);$rejected=false;
    try{wmWrite($card['id'],$normalized['related']);}catch(Throwable $e){$rejected=strpos($e->getMessage(),'已删除')!==false;}
    wmCheck($rejected&&wmRows($card['id'])===$before,'deleted component is rejected atomically without losing old card');
    Db::name('store_product')->where('id',$b['id'])->update(['is_del'=>0]);
    Db::name('store_product')->where('id',$b['id'])->update(['is_verify'=>0]);$unreviewedRejected=false;
    try{wmWrite($card['id'],$normalized['related']);}catch(Throwable $e){$unreviewedRejected=strpos($e->getMessage(),'未审核')!==false;}
    wmCheck($unreviewedRejected&&wmRows($card['id'])===$before,'unreviewed project cannot be restored by explicit card save');
    Db::name('store_product')->where('id',$b['id'])->update(['is_verify'=>1]);
    wmCheck($normalized['related'][0]['combination_shared_times']===3&&$normalized['related'][0]['labor_fee_cents']===1000&&$normalized['related'][0]['craftsman_default_unit_amount_cents']===1500,'repair preserves counts labor and craftsman defaults');
    Db::name('store_card_related')->where('card_product_id',$storeCard['id'])->where('product_id',$sa['id'])->update(['status'=>0]);
    $inherited=wmEditPayload($products,$storeCard['id']);$inherited['related'][0]['status']=1;$products->saveData($storeCard['id'],$inherited,1,23,4796);
    wmCheck((int)Db::name('store_card_related')->where('card_product_id',$storeCard['id'])->where('product_id',$sa['id'])->value('status')===0,'real inherited-card edit cannot reactivate platform-owned definition');
    wmCheck((int)Db::name('store_card_related')->where('card_product_id',$storeCard['id'])->where('product_id',$sa['id'])->value('craftsman_default_unit_amount_cents')===1500,'inherited-card edit keeps trusted manual amount exactly');
    $branch->syncProduct($card['id'],23,0,0,1);$synced=$related->getCardRelatedProduct($storeCard['id']);
    $raceEdit=wmEditPayload($products,$storeCard['id']);$priceBefore=Db::name('store_product_attr_value')->where('id',$storeCard['skuId'])->value('price');
    $race=$app->make(WMConcurrentProduct::class);$race->cardId=$storeCard['id'];$race->projectId=$sa['id'];$race->armed=true;$raceRejected=false;
    try{$race->saveData($storeCard['id'],$raceEdit,1,23,4796);}catch(Throwable $e){$raceRejected=strpos($e->getMessage(),'平台卡项配置已变化')!==false;}
    wmCheck($raceRejected&&(int)Db::name('store_card_related')->where('card_product_id',$storeCard['id'])->where('product_id',$sa['id'])->value('status')===0&&Db::name('store_product_attr_value')->where('id',$storeCard['skuId'])->value('price')===$priceBefore,'locked re-read blocks stale inherited definition without overwriting concurrent change');
    $branch->syncProduct($card['id'],23,0,0,1);$synced=$related->getCardRelatedProduct($storeCard['id']);
    $retail=wmClone(253861,'retail',0,0);Db::name('store_product')->where('id',$retail['id'])->update(['is_inventory'=>1,'stock'=>0,'is_sold'=>0]);Db::name('store_product_attr_value')->where('id',$retail['skuId'])->update(['stock'=>0]);
    $retailRow=wmRelation($retail,3);$retailRow['product_type']=0;$allRows=$synced;$allRows[]=$retailRow;wmWrite($storeCard['id'],$allRows);$beforeStock=wmRows($storeCard['id']);
    (new app\jobs\product\ProductStockTips())->doJob($retail['id'],0);
    wmCheck((int)Db::name('store_product')->where('id',$retail['id'])->value('is_sold')===1,'real stock job still marks zero-stock product sold out');
    wmCheck(wmRows($storeCard['id'])===$beforeStock,'zero-stock job no longer removes product from card');
    // Leave the two-group business example coherent for an optional browser visit.
    $branch->syncProduct($card['id'],23,0,0,1);
    if(getenv('WM_KEEP_FOR_UI')){$keepForUi=true;echo 'UI_FIXTURE '.json_encode(['tag'=>$tag,'platformCardId'=>$card['id'],'storeCardId'=>$storeCard['id'],'projectId'=>$a['id'],'storeProjectId'=>$sa['id'],'ownedIds'=>$owned])."\n";}
    echo "PASS WAREHOUSE_MEMBERSHIP checks=$checks\n";
}finally {
    $queueProperty->setValue(null,$oldQueue);
    // Only identities allocated by this test are cleanup targets; no customer facts or source templates are touched.
    if(!$keepForUi){wmCleanup($owned,$tag);echo 'CLEANUP remaining='.Db::name('store_product')->where('keyword',$tag)->count()."\n";}
}
