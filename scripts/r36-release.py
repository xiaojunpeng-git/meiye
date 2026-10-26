# coding: utf-8
"""R36同一两文件补丁，保留现网已验收版本差异；只发门店端静态文件，不改后台或业务数据。"""
import subprocess,json,hashlib,tempfile,os,sys,io,tarfile,base64,time,datetime
from pathlib import Path
E=Path('tests/cashier-v3/evidence/R36-member-debt-visibility');R=E/'release';BRANCH='release/r36-member-debt-visibility-20261026'
def sha(b):return hashlib.sha256(b).hexdigest()
def run(a,**kw):return subprocess.check_output(a,**kw)
def g(*a,**kw):return run(['git',*a],**kw)
def read(n):return json.loads((R/n).read_text())
def save(n,v):(R/n).write_text(json.dumps(v,ensure_ascii=False,indent=2)+'\n')
def ssh(s,cmd,b=None,t=90):return run(['ssh','-q','-o','BatchMode=yes','-o','ControlMaster=no','-o','ControlPath=none','-o','ConnectTimeout=10','-o','ServerAliveInterval=10','-o','ServerAliveCountMax=2','rh-server'if s=='rh'else'mohe-server',cmd],input=b,stderr=subprocess.PIPE,timeout=t)
def probe(s):
 for i in range(3):
  try:return json.loads(ssh(s,'/www/server/php/74/bin/php -- '+s,Path('scripts/r28-permissions-inspect.php').read_bytes(),55))
  except(subprocess.CalledProcessError,subprocess.TimeoutExpired):
   if i==2:raise

def commit():
 p=read('trees.json');f=json.loads((E/'fixed-files.json').read_text());assert all(sha(Path(n).read_bytes())==h for n,h in f['files'].items());assert sha((E/'scope.patch').read_bytes())==f['patchSHA256']
 for s in p['sites']:
  a=p['sites'][s];b=read(s+'/candidate-cashier-v3-build.json');old=read(s+'/baseline-cashier-v3-build.json');live=read(s+'-before.json');front=next(x for x in live['frontends']if x['kind']=='cashier'and x['root']==live['root']);assert b['result']=='PASS'and b['sourceStable']and b['tree']==a['candidateTree'];assert old['result']=='PASS'and old['assets']['index.html']==front['sha256']and all(old['assets'][n.removeprefix('/view_cashier_v3/')]==h for n,h in front['assets'].items())
 shared=Path(g('rev-parse','--git-path','index').decode().strip());ih=sha(shared.read_bytes());head=g('rev-parse','HEAD');fd,idx=tempfile.mkstemp(prefix='r36-commit-');os.close(fd);os.unlink(idx);env=dict(os.environ,GIT_INDEX_FILE=idx)
 try:
  # Extra parents retain all fixed build trees in Git history without separate customer source branches.
  variants={}
  for s in ['008','010']:
   a=p['sites'][s];base=g('commit-tree',a['baselineTree'],'-p',p['baselineCommit'],input=('R36 既有'+s+'已验收前端基线（报表保持原样）\n').encode()).decode().strip();variants[s]=g('commit-tree',a['candidateTree'],'-p',base,input=('R36-00001 '+s+'固定构建树：同一欠款显隐补丁\n').encode()).decode().strip()
  g('read-tree',p['candidateTree'],env=env);extra=[str(x)for x in E.iterdir()if x.is_file()]+['scripts/'+n for n in ['r36-010-custom-card-repair.php','r36-release-prepare.py','r36-release-build.cjs','r36-release-install.php','r36-release-public.py','r36-release.py','r36-release-archive.py']]
  for n in extra:
   blob=g('hash-object','-w','--stdin',input=Path(n).read_bytes()).decode().strip();g('update-index','--add','--cacheinfo','100644',blob,n,env=env)
  tree=g('write-tree',env=env).decode().strip();paths=g('diff','--name-only',p['baselineCommit'],tree).decode().splitlines();assert sorted(paths)==sorted(extra+p['paths']);g('diff','--check',p['baselineCommit'],tree,'--',*[n for n in paths if not n.endswith('.patch')]);assert g('rev-parse',tree+':前端代码/cashier-v3')==g('rev-parse',p['candidateTree']+':前端代码/cashier-v3')
  c=g('commit-tree',tree,'-p',p['baselineCommit'],'-p',variants['008'],'-p',variants['010'],input='R36-00001 欠款关闭时隐藏会员摘要总欠款\n\n产品经理已验收；只含两个Vue文件同一补丁及证据/脚本，保留各站已上线报表差异。010单独授权补齐缺失定制卡配置，未改订单或收银事实。\n'.encode()).decode().strip();g('update-ref','refs/heads/'+BRANCH,c,'0'*40);assert g('rev-parse','HEAD')==head and sha(shared.read_bytes())==ih
  save('commit.json',{'commit':c,'branch':BRANCH,'tree':tree,'variantCommits':variants,'paths':paths,'sharedHEADAndIndexUntouched':True,'acceptance':'User tested R36 debt visibility and authorized RH deploy, push then 008/010; separately approved 010 one configuration repair','sourceBoundary':'identical two changed Vue files on all sites; all remaining files use each already accepted baseline'});print('COMMIT',c,flush=True)
 finally:Path(idx).unlink(missing_ok=True)

