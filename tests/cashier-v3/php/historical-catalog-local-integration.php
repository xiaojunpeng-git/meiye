<?php
declare(strict_types=1);

/** 本地真实 MySQL 回滚验证：临时打开样本上架状态，不提交任何订单、库存或权益变更。 */
require '/var/www/html/vendor/autoload.php';
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\cashier\CashierV3SaleCatalogServices;
use app\services\cashier\v3\cashier\ThinkPhpCashierV3SaleCatalogAuthority;
use app\services\cashier\v3\cashier\CashierV3EntitlementProjectionServices;
use app\services\product\branch\StoreBranchProductServices;
use app\services\product\product\StoreProductSaleUsagePolicy;
use app\services\cashier\v3\order\CashierV3UnifiedRefundServices;
use think\facade\Db;

$app = new think\App('/var/www/html/');
$app->initialize();
if (config('database.connections.mysql.hostname') !== 'mysql' || config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('Local Docker ruihao only');
}
$checks = [];
$check = static function (bool $ok, string $name) use (&$checks): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $name);
    $checks[] = $name;
};
$deny = static function (callable $call, string $name) use ($check): void {
    try { $call(); } catch (CashierV3CommandException $e) {
        $check(($e->getDetail()['reason'] ?? '') === 'cashier_catalog_historical_carrier', $name);
        return;
    }
    throw new RuntimeException('Expected history-only rejection: ' . $name);
};
$operator = new CashierV3OperatorScope(132, 179, '', '0');
// 退款候选对照使用显式授权的本地测试上下文；不绕过服务端权限守卫。
$scope = new CashierV3DataScopeContext(179,179,132,'0','',[132],CashierV3DataScopeContext::MODE_STORES,[],false,'','r47-test',['cashier.v3.order.refund'],[]);
$authority = new ThinkPhpCashierV3SaleCatalogAuthority();
$service = new CashierV3SaleCatalogServices($authority);
$snapshot = static function (): string {
    return hash('sha256', json_encode([
        Db::name('store_product')->whereIn('id',[96884,371026])->order('id')->select()->toArray(),
        Db::name('store_product_attr_value')->whereIn('product_id',[96884,371026])->order('id')->select()->toArray(),
        Db::name('store_order_cart_info')->whereIn('product_id',[96884,371026])->order('id')->select()->toArray(),
        Db::name('store_card_related')->whereIn('product_id',[96884,371026])->order('id')->select()->toArray(),
    ]));
};
$before = $snapshot();
$regexp = (string)config('product_catalog.historical_store_keyword_regexp');
Db::startTrans();
try {
    foreach (['RH_PROJECT:x:STORE:132','RH_GOODS:x:STORE:132','RH_CARDCLASS:x:STORE:132',
        'RH_SWXT:PROJECT:x:STORE:132','RH_SWXT:GOODS:x:STORE:132','RH_SWXT:CARD:x:STORE:132',
        'RH_TIMECARD_PROJECT_V1:STORE:132'] as $keyword) {
        $check(StoreProductSaleUsagePolicy::isHistoricalCarrier(['type'=>1,'relation_id'=>132,'keyword'=>$keyword]), 'recognized ' . $keyword);
    }
    foreach (['','普通项目','RH_PROJECT:x','RH_PROJECT:x:STORE:133','rh_project:x:STORE:132',
        'RH_PROJECT:x:STORE:0132','RH_PROJECT:x:STORE:132:extra','RH_PROJECT:x:STORE:132' . "\n"] as $keyword) {
        $check(!StoreProductSaleUsagePolicy::isHistoricalCarrier(['type'=>1,'relation_id'=>132,'keyword'=>$keyword]), 'not guessed from keyword ' . json_encode($keyword));
    }
    $check(!StoreProductSaleUsagePolicy::isHistoricalCarrier(['type'=>0,'relation_id'=>132,'keyword'=>'RH_PROJECT:x:STORE:132']), 'platform master is not a store carrier');
    Db::name('store_product')->whereIn('id',[96884,371026])->update(['is_show'=>1,'is_verify'=>1,'is_del'=>0]);
    Db::name('store_product_attr_value')->whereIn('product_id',[96884,371026])->where('type',0)->update(['is_show'=>1]);
    $listed = $authority->listStoreItems(132);
    $ids = array_column($listed,'sku_id');
    $check(in_array(95163,$ids) && !in_array(354890,$ids), 'real SQL keeps normal SKU and hides historical SKU');
    $catalog = $service->catalog($operator,$scope);
    $matching = array_values(array_filter($catalog['items'],static function($r){return $r['kind']==='项目' && $r['name']==='通(背/腿)';}));
    $check(count($matching)===1 && (int)$matching[0]['id']===95163, 'service catalog has one project at the normal identity');
    $check($matching[0]['price']==='158.00', 'normal authority price stays 15800 cents');
    $row = $authority->readStoreItemBySkuId(132,354890);
    $check(is_array($row) && $row['product_keyword']==='RH_PROJECT:0091000552:STORE:132', 'historical identity remains readable');
    $check(is_array($authority->lockStoreItemBySkuId(132,354890)), 'historical identity remains lockable');
    $check($authority->readStoreItemBySkuId(133,354890)===null, 'cross-store historical lookup remains denied');
    foreach (['RH_PROJECT:x:STORE:132','RH_GOODS:x:STORE:132','RH_SWXT:PROJECT:x:STORE:132',
        'rh_project:x:STORE:132','RH_PROJECT:x:STORE:133','RH_PROJECT:x:STORE:0132',
        '普通项目','RH_PROJECT:x:STORE:132:extra','RH_PROJECT:x:STORE:132' . "\n"] as $keyword) {
        Db::name('store_product')->where('id',371026)->update(['keyword'=>$keyword]);
        $query=Db::name('store_product')->alias('p')->where('p.id',371026);
        $excluded=StoreProductSaleUsagePolicy::excludeHistoricalCarriers($query,'p')->count()===0;
        $expected=StoreProductSaleUsagePolicy::isHistoricalCarrier(['type'=>1,'relation_id'=>132,'keyword'=>$keyword]);
        $check($excluded===$expected,'SQL and PHP policy agree for ' . json_encode($keyword));
    }
    Db::name('store_product')->where('id',371026)->update(['keyword'=>$row['product_keyword']]);
    $deny(static function()use($service,$operator,$scope){$service->discoverItemResources(354890,$operator,$scope);}, 'forged catalog selection discovery denied');
    $deny(static function()use($service,$operator,$scope){$service->discoverSnapshotSaleResources(354890,$operator,$scope);}, 'old browser snapshot discovery denied');
    $deny(static function()use($service,$operator,$scope){$service->selectCheckoutSaleLineInTx(354890,'r47',$operator,$scope);}, 'final individual sale denied');
    $line=['line_role'=>'sale','source_id'=>371026,'catalog_sku_id'=>354890,'source_type'=>'project'];
    $deny(static function()use($line,$scope){CashierV3SaleCatalogServices::assertNewSaleLinesAvailableInTx([$line],$scope);}, 'shared final transaction rejects historical new sale');
    $line['line_role']='entitlement_service';
    CashierV3SaleCatalogServices::assertNewSaleLinesAvailableInTx([$line],$scope);
    $check(true,'existing entitlement row is not reclassified as a sale');
    $line=['line_role'=>'sale','source_id'=>96884,'catalog_sku_id'=>95163,'source_type'=>'project'];
    CashierV3SaleCatalogServices::assertNewSaleLinesAvailableInTx([$line],$scope);
    $check(true,'normal sale passes final transaction');
    $normal=$service->selectCheckoutSaleLineInTx(95163,'r47-normal',$operator,$scope);
    $check((int)$normal['catalog_product_id']===96884,'normal final sale line uses unchanged product');
    $syncMethod=new ReflectionMethod(StoreBranchProductServices::class,'readSyncSaleProduct');
    $syncMethod->setAccessible(true);
    $branch=$app->make(StoreBranchProductServices::class);
    $check((int)$syncMethod->invoke($branch,79589,132)['id']===96884,'normal synchronization reuses sale copy only');
    Db::name('store_product')->where('id',96884)->update(['pid'=>0]);
    $check($syncMethod->invoke($branch,79589,132)===[],'history-only store does not overwrite carrier on synchronization');
    Db::name('store_product')->where('id',96884)->update(['pid'=>79589]);

    // 同名和合法多规格不去重：只排除明确承载用途。临时新 SKU 随外层事务回滚。
    $sku=(array)Db::name('store_product_attr_value')->where('id',95163)->find();
    unset($sku['id']);$sku['suk']='R47-second-spec';$sku['unique']='r47spec';
    $extraSku=Db::name('store_product_attr_value')->insertGetId($sku);
    $listedIds=array_column($authority->listStoreItems(132),'sku_id');
    $check(in_array(95163,$listedIds) && in_array($extraSku,$listedIds),'legitimate multiple SKUs remain selectable');

    // 对真实历史权益的只读投影对照：新销售配置开关前后必须完全相同。
    $memberIds=Db::name('store_order_cart_info')->where('product_id',371026)->group('uid')->column('uid');
    $projection=$app->make(CashierV3EntitlementProjectionServices::class);
    $historicalSources=0;
    $refundService=new CashierV3UnifiedRefundServices();
    $refundTargets=0;
    foreach($memberIds as $memberId){
        $enabled=$projection->reservationSources((int)$memberId,$operator,$scope);
        $app->config->set(['historical_store_keyword_regexp'=>''],'product_catalog');
        try{$disabled=$projection->reservationSources((int)$memberId,$operator,$scope);}
        finally{$app->config->set(['historical_store_keyword_regexp'=>$regexp],'product_catalog');}
        $check($enabled===$disabled,'historical rights projection unchanged for member ' . (int)$memberId);
        $refundEnabled=$refundService->candidates(['memberId'=>(int)$memberId,'mode'=>'card'],$operator,$scope);
        $app->config->set(['historical_store_keyword_regexp'=>''],'product_catalog');
        try{$refundDisabled=$refundService->candidates(['memberId'=>(int)$memberId,'mode'=>'card'],$operator,$scope);}
        finally{$app->config->set(['historical_store_keyword_regexp'=>$regexp],'product_catalog');}
        $check($refundEnabled===$refundDisabled,'historical refund candidates unchanged for member ' . (int)$memberId);
        $refundTargets+=count($refundEnabled['cards']);
        foreach($enabled as $source){foreach(($source['projects']??[]) as $project){
            if((int)($project['projectId']??0)===371026)$historicalSources++;
        }}
    }
    $check(count($memberIds)>0,'real historical order references exercised');
    $check($historicalSources>0,'historical carrier projects still appear in existing rights');
    $check($refundTargets>0,'historical refund candidates are not empty');
} finally {
    $app->config->set(['historical_store_keyword_regexp'=>$regexp],'product_catalog');
    Db::rollback();
}
$check($snapshot()===$before,'products SKUs order references and card relations restored exactly');
echo json_encode(['passed'=>count($checks),'checks'=>$checks,'historical_projection_matches'=>$historicalSources,
    'refund_candidate_count'=>$refundTargets,'rollback_verified'=>true],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) . PHP_EOL;
