<?php
declare(strict_types=1);
/** Real ORM/MySQL search regressions: fabricated group facts roll back, no payments or remote traffic. */
error_reporting(E_ALL & ~E_DEPRECATED);
$root=dirname(__DIR__,2);require $root.'/后端代码/vendor/autoload.php';
use think\facade\Db;
use app\services\activity\combination\CombinationGroupListServices;
$c=array_change_key_case(parse_ini_file($root.'/后端代码/.env',true,INI_SCANNER_RAW)['DATABASE']);
if($c['hostname']!=='mysql'||$c['database']!=='ruihao')throw new RuntimeException('Only local Docker ruihao');
$db=new think\DbManager();$db->setConfig(['default'=>'mysql','connections'=>['mysql'=>[
    'type'=>'mysql','hostname'=>'127.0.0.1','hostport'=>3307,'database'=>'ruihao','username'=>$c['username'],
    'password'=>$c['password'],'prefix'=>'eb_','charset'=>'utf8mb4','debug'=>false,'fields_cache'=>false]]]);
think\Container::getInstance()->instance('think\DbManager',$db);
// Inject only resolved grants; exercise the production query/page/export, never substitute search SQL.
$reader=new class extends CombinationGroupListServices {
    public function allowedStores(array $admin): ?array {return $admin['stores'];}
};
$checks=0;
function verify($ok,string $label):void {global $checks;if(!$ok)throw new RuntimeException('FAIL '.$label);$checks++;echo 'PASS '.$label.PHP_EOL;}
function seedSearch(string $table,array $data):int {
    foreach(Db::query('SHOW COLUMNS FROM eb_'.$table)as$f){$key=$f['Field'];if(array_key_exists($key,$data)||$f['Default']!==null||$f['Null']==='YES'||strpos($f['Extra'],'auto_increment')!==false)continue;
        $data[$key]=preg_match('/int|decimal|float|double/',$f['Type'])?0:'';}
    return (int)Db::name($table)->insertGetId($data);
}
function person(int $store,int $cid,string $name,string $phone,int $leader=0,bool $virtual=false):array {
    global $tag;
    $uid=seedSearch('user',['nickname'=>$name.'昵称','real_name'=>$name,'phone'=>$phone,'user_type'=>'import']);
    $oid=seedSearch('store_order',['uid'=>$uid,'store_id'=>$store,'order_id'=>$tag.'-'.bin2hex(random_bytes(4)),'unique'=>bin2hex(random_bytes(12)),
        'real_name'=>$name.'订单','user_phone'=>$phone,'paid'=>1,'refund_status'=>0,'pay_price'=>100]);
    $id=seedSearch('store_pink',['uid'=>$uid,'nickname'=>$name.'参团','k_id'=>$leader,'cid'=>$cid,'order_id_key'=>$oid,
        'people'=>3,'price'=>100,'status'=>1,'add_time'=>time(),'stop_time'=>time()+3600,'is_virtual'=>(int)$virtual]);
    if(!$leader)seedSearch('combination_group_origin',['group_id'=>$id,'expires_at'=>time()+3600,'activity_name'=>$tag]);
    return compact('id','uid','oid');
}
$tag='GROUPSEARCH'.bin2hex(random_bytes(5));$admin=['stores'=>null];
$before=(int)Db::name('store_pink')->count();Db::startTrans();
try {
    $store=seedSearch('system_store',['name'=>$tag,'is_del'=>0]);$other=seedSearch('system_store',['name'=>$tag.'OTHER','is_del'=>0]);
    $cid=seedSearch('store_combination',['title'=>$tag]);$filters=['cid'=>$cid];
    $lead=person($store,$cid,$tag.'团长','19900001001');$member=person($store,$cid,$tag.'团员','19900001002',$lead['id']);
    person($store,$cid,$tag.'团员','19900001003',$lead['id']);
    $outside=person($other,$cid,$tag.'外店团长','19900001004');person($other,$cid,$tag.'团员','19900001005',$outside['id']);
    $virtualGroup=person($store,$cid,$tag.'虚拟测试团长','19900001006');person($store,$cid,$tag.'虚拟专用','19900001007',$virtualGroup['id'],true);
    verify($reader->page($filters,$admin)['count']===3,'empty keyword preserves all original groups');
    verify($reader->page($filters+['keyword'=>$tag.'团员','status'=>2],$admin)['count']===0,'member search retains group-status narrowing');
    foreach(['团长','团员']as$role){$result=$reader->page($filters+['keyword'=>$tag.$role],$admin);
        verify(in_array($lead['id'],array_column($result['list'],'id')),$role.' name finds original group');}
    foreach(['19900001001','19900001002','001002']as$key){$result=$reader->page($filters+['keyword'=>$key],$admin);
        verify($result['count']===1&&(int)$result['list'][0]['id']===$lead['id'],'leader/member full or partial phone '.$key);}
    $result=$reader->page($filters+['keyword'=>$tag.'团员'],$admin);verify($result['count']===2&&count($result['list'])===2,'multiple member matches never duplicate groups');
    $result=$reader->page($filters+['keyword'=>$tag.'虚拟专用'],$admin);verify($result['count']===0,'virtual participant name excluded');
    verify($reader->page($filters+['keyword'=>'19900001007'],$admin)['count']===0,'virtual participant phone excluded');
    Db::name('user')->where('uid',$member['uid'])->update(['real_name'=>$tag.'新姓名','nickname'=>$tag.'独立昵称','phone'=>'19900001999']);
    Db::name('store_order')->where('id',$member['oid'])->update(['real_name'=>$tag.'原单姓名','user_phone'=>'19900001888']);
    Db::name('store_pink')->where('id',$member['id'])->update(['nickname'=>$tag.'独立参团快照']);
    foreach([$tag.'新姓名',$tag.'独立昵称',$tag.'原单姓名','19900001999','19900001888',$tag.'独立参团快照']as$key)
        verify($reader->page($filters+['keyword'=>$key],$admin)['count']===1,'member current/archive/snapshot search '.substr($key,-12));
    Db::name('store_pink')->where('id',$member['id'])->update(['is_refund'=>1]);
    verify($reader->page($filters+['keyword'=>$tag.'新姓名'],$admin)['count']===1,'historical refunded real member remains searchable');
    $scoped=['stores'=>[$store]];$matches=$reader->page($filters+['keyword'=>$tag.'团员'],$scoped);
    verify($matches['count']===1&&(int)$matches['list'][0]['id']===$lead['id'],'member OR cannot escape outer store grants');
    verify($reader->page($filters+['keyword'=>$tag.'团员','store_ids'=>[$other]],$scoped)['count']===0,'selected unauthorized store cannot broaden grants');
    verify($reader->page($filters+['keyword'=>$tag.'团员'],['stores'=>[]])['count']===0,'deny-all remains deny-all');
    $literal='S'.substr($tag,-6).'%_反斜线\\';Db::name('user')->where('uid',$member['uid'])->update(['real_name'=>$literal]);
    verify($reader->page($filters+['keyword'=>$literal],$scoped)['count']===1,'percent underscore backslash treated as literal text');
    verify($reader->page($filters+['keyword'=>"' OR 1=1 --"],$admin)['count']===0,'SQL-like text stays bound search input');
    verify($reader->page($filters+['keyword'=>$tag.'不存在'],$admin)['count']===0,'no-match is an explicit empty result');
    $pageKeyword=$tag.'分页成员';$expected=[];
    for($i=0;$i<25;$i++){$rootGroup=person($store,$cid,$tag.'分页团长'.$i,'19800000000');$expected[]=$rootGroup['id'];
        person($store,$cid,$pageKeyword,'19800000001',$rootGroup['id']);person($store,$cid,$pageKeyword,'19800000002',$rootGroup['id']);}
    $f=$filters+['keyword'=>$pageKeyword];$one=$reader->page($f,$scoped);$two=$reader->page($f+['page'=>2],$scoped);
    verify($one['count']===25&&count($one['list'])===20,'member-only page one has 20 of 25 groups');
    verify($two['count']===25&&count($two['list'])===5,'member-only page two has remaining five groups');
    verify(!array_intersect(array_column($one['list'],'id'),array_column($two['list'],'id')),'member matches retain stable disjoint pagination');
    $export=$reader->page($f,$scoped,true);verify(count($export['export'])===25,'export includes all 25 unique matching groups');
    verify($reader->page($f,['stores'=>[$other]],true)['export']===[],'export shares exact store scope');
    verify($reader->page($f+['page'=>999],$scoped)['page']===2,'out-of-range page resolves to last matching page');
    $m=new ReflectionMethod($reader,'query');$m->setAccessible(true);$sql=$m->invoke($reader,$f,[$store])->field('g.id')->fetchSql()->select();
    $plan=Db::query('EXPLAIN '.$sql);verify(count($plan)>0,'real member lookup has an executable MySQL plan');
    verify(!array_filter($plan,static function($row){return $row['select_type']==='DEPENDENT SUBQUERY'&&$row['table']==='search_member'&&$row['type']==='ALL';}),'member lookup avoids a full member scan for every group');
    file_put_contents(__DIR__.'/evidence/query-plan.json',json_encode(['sql'=>$sql,'plan'=>$plan],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
}finally{Db::rollback();}
verify((int)Db::name('store_pink')->count()===$before,'all fabricated group facts rolled back');
echo 'COMBINATION_MEMBER_SEARCH_PASS '.$checks.' checks'.PHP_EOL;
