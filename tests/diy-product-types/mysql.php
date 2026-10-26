<?php
/** Local-only real DAO/controller integration. All fixture rows are rolled back. */
require '/var/www/html/vendor/autoload.php';
$app = new think\App('/var/www/html/');
$app->env->load('/var/www/html/.env');
$app->initialize();
if (config('database.connections.mysql.hostname') !== 'mysql') throw new RuntimeException('Local database only');
use think\facade\Db;
use app\services\diy\DiyProductTypeFilter as Filter;
use app\services\product\product\StoreProductServices;
function demand($ok,$message) { if (!$ok) throw new RuntimeException($message); }
function requestFor($get=[], $post=[]) {
    $request = new app\Request();
    $request->withGet($get)->withPost($post)->withServer(['REQUEST_METHOD'=>$post?'POST':'GET']);
    app()->instance('request',$request);
    return $request;
}
function diy() { return app()->make(app\controller\admin\v1\diy\Diy::class,[],true); }
function body($response) { return json_decode($response->getContent(),true); }
function types($rows) { return array_values(array_unique(array_map('intval',array_column($rows,'product_type')))); }
$version=Db::query('SELECT VERSION() AS v')[0]['v'];
demand(strpos($version,'5.7.')===0,'MySQL 5.7 required');
$before=Db::name('diy')->order('id')->select()->toArray();
Db::startTrans();
try {
    $seed=Db::name('store_product')->where('type',0)->find();unset($seed['id']);
    $ids=[];
    foreach ([0,5,6,1,6] as $i=>$type) {
        $row=array_merge($seed,['product_type'=>$type,'pid'=>0,'type'=>0,'relation_id'=>0,'is_del'=>0,'is_show'=>1,'is_verify'=>1,'show_type'=>0,
            'is_vip_product'=>0,'store_name'=>'LOCAL-DIY-TYPE-'.$i,'brand_id'=>987601,'cate_id'=>'987601','store_label_id'=>'987601','sort'=>0,'sales'=>100-$i,'price'=>100+$i]);
        if ($i===4) $row['is_show']=0;
        $ids[]=Db::name('store_product')->insertGetId($row);
        // Category/brand/label searches use the relation authority, not just display fields.
        $relation=Db::name('store_product_relation')->find() ?: [];unset($relation['id']);
        foreach([1,2,3] as $relationType) {
            Db::name('store_product_relation')->insert(array_merge($relation,[
                'product_id'=>end($ids),'relation_id'=>987601,'type'=>$relationType
            ]));
        }
    }
    $service=app()->make(StoreProductServices::class);
    $storeIds=[];
    foreach([118,107] as $storeId) {
        $child=array_merge($row,['type'=>1,'relation_id'=>$storeId,'pid'=>$ids[2],'is_show'=>1]);
        $storeIds[]=Db::name('store_product')->insertGetId($child);
    }
    $scoped=$service->getSearchList(Filter::apply(['ids'=>$storeIds,'type'=>1,'relation_id'=>118],'[6]'),0,0,['id','relation_id']);
    demand(count($scoped)===1 && (int)$scoped[0]['id']===(int)$storeIds[0],'type filter expanded store scope');
    for($mask=0;$mask<8;$mask++) {
        $selected=[];foreach([0,5,6] as $i=>$type) if($mask&(1<<$i))$selected[]=$type;
        requestFor(['ids'=>implode(',',$ids),'catalog_types'=>json_encode($selected),'page'=>1,'limit'=>20]);
        $list=body(diy()->getProduct())['data'];
        sort($selected);$got=types($list);sort($got);demand($got===$selected,'controller combinations differ');
        demand(!in_array($ids[4],array_column($list,'id')),'hidden product leaked');
        // The generic picker also builds stock metadata; check empty/all independently
        // without repeating that unrelated expensive projection for every combination.
        if ($mask===0 || $mask===7) {
            requestFor(['store_name'=>'LOCAL-DIY-TYPE-','catalog_types'=>json_encode($selected),'type'=>1,'page'=>1,'limit'=>20]);
            $picker=body(app()->make(app\controller\admin\v1\product\StoreProduct::class,[],true)->search_list())['data'];
            $got=types($picker['list']);sort($got);
            demand($got===$selected && (int)$picker['count']===count($selected),'picker type/count scope differs');
        }
    }
    requestFor(['page'=>1,'limit'=>1]);
    $where=Filter::apply(['ids'=>$ids,'type'=>0,'pid'=>0,'is_show'=>1,'is_verify'=>1,'is_del'=>0,'salesOrder'=>'desc','brand_id'=>[987601]],'[6]');
    $list=$service->getSearchList($where,1,1,['id','product_type']);
    demand(count($list)===1 && (int)$list[0]['id']===(int)$ids[2],'filter must precede limit: '.json_encode([$list,$ids]));
    demand($service->getSearchList(Filter::apply(['ids'=>$ids,'brand_id'=>[987602]],'[6]'),0,0,['id'])===[],'brand intersection lost');
    demand($service->getSearchList(Filter::apply(['ids'=>$ids,'cate_id'=>[987602]],'[6]'),0,0,['id'])===[],'category intersection lost');
    demand($service->getSearchList(Filter::apply(['ids'=>$ids,'store_label_id'=>[987602]],'[6]'),0,0,['id'])===[],'label intersection lost');
    demand(Filter::apply(['relation_id'=>118,'type'=>1],null)===['relation_id'=>118,'type'=>1],'legacy scope changed');
    foreach(['oops','[1]','[false]','{}','0'] as $invalid) {
        try { Filter::normalize($invalid);throw new RuntimeException('invalid input accepted:'.$invalid); }
        catch(think\exception\ValidateException $expected) {}
    }
    // Clone one local template only inside the transaction; never publish/change default.
    $template=Db::name('diy')->find();unset($template['id']);
    $template['name']='LOCAL-DIY-TYPES';$template['status']=0;$template['is_diy']=1;
    $manual=['name'=>'goodList','catalogTypes'=>['type'=>[6]],'typeConfig'=>['activeValue'=>1],'goodsList'=>['ids'=>$ids]];
    $tabs=['name'=>'promotionList','tabConfig'=>['list'=>[
        ['catalogTypes'=>['type'=>[5]],'tabVal'=>1,'goodsList'=>['ids'=>$ids]],
        ['catalogTypes'=>['type'=>[6]],'tabVal'=>2,'brandConfig'=>['brandVal'=>[987602]],'numConfig'=>['val'=>10]],
        ['catalogTypes'=>['type'=>[]],'tabVal'=>1,'goodsList'=>['ids'=>$ids]]
    ]]];
    $template['value']=json_encode([$manual,$tabs]);
    $id=Db::name('diy')->insertGetId($template);
    // Exercise the real save controller/service and DB, including removal of preview rows.
    $manual['goodsList']['list']=Db::name('store_product')->whereIn('id',$ids)->field('id,product_type,image')->select()->toArray();
    foreach($tabs['tabConfig']['list'] as &$tab) $tab['goodsList']['list']=$manual['goodsList']['list'];unset($tab);
    requestFor([],['name'=>'LOCAL-DIY-TYPES','type'=>1,'value'=>json_encode([$manual,$tabs])]);
    demand(body(diy()->saveData($id))['status']===200,'actual decoration save failed');
    $saved=json_decode(Db::name('diy')->where('id',$id)->value('value'),true);
    demand($saved[0]['catalogTypes']['type']===[6] && !isset($saved[0]['goodsList']['list']),'saved source/snapshot boundary');
    requestFor();
    $read=body(diy()->getInfo($id,$service,app()->make(app\services\activity\newcomer\StoreNewcomerServices::class),app()->make(app\services\activity\video\VideoServices::class)))['data']['info'] ?? null;
    if ($read===null) {
        $response=body(diy()->getInfo($id,$service,app()->make(app\services\activity\newcomer\StoreNewcomerServices::class),app()->make(app\services\activity\video\VideoServices::class)));
        $read=$response['data'];
    }
    demand(types($read['value'][0]['goodsList']['list'])===[6],'saved manual type lost');
    demand(types($read['value'][1]['tabConfig']['list'][0]['goodsList']['list'])===[5],'saved tab type lost');
    demand($read['value'][1]['tabConfig']['list'][1]['productList']['list']===[],'tab brand scope lost on refresh');
    demand($read['value'][1]['tabConfig']['list'][2]['goodsList']['list']===[],'empty tab became all');
    $cases=['8-real-controller-combinations','real-picker-empty-all-count','hidden-status','filter-before-pagination','brand-category-label-intersection','legacy-store-scope','invalid-input','actual-controller-service-save','db-refresh-manual','db-refresh-independent-tabs','db-refresh-brand','empty-tab'];
} finally {
    Db::rollback();
    // Save listeners only invalidate local DIY caches. Discard test-version cache entries too.
    app()->make(app\services\diy\DiyServices::class)->cacheTag()->clear();
}
demand(Db::name('diy')->order('id')->select()->toArray()===$before,'rollback changed local decoration');
echo json_encode(['status'=>'PASS','database'=>$version,'cases'=>$cases,'rollback'=>'all decoration rows unchanged'],JSON_UNESCAPED_UNICODE).PHP_EOL;
