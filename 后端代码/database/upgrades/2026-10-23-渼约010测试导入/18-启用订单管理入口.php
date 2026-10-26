<?php
/** 仅启用010订单管理展示总入口；不增加岗位授权、不调整数据范围。 */
function enable010OrderEntry(): array {
 $db=(string)\think\facade\Db::query('SELECT DATABASE() d')[0]['d'];
 $local=is_file('/.dockerenv')&&getenv('SOURCE010_TEST')==='1'&&preg_match('/^test_010_source_[a-f0-9]+$/D',$db);
 if(PHP_SAPI!=='cli'||(!$local&&$db!=='mohe_010_app'))throw new RuntimeException('010_TARGET_REQUIRED');
 return \think\facade\Db::transaction(function(){
  $before=\think\facade\Db::name('store_v3_permission_catalog')->where('id',1)->lock(true)->find();
  if(!$before)throw new RuntimeException('CATALOG_REQUIRED');
  $rows=json_decode($before['rules_json'],true);if(!is_array($rows))throw new RuntimeException('INVALID_CATALOG');
  $matched=0;foreach($rows as &$r)if((int)$r['id']===-2806){if($r['title']!=='订单管理'||(int)$r['enabled']!==0)throw new RuntimeException('BASELINE_CHANGED');$r['enabled']=1;$matched++;}unset($r);
  if($matched!==1)throw new RuntimeException('SINGLE_ENTRY_REQUIRED');
  $json=json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  \think\facade\Db::name('store_v3_permission_catalog')->where('id',1)->update(['rules_json'=>$json,'revision'=>(int)$before['revision']+1,'updated_at'=>time()]);
  \think\facade\Db::name('store_v3_permission_audit')->insert(['operator_id'=>0,'action'=>'010_ENABLE_ORDER_ENTRY','before_json'=>$before['rules_json'],'after_json'=>$json,'created_at'=>time()]);
  return ['entry'=>-2806,'enabled'=>1,'positions_unchanged'=>true];
 });
}
