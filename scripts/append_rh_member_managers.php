<?php
declare(strict_types=1);
/**
 * 瑞昊第二轮：开启有效店长的两项收银资格，并给既有会员追加本店店长。
 * 默认只读计划；原人员顺序和关系保留，旧单人关系仅作首位兼容投影。
 * 产品经理明确不做数据备份；本脚本仍保留业务变更记录和原子回滚。
 */
ini_set('memory_limit','1024M');
require getcwd().'/vendor/autoload.php';
(new \think\App())->initialize();
use think\facade\Db;
$options=getopt('',['apply','accepted-plan:','max-member-id:','local-test']);
$apply=isset($options['apply']);
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);});
function managerRequire(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function managerJson($value):string{return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
/** Append only missing staff IDs; existing names, staff identities and order stay authoritative. */
function appendManagerPeople(array $selected,?array $legacy,array $managers):array{
    $before=$selected?:($legacy&&(int)$legacy['status']===1?[$legacy]:[]);
    $after=$before;$seen=array_fill_keys(array_map('intval',array_column($before,'staff_id')),true);
    foreach($managers as$person)if(!isset($seen[(int)$person['staff_id']])){$after[]=$person;$seen[(int)$person['staff_id']]=true;}
    return [$before,$after];
}
/** Use bounded multi-row INSERTs so the member lock is not held for one network round trip per row. */
function managerFlush(string $table,array &$rows):void{
    foreach(array_chunk($rows,300)as$chunk)Db::name($table)->insertAll($chunk);
    $rows=[];
}
$c=config('database.connections.mysql');
if(isset($options['local-test'])){
    managerRequire(getcwd()==='/var/www/html'&&in_array($c['hostname'],['mysql','mohe-mysql'],true),'LOCAL_TEST_ONLY');
    $config=config('database');$config['connections']['mysql']['database']='codex_rh_r2_manager_test';
    \think\facade\Config::set($config,'database');
}else managerRequire(realpath(getcwd())==='/www/wwwroot/rh.cc3798.com'&&$c['database']==='ruihao','TARGET_NOT_RH');
Db::execute($apply?'START TRANSACTION':'START TRANSACTION READ ONLY');
try{
    $now=time();
    $sql="SELECT DISTINCT j.store_id,st.name store_name,s.id staff_id,s.employee_id,s.staff_name,s.cashier_craftsman_enabled,s.cashier_salesperson_enabled
        FROM eb_staff_job_position j JOIN eb_position p ON p.id=j.position_id
        JOIN eb_system_store_staff s ON s.id=j.staff_id AND s.store_id=j.store_id AND s.employee_id=j.employee_id
        JOIN eb_employee e ON e.id=s.employee_id JOIN eb_system_store st ON st.id=j.store_id
        WHERE p.name='店长' AND p.status=1 AND j.status=1 AND j.is_del=0 AND s.status=1 AND s.is_del=0
        AND e.status=1 AND e.is_del=0 AND st.is_del=0 AND j.start_time<={$now}
        AND (j.end_time=0 OR j.end_time>{$now}) ORDER BY j.store_id,s.id";
    $managers=Db::query($sql.($apply?' FOR UPDATE':''));
    managerRequire(count($managers)>0,'NO_MANAGERS');
    $byStore=[];foreach($managers as$m)$byStore[(int)$m['store_id']][]=$m;
    $max=(int)($options['max-member-id']??Db::name('user')->max('uid'));
    $q=Db::name('user')->where('is_del',0)->where('uid','<=',$max)->whereIn('belong_store_id',array_keys($byStore))->field('uid,belong_store_id')->order('uid');
    if($apply)$q->lock(true);
    $members=$q->select()->toArray();
    $all=[];$selected=[];$legacy=[];
    foreach(['member_exclusive_service_selection','member_exclusive_service']as$table){
        $q=Db::name($table)->alias('r')->join('user u','u.uid=r.member_id')->where('u.is_del',0)->where('u.uid','<=',$max)
            ->whereIn('u.belong_store_id',array_keys($byStore))->field('r.*')->order($table==='member_exclusive_service_selection'?'r.sort_order,r.id':'r.id');
        if($apply)$q->lock(true);
        foreach($q->select()->toArray()as$r){
            if($table==='member_exclusive_service')$legacy[(int)$r['member_id']]=$r;
            else{$all[(int)$r['member_id']][(int)$r['staff_id']]=$r;if((int)$r['status']===1)$selected[(int)$r['member_id']][]=$r;}
        }
    }
    $hash=hash_init('sha256');hash_update($hash,managerJson([$max,$managers]));
    $counts=['manager_count'=>count($managers),'store_count'=>count($byStore),'member_count'=>count($members),
        'members_changed'=>0,'manager_relations_added'=>0,'legacy_rows_materialized'=>0,'primary_rows_changed'=>0,
        'craftsman_flags_enabled'=>0,'salesperson_flags_enabled'=>0];$stores=[];
    foreach($managers as$m){$counts['craftsman_flags_enabled']+=(int)$m['cashier_craftsman_enabled']===0?1:0;$counts['salesperson_flags_enabled']+=(int)$m['cashier_salesperson_enabled']===0?1:0;}
    foreach($members as$member){
        $uid=(int)$member['uid'];$store=(int)$member['belong_store_id'];$old=$selected[$uid]??[];$single=$legacy[$uid]??null;
        [$before,$after]=appendManagerPeople($old,$single,$byStore[$store]);
        $beforeIds=array_map('intval',array_column($before,'staff_id'));$afterIds=array_map('intval',array_column($after,'staff_id'));
        managerRequire(count($beforeIds)===count(array_unique($beforeIds)),'EXISTING_DUPLICATES_'.$uid);
        if($old&&$single&&(int)$single['status']===1)managerRequire(in_array((int)$single['staff_id'],$beforeIds,true),'LEGACY_SELECTION_CONFLICT_'.$uid);
        hash_update($hash,managerJson([$member,$all[$uid]??[],$single,$afterIds])."\n");
        if($beforeIds===$afterIds)continue;
        $counts['members_changed']++;$counts['manager_relations_added']+=count($after)-count($before);
        if(!$old&&$before)$counts['legacy_rows_materialized']+=count($before);
        if(!$single||(int)$single['status']!==1)$counts['primary_rows_changed']++;
        $stores[$store]=($stores[$store]??0)+1;
    }
    $fingerprint=hash_final($hash);
    $result=['target'=>isset($options['local-test'])?'local/codex_rh_r2_manager_test':'rh.cc3798.com/ruihao','mode'=>$apply?'apply':'plan','max_member_id'=>$max,'counts'=>$counts,
        'changed_members_by_store'=>$stores,'plan_sha256'=>$fingerprint,'backup'=>'none_per_user_instruction'];
    if(!$apply){Db::execute('ROLLBACK');echo managerJson($result),PHP_EOL;exit;}
    managerRequire(hash_equals((string)($options['accepted-plan']??''),$fingerprint),'FIXED_PLAN_CHANGED');
    $batch='r2_manager_append_20261106';
    $storeNames=Db::name('system_store')->column('name','id');
    $insert=[];$selectionAudit=[];$primaryInsert=[];$primaryAudit=[];
    foreach($managers as$m){
        if((int)$m['cashier_craftsman_enabled']===1&&(int)$m['cashier_salesperson_enabled']===1)continue;
        $flags=['cashier_craftsman_enabled'=>1,'cashier_salesperson_enabled'=>1];
        Db::name('system_store_staff')->where('id',$m['staff_id'])->update($flags);
        Db::name('employee_change_log')->insert(['employee_id'=>$m['employee_id'],'action'=>'manager_qualification_append',
            'target_type'=>'staff','target_id'=>$m['staff_id'],'source'=>'admin',
            'before_data'=>managerJson(array_intersect_key($m,$flags)),'after_data'=>managerJson($flags),
            'reason'=>'产品经理授权：全部店长开启销售人和手艺人资格','operator_type'=>'system','operator_id'=>0,
            'operator_name'=>'产品经理授权数据优化','request_id'=>$batch.':'.$m['staff_id'],'add_time'=>$now]);
    }
    foreach($members as$member){
        $uid=(int)$member['uid'];$store=(int)$member['belong_store_id'];$old=$selected[$uid]??[];$single=$legacy[$uid]??null;
        [$before,$after]=appendManagerPeople($old,$single,$byStore[$store]);
        $beforeIds=array_map('intval',array_column($before,'staff_id'));$afterIds=array_map('intval',array_column($after,'staff_id'));
        if($beforeIds===$afterIds)continue;
        $nextSort=$old?max(array_map('intval',array_column($old,'sort_order'))):0;
        foreach($after as$i=>$p){
            $staff=(int)$p['staff_id'];$existing=$all[$uid][$staff]??null;
            if($existing&&(int)$existing['status']===1)continue;
            $values=['employee_id'=>(int)$p['employee_id'],'store_id'=>(int)$p['store_id'],'staff_name'=>$p['staff_name'],
                'sort_order'=>++$nextSort,'status'=>1,'updated_at'=>$now];
            if($existing)managerRequire(Db::name('member_exclusive_service_selection')->where('id',$existing['id'])->where('version',$existing['version'])
                ->update($values+['version'=>(int)$existing['version']+1])===1,'SELECTION_CONFLICT');
            else $insert[]=$values+['member_id'=>$uid,'staff_id'=>$staff,'version'=>1,'created_at'=>$now];
        }
        $selectionAudit[]=['change_key'=>'exclusive_selection:'.$uid.':'.$batch,'member_id'=>$uid,
            'previous_json'=>managerJson($beforeIds),'current_json'=>managerJson($afterIds),'operator_id'=>0,'occurred_at'=>$now];
        if(!$single||(int)$single['status']!==1){
            $p=$after[0];$key='exclusive_service:'.$uid.':'.$batch;
            $values=['staff_id'=>(int)$p['staff_id'],'employee_id'=>(int)$p['employee_id'],'store_id'=>(int)$p['store_id'],
                'staff_name'=>$p['staff_name'],'store_name'=>$storeNames[(int)$p['store_id']]??'',
                'source_type'=>'data_optimization','source_business_type'=>'member','source_business_id'=>(string)$uid,
                'reason'=>'第二轮数据优化：保留原人员，追加本店店长','status'=>1,'bound_at'=>$now,
                'operator_id'=>0,'operator_name'=>'产品经理授权数据优化','idempotency_key'=>$batch,'updated_at'=>$now];
            if($single)managerRequire(Db::name('member_exclusive_service')->where('id',$single['id'])->where('version',$single['version'])
                ->update($values+['version'=>(int)$single['version']+1])===1,'PRIMARY_CONFLICT');
            else $primaryInsert[]=$values+['member_id'=>$uid,'version'=>1,'created_at'=>$now];
            $audit=['change_key'=>$key,'member_id'=>$uid,'source_type'=>$values['source_type'],'source_business_type'=>'member',
                'source_business_id'=>(string)$uid,'reason'=>$values['reason'],'operator_id'=>0,'operator_name'=>$values['operator_name'],
                'idempotency_key'=>$batch,'occurred_at'=>$now,'recorded_at'=>$now,'created_at'=>$now];
            foreach(['staff_id','employee_id','store_id','staff_name','store_name']as$field){
                $audit['previous_'.$field]=$single[$field]??(substr($field,-3)==='_id'?0:'');
                $audit['current_'.$field]=$values[$field];
            }
            $primaryAudit[]=$audit;
        }
        if(count($selectionAudit)>=300){
            managerFlush('member_exclusive_service_selection',$insert);managerFlush('member_exclusive_service_selection_change',$selectionAudit);
            managerFlush('member_exclusive_service',$primaryInsert);managerFlush('member_exclusive_service_change',$primaryAudit);
        }
    }
    managerFlush('member_exclusive_service_selection',$insert);managerFlush('member_exclusive_service_selection_change',$selectionAudit);
    managerFlush('member_exclusive_service',$primaryInsert);managerFlush('member_exclusive_service_change',$primaryAudit);
    // Verify completeness through the same stored selection used after refresh;
    // original active rows were never updated or removed by this append-only path.
    $missing=0;
    foreach($managers as$m)$missing+=(int)Db::name('user')->alias('u')->where('u.is_del',0)->where('u.uid','<=',$max)->where('u.belong_store_id',$m['store_id'])
        ->whereNotExists(function($q)use($m){$q->name('member_exclusive_service_selection')->whereRaw('member_id=u.uid')->where('staff_id',$m['staff_id'])->where('status',1);})->count();
    managerRequire($missing===0,'POST_APPLY_MISSING_MANAGERS');
    $result['missing_after']=$missing;
    Db::execute('COMMIT');echo managerJson($result),PHP_EOL;
}catch(Throwable$e){Db::execute('ROLLBACK');fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
