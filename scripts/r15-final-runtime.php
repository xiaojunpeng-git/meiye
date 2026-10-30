<?php
/** Customer-bound, read-only personal-center contract. Rendering a missing all-orders
 * entry must never rewrite a customer's saved decoration or other menu choices. */
declare(strict_types=1);
$site=$argv[1]??'';$sites=['rh'=>['rh.cc3798.com','ruihao',3306],'008'=>['008.cc3798.com','mohe_008',3307],'010'=>['010.cc3798.com','mohe_010_app',3307]];
if(!isset($sites[$site]))throw new RuntimeException('Named customer required');[$domain,$database,$port]=$sites[$site];$root='/www/wwwroot/'.$domain;chdir($root);require$root.'/vendor/autoload.php';$app=new think\App($root.'/');$app->initialize();set_exception_handler(function($e){file_put_contents('php://stderr',$e->getMessage());exit(1);});$cfg=config('database.connections.mysql');if($cfg['database']!==$database||(int)$cfg['hostport']!==$port)throw new RuntimeException('Wrong database');$db=think\facade\Db::connect();$db->execute('START TRANSACTION READ ONLY');
try{
 $before=hash('sha256',json_encode($db->name('diy')->order('id')->select()->toArray()));$svc=app()->make(app\services\diy\DiyServices::class);$data=$svc->getMemberData();$member=$data['member'];$cached=$svc->getCacheDiyData('member');
 // Read-only projections must filter hidden entries while retaining admin configuration.
 $expected=array_values(array_filter($member['menu']['list']??[],static function($row){return !in_array($row['is_show']??null,[0,'0',false],true);}));
 if($expected!==($cached['menu']['list']??[]))throw new RuntimeException('Service visibility projection differs');

 foreach([$member,$cached]as$menu){$all=array_values(array_filter($menu['order']['list'],static function($r){return($r['key']??'')==='all';}));if(count($all)!==1||$all[0]['url']!=='/pages/goods/order_list/index')throw new RuntimeException('All-orders contract missing');}
 if($before!==hash('sha256',json_encode($db->name('diy')->order('id')->select()->toArray())))throw new RuntimeException('Saved decoration changed');
 echo json_encode(['result'=>'PASS','site'=>$site,'allOrdersEntry'=>true,'serviceVisibilityProjection'=>true,'warmCache'=>true,'configurationUnchanged'=>true,'businessWrites'=>0]),PHP_EOL;
}finally{$db->execute('ROLLBACK');}
