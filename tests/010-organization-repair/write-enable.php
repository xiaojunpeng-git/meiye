<?php
/** Isolated Docker fixture; no customer data, local shared tables or Redis keys are modified. */
require '/var/www/html/vendor/autoload.php';
use think\facade\Db;
$app=new think\App('/var/www/html/');$app->initialize();
set_exception_handler(function($e){fwrite(STDERR,get_class($e).': '.$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);});
$c=config('database.connections.mysql');
if(!is_file('/.dockerenv')||!in_array($c['hostname'],['mysql','mohe-mysql'],true))throw new RuntimeException('local only');
$admin=new PDO('mysql:host='.$c['hostname'].';port='.$c['hostport'].';dbname='.$c['database'],$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$schema='test_010_org_'.bin2hex(random_bytes(5));$prefix=$schema.':';
$admin->exec('CREATE DATABASE `'.$schema.'`');
try {
 foreach($admin->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as $t)$admin->exec('CREATE TABLE `'.$schema.'`.`'.$t[0].'` LIKE `'.$c['database'].'`.`'.$t[0].'`');
 $dbc=config('database');$dbc['connections']['mysql']['database']=$schema;$dbc['connections']['mysql']['fields_cache']=false;
 $app->config->set($dbc,'database');
 $cache=config('cache');$cache['stores']['redis']['prefix']=$prefix;$cache['stores']['redis']['tag_prefix']=$prefix.'tag:';$app->config->set($cache,'cache');$app->instance('cache',new \think\Cache($app));
 putenv('ORG010_SYNTHETIC_TEST=1');
 $insert=function($table,$values)use($admin,$schema){$row=[];foreach($admin->query('SHOW COLUMNS FROM `'.$schema.'`.`eb_'.$table.'`')->fetchAll(PDO::FETCH_ASSOC) as $col){$k=$col['Field'];if(array_key_exists($k,$values)){$row[$k]=$values[$k];continue;}if($col['Extra']==='auto_increment'||$col['Default']!==null||$col['Null']==='YES')continue;$row[$k]=preg_match('/int|decimal|float|double/',$col['Type'])?0:'';}return Db::name($table)->insertGetId(array_merge($row,$values));};
 foreach([[19,0,'厦门'],[22,19,'事业一部'],[23,19,'事业二部']] as $r)$insert('system_region_manage',['id'=>$r[0],'pid'=>$r[1],'name'=>$r[2],'is_del'=>0]);
 foreach([[2,19],[18,23],[33,22]] as $r)$insert('system_region_agent',['id'=>$r[0],'name'=>'synthetic-agent-'.$r[0],'manage_region_id'=>$r[1],'is_del'=>0,'is_show'=>1]);
 foreach([1=>33,2=>18,3=>18,4=>33,6=>18,7=>33,8=>33,9=>18,10=>18,11=>33,31=>2] as $id=>$region)$insert('system_store',['id'=>$id,'region_id'=>$region,'name'=>$id===31?'总部':'synthetic-store-'.$id,'is_del'=>0,'is_show'=>1]);
 $insert('position',['id'=>1,'name'=>'店长','status'=>1,'use_store'=>1]);
 foreach([12,13,14,15,16,17,20,106,141,238,246,267] as $id){
 $insert('employee',['id'=>$id,'name'=>'synthetic-'.$id,'phone'=>sprintf('138%08d',$id),'status'=>1,'is_del'=>0,'employment_type_code'=>'internal','employment_type_version'=>1,'auth_version'=>1]);
 $insert('system_store_staff',['id'=>$id,'employee_id'=>$id,'store_id'=>31,'staff_name'=>'synthetic-'.$id,'phone'=>sprintf('138%08d',$id),'status'=>1,'is_del'=>0]);}
 $insert('staff_job_position',['id'=>774,'employee_id'=>106,'staff_id'=>106,'store_id'=>31,'position_id'=>1,'status'=>1,'is_del'=>0,'request_token'=>'fixture-job']);
 $insert('store_order',['id'=>1,'store_id'=>31,'order_id'=>'synthetic-hq-history','pay_price'=>'123.45']);
 require '/var/www/html/database/upgrades/2026-10-23-渼约010测试导入/08-组织与总部承接.php';
 $result=migrate010Organization();echo json_encode($result,JSON_UNESCAPED_UNICODE)."\n";
 $assert=function($ok,$name){if(!$ok)throw new RuntimeException($name);echo 'PASS '.$name."\n";};
 $assert((int)Db::name('organization')->count()===4,'four_org_nodes');
 $assert((int)Db::name('organization_store')->count()===10,'ten_store_bindings');
 $assert((int)Db::name('system_store')->where('is_del',0)->count()===10,'only_ten_current_stores');
 $assert((int)Db::name('organization_employee')->count()===12,'twelve_hq_members');
 $assert((int)Db::name('system_store_staff')->where('store_id',31)->where('status',1)->count()===0,'no_active_hq_store_tenure');
 $assert((int)Db::name('employee')->where('status',1)->count()===12,'employee_active_status_preserved');
 foreach([12,106] as $eid){$detail=app()->make(\app\services\employee\EmployeePersonCompleteWriteServices::class)->getComplete($eid,0,'hq',true);$assert($detail['store_id']===0&&$detail['org_name']==='总部'&&$detail['phone']!=='','hq_complete_detail_'.$eid);}
 $tree=app()->make(\app\services\organization\OrganizationResourceSelectorServices::class)->search(['resource'=>'org_store_tree'],['id'=>1,'level'=>0,'admin_type'=>0]);
 // Verify the actual picker contract: HQ is selectable with a positive organization ID.
 $flat=[];$walk=function($nodes)use(&$walk,&$flat){foreach($nodes as $n){$flat[]=$n;$walk($n['children']??[]);}};$walk($tree['tree']);
 $hqNodes=array_values(array_filter($flat,fn($n)=>$n['name']==='总部'));
 $assert(count($hqNodes)===1&&$hqNodes[0]['node_type']==='org'&&$hqNodes[0]['org_id']>0&&!$hqNodes[0]['disabled'],'hq_picker_valid_org');
 $assert(count(array_filter($flat,fn($n)=>$n['node_type']==='store'))===10,'picker_ten_stores');
 foreach([22=>[1,4,7,8,11],23=>[2,3,6,9,10]] as $region=>$ids){$org=Db::name('organization')->where('legacy_manage_region_id',$region)->value('id');$actual=array_map('intval',Db::name('organization_store')->where('org_id',$org)->order('store_id')->column('store_id'));$assert($actual===$ids,'store_mapping_'.$region);}
 $assert(Db::name('store_order')->where('id',1)->value('pay_price')==='123.45'&&(int)Db::name('store_order')->where('id',1)->value('store_id')===31,'historical_order_unchanged');
 $historical=\app\model\order\StoreOrder::with(['storeInfo'])->find(1)->toArray();$assert(($historical['storeInfo']['name']??$historical['store_info']['name']??'')==='总部','historical_store_relation_readable');
 $assert((int)Db::name('staff_job_position')->where('employee_id',106)->where('status',1)->where('store_id',0)->count()===1,'hq_job_retained_without_store');
 $assert((int)Db::name('employee_data_scope')->count()===0,'no_extra_store_authority');

 $insert('system_admin',['id'=>1,'account'=>'synthetic-admin','status'=>1,'is_del'=>0,'level'=>0,'admin_type'=>0]);
 $adminInfo=['id'=>1,'level'=>0,'admin_type'=>0,'real_name'=>'synthetic-admin'];
 $gate=app()->make(\app\services\organization\OrganizationWorkspaceWriteGate::class);
 $assert(!$gate->evaluateWriteGate()['ok'],'write_disabled_reproduced');
 require '/var/www/html/database/upgrades/2026-10-23-渼约010测试导入/10-组织正式写入启用.php';
 putenv('ORG010_WRITE_TEST=1');enable010OrganizationWrites();
 $assert($gate->evaluateWriteGate()['ok'],'formal_write_gate_enabled');
 $assert($gate->readConfigFlag('organization_workspace_write_allow_legacy')['value'] === 0,'legacy_write_stays_disabled');
 $svc=app()->make(\app\services\employee\EmployeePersonCompleteWriteServices::class);
 $detail=$svc->getComplete(106,0,'hq',true);
 $input=['employee_id'=>106,'staff_id'=>0,'org_id'=>$result['hq_org_id'],'store_id'=>0,'staff_name'=>$detail['staff_name'],'phone'=>$detail['phone'],'avatar'=>$detail['avatar'],'employment_type_code'=>'partner','employment_type_version'=>$detail['employment_type_version']];
 $svc->saveComplete($input,$adminInfo,['body_token'=>'01000000-0000-4000-8000-000000000001'],'hq');
 $fresh=$svc->getComplete(106,0,'hq',true);
 $assert($fresh['employment_type_code']==='partner','save_and_read_partner');
 $input['employment_type_code']='internal';$input['employment_type_version']=$fresh['employment_type_version'];
 $svc->saveComplete($input,$adminInfo,['body_token'=>'01000000-0000-4000-8000-000000000002'],'hq');
 $assert($svc->getComplete(106,0,'hq',true)['employment_type_code']==='internal','save_and_read_internal');
 $denied=false;try{$svc->saveComplete($input,['id'=>2,'level'=>1,'admin_type'=>0],['body_token'=>'01000000-0000-4000-8000-000000000003'],'hq');}catch(Throwable $e){$denied=true;}$assert($denied,'unauthorized_still_denied');
}finally{$admin->exec('DROP DATABASE `'.$schema.'`');}
