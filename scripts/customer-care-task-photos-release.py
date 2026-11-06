"""R54: immutable Git builds and guarded per-instance task-photo release.

Preparation is read-only on production. Migration and installation require their
separate recorded approvals; no business rows are created or changed remotely.
"""
import base64, concurrent.futures, hashlib, io, json, os, pathlib, shlex, subprocess, sys, tarfile, tempfile, urllib.request
R=pathlib.Path(__file__).resolve().parents[1]
E=R.parent/'任务管理/第54轮-客情任务多图-20261107'
O=E/'release'
NODE='/Applications/HBuilderX.app/Contents/HBuilderX/plugins/node/node'
PLUGINS='/Applications/HBuilderX.app/Contents/HBuilderX/plugins'
S={'rh':('rh-server','ruihao','/etc/init.d/ruihao_swoole restart'),'008':('mohe-server','mohe_008','systemctl restart mohe-008-swoole.service'),'010':('mohe-server','mohe_010_app','systemctl restart mohe-010-swoole.service')}
MIG='database/upgrades/20261107_customer_care_task_photos.php'
KEY='20261107-r54-customer-care-task-photos'
def sha(b):return hashlib.sha256(b).hexdigest()
def load(n):return json.loads((O/n).read_text())
def save(n,v):(O/n).write_text(json.dumps(v,ensure_ascii=False,indent=2)+'\n')
def git(*a,data=None,env=None):return subprocess.check_output(['git',*a],cwd=R,input=data,env=env)
def remote(s,code,app=True,user='www'):
    p='<?php chdir('+json.dumps('/www/wwwroot/'+s+'.cc3798.com')+');'
    if app:p+="require 'vendor/autoload.php';(new think\\App())->initialize();if(config('database.connections.mysql.database')!=="+json.dumps(S[s][1])+")throw new Exception('WRONG_INSTANCE');"
    x=subprocess.run(['ssh',S[s][0],('sudo -u www php' if user=='www' else 'php')],input=p+code,text=True,capture_output=True,timeout=180)
    if x.returncode:raise RuntimeError(x.stdout+x.stderr)
    try:v=json.loads(x.stdout)
    except Exception:raise RuntimeError('INVALID_RESPONSE '+x.stdout[:1000])
    if 'error' in v:raise RuntimeError(v)
    return v

def freeze():
    c=json.loads((E/'candidate.json').read_text());b=json.loads((E/'baseline.json').read_text());head=git('rev-parse','HEAD').decode().strip();idx=sha((R/'.git/index').read_bytes())
    for p,v in b['files'].items():assert sha(git('show',head+':'+p))==v['sha256'],('BASE_MISMATCH',p)
    env=dict(os.environ,GIT_INDEX_FILE=tempfile.mktemp(prefix='r54-photo-index-'))
    try:
        git('read-tree',head,env=env)
        for p,h in c['files'].items():
            data=(R/p).read_bytes();assert sha(data)==h,('ACCEPTED_CHANGED',p)
            blob=git('hash-object','-w','--stdin',data=data).decode().strip();git('update-index','--add','--cacheinfo','100644',blob,p,env=env)
        # The release driver is reviewable engineering support, never an app asset.
        p='scripts/customer-care-task-photos-release.py';blob=git('hash-object','-w','--',p).decode().strip();git('update-index','--add','--cacheinfo','100644',blob,p,env=env)
        tree=git('write-tree',env=env).decode().strip()
    finally:pathlib.Path(env['GIT_INDEX_FILE']).unlink(missing_ok=True)
    assert sha((R/'.git/index').read_bytes())==idx
    out={'base':head,'tree':tree,'businessFiles':c['files'],'script':'scripts/customer-care-task-photos-release.py','inputs':{},'sharedIndex':idx,'acceptedPatch':c['patchSha256']}
    for kind,ref in [('baseline',head),('candidate',tree)]:
        dest=pathlib.Path(tempfile.mkdtemp(prefix='r54-photos-'+kind+'-'))
        paths=['前端代码/mobile-vue3','tests/mobile-vue3','tests/customer-care','后端代码/app/services/customer/care','后端代码/app/services/mobile/protocol']
        with tarfile.open(fileobj=io.BytesIO(git('archive',ref,*paths))) as a:a.extractall(dest)
        hashes={}
        for f in dest.rglob('*'):
            if f.is_file():hashes[f.relative_to(dest).as_posix()]=sha(f.read_bytes());f.chmod(0o444)
        out['inputs'][kind]=str(dest);save(kind+'-sources.json',hashes)
    save('candidate.json',out);print(json.dumps({'base':head,'tree':tree}))

