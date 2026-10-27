"""R38固定补丁发布：不写业务库，独立备份CAS更新，严格瑞昊→push→008→010。"""
import subprocess,json,hashlib,tempfile,os,sys,io,tarfile,base64,datetime,importlib.util,urllib.request,urllib.error
from pathlib import Path
spec=importlib.util.spec_from_file_location('r37','scripts/r37-release.py');core=importlib.util.module_from_spec(spec);spec.loader.exec_module(core)
ssh=core.ssh;probe=core.probe;restart=core.restart
E=Path('tests/inventory/evidence/R38-warehouse-labels-expiry');R=E/'release';BRANCH='release/r38-inventory-labels-expiry-20261027'
def sha(b):return hashlib.sha256(b).hexdigest()
def g(*a,**k):return subprocess.check_output(['git',*a],**k)
def read(n):return json.loads((R/n).read_text())
def save(n,v):(R/n).write_text(json.dumps(v,ensure_ascii=False,indent=2)+'\n')
def inventory(s):
 root='/www/wwwroot/'+s+'.cc3798.com/public/view_inventory_v3';php="<?php $r='"+root+"';$a=[];foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($r,FilesystemIterator::SKIP_DOTS))as$f)if($f->isFile())$a[substr($f->getPathname(),strlen($r)+1)]=hash_file('sha256',$f->getPathname());ksort($a);echo json_encode($a);";return json.loads(ssh(s,'/www/server/php/74/bin/php',php.encode()))
def smoke(s,mode):
 p=read('trees.json');body=Path('scripts/r38-release-smoke.php').read_text()
 if mode=='candidate':
  paths=[x for x in p['backendPaths']if '/controller/'not in x];paths.sort(key=lambda n:0 if n.endswith('InventoryMovementDisplayServices.php')else 1)
  inject='\n'.join("eval(substr(base64_decode('"+base64.b64encode(g('show',p['candidateTree']+':'+n)).decode()+"'),5));"for n in paths);body=body.replace('// Inject fixed candidate read services here, before application autoload.',inject)
 temp='/tmp/r38-smoke-'+s+'-'+mode+'.php';ssh(s,'install -m 600 -o www -g www /dev/stdin '+temp,body.encode())
 try:out=ssh(s,"su -s /bin/sh www -c '/www/server/php/74/bin/php "+temp+' '+s+' '+mode+"'",t=180)
 except subprocess.CalledProcessError as e:print(e.stderr.decode()[-1000:]);raise
 finally:ssh(s,'rm -f '+temp)
 v=json.loads([x for x in out.decode().splitlines()if x.startswith('{')][-1]);assert v['result']=='PASS';save(s+'-runtime-'+mode+'.json',v);return v

def builds():
 p=read('trees.json')
 for term in ['cashier-v3','inventory-vue3']:
  b=read('candidate-'+term+'-build.json');assert b['result']=='PASS'and b['sourceStable']and b['tree']==p['candidateTree']
 for s in ['rh','008','010']:
  live=read(s+'-before.json');f=next(x for x in live['frontends']if x['kind']=='cashier'and x['root']==live['root']);b=read('baseline-cashier-v3-build.json');assert b['assets']['index.html']==f['sha256']and all(b['assets'][n.removeprefix('/view_cashier_v3/')]==h for n,h in f['assets'].items())

def preflight(s):
 assert probe(s)==read(s+'-before.json')and inventory(s)==read(s+'-inventory-before.json'),'LIVE_CHANGED';builds();a=smoke(s,'before');b=smoke(s,'candidate');assert all(a[k]==b[k]for k in ['batchRows','inventorySHA256','expirySHA256','configSHA256']),'DATA_OR_CONFIG_CHANGED';save(s+'-preflight.json',{'result':'PASS','readOnly':True,'inventoryCostExpiryUnchanged':True,'cashierBaselineReproducesLive':True});print('PREFLIGHT PASS',s,flush=True)

