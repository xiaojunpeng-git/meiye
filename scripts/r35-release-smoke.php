<?php
/** 逐实例只读验证组织编辑、原写状态及原组织详情投影；不保存真实组织或调整账号角色。 */
$site=$argv[1]??'';$mode=$argv[2]??'';
$sites=['rh'=>['rh.cc3798.com','ruihao','3306'],'008'=>['008.cc3798.com','mohe_008','3307'],'010'=>['010.cc3798.com','mohe_010_app','3307']];
if(!isset($sites[$site])||!in_array($mode,['before','candidate','after'],true))throw new RuntimeException('EXPLICIT_INSTANCE_MODE');
[$domain,$database,$port]=$sites[$site];$root='/www/wwwroot/'.$domain;
require $root.'/vendor/autoload.php';
// Candidate classes, when present, are injected from fixed canonical Git blobs before autoload.
(new think\App($root.'/'))->initialize();$cfg=config('database.connections.mysql');
if($cfg['database']!==$database||(string)$cfg['hostport']!==$port)throw new RuntimeException('WRONG_INSTANCE');
use think\facade\Db;
set_exception_handler(static function(Throwable $e):void{file_put_contents('php://stderr',$e->getMessage().PHP_EOL);exit(1);});
Db::execute('SET TRANSACTION READ ONLY');Db::startTrans();
try{
 $gate=app()->make(app\services\organization\OrganizationWorkspaceWriteGate::class);
 $admins=Db::name('system_admin')->where('is_del',0)->where('status',1)->where('admin_type','<>',3)->order('level','desc')->order('id','asc')->limit(8)->select()->toArray();
 $super=Db::name('system_admin')->where('is_del',0)->where('status',1)->where('level',0)->where('admin_type','<>',3)->find();
 if($super)$admins[]=$super;
 $operator=Db::name('system_admin')->where('account','4796')->where('is_del',0)->where('status',1)->find();if($operator)$admins[]=$operator;
 $base=[];$capabilities=[];$seen=[];$assertions=0;
 foreach($admins as $admin){
  $id=(int)$admin['id'];if(isset($seen[$id]))continue;$seen[$id]=true;$status=$gate->getWriteStatus($admin);
  $base[$id]=array_intersect_key($status,array_flip(['write_enabled','can_write','reason_code','reason_text','source_mode','is_super_admin']));
  if($mode!=='before'){
   if(!array_key_exists('can_edit_organization',$status))throw new RuntimeException('CAPABILITY_MISSING');
   $access=app()->make(app\services\organization\OrganizationEditAccessServices::class);$direct=$access->status($admin);
   if($direct['can_edit_organization']!==$status['can_edit_organization']||$direct['editable_organization_ids']!==$status['editable_organization_ids'])throw new RuntimeException('STATUS_MISMATCH');
   $ids=$direct['editable_organization_ids'];$capabilities[$id]=['enabled'=>$direct['can_edit_organization'],'scopeCount'=>$ids===null?'all':count($ids),'ordinary'=>(int)$admin['level']!==0];
   if($direct['can_edit_organization']){
    $orgId=$ids===null?(int)Db::name('organization')->where('is_del',0)->order('id')->value('id'):(int)reset($ids);
    if($orgId>0){$pid=(int)Db::name('organization')->where('id',$orgId)->value('pid');$access->assertCanEdit($orgId,$pid,$admin);$assertions++;}
   }
   try{$access->assertCanEdit(2147483647,0,$admin);throw new RuntimeException('INVALID_ORG_ACCEPTED');}catch(Throwable $e){if($e->getMessage()==='INVALID_ORG_ACCEPTED')throw $e;$assertions++;}
  }
 }
 ksort($base);ksort($capabilities);
 $snap=[];foreach(['organization','organization_store','organization_admin','system_role','employee_data_scope']as$table){$rows=Db::name($table)->order('id')->select()->toArray();$snap[$table]=['count'=>count($rows),'sha256'=>hash('sha256',json_encode($rows))];}
 // Existing editor detail and statistics-dimension projection must remain readable.
 $org=(int)Db::name('organization')->where('is_del',0)->order('id')->value('id');$read=app()->make(app\services\organization\OrganizationWorkspaceReadServices::class);$dims=$read->getStatisticDimensions($org);
 echo json_encode(['site'=>$site,'mode'=>$mode,'result'=>'PASS','baseStatuses'=>$base,'capabilities'=>$capabilities,'realTargetChecks'=>$assertions,'protectedData'=>$snap,'dimensionReadSHA256'=>hash('sha256',json_encode($dims)),'readOnly'=>true],JSON_UNESCAPED_UNICODE),PHP_EOL;
}finally{Db::rollback();}
