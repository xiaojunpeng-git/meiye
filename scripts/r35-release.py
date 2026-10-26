# coding: utf-8
"""R35 精准提交、三站CAS文件发布及只读业务验证；不动共享索引，不改生产数据库或授权配置。"""
import subprocess,json,hashlib,tempfile,os,sys,io,tarfile,base64,time,datetime
from pathlib import Path
E=Path('tests/organization-store-v3/evidence/R35-organization-edit');R=E/'release';BRANCH='release/r35-organization-edit-20261026'
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
def smoke(site,mode):
 body=Path('scripts/r35-release-smoke.php').read_text()
 if mode=='candidate':
  tree=read('trees.json')['candidateTree'];inject=''
  for p in read('trees.json')['backendPaths']:
   inject+="eval(substr(base64_decode('"+base64.b64encode(g('show',tree+':'+p)).decode()+"'),5));\n"
  body=body.replace('// Candidate classes, when present, are injected from fixed canonical Git blobs before autoload.',inject)
 temp='/tmp/r35-org-edit-'+site+'-'+mode+'.php';ssh(site,'install -m 600 -o www -g www /dev/stdin '+temp,body.encode())
 try:output=ssh(site,"su -s /bin/sh www -c '/www/server/php/74/bin/php "+temp+' '+site+' '+mode+"'",timeout=120)
 finally:ssh(site,'rm -f '+temp)
 lines=[x for x in output.decode().splitlines()if x.startswith('{')];assert lines,output.decode();v=json.loads(lines[-1]);assert v['result']=='PASS';save(site+'-runtime-'+mode+'.json',v);return v

def preflight(site):
 assert probe(site)==read(site+'-before.json'),'LIVE_BASELINE_CHANGED'
 before=smoke(site,'before');candidate=smoke(site,'candidate')
 assert before['baseStatuses']==candidate['baseStatuses'] and before['protectedData']==candidate['protectedData'] and before['dimensionReadSHA256']==candidate['dimensionReadSHA256'],'PREFLIGHT_EXISTING_BEHAVIOR_CHANGED'
 save(site+'-preflight.json',{'site':site,'result':'PASS','oldWriteStatusesUnchanged':True,'organizationAndGrantsUnchanged':True,'dimensionReadUnchanged':True,'candidateTargetChecks':candidate['realTargetChecks']});print('PREFLIGHT PASS',site,flush=True)

def commit():
 plan=read('trees.json');build=read('candidate-admin-build.json');assert build['result']=='PASS' and build['sourceStable'] and build['tree']==plan['candidateTree'];fixed=json.loads((E/'fixed-files.json').read_text());assert all(sha(Path(p).read_bytes())==h for p,h in fixed['files'].items());assert all(read(s+'-preflight.json')['result']=='PASS'for s in ['rh','008','010'])
 shared=Path(g('rev-parse','--git-path','index').decode().strip());indexSHA=sha(shared.read_bytes());head=g('rev-parse','HEAD');fd,idx=tempfile.mkstemp(prefix='r35-commit-');os.close(fd);os.unlink(idx);env=dict(os.environ,GIT_INDEX_FILE=idx)
 try:
  g('read-tree',plan['candidateTree'],env=env);docs=[str(p)for p in E.iterdir()if p.is_file()];scripts=[str(p)for p in Path('scripts').glob('r35-*')if p.is_file()];files=docs+scripts
  for p in files:
   blob=g('hash-object','-w','--stdin',input=Path(p).read_bytes()).decode().strip();g('update-index','--add','--cacheinfo','100644',blob,p,env=env)
  tree=g('write-tree',env=env).decode().strip();paths=g('diff','--name-only',plan['baselineCommit'],tree).decode().splitlines();assert sorted(paths)==sorted(files+plan['paths']);g('diff','--check',plan['baselineCommit'],tree,'--',*[p for p in paths if not p.endswith('.patch')]);assert g('rev-parse',tree+':前端代码/admin')==g('rev-parse',plan['candidateTree']+':前端代码/admin')
  c=g('commit-tree',tree,'-p',plan['baselineCommit'],input='R35-00001 组织编辑按已有功能权限与组织范围校验\n\n产品经理已验收。只放开组织编辑额外超管限制；保留写开关、范围、结构锁、幂等及审计，不改变新增删除和授权门禁。\n'.encode()).decode().strip();g('update-ref','refs/heads/'+BRANCH,c,'0'*40);assert g('rev-parse','HEAD')==head and sha(shared.read_bytes())==indexSHA
  save('commit.json',{'commit':c,'branch':BRANCH,'tree':tree,'parent':plan['baselineCommit'],'files':paths,'sharedHEADAndIndexUntouched':True,'acceptance':'User tested fixed R35 and authorized commit, RH deploy/verify, push, then 008/010 deploy/verify'});print('COMMIT',c,flush=True)
 finally:Path(idx).unlink(missing_ok=True)

