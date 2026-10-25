# coding: utf-8
"""R34 独立索引提交、逐站CAS发布与两字段更新；不操作其他任务索引，不备份配置旧值。"""
import subprocess,json,hashlib,tempfile,os,sys,io,tarfile,base64,time
from pathlib import Path
E=Path('tests/store-admin/evidence/R34-delivery-defaults');R=E/'release';BRANCH='release/r34-store-delivery-defaults-20261026'
def sha(b):return hashlib.sha256(b).hexdigest()
def run(a,**kw):return subprocess.check_output(a,**kw)
def g(*a,**kw):return run(['git',*a],**kw)
def read(n):return json.loads((R/n).read_text())
def save(n,v):(R/n).write_text(json.dumps(v,ensure_ascii=False,indent=2)+'\n')
def ssh(site,cmd,data=None,timeout=90):
 return run(['ssh','-q','-o','BatchMode=yes','-o','ControlMaster=no','-o','ControlPath=none','-o','ConnectTimeout=10','-o','ServerAliveInterval=10','-o','ServerAliveCountMax=2','rh-server' if site=='rh' else 'mohe-server',cmd],input=data,stderr=subprocess.PIPE,timeout=timeout)
def probe(site):
 for attempt in range(3):
  try:return json.loads(ssh(site,'/www/server/php/74/bin/php -- '+site,Path('scripts/r28-permissions-inspect.php').read_bytes(),55))
  except(subprocess.CalledProcessError,subprocess.TimeoutExpired):
   if attempt==2:raise
   time.sleep(1)
def data_update(site,mode):
 # App initialization/cache writes run as the site's runtime owner; no root-owned runtime files.
 temp='/tmp/r34-store-defaults-'+site+'.php';ssh(site,"install -m 600 -o www -g www /dev/stdin "+temp,Path('scripts/r34-store-defaults.php').read_bytes())
 try:output=ssh(site,"su -s /bin/sh www -c '/www/server/php/74/bin/php "+temp+' '+site+' '+mode+"'",timeout=120)
 finally:ssh(site,'rm -f '+temp)
 lines=[x for x in output.decode().splitlines() if x.startswith('{')];assert lines,output.decode();v=json.loads(lines[-1]);assert v['result']=='PASS';save(site+'-store-'+mode+'.json',v);return v

def commit():
 plan=read('trees.json');build=read('candidate-admin-build.json');assert build['result']=='PASS' and build['sourceStable'] and build['tree']==plan['candidateTree'];fixed=json.loads((E/'fixed-files.json').read_text());assert all(sha(Path(p).read_bytes())==h for p,h in fixed['files'].items())
 shared=Path(g('rev-parse','--git-path','index').decode().strip());indexSHA=sha(shared.read_bytes());head=g('rev-parse','HEAD');fd,idx=tempfile.mkstemp(prefix='r34-commit-');os.close(fd);os.unlink(idx);env=dict(os.environ,GIT_INDEX_FILE=idx)
 try:
  g('read-tree',plan['candidateTree'],env=env)
  docs=[str(p) for p in E.iterdir() if p.is_file()];scripts=[str(p)for p in Path('scripts').glob('r34-*') if p.is_file()];files=docs+scripts
  for p in files:
   blob=g('hash-object','-w','--stdin',input=Path(p).read_bytes()).decode().strip();g('update-index','--add','--cacheinfo','100644',blob,p,env=env)
  tree=g('write-tree',env=env).decode().strip();paths=g('diff','--name-only',plan['baselineCommit'],tree).decode().splitlines();assert sorted(paths)==sorted(files+plan['paths']);g('diff','--check',plan['baselineCommit'],tree,'--',*[p for p in paths if not p.endswith('.patch')])
  assert g('rev-parse',tree+':前端代码/admin')==g('rev-parse',plan['candidateTree']+':前端代码/admin')
  c=g('commit-tree',tree,'-p',plan['baselineCommit'],input='R34-00001 门店配送默认到店与三站现有门店配置统一\n\n仅R34两平台文件及发布脚本/验收证据；保留已验收R62平台基线。产品经理已验收并授权三站发布与两字段配置更新，不备份字段原值。\n'.encode()).decode().strip()
  g('update-ref','refs/heads/'+BRANCH,c,'0'*40);assert g('rev-parse','HEAD')==head and sha(shared.read_bytes())==indexSHA
  save('commit.json',{'commit':c,'branch':BRANCH,'tree':tree,'parent':plan['baselineCommit'],'files':paths,'sharedHEADAndIndexUntouched':True,'acceptance':'user tested and accepted R34; authorized deployment, push and existing-store two-field update; explicitly no old-value backup'})
  print('COMMIT',c,flush=True)
 finally:Path(idx).unlink(missing_ok=True)