def commit():
 builds();p=read('trees.json');assert all(read(s+'-preflight.json')['result']=='PASS'for s in ['rh','008','010']);f=json.loads((E/'fixed-version.json').read_text());assert all(sha(Path(n).read_bytes())==h for n,h in f['files'].items());head=g('rev-parse','HEAD');index=Path(g('rev-parse','--git-path','index').decode().strip());ih=sha(index.read_bytes());fd,idx=tempfile.mkstemp(prefix='r38-commit-');os.close(fd);os.unlink(idx);env=dict(os.environ,GIT_INDEX_FILE=idx)
 try:
  # 前置来源本身已验收上线；本轮第一父提交差异仅固定R38补丁、工程脚本及证据。
  g('read-tree',p['candidateTree'],env=env);extra=[str(x)for x in E.iterdir()if x.is_file()]+['scripts/'+n for n in ['r38-release.py','r38-release-prepare.py','r38-release-build.cjs','r38-release-smoke.php','r38-release-install.php']]
  for n in extra:g('update-index','--add','--cacheinfo','100644',g('hash-object','-w',n).decode().strip(),n,env=env)
  tree=g('write-tree',env=env).decode().strip();paths=g('diff','--name-only',p['baselineCommit'],tree).decode().splitlines();assert sorted(paths)==sorted(p['paths']+extra);g('diff','--check',p['baselineCommit'],tree,'--',*[n for n in paths if not n.endswith('.patch')]);c=g('commit-tree',tree,'-p',p['baselineCommit'],'-p','345e4590aaffcdbfc93ec6683f458b2658fae876','-p','166f095f',input='R38-00001 库存仓库选择器中文业务名称与临期批次明细\n\n产品经理测试验收通过并授权三站发布。只叠加R38固定补丁，保留已上线工资/基础报表、R37手工显隐和所有既有收银功能。无数据库迁移或业务数据改写。\n'.encode()).decode().strip();g('update-ref','refs/heads/'+BRANCH,c,'0'*40);assert g('rev-parse','HEAD')==head and sha(index.read_bytes())==ih;save('commit.json',{'commit':c,'tree':tree,'branch':BRANCH,'paths':paths,'sharedHEADAndIndexUntouched':True});print('COMMIT',c,flush=True)
 finally:Path(idx).unlink(missing_ok=True)

def deploy(s):
 builds();i=read('commit.json');p=read('trees.json');c=i['commit'];before=read(s+'-before.json');inv=read(s+'-inventory-before.json');assert probe(s)==before and inventory(s)==inv,'LIVE_CHANGED'
 if s=='008':assert read('rh-verified.json')['result']=='PASS'and read('push.json')['result']=='PASS'
 if s=='010':assert read('008-verified.json')['result']=='PASS'
 files={n.removeprefix('后端代码/'):g('show',c+':'+n)for n in p['backendPaths']}
 for term,folder in [('cashier-v3','view_cashier_v3'),('inventory-vue3','view_inventory_v3')]:
  b=read('candidate-'+term+'-build.json')
  for n,h in b['assets'].items():
   if not(n=='index.html'or(n.startswith('assets/')and n.endswith(('.js','.css')))):continue
   data=(Path(b['output'])/n).read_bytes();assert sha(data)==h;files['public/'+folder+'/'+n]=data
 root=before['root'];php="<?php $r='"+root+"';$o=[];foreach(json_decode(base64_decode('"+base64.b64encode(json.dumps(list(files)).encode()).decode()+"'),true)as$n)$o[$n]=is_file($r.'/'.$n)?hash_file('sha256',$r.'/'.$n):null;echo json_encode($o);";old=json.loads(ssh(s,'/www/server/php/74/bin/php',php.encode()));files={n:b for n,b in files.items()if sha(b)!=old[n]};targets=dict(sorted({n:{'before':old[n],'after':sha(b)}for n,b in files.items()}.items(),key=lambda x:(x[0].endswith('/index.html'),x[0])));manifest={'site':s,'commit':c,'sourceTree':p['candidateTree'],'targets':targets,'noDBWrites':True};d='/www/backups/'+s+'/r38-inventory-'+c[:8];payload=io.BytesIO()
 with tarfile.open(fileobj=payload,mode='w:gz')as t:
  for n,b in [('payload/'+n,b)for n,b in files.items()]+[('payload/manifest.json',json.dumps(manifest).encode()),('install.php',Path('scripts/r38-release-install.php').read_bytes())]:item=tarfile.TarInfo(n);item.size=len(b);item.mode=0o600;t.addfile(item,io.BytesIO(b))
 ssh(s,'test ! -e '+d+' && mkdir -p -m 700 '+d);ssh(s,'tar -xzf - -C '+d,payload.getvalue(),120)
 for n in p['backendPaths']:ssh(s,'/www/server/php/74/bin/php -l '+d+'/payload/'+n.removeprefix('后端代码/'))
 assert probe(s)==before and inventory(s)==inv,'LIVE_CHANGED';save(s+'-manifest.json',manifest)
 try:
  logs=ssh(s,'/www/server/php/74/bin/php '+d+'/install.php '+d+' install')+restart(s);(R/(s+'-deployment.log')).write_bytes(logs);runtime=smoke(s,'after');expectedRuntime=read(s+'-runtime-candidate.json');assert all(runtime[k]==expectedRuntime[k]for k in ['batchRows','inventorySHA256','expirySHA256','configSHA256','detail']);after=probe(s);newInv=inventory(s);expected=json.loads(json.dumps(before))
  for n,x in targets.items():
   if n.startswith(('app/','route/','config/')):expected['backend'][n]=x['after']
  expected['frontends']=[z if z['root']==root and z['kind']=='cashier'else a for a,z in zip(before['frontends'],after['frontends'])];expected['protected']['public/view_inventory_v3/index.html']=read('candidate-inventory-vue3-build.json')['assets']['index.html'];assert after==expected,'UNRELATED_CHANGE'
  expectedInv=dict(inv);expectedInv.update({n.removeprefix('public/view_inventory_v3/'):x['after']for n,x in targets.items()if n.startswith('public/view_inventory_v3/')});assert newInv==dict(sorted(expectedInv.items())),'UNRELATED_INVENTORY_CHANGE';save(s+'-after.json',after);save(s+'-inventory-after.json',newInv);public(s);print('DEPLOY/READONLY/PUBLIC PASS',s,flush=True)
 except BaseException:ssh(s,'/www/server/php/74/bin/php '+d+'/install.php '+d+' rollback');restart(s);raise