def build(kind):
    c=load('candidate.json');source=pathlib.Path(c['inputs'][kind]);hashes=load(kind+'-sources.json');assert all(sha((source/p).read_bytes())==h for p,h in hashes.items())
    out=O/'build'/kind;env=dict(os.environ,NODE_ENV='production',UNI_INPUT_DIR=str(source/'前端代码/mobile-vue3'),UNI_OUTPUT_DIR=str(out),UNI_HBUILDERX_PLUGINS=PLUGINS,UNI_APP_X='true',UNI_UTS_PLATFORM='web')
    with (O/(kind+'-build.log')).open('w') as log:subprocess.run([NODE,PLUGINS+'/uniapp-cli-vite/node_modules/@dcloudio/vite-plugin-uni/bin/uni.js','build','-p','h5','--base','/view_mobile_v3/'],cwd=PLUGINS+'/uniapp-cli-vite',env=env,stdout=log,stderr=subprocess.STDOUT,check=True)
    assert all(sha((source/p).read_bytes())==h for p,h in hashes.items()),'BUILD_INPUT_DRIFT'
    assets={p.relative_to(out).as_posix():sha(p.read_bytes()) for p in out.rglob('*') if p.is_file()};save(kind+'-assets.json',assets);print(json.dumps({'kind':kind,'files':len(assets),'entryHash':assets['index.html']}))

def probe(s,paths):
    return remote(s,'$o=[];foreach('+json.dumps(sorted(paths))+" as $p)$o[$p]=is_file($p)?hash_file('sha256',$p):null;echo json_encode(['files'=>$o,'environment'=>hash_file('sha256','.env')]);",False,user='root')

def baseline(s):
    c=load('candidate.json');paths=[p.removeprefix('后端代码/') for p in c['businessFiles'] if p.startswith('后端代码/')]
    deps=git('ls-tree','-r','--name-only',c['base'],'后端代码/app/services/customer/care','后端代码/app/services/mobile/protocol','后端代码/app/services/mobile/merchant','后端代码/app/http/middleware/mobile').decode().splitlines()
    paths += [p.removeprefix('后端代码/') for p in deps if p.endswith('.php')]
    paths += ['route/api-mobile.php','app/controller/mobile/merchant/CustomerCare.php','app/services/system/attachment/SystemAttachmentServices.php','public/view_cashier_v3/index.html','public/view_inventory_v3/index.html','public/view_fund_v3/index.html','public/index.html','public/static/html/pc.html']
    assets=load('baseline-assets.json');paths+=['public/view_mobile_v3/'+p for p in assets]
    now=probe(s,paths);initial=load(s+'-initial.json');assert now['environment']==initial['environment']
    for p,h in initial['files'].items():
        if p in now['files']:assert now['files'][p]==h,('LIVE_CHANGED',s,p)
    for p,h in assets.items():assert now['files']['public/view_mobile_v3/'+p]==h,('MOBILE_BASELINE_MISMATCH',s,p)
    save(s+'-before.json',now);differences=[]
    for p in deps:
        if p.endswith('.php') and sha(git('show',c['base']+':'+p))!=now['files'][p.removeprefix('后端代码/')]:differences.append(p)
    save(s+'-dependency-differences.json',differences);print(json.dumps({'site':s,'resourcesMatched':len(assets),'dependencyDifferences':differences}))