def restart(site):
 cmd='/etc/init.d/ruihao_swoole restart'if site=='rh'else'systemctl restart mohe-'+site+'-swoole.service && systemctl is-active mohe-'+site+'-swoole.service';return ssh(site,cmd,timeout=90)

def deploy(site):
 assert site in ['rh','008','010'];info=read('commit.json');c=info['commit'];before=read(site+'-before.json');build=read('candidate-admin-build.json');plan=read('trees.json');assert build['result']=='PASS'and read(site+'-preflight.json')['result']=='PASS';assert probe(site)==before,'LIVE_BASELINE_CHANGED'
 if site=='008':assert read('rh-verified.json')['result']=='PASS'and read('push.json')['result']=='PASS'
 if site=='010':assert read('008-verified.json')['result']=='PASS'
 domain='rh.cc3798.com'if site=='rh'else site+'.cc3798.com';root='/www/wwwroot/'+domain;directory='/www/backups/'+site+'/r35-org-edit-'+c[:8]
 files={p.removeprefix('后端代码/'):g('show',c+':'+p)for p in plan['backendPaths']}
 for p,h in build['assets'].items():
  b=(Path(build['output'])/p).read_bytes();assert sha(b)==h;files['public/'+p]=b
 encoded=base64.b64encode(json.dumps(list(files)).encode()).decode();query="<?php $r='"+root+"';$o=[];foreach(json_decode(base64_decode('"+encoded+"'),true)as$p)$o[$p]=is_file($r.'/'.$p)?hash_file('sha256',$r.'/'.$p):null;echo json_encode($o);"
 old=json.loads(ssh(site,'/www/server/php/74/bin/php',query.encode()));front=next(f for f in before['frontends']if f['kind']=='admin'and f['root']==root);assert old[front['entry']]==front['sha256']
 for p in plan['backendPaths']:
  rel=p.removeprefix('后端代码/');assert old[rel]==before['backend'].get(rel)
 files={p:b for p,b in files.items()if old[p]!=sha(b)};backend=set(p.removeprefix('后端代码/')for p in plan['backendPaths']);assert all(p in backend or p=='public/system.html'or p.startswith('public/view_admin/')for p in files)
 manifest={'site':site,'commit':c,'sourceTree':build['tree'],'targets':dict(sorted({p:{'before':old[p],'after':sha(b)}for p,b in files.items()}.items(),key=lambda x:(x[0]=='public/system.html',x[0]))),'scope':'R35 organization edit only; R34 admin defaults retained','database':'No migration or business/role writes'}
 payload=io.BytesIO()
 with tarfile.open(fileobj=payload,mode='w:gz')as tar:
  for n,b in [('payload/'+p,b)for p,b in files.items()]+[('payload/manifest.json',json.dumps(manifest).encode()),('install.php',Path('scripts/r35-release-install.php').read_bytes())]:
   item=tarfile.TarInfo(n);item.size=len(b);item.mode=0o600;tar.addfile(item,io.BytesIO(b))
 ssh(site,"test ! -e '"+directory+"' && mkdir -p -m 700 '"+directory+"'");ssh(site,'tar -xzf - -C '+directory,payload.getvalue(),timeout=120);assert probe(site)==before
 for p in backend:ssh(site,'/www/server/php/74/bin/php -l '+directory+'/payload/'+p)
 save(site+'-manifest.json',manifest)
 try:
  output=ssh(site,'/www/server/php/74/bin/php '+directory+'/install.php '+directory+' install');output+=restart(site);(R/(site+'-deployment.log')).write_bytes(output)
  runtime=smoke(site,'after');candidate=read(site+'-runtime-candidate.json');assert all(runtime[k]==candidate[k]for k in ['baseStatuses','capabilities','protectedData','dimensionReadSHA256','realTargetChecks']),'RUNTIME_REGRESSION'
  actual=json.loads(ssh(site,'/www/server/php/74/bin/php',query.encode()));assert all(actual[p]==sha(b)for p,b in files.items())
  after=probe(site);expected=json.loads(json.dumps(before));expected['frontends']=after['frontends']
  for p in backend:expected['backend'][p]=sha(files[p])
  assert after==expected,'UNRELATED_APP_CHANGE'
  for a,b in zip(before['frontends'],after['frontends']):
   if a['kind']!='admin'or a['root']!=root:assert a==b
   else:assert b['sha256']==build['assets']['system.html']and all(build['assets'][p.lstrip('/')]==h for p,h in b['assets'].items())
  save(site+'-after.json',after)
 except BaseException:
  ssh(site,'/www/server/php/74/bin/php '+directory+'/install.php '+directory+' rollback');restart(site);raise
 # Public transport failures leave the verified code in place; retry only the read-only checks.
 run([sys.executable,'scripts/r35-release-public.py',site])
 print('CODE AND PUBLIC ASSETS VERIFIED; browser verification required:',site,flush=True)