def public(s):
 domain=s+'.cc3798.com';checks=[];targets=read(s+'-manifest.json')['targets']
 for n,x in targets.items():
  if not n.startswith('public/'):continue
  path='/'+n.removeprefix('public/');data=urllib.request.urlopen('https://'+domain+path,timeout=30).read();assert sha(data)==x['after'],path;checks.append({'path':path,'sha256':x['after']})
 # 平台外壳不更新；正式入口仍需可用，历史预览路径保持410。
 assert urllib.request.urlopen('https://'+domain+'/admin/login',timeout=30).status==200
 if s=='rh':
  for p in ['/preview-8080/view_cashier_v3/','/store-v3/']:
   try:urllib.request.urlopen('https://'+domain+p,timeout=20);raise RuntimeError('RETIRED_PATH_ACTIVE')
   except urllib.error.HTTPError as e:assert e.code==410
 save(s+'-public.json',{'result':'PASS','checks':checks})
def finish(s):
 assert read(s+'-public.json')['result']=='PASS'and read(s+'-browser.json')['result']=='PASS';assert probe(s)==read(s+'-after.json')and inventory(s)==read(s+'-inventory-after.json');c=read('commit.json')['commit'];save(s+'-verified.json',{'result':'PASS','site':s,'commit':c,'sourceTree':read('trees.json')['candidateTree'],'rollback':'/www/backups/'+s+'/r38-inventory-'+c[:8],'businessWrites':0,'timeUTC':datetime.datetime.now(datetime.timezone.utc).isoformat()});print('VERIFIED',s,flush=True)
def push():
 i=read('commit.json');assert read('rh-verified.json')['result']=='PASS';g('push','origin','refs/heads/'+BRANCH+':refs/heads/'+BRANCH);assert g('ls-remote','origin','refs/heads/'+BRANCH).decode().split()[0]==i['commit'];save('push.json',{'result':'PASS','commit':i['commit']});print('PUSH PASS',flush=True)
if __name__=='__main__':
 mode=sys.argv[1]
 if mode=='preflight':preflight(sys.argv[2])
 elif mode=='commit':commit()
 elif mode=='deploy':deploy(sys.argv[2])
 elif mode=='public':public(sys.argv[2])
 elif mode=='finish':finish(sys.argv[2])
 elif mode=='push':push()
 else:raise RuntimeError('EXPLICIT_MODE_REQUIRED')