def smoke(s,mode):
    c=load('candidate.json');code="think\\facade\\Db::execute('SET TRANSACTION READ ONLY');think\\facade\\Db::startTrans();try {"
    for name,path in [('R54Metadata','app/services/mobile/protocol/MobileRequestMetadata.php'),('R54Contract','app/services/customer/care/CustomerCareCommandContract.php'),('R54Query','app/services/customer/care/query/CustomerCareWorkbenchQueryService.php')]:
        if mode=='before':
            data=git('show',c['tree']+':后端代码/'+path);original=pathlib.PurePosixPath(path).stem;data=data.replace(('final class '+original).encode(),('final class '+name).encode(),1);code+='eval(substr(base64_decode('+json.dumps(base64.b64encode(data).decode())+'),5));'
    meta='R54Metadata' if mode=='before' else 'MobileRequestMetadata';query='R54Query' if mode=='before' else 'CustomerCareWorkbenchQueryService'
    code+=r'''
$request=new class {public function method(){return 'POST';}public function pathinfo(){return 'api/mobile/merchant/customer-care/service-photo';}public function get($k,$d=null){return $d;}public function post($k,$d=null){return $d;}public function header($k,$d=''){return ['Content-Type'=>'multipart/form-data; boundary=r54-test','Authorization'=>'Bearer aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','X-Mobile-Contract-Version'=>'mobile-merchant-v1','X-Mobile-Client-Session-Id'=>'r54-audit-session','X-Mobile-Platform'=>'WEB','X-Mobile-Request-Id'=>'r54-audit-request','X-Mobile-Active-Context-Id'=>'r54-active-context','X-Mobile-State-Context-Id'=>'r54-state-context'][$k]??$d;}};
'''
    code+='$meta=app\\services\\mobile\\protocol\\'+meta+'::merchant($request);'
    code+=r'''
$row=think\facade\Db::name('customer_care_task')->where('is_visible',1)->order('id','desc')->find();$checks=[];
if($row){$context=['tenantId'=>$row['tenant_id'],'staffId'=>(int)$row['owner_staff_id'],'employeeId'=>max(1,(int)$row['owner_employee_id']),'staffName'=>$row['owner_name_snapshot'],'operationStoreId'=>(int)$row['business_store_id'],'operationStoreName'=>$row['business_store_name_snapshot'],'operationOrganizationId'=>$row['organization_id'],'operationOrganizationPath'=>$row['organization_path'],'operationOrganizationName'=>$row['organization_name_snapshot'],'allowedBusinessStoreIds'=>[(int)$row['business_store_id']],'businessTimezone'=>'Asia/Shanghai','canViewAllTasks'=>true,'canCreateTask'=>false,'canCreateRecord'=>false,'canReassign'=>false,'canViewStatistics'=>false];
$clock=new class implements app\services\customer\care\CustomerCareClock {public function now():int{return time();}public function businessDate(int $t,string $z):string{return(new DateTimeImmutable('@'.$t))->setTimezone(new DateTimeZone($z))->format('Y-m-d');}};
$repo=new app\services\customer\care\query\ThinkPhpCustomerCareQueryRepository();$codec=new app\services\customer\care\query\CustomerCareCursorCodec(str_repeat('r54',16));
$old=(new app\services\customer\care\query\CustomerCareWorkbenchQueryService($repo,$clock,$codec))->taskDetail($context,(int)$row['id']);
'''
    code+='$actual=(new app\\services\\customer\\care\\query\\'+query+'($repo,$clock,$codec))->taskDetail($context,(int)$row[\'id\']);'
    code+=r'''
if(!$actual||$actual['note']!==(string)$row['note']||$actual['taskPhotos']!==(empty($row['task_photos'])?[]:json_decode($row['task_photos'],true)))throw new Exception('TASK_DETAIL_MISMATCH');
$strip=static function($v){unset($v['note'],$v['taskPhotos']);return $v;};if($strip($actual)!==$strip($old))throw new Exception('EXISTING_TASK_CHANGED');
$checks=['taskId'=>(int)$row['id'],'existingHash'=>hash('sha256',json_encode($strip($actual))),'noteReadback'=>true,'photosReadback'=>true];}
$column=think\facade\Db::query("SHOW COLUMNS FROM eb_customer_care_task LIKE 'task_photos'");
$compiled=null;if($column){$schema=think\facade\Db::connect()->getSchemaInfo('eb_customer_care_task');if(!in_array('task_photos',$schema['fields'],true))throw new Exception('STALE_TASK_SCHEMA');$compiled=think\facade\Db::name('customer_care_task')->fetchSql(true)->insert(['task_photos'=>'[]']);if(strpos($compiled,'task_photos')===false)throw new Exception('ORM_DROPPED_PHOTOS');}
echo json_encode(['database'=>config('database.connections.mysql.database'),'metadata'=>'PASS','detail'=>$checks,'photoColumn'=>count($column),'ormPhotoWriteCompiled'=>$compiled!==null]);
}catch(\Throwable $e){echo json_encode(['error'=>$e->getMessage(),'file'=>$e->getFile(),'line'=>$e->getLine()]);}finally{think\facade\Db::rollback();}
'''
    v=remote(s,code);save(s+'-smoke-'+mode+'.json',v);assert v['metadata']=='PASS'
    if mode=='after':assert v['photoColumn']==1 and v['ormPhotoWriteCompiled'];assert v['detail']==load(s+'-smoke-before.json')['detail']
    return v