def finish(site):
 # Allows finishing verification after a read-only public network retry, without reinstalling files.
 info=read('commit.json');manifest=read(site+'-manifest.json');runtime=read(site+'-runtime-after.json');candidate=read(site+'-runtime-candidate.json');assert read(site+'-public.json')['result']=='PASS'and all(x['result']=='PASS'for x in read(site+'-public-browser.json'));assert all(runtime[k]==candidate[k]for k in ['baseStatuses','capabilities','protectedData','dimensionReadSHA256']);assert probe(site)==read(site+'-after.json')
 save(site+'-verified.json',{'site':site,'commit':info['commit'],'result':'PASS','filesChanged':len(manifest['targets']),'rollback':'/www/backups/'+site+'/r35-org-edit-'+info['commit'][:8],'backendFiles':3,'existingWriteStatusesPreserved':True,'organizationAndRoleDataUnchanged':True,'readOnlyTargetChecks':runtime['realTargetChecks'],'publicAssetsAndBrowserBoot':'PASS','otherTerminalsUnchanged':True,'verifiedAtUTC':datetime.datetime.now(datetime.timezone.utc).isoformat()});print('DEPLOY VERIFIED',site,flush=True)

def push():
 assert read('rh-verified.json')['result']=='PASS';c=read('commit.json')['commit'];run(['git','push','origin','refs/heads/'+BRANCH+':refs/heads/'+BRANCH]);assert g('ls-remote','origin','refs/heads/'+BRANCH).decode().split()[0]==c;save('push.json',{'commit':c,'remote':'origin','branch':BRANCH,'result':'PASS'});print('PUSH VERIFIED',c,flush=True)
if __name__=='__main__':
 mode=sys.argv[1]
 if mode=='commit':commit()
 elif mode=='preflight':preflight(sys.argv[2])
 elif mode=='deploy':deploy(sys.argv[2])
 elif mode=='finish':finish(sys.argv[2])
 elif mode=='push':push()
 else:raise RuntimeError('EXPLICIT_MODE_REQUIRED')