def deploy(site):
 assert site in ['rh','008','010'];info=read('commit.json');c=info['commit'];before=read(site+'-before.json');build=read('candidate-admin-build.json');assert build['result']=='PASS';assert probe(site)==before,'LIVE_BASELINE_CHANGED'
 if site=='008':assert read('rh-verified.json')['result']=='PASS' and read('push.json')['result']=='PASS'
 if site=='010':assert read('008-verified.json')['result']=='PASS'
 domain='rh.cc3798.com' if site=='rh' else site+'.cc3798.com';root='/www/wwwroot/'+domain;directory='/www/backups/'+site+'/r34-store-defaults-'+c[:8]
 files={}
 for p,h in build['assets'].items():
  # Admin output can contain copied public assets; publish only changed package files after hash comparison.
  b=(Path(build['output'])/p).read_bytes();assert sha(b)==h;files['public/'+p]=b
 encoded=base64.b64encode(json.dumps(list(files)).encode()).decode();query="<?php $r='"+root+"';$o=[];foreach(json_decode(base64_decode('"+encoded+"'),true) as $p)$o[$p]=is_file($r.'/'.$p)?hash_file('sha256',$r.'/'.$p):null;echo json_encode($o);"
 old=json.loads(ssh(site,'/www/server/php/74/bin/php',query.encode()));front=next(f for f in before['frontends'] if f['kind']=='admin' and f['root']==root);assert old[front['entry']]==front['sha256']
 # Never overwrite copied entrypoints for other terminals; only the admin asset namespace and platform HTML.
 files={p:b for p,b in files.items() if old[p]!=sha(b)};assert all(p=='public/system.html' or p.startswith('public/view_admin/') for p in files),list(files)
 manifest={'site':site,'commit':c,'sourceTree':build['tree'],'targets':dict(sorted({p:{'before':old[p],'after':sha(b)}for p,b in files.items()}.items(),key=lambda x:(x[0]=='public/system.html',x[0]))),'scope':'R34 admin defaults only; existing live admin features preserved','database':'Only undeleted store delivery_type=2 and default_delivery=2; no old-value backup per user'}
 payload=io.BytesIO()
 with tarfile.open(fileobj=payload,mode='w:gz') as tar:
  for n,b in [('payload/'+p,b)for p,b in files.items()]+[('payload/manifest.json',json.dumps(manifest).encode()),('install.php',Path('scripts/r34-release-install.php').read_bytes())]:
   item=tarfile.TarInfo(n);item.size=len(b);item.mode=0o600;tar.addfile(item,io.BytesIO(b))
 ssh(site,"test ! -e '"+directory+"' && mkdir -p -m 700 '"+directory+"'");ssh(site,'tar -xzf - -C '+directory,payload.getvalue(),timeout=120);assert probe(site)==before
 ssh(site,'/www/server/php/74/bin/php '+directory+'/install.php '+directory+' install');save(site+'-manifest.json',manifest)
 # A failed database operation is reported, not retried or restored to unknown previous values.
 applied=data_update(site,'apply');verified=data_update(site,'verify');assert verified['existingStores']==applied['existingStores'] and verified['remainingMismatches']==0
 actual=json.loads(ssh(site,'/www/server/php/74/bin/php',query.encode()));assert all(actual[p]==sha(b)for p,b in files.items())
 after=probe(site);expected=json.loads(json.dumps(before));expected['frontends']=after['frontends'];assert after==expected,'UNRELATED_APP_CHANGE'
 for a,b in zip(before['frontends'],after['frontends']):
  if a['kind']!='admin' or a['root']!=root:assert a==b
  else:assert b['sha256']==build['assets']['system.html'] and all(build['assets'][p.lstrip('/')]==h for p,h in b['assets'].items())
 save(site+'-after.json',after)
 run([sys.executable,'scripts/r34-release-public.py',site]);run(['/Users/xiaojunpeng/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node','scripts/r34-release-browser.cjs',site])
 save(site+'-verified.json',{'site':site,'commit':c,'result':'PASS','adminFilesChanged':len(files),'frontendRollback':directory,'storeCount':applied['existingStores'],'storesChanged':applied['changedStores'],'defaults':'delivery_type=2; default_delivery=2','storeOldValuesBackedUp':False,'backendAndOtherTerminalsUnchanged':True,'publicAssetsAndBrowserBoot':'PASS','cacheAndDetailReadback':'PASS'});print('DEPLOY VERIFIED',site,flush=True)

def push():
 assert read('rh-verified.json')['result']=='PASS';c=read('commit.json')['commit'];run(['git','push','origin','refs/heads/'+BRANCH+':refs/heads/'+BRANCH]);remote=g('ls-remote','origin','refs/heads/'+BRANCH).decode().split()[0];assert remote==c;save('push.json',{'commit':c,'remote':'origin','branch':BRANCH,'result':'PASS'});print('PUSH VERIFIED',c)
if __name__=='__main__':
 mode=sys.argv[1]
 if mode=='commit':commit()
 elif mode=='deploy':deploy(sys.argv[2])
 elif mode=='push':push()
 else:raise RuntimeError('EXPLICIT_MODE_REQUIRED')