def payload():
    c=load('candidate.json');out={}
    for p in c['businessFiles']:
        if p.startswith('后端代码/'):out[p.removeprefix('后端代码/')]=git('show',c['tree']+':'+p)
    for p in (O/'build/candidate').rglob('*'):
        if p.is_file():out['public/view_mobile_v3/'+p.relative_to(O/'build/candidate').as_posix()]=p.read_bytes()
    return out

def prepare(s):
    b=load(s+'-before.json');data=payload();now=probe(s,set(b['files'])|set(data));assert now['environment']==b['environment']
    for p,h in b['files'].items():assert now['files'][p]==h,('LIVE_CHANGED',s,p)
    selected={p:d for p,d in data.items() if sha(d)!=now['files'][p]}
    for p in selected:
        if p.startswith('public/') and p!='public/view_mobile_v3/index.html':assert now['files'][p] is None,('ASSET_COLLISION',p)
    save(s+'-preinstall.json',now);save(s+'-manifest.json',{p:sha(d) for p,d in selected.items()})
    with tarfile.open(O/(s+'-payload.tar.gz'),'w:gz') as a:
        for p,d in selected.items():i=tarfile.TarInfo(p);i.size=len(d);i.mode=0o644;a.addfile(i,io.BytesIO(d))
    v=smoke(s,'before');print(json.dumps({'site':s,'changedFiles':len(selected),'smoke':v}))

if __name__=='__main__':
    action=sys.argv[1]
    if action=='freeze':freeze()
    elif action=='build':build(sys.argv[2])
    elif action=='baseline':baseline(sys.argv[2])
    elif action=='prepare':prepare(sys.argv[2])

# These explicit phases keep the additive schema authorization separate from
# ordinary code publication. Backups preserve old files; rollback retains the
# nullable column so newly saved photo references can never be destroyed.
def stage(s):
    c=load('candidate.json');commit=load('commit.json')['commit'];before=load(s+'-preinstall.json');targets=load(s+'-manifest.json')
    now=probe(s,before['files']);assert now==before,'LIVE_CHANGED_BEFORE_BACKUP'
    folder='/www/backups/'+s+'/r54-task-photos-'+commit[:12]
    code='$d='+json.dumps(folder)+';if(is_dir($d))throw new Exception("BACKUP_EXISTS");mkdir($d,0700,true);mkdir($d."/before",0700);$o=[];foreach('+json.dumps(list(targets))+''' as $p){$o[$p]=is_file($p)?hash_file('sha256',$p):null;if($o[$p]!==null&&!copy($p,$d.'/before/'.hash('sha256',$p)))throw new Exception('BACKUP_FAILED');}file_put_contents($d.'/files.json',json_encode($o));echo json_encode(['backup'=>$d,'files'=>$o]);'''
    v=remote(s,code,False,user='root');assert v['files']=={p:before['files'][p] for p in targets}
    subprocess.run(['scp',str(O/(s+'-payload.tar.gz')),S[s][0]+':'+folder+'/payload.tar.gz'],check=True,stdout=subprocess.DEVNULL)
    subprocess.run(['ssh',S[s][0],'mkdir '+folder+'/payload && tar -xzf '+folder+'/payload.tar.gz -C '+folder+'/payload && chown -R www:www '+folder+'/payload && chmod 711 '+folder+' && chmod 755 '+folder+'/payload'],check=True)
    schema=remote(s,"echo json_encode(['create'=>think\\facade\\Db::query('SHOW CREATE TABLE eb_customer_care_task'),'rows'=>think\\facade\\Db::name('customer_care_task')->count()]);")
    save(s+'-backup.json',dict(v,schema=schema,commit=commit));print(json.dumps({'site':s,'backup':folder}))

