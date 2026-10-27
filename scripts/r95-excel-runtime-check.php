<?php
/** Read-only post-deploy checks against real catalog and target readers. Never
 * create/save/activate/void a scheme; the DB transaction explicitly forbids writes. */
$site=$argv[1]??'';$phase=$argv[2]??'after';
if(!in_array($phase,['before','after'],true))throw new RuntimeException('Explicit phase required');
$sites=['rh'=>['rh.cc3798.com','ruihao','3306'],'008'=>['008.cc3798.com','mohe_008','3307'],'010'=>['010.cc3798.com','mohe_010_app','3307']];
if(!isset($sites[$site]))throw new RuntimeException('Explicit instance required');
[$domain,$database,$port]=$sites[$site];$root='/www/wwwroot/'.$domain;
chdir($root);require $root.'/vendor/autoload.php';(new think\App($root.'/'))->initialize();
$config=config('database.connections.mysql');
if($config['database']!==$database||(string)$config['hostport']!==$port||$config['prefix']!=='eb_')throw new RuntimeException('Wrong database');
think\facade\Db::execute('SET TRANSACTION READ ONLY');think\facade\Db::startTrans();
try{
    $stores=array_map('intval',think\facade\Db::name('system_store')->where('is_del',0)->column('id'));
    $controller=(new ReflectionClass(app\controller\admin\v1\target\StoreTarget::class))->newInstanceWithoutConstructor();
    $set=static function($name,$value)use($controller){$p=new ReflectionProperty($controller,$name);$p->setAccessible(true);$p->setValue($controller,$value);};
    $set('adminId',95);$set('adminInfo',['level'=>0]);$set('adminType',0);$set('agentId',0);
    $product=think\facade\Db::name('store_product')->where('type',1)->where('is_del',0)->whereIn('relation_id',$stores)->order('id')->find();
    $checks=[];
    if($product&&$phase==='after'){
        $query=static function($params)use($set,$controller){$request=new app\Request();$request->withGet($params);$set('request',$request);return $controller->schemeProducts()->getData();};
        $params=['store_id'=>(int)$product['relation_id'],'product_id'=>(int)$product['id']];$result=$query($params);
        if($result['status']!==200||count($result['data'])!==1||(int)$result['data'][0]['id']!==(int)$product['id'])throw new RuntimeException('Exact ID lookup failed');$checks[]='exact scoped product ID';
        $result=$query(['store_id'=>$product['relation_id'],'exact_name'=>$product['store_name']]);
        $expected=think\facade\Db::name('store_product')->where('type',1)->where('relation_id',$product['relation_id'])->where('is_del',0)->where('store_name',$product['store_name'])->count();
        if($result['status']!==200||count($result['data'])!==min(2,$expected))throw new RuntimeException('Exact name lookup failed');$checks[]='exact name and duplicate limit';
        if($query(['store_id'=>$product['relation_id'],'product_id'=>'1 or 1=1'])['status']===200)throw new RuntimeException('Invalid ID accepted');$checks[]='invalid ID rejected';
    }else $checks[]=$phase==='before'?'pre-release target reader baseline':'catalog empty; product sample unavailable';
    $service=new app\services\target\TargetSchemeServices();$reports=[];
    foreach(array_keys(app\services\target\TargetSchemeServices::REPORTS)as$code){$value=$service->effective(['tenant_id'=>'0','store_ids'=>$stores,'actor_id'=>95],$code);$reports[$code]=['rows'=>count($value['lines']),'sha256'=>hash('sha256',json_encode($value))];}
    $methods=[];foreach(['schemeVoid','schemeDelete']as$method){$methods[$method]=method_exists($controller,$method);if($phase==='after'&&!$methods[$method])throw new RuntimeException('Missing retained method');}
    echo json_encode(['site'=>$site,'phase'=>$phase,'checks'=>$checks,'reports'=>$reports,'retainedMethods'=>$methods,'writes'=>false],JSON_UNESCAPED_UNICODE),PHP_EOL;
}finally{think\facade\Db::rollback();}
