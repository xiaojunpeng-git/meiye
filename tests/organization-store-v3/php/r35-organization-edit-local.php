<?php
/** R35 本地事务回归：真实功能授权、组织保存/审计/幂等和拒绝越界；合成数据全部回滚。 */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();
set_exception_handler(static function(Throwable $e):void{file_put_contents('php://stderr',$e->getMessage().PHP_EOL);exit(1);});
use think\facade\Db;
use app\services\organization\OrganizationWorkspaceWriteServices;
use app\services\organization\OrganizationWorkspaceWriteGate;
use app\services\organization\OrganizationEditAccessServices;
if(getenv('R35_LOCAL_TEST')!=='1'||config('database.connections.mysql.hostname')!=='mysql'||config('database.connections.mysql.database')!=='ruihao')throw new RuntimeException('LOCAL_ONLY');
function r35ok(bool $v,string $label):void{if(!$v)throw new RuntimeException($label);echo 'PASS ',$label,PHP_EOL;}
function r35reject(callable $fn,string $message):void{try{$fn();}catch(Throwable $e){r35ok(strpos($e->getMessage(),$message)!==false,$message);return;}throw new RuntimeException('Expected denial: '.$message);}
function r35ctx():array{return ['header_token'=>'35000000-0000-4000-8000-'.bin2hex(random_bytes(6))];}
$tag='r35-'.bin2hex(random_bytes(5));$svc=app()->make(OrganizationWorkspaceWriteServices::class);$access=app()->make(OrganizationEditAccessServices::class);$gate=app()->make(OrganizationWorkspaceWriteGate::class);
Db::startTrans();
try{
 $menu=(int)Db::name('system_menus')->where('unique_auth',OrganizationEditAccessServices::AUTH_EDIT)->where('is_del',0)->value('id');r35ok($menu>0,'使用现有编辑组织功能权限');
 $role=(int)Db::name('system_role')->insertGetId(['role_name'=>$tag,'rules'=>(string)$menu,'status'=>1]);
 $employee=(int)Db::name('employee')->insertGetId(['name'=>$tag,'is_del'=>0]);
 $id=(int)Db::name('system_admin')->insertGetId(['account'=>$tag,'real_name'=>$tag,'pwd'=>'','level'=>1,'admin_type'=>0,'status'=>1,'is_del'=>0,'roles'=>(string)$role,'employee_id'=>$employee]);
 $admin=['id'=>$id,'level'=>1,'admin_type'=>0,'account'=>$tag];
 $root=(int)Db::name('organization')->where('is_del',0)->where('pid',0)->value('id');r35ok($root>0,'存在本地组织根');
 $org=(int)Db::name('organization')->insertGetId(['pid'=>$root,'name'=>$tag,'sort'=>0,'is_del'=>0]);
 $child=(int)Db::name('organization')->insertGetId(['pid'=>$org,'name'=>$tag.'-child','sort'=>0,'is_del'=>0]);
 $outside=(int)Db::name('organization')->insertGetId(['pid'=>$root,'name'=>$tag.'-outside','sort'=>0,'is_del'=>0]);
 $scope=(int)Db::name('employee_data_scope')->insertGetId(['employee_id'=>$employee,'source_type'=>'hq','source_store_id'=>0,'scope_mode'=>'org','org_ids'=>json_encode([$org]),'store_ids'=>'[]','status'=>1,'is_del'=>0]);
 $status=$gate->getWriteStatus($admin);r35ok(!$status['can_write']&&$status['can_edit_organization']&&in_array($org,$status['editable_organization_ids'],true),'普通编辑功能可用且不开放其他超管写操作');
 $data=['pid'=>$root,'name'=>$tag.'-edited','sort'=>7];$ctx=r35ctx();
 $first=$svc->saveOrganization($org,$data,$admin,$ctx);r35ok(!$first['replay']&&(int)$first['data']['id']===$org,'已授权普通账号真实保存成功');
 $row=Db::name('organization')->where('id',$org)->find();r35ok($row['name']===$data['name']&&(int)$row['sort']===7&&(int)$row['pid']===$root,'数据库刷新读回名称排序与原父组织');
 $audit=Db::name('organization_change_log')->where('request_token',$ctx['header_token'])->find();r35ok($audit&&(int)$audit['operator_id']===$id&&json_decode($audit['after_data'],true)['name']===$data['name'],'组织审计记录实际操作人及修改后值');
 $failed=r35ctx();$fault=$data;$fault['name']=$tag.'-must-rollback';OrganizationWorkspaceWriteServices::armCliAuditFaultOnce();
 r35reject(static function()use($svc,$org,$fault,$admin,$failed){$svc->saveOrganization($org,$fault,$admin,$failed);},'组织审计故障注入');
 r35ok(Db::name('organization')->where('id',$org)->value('name')===$data['name']&&!Db::name('organization_write_idempotency')->where('request_token',$failed['header_token'])->count(),'审计失败时组织和幂等结果同时回滚');
 $replay=$svc->saveOrganization($org,$data,$admin,$ctx);r35ok($replay['replay']&&$replay['data']===$first['data'],'重复请求只回放一次结果');
 r35reject(static function()use($svc,$org,$admin,$ctx,$data){$data['sort']=8;$svc->saveOrganization($org,$data,$admin,$ctx);},'IDEMPOTENCY_TOKEN_CONFLICT');
 r35reject(static function()use($svc,$org,$admin,$data){$svc->saveOrganization($org,$data,$admin,[]);},'缺少请求令牌');
 r35reject(static function()use($svc,$outside,$root,$admin,$tag){$svc->saveOrganization($outside,['pid'=>$root,'name'=>$tag],$admin,r35ctx());},'组织管理范围');
 r35reject(static function()use($svc,$org,$outside,$admin,$data){$data['pid']=$outside;$svc->saveOrganization($org,$data,$admin,r35ctx());},'上级组织超出');
 r35reject(static function()use($svc,$org,$admin,$data){$data['pid']=0;$svc->saveOrganization($org,$data,$admin,r35ctx());},'上级组织超出');
 r35reject(static function()use($svc,$root,$admin,$tag){$svc->saveOrganization(0,['pid'=>$root,'name'=>$tag],$admin,r35ctx());},'无权限');
 r35reject(static function()use($svc,$org,$admin){$svc->deleteOrganization($org,$admin,r35ctx());},'无权限');
 // 缩小范围后，即便重用此前成功请求令牌也不能回放越权结果。
 Db::name('employee_data_scope')->where('id',$scope)->update(['org_ids'=>json_encode([$child])]);
 r35reject(static function()use($svc,$org,$data,$admin,$ctx){$svc->saveOrganization($org,$data,$admin,$ctx);},'组织管理范围');
 Db::name('employee_data_scope')->where('id',$scope)->update(['org_ids'=>json_encode([$org])]);
 Db::name('system_role')->where('id',$role)->update(['rules'=>'']);
 r35reject(static function()use($svc,$org,$data,$admin){$svc->saveOrganization($org,$data,$admin,r35ctx());},'没有组织编辑权限');
 Db::name('system_role')->where('id',$role)->update(['rules'=>(string)$menu,'status'=>0]);r35ok(!$access->status($admin)['can_edit_organization'],'停用角色不能编辑');
 Db::name('system_role')->where('id',$role)->update(['status'=>1]);
 Db::name('system_admin')->where('id',$id)->update(['status'=>0]);r35ok(!$access->status($admin)['can_edit_organization'],'停用账号拒绝编辑');
 Db::name('system_admin')->where('id',$id)->update(['status'=>1,'admin_type'=>3]);r35ok(!$access->status($admin)['can_edit_organization'],'代理账号不能冒充平台编辑身份');
 r35ok(!$access->status(['id'=>0,'level'=>0])['can_edit_organization'],'无效身份不能用前端超管字段放行');
 $super=Db::name('system_admin')->where('level',0)->where('admin_type','<>',3)->where('status',1)->where('is_del',0)->find();r35ok($super&&$access->status($super)['editable_organization_ids']===null,'总部超管原能力保留');
 $flag=Db::name('system_config')->where('menu_name',OrganizationWorkspaceWriteGate::CFG_WRITE_ENABLED)->where('is_store',0);$flag->update(['value'=>'0']);r35ok(!$access->status($super)['can_edit_organization'],'关闭组织写开关仍拒绝编辑');
}finally{Db::rollback();}
r35ok(!Db::name('system_admin')->where('account',$tag)->count()&&!Db::name('organization')->whereLike('name',$tag.'%')->count(),'所有合成身份组织与保存结果回滚');