def migrate(s):
    # The local approval record is mandatory and scoped to this exact candidate.
    approval=load('authorization.json');assert approval['migrationAuthorized'] is True and s in approval['sites']
    c=load('candidate.json');assert approval['tree']==c['tree'];b=load(s+'-backup.json');folder=b['backup']
    current=probe(s,load(s+'-preinstall.json')['files']);assert current==load(s+'-preinstall.json'),'LIVE_CHANGED_BEFORE_MIGRATION'
    if s=='010':
        code=r'''$f='/root/.mysql57-008.cnf';if((fileperms($f)&0777)!==0600||fileowner($f)!==0)throw new Exception('DBA_IDENTITY');$a=parse_ini_file($f,true,INI_SCANNER_RAW)['client'];if($a['user']!=='root'||$a['socket']!=='/run/mysql57-008/mysql.sock')throw new Exception('DBA_SOCKET');$d=new PDO('mysql:unix_socket='.$a['socket'].';dbname=mohe_010_app;charset=utf8mb4',$a['user'],$a['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);if($d->query('SELECT DATABASE()')->fetchColumn()!=='mohe_010_app'||(int)$d->query('SELECT @@port')->fetchColumn()!==3307)throw new Exception('DBA_DATABASE');$d->exec('SET SESSION lock_wait_timeout=5');if(!$d->query("SHOW COLUMNS FROM eb_customer_care_task LIKE 'task_photos'")->fetch())$d->exec("ALTER TABLE eb_customer_care_task ADD COLUMN task_photos TEXT NULL COMMENT '任务备注图片URL数组，历史任务为空'");echo json_encode(['column'=>true,'grantsChanged'=>false]);'''
        save(s+'-ddl.json',remote(s,code,False,user='root'))
    checksum=load(s+'-manifest.json')[MIG]
    code="think\\facade\\Db::execute('SET SESSION lock_wait_timeout=5');$f="+json.dumps(folder+'/payload/'+MIG)+';if(hash_file("sha256",$f)!=='+json.dumps(checksum)+')throw new Exception("MIGRATION_HASH");$apply=require $f;$added=$apply();'
    code+=r'''$schema=think\facade\Db::connect()->getSchemaInfo('eb_customer_care_task',true);if(!in_array('task_photos',$schema['fields'],true))throw new Exception('SCHEMA_CACHE');$column=think\facade\Db::query("SHOW COLUMNS FROM eb_customer_care_task LIKE 'task_photos'")[0];if(strtolower($column['Type'])!=='text'||$column['Null']!=='YES')throw new Exception('COLUMN_CONTRACT');'''
    code+='$key='+json.dumps(KEY)+';$checksum='+json.dumps(checksum)+';$old=think\\facade\\Db::name("database_upgrade_log")->where("upgrade_key",$key)->find();if($old&&$old["sql_checksum"]!==$checksum)throw new Exception("MIGRATION_CONFLICT");'
    # JSON carries the exact upgrade record without shell/PHP string interpolation.
    code+="if(!$old){$log=json_decode("+json.dumps(json.dumps({'upgrade_key':KEY,'title':'商家客情任务备注多图','task_file':MIG,'sql_checksum':checksum,'code_version':b['commit'],'executed_by':'Codex R54 www','result_note':'仅新增可空图片字段；不回填历史任务'},ensure_ascii=False))+",true);$log['executed_at']=date('Y-m-d H:i:s');think\\facade\\Db::name('database_upgrade_log')->insert($log);}"
    code+="echo json_encode(['added'=>$added,'column'=>$column,'rows'=>think\\facade\\Db::name('customer_care_task')->count(),'upgradeKey'=>$key]);"
    result=remote(s,code);save(s+'-migration.json',result);print(json.dumps({'site':s,'migration':result}))

