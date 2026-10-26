<?php
/** 补漏发布只读检查：元数据两种显示、原响应和卡内金额配置完全不变。线上配置不切换、不保存商品。 */
$site=$argv[1]??'';$mode=$argv[2]??'';$sites=['rh'=>['rh.cc3798.com','ruihao','3306'],'008'=>['008.cc3798.com','mohe_008','3307'],'010'=>['010.cc3798.com','mohe_010_app','3307']];
if(!isset($sites[$site])||!in_array($mode,['before','candidate','after'],true))throw new RuntimeException('EXPLICIT_INSTANCE_MODE');
[$domain,$database,$port]=$sites[$site];$root='/www/wwwroot/'.$domain;require $root.'/vendor/autoload.php';
// Fixed candidate product service injected here, before autoload.
(new think\App($root.'/'))->initialize();set_exception_handler(static function(Throwable $e):void{file_put_contents('php://stderr',$e->getMessage().PHP_EOL);exit(1);});$cfg=config('database.connections.mysql');if($cfg['database']!==$database||(string)$cfg['hostport']!==$port)throw new RuntimeException('WRONG_INSTANCE');
use think\facade\Db;
Db::execute('SET TRANSACTION READ ONLY');Db::startTrans();try{
 $config=Db::name('system_config')->order('id')->select()->toArray();$effective=(int)sys_config('cashier_labor_fee_mode',0);
 if($mode!=='before'){$GLOBALS['r37CardMode']=$effective;eval('namespace app\\services\\product\\product; function sys_config($key,$default=""){return $key==="cashier_labor_fee_mode"?$GLOBALS["r37CardMode"]:\\sys_config($key,$default);}');}

 $ids=Db::name('store_product')->where('product_type',5)->where('is_del',0)->order('id')->limit(2)->column('id');$ids=array_merge([0],array_map('intval',$ids));
 $related=Db::name('store_card_related')->whereIn('card_product_id',$ids)->order('id')->select()->toArray();$feesSHA=hash('sha256',json_encode($related));
 $service=app()->make(app\services\product\product\StoreProductServices::class);$responses=[];$cases=[];
 foreach($ids as$id){$data=$service->otherInfo($id,0);if($mode!=='before'&&($data['labor_fee_visible']??null)!==($effective!==1))throw new RuntimeException('EFFECTIVE_MODE_MISMATCH');unset($data['labor_fee_visible']);$responses[$id]=hash('sha256',json_encode($data));}
 if($mode!=='before'){
  // 仅当前CLI命名空间模拟选项；不能为了测试而改变线上配置或商品金额。

  foreach($ids as$id)foreach([0,1,null]as$value){$GLOBALS['r37CardMode']=$value;$data=$service->otherInfo($id,0);$visible=$data['labor_fee_visible'];if($visible!==($value!==1))throw new RuntimeException('MODE_INVALID');unset($data['labor_fee_visible']);if(hash('sha256',json_encode($data))!==$responses[$id])throw new RuntimeException('AMOUNT_OR_RESPONSE_CHANGED');$cases[]=['id'=>$id,'mode'=>$value,'visible'=>$visible];}
 }
 if(hash('sha256',json_encode(Db::name('store_card_related')->whereIn('card_product_id',$ids)->order('id')->select()->toArray()))!==$feesSHA)throw new RuntimeException('RELATED_CHANGED');
 echo json_encode(['site'=>$site,'mode'=>$mode,'result'=>'PASS','effectiveMode'=>$effective,'configSHA256'=>hash('sha256',json_encode($config)),'relatedRows'=>count($related),'relatedSHA256'=>$feesSHA,'responses'=>$responses,'cases'=>$cases,'readOnly'=>true,'businessWrites'=>0],JSON_UNESCAPED_UNICODE),PHP_EOL;
}finally{Db::rollback();}