def deploy(s):
 assert s in ['rh','008','010'];info=read('commit.json');c=info['commit'];before=read(s+'-before.json');p=read('trees.json')['sites'][s];b=read(s+'/candidate-cashier-v3-build.json');assert b['result']=='PASS'and probe(s)==before,'LIVE_BASELINE_CHANGED'
 if s=='008':assert read('rh-verified.json')['result']=='PASS'and read('push.json')['result']=='PASS'
 if s=='010':assert read('008-verified.json')['result']=='PASS'
 root=before['root'];d='/www/backups/'+s+'/r36-member-debt-'+c[:8];files={}
 for n,h in b['assets'].items():
  data=(Path(b['output'])/n).read_bytes();assert sha(data)==h;files['public/view_cashier_v3/'+n]=data
 encoded=base64.b64encode(json.dumps(list(files)).encode()).decode();php="<?php $r='"+root+"';$o=[];foreach(json_decode(base64_decode('"+encoded+"'),true)as$n)$o[$n]=is_file($r.'/'.$n)?hash_file('sha256',$r.'/'.$n):null;echo json_encode($o);"
 old=json.loads(ssh(s,'/www/server/php/74/bin/php',php.encode()));front=next(x for x in before['frontends']if x['kind']=='cashier'and x['root']==root);assert old[front['entry']]==front['sha256'];files={n:data for n,data in files.items()if old[n]!=sha(data)}
 manifest={'site':s,'commit':c,'sourceTree':p['candidateTree'],'baselineTree':p['baselineTree'],'targets':dict(sorted({n:{'before':old[n],'after':sha(data)}for n,data in files.items()}.items(),key=lambda x:(x[0].endswith('index.html'),x[0]))),'scope':'R36 debt visibility only; inherited report functionality unchanged; no backend deployment or database writes'}
 payload=io.BytesIO()
 with tarfile.open(fileobj=payload,mode='w:gz')as tar:
  for n,data in [('payload/'+n,data)for n,data in files.items()]+[('payload/manifest.json',json.dumps(manifest).encode()),('install.php',Path('scripts/r36-release-install.php').read_bytes())]:
   f=tarfile.TarInfo(n);f.size=len(data);f.mode=0o600;tar.addfile(f,io.BytesIO(data))
 ssh(s,'test ! -e '+d+' && mkdir -p -m 700 '+d);ssh(s,'tar -xzf - -C '+d,payload.getvalue(),120);assert probe(s)==before;save(s+'-manifest.json',manifest)
 try:
  log=ssh(s,'/www/server/php/74/bin/php '+d+'/install.php '+d+' install');(R/(s+'-deployment.log')).write_bytes(log)
  actual=json.loads(ssh(s,'/www/server/php/74/bin/php',php.encode()));assert all(actual[n]==sha(data)for n,data in files.items());after=probe(s);expected=json.loads(json.dumps(before));expected['frontends']=after['frontends'];assert after==expected,'UNRELATED_SERVER_CHANGE'
  for a,z in zip(before['frontends'],after['frontends']):
   if a['kind']!='cashier'or a['root']!=root:assert a==z
   else:assert z['sha256']==b['assets']['index.html']and all(b['assets'][n.removeprefix('/view_cashier_v3/')]==h for n,h in z['assets'].items())
  save(s+'-after.json',after)
 except BaseException:
  ssh(s,'/www/server/php/74/bin/php '+d+'/install.php '+d+' rollback');raise
 run([sys.executable,'scripts/r36-release-public.py',s]);print('FILES AND PUBLIC VERIFIED; browser verification required',s,flush=True)

def finish(s):
 info=read('commit.json');assert read(s+'-public.json')['result']=='PASS'and read(s+'-browser.json')['result']=='PASS';assert probe(s)==read(s+'-after.json')
 save(s+'-verified.json',{'site':s,'commit':info['commit'],'sourceTree':read('trees.json')['sites'][s]['candidateTree'],'result':'PASS','rollback':'/www/backups/'+s+'/r36-member-debt-'+info['commit'][:8],'existingReportSourcesPreserved':True,'backendAdminAndOtherTerminalsUnchanged':True,'financialWrites':0,'verifiedAtUTC':datetime.datetime.now(datetime.timezone.utc).isoformat()});print('DEPLOY VERIFIED',s,flush=True)
def push():
 info=read('commit.json');assert read('rh-verified.json')['result']=='PASS';g('push','origin','refs/heads/'+BRANCH+':refs/heads/'+BRANCH);assert g('ls-remote','origin','refs/heads/'+BRANCH).decode().split()[0]==info['commit'];save('push.json',{'commit':info['commit'],'branch':BRANCH,'result':'PASS'});print('PUSH VERIFIED',flush=True)
if __name__=='__main__':
 m=sys.argv[1]
 if m=='commit':commit()
 elif m=='deploy':deploy(sys.argv[2])
 elif m=='finish':finish(sys.argv[2])
 elif m=='push':push()
 else:raise RuntimeError('EXPLICIT_MODE_REQUIRED')