def install(s):
    assert load(s+'-migration.json')['upgradeKey']==KEY
    b=load(s+'-backup.json');before=load(s+'-preinstall.json');assert probe(s,before['files'])==before,'LIVE_CHANGED_BEFORE_SWITCH'
    targets=load(s+'-manifest.json');folder=b['backup']
    code='$targets=json_decode('+json.dumps(json.dumps(targets))+',true);$folder='+json.dumps(folder)+';'
    code+=r'''foreach($targets as $p=>$h)if(hash_file('sha256',$folder.'/payload/'.$p)!==$h)throw new Exception('PAYLOAD_HASH');$paths=array_keys($targets);usort($paths,static function($a,$b){return ($a==='public/view_mobile_v3/index.html'?1:0)<=>($b==='public/view_mobile_v3/index.html'?1:0);});foreach($paths as $p){$tmp=$p.'.r54-'.getmypid();if(!is_dir(dirname($p)))mkdir(dirname($p),0755,true);if(!copy($folder.'/payload/'.$p,$tmp))throw new Exception('COPY_FAILED');chmod($tmp,0644);chown($tmp,'www');chgrp($tmp,'www');if(!rename($tmp,$p))throw new Exception('SWITCH_FAILED');}echo json_encode(['files'=>count($targets),'result'=>'PASS']);'''
    save(s+'-install.json',remote(s,code,False,user='root'))
    subprocess.run(['ssh',S[s][0],S[s][2]],check=True,capture_output=True,timeout=180)
    verify(s)

def verify(s):
    before=load(s+'-preinstall.json');targets=load(s+'-manifest.json');actual=probe(s,before['files']);expected=dict(before['files']);expected.update(targets)
    assert actual['files']==expected and actual['environment']==before['environment'],'POST_DEPLOY_DRIFT'
    smoke(s,'after');assets=load('candidate-assets.json');checks={}
    for p in assets:
        url='https://'+s+'.cc3798.com/view_mobile_v3/'+p
        with urllib.request.urlopen(url,timeout=30) as response:data=response.read()
        assert sha(data)==assets[p],('PUBLIC_ASSET',p);checks[p]=sha(data)
    save(s+'-verified.json',{'commit':load('commit.json')['commit'],'files':actual,'publicAssets':checks,'backup':load(s+'-backup.json')['backup'],'businessWrites':0});print(json.dumps({'site':s,'verified':True,'publicAssets':len(checks)}))

def rollback(s):
    b=load(s+'-backup.json');targets=load(s+'-manifest.json');folder=b['backup'];now=probe(s,targets)
    assert all(now['files'][p] in (h,b['files'][p]) for p,h in targets.items()),'ROLLBACK_CONFLICT'
    code='$before=json_decode('+json.dumps(json.dumps(b['files']))+',true);$folder='+json.dumps(folder)+';'
    code+=r'''foreach($before as $p=>$h){if($h===null)continue;$src=$folder.'/before/'.hash('sha256',$p);if(hash_file('sha256',$src)!==$h)throw new Exception('BACKUP_CORRUPT');$tmp=$p.'.r54-rollback';if(!copy($src,$tmp))throw new Exception('RESTORE_FAILED');chmod($tmp,0644);chown($tmp,'www');chgrp($tmp,'www');rename($tmp,$p);}echo json_encode(['rollback'=>true,'schemaRetained'=>true,'immutableAssetsRetained'=>true]);'''
    save(s+'-rollback.json',remote(s,code,False,user='root'));subprocess.run(['ssh',S[s][0],S[s][2]],check=True,capture_output=True,timeout=180)

if __name__=='__main__' and sys.argv[1] in ['stage','migrate','install','verify','rollback']:
    globals()[sys.argv[1]](sys.argv[2])
