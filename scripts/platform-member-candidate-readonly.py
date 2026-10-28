"""Load fixed classes in a disposable read-only CLI process on named instances; no deployment or data writes."""
import json,base64,subprocess,pathlib,re,concurrent.futures
root=pathlib.Path(__file__).resolve().parents[1];R=root/'tests/platform-member-login/release/unified';tree=json.load(open(R/'trees.json'))['candidateTree'];fixed=json.load(open(root/'tests/platform-member-login/evidence/fixed.json'));prior=json.load(open(root/'tests/member-phone-reset/evidence/fixed.json'))
def git(f):return subprocess.check_output(['git','show',tree+':'+f],cwd=root)
classes={}
dependencies={'后端代码/app/services/cashier/v3/card/CashierV3HistoricalEntitlementReadServices.php','后端代码/app/services/cashier/v3/card/CashierV3HistoricalEntitlementScopePolicy.php'}
for f in set(fixed['files'])|set(prior['files'])|dependencies:
 if not f.endswith('.php') or not f.startswith('后端代码/'):continue
 data=git(f);s=data.decode();ns=re.search(r'namespace\s+([^;]+);',s);cl=re.search(r'^(?:abstract |final )?class\s+(\w+)',s,re.M)
 if ns and cl:classes[ns[1]+'\\'+cl[1]]=base64.b64encode(data).decode()
header="<?php\n$classes=json_decode(base64_decode('"+base64.b64encode(json.dumps(classes).encode()).decode()+"'),true);\n$queryConfig='"+base64.b64encode(git('后端代码/config/unified_query.php')).decode()+"';\n"
code=header+r'''
$sites=['rh'=>['rh.cc3798.com','ruihao',3306],'008'=>['008.cc3798.com','mohe_008',3307],'010'=>['010.cc3798.com','mohe_010_app',3307]];
$s=$argv[1]??'';if(!isset($sites[$s]))throw new RuntimeException('Named instance required');[$domain,$db,$port]=$sites[$s];$root='/www/wwwroot/'.$domain;chdir($root);require $root.'/vendor/autoload.php';
spl_autoload_register(function($class)use($classes){if(isset($classes[$class]))eval(substr(base64_decode($classes[$class]),5));},true,true);
(new think\App($root.'/'))->initialize();set_exception_handler(function($e){file_put_contents('php://stderr',get_class($e).': '.$e->getMessage()."\n");exit(1);});
$c=config('database.connections.mysql');if($c['database']!==$db||(int)$c['hostport']!==$port)throw new RuntimeException('Wrong instance');
config(eval(substr(base64_decode($queryConfig),5)),'unified_query');
think\facade\Db::execute('SET SESSION TRANSACTION READ ONLY');think\facade\Db::startTrans();
try{
 $admin=(int)think\facade\Db::name('system_admin')->where('status',1)->where('is_del',0)->where('admin_type',0)->where('level',0)->value('id');
 $service=new app\services\user\PlatformMemberCenterServices();$boot=$service->bootstrap($admin);
 $cap=$service->action($admin,'query-unified-query-capabilities',['pageCode'=>'platform_member_list']);
 $page=$service->action($admin,'query-members',['pageCode'=>'platform_member_list','page'=>1,'limit'=>20]);
 $fields=array_column($cap['data']['unifiedQueryCapability']['fields'],'key');if(!in_array('wechat_binding',$fields,true)||count($page['data']['records'])>20)throw new RuntimeException('Member contract failed');
 $empty=$service->action($admin,'query-members',['pageCode'=>'platform_member_list','keyword'=>'PML_READONLY_NO_SUCH_MEMBER_7281','page'=>1,'limit'=>20]);
 if($empty['data']['total']!==0||$empty['data']['records']!==[])throw new RuntimeException('Empty member contract failed');
 $forbidden=false;try{$service->action($admin,'submit-recharge-checkout',[]);}catch(think\exception\ValidateException $e){$forbidden=true;}if(!$forbidden)throw new RuntimeException('Financial command boundary failed');
 echo json_encode(['result'=>'PASS','site'=>$s,'readOnly'=>true,'businessWrites'=>0,'allowedStores'=>count($boot['stores']),'fields'=>count($fields),'total'=>(int)$page['data']['total'],'pageRows'=>count($page['data']['records']),'emptyQuery'=>'PASS','unrelatedMoneyCommandDenied'=>true]),"\n";
}finally{think\facade\Db::rollback();}
'''
def run(s):
 host='rh-server' if s=='rh' else 'mohe-server';r=subprocess.run(['ssh','-q','-o','BatchMode=yes','-o','ConnectTimeout=15',host,'/www/server/php/74/bin/php -- '+s],input=code.encode(),capture_output=True,timeout=150)
 if r.returncode:raise RuntimeError(s+' '+r.stderr.decode()[-1600:])
 v=json.loads(r.stdout);assert v['result']=='PASS';(R/(s+'-candidate-readonly.json')).write_text(json.dumps(v,indent=2)+'\n');return v
if __name__=='__main__':
 for v in concurrent.futures.ThreadPoolExecutor(max_workers=2).map(run,['rh','008','010']):print(json.dumps(v),flush=True)
