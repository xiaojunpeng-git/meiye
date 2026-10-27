# coding: utf-8
"""R37-00002：两个业务文件的精准提交与平台静态包逐站CAS发布；不改配置或业务数据。"""
import subprocess,json,hashlib,tempfile,os,sys,io,tarfile,base64,datetime,importlib.util
from pathlib import Path
spec=importlib.util.spec_from_file_location('r37base','scripts/r37-release.py');core=importlib.util.module_from_spec(spec);spec.loader.exec_module(core)
ssh=core.ssh;probe=core.probe;restart=core.restart
E=Path('tests/cashier-v3/evidence/R37-card-labor-followup');R=E/'release';BRANCH='release/r37-card-labor-followup-20261027'
def sha(b):return hashlib.sha256(b).hexdigest()
def g(*a,**kw):return subprocess.check_output(['git',*a],**kw)
def read(n):return json.loads((R/n).read_text())
def save(n,v):(R/n).write_text(json.dumps(v,ensure_ascii=False,indent=2)+'\n')
def plan(s):p=read('trees.json');return p['plans'][p['siteVariants'][s]]
def smoke(s,mode):
 body=Path('scripts/r37-card-release-smoke.php').read_text()
 if mode=='candidate':body=body.replace('// Fixed candidate product service injected here, before autoload.',"eval(substr(base64_decode('"+base64.b64encode(g('show',plan(s)['candidateTree']+':'+plan(s)['backendPaths'][0])).decode()+"'),5));")
 temp='/tmp/r37-card-'+s+'-'+mode+'.php';ssh(s,'install -m 600 -o www -g www /dev/stdin '+temp,body.encode())
 try:out=ssh(s,"su -s /bin/sh www -c '/www/server/php/74/bin/php "+temp+' '+s+' '+mode+"'",t=120)
 except subprocess.CalledProcessError as err:print(err.stderr.decode()[-1500:],file=sys.stderr);raise
 finally:ssh(s,'rm -f '+temp)
 lines=[line for line in out.decode().splitlines()if line.startswith('{')];assert lines,out.decode();v=json.loads(lines[-1]);assert v['result']=='PASS';save(s+'-runtime-'+mode+'.json',v);return v

def preflight(s):
 assert probe(s)==read(s+'-before.json'),'LIVE_BASELINE_CHANGED';a=smoke(s,'before');b=smoke(s,'candidate');keys=['effectiveMode','configSHA256','relatedRows','relatedSHA256','responses'];assert all(a[k]==b[k]for k in keys),'ORIGINAL_DATA_OR_RESPONSE_CHANGED';save(s+'-preflight.json',{'result':'PASS','site':s,'originalResponsesAndFeesUnchanged':True,'configurationUnchanged':True,'readOnly':True});print('PREFLIGHT PASS',s,flush=True)

def builds(sites=('rh','008','010')):
 p=read('trees.json');proof=read('canonical-runtime-baseline.json');assert proof['result']=='PASS'
 for site in sites:
  variant=p['siteVariants'][site];new=read(variant+'/candidate-admin-build.json');assert new['result']=='PASS'and new['sourceStable']and new['tree']==plan(site)['candidateTree'];assert read(variant+'/candidate-functional.json')['result']=='PASS'
  if site!='010':
   live=read(site+'-before.json');front=next(f for f in live['frontends']if f['kind']=='admin'and f['root']==live['root']);assert proof['assets']['system.html']==front['sha256'];assert all(proof['assets'][n.lstrip('/')]==h for n,h in front['assets'].items())
  else:
   assert read('010-acceptance.json')['approved'] and read('010-acceptance.json')['candidateTree']==plan(site)['candidateTree'],'010_REACCEPTANCE_REQUIRED'

def commit():
 builds(('rh','008'));p=read('trees.json');f=json.loads((E/'fixed-files.json').read_text());assert all(sha(Path(n).read_bytes())==h for n,h in f['files'].items());assert all(read(s+'-preflight.json')['result']=='PASS'for s in ['rh','008']);shared=Path(g('rev-parse','--git-path','index').decode().strip());ih=sha(shared.read_bytes());head=g('rev-parse','HEAD');fd,idx=tempfile.mkstemp(prefix='r37-card-commit-');os.close(fd);os.unlink(idx);env=dict(os.environ,GIT_INDEX_FILE=idx)
 try:
  # 010同文件现网已更新，未补验前不创建该站业务提交；先完成原候选已验收的瑞昊/008。
  variant=None;g('read-tree',p['plans']['standard']['candidateTree'],env=env)
  extra=[str(x)for x in E.iterdir()if x.is_file()]+['tests/cashier-v3/js/r37-card-labor-display.mjs','tests/cashier-v3/php/r37-card-labor-display.php']+['scripts/'+n for n in ['r37-card-release.py','r37-card-release-prepare.py','r37-card-release-build.cjs','r37-card-release-smoke.php','r37-card-release-install.php','r37-card-release-public.py','r37-card-release-archive.py','r37-card-baseline-proof.py','r37-card-merge-010.py','r37-card-merged-build.cjs']]
  for n in extra:g('update-index','--add','--cacheinfo','100644',g('hash-object','-w','--stdin',input=Path(n).read_bytes()).decode().strip(),n,env=env)
  tree=g('write-tree',env=env).decode().strip();paths=g('diff','--name-only',p['baselineCommit'],tree).decode().splitlines();assert sorted(paths)==sorted(extra+p['paths']);g('diff','--check',p['baselineCommit'],tree,'--',*[n for n in paths if not n.endswith('.patch')]);assert g('rev-parse',tree+':前端代码/admin')==g('rev-parse',p['plans']['standard']['candidateTree']+':前端代码/admin')
  c=g('commit-tree',tree,'-p',p['baselineCommit'],'-p','c3e77bce39723e307377d089a22fedacebcc2972',input='R37-00002 平台卡内项目手工列复用算法显示配置\n\n产品经理测试验收通过。只补齐卡内项目手工列显隐，保留卡内金额、保存逻辑、各站既有平台功能与配置。\n'.encode()).decode().strip();g('update-ref','refs/heads/'+BRANCH,c,'0'*40);assert g('rev-parse','HEAD')==head and sha(shared.read_bytes())==ih;save('commit.json',{'commit':c,'branch':BRANCH,'tree':tree,'styledCommit':variant,'paths':paths,'sharedHEADAndIndexUntouched':True,'scope':'Only R37-00002 two-file patch, tests and evidence; preserve R19/R68 and 010 accepted public menu style','acceptance':'产品经理测试无问题，授权只提交本补漏，瑞昊部署验证后push再008、010'});print('COMMIT',c,flush=True)
 finally:Path(idx).unlink(missing_ok=True)

def deploy(s):
 builds((s,));info=read('commit.json');c=info['commit'];p=plan(s);variant=read('trees.json')['siteVariants'][s];b=read(variant+'/candidate-admin-build.json');baseline=read('canonical-runtime-baseline.json') if s!='010' else {'assets':{}};before=read(s+'-before.json');assert probe(s)==before,'LIVE_BASELINE_CHANGED';assert read(s+'-preflight.json')['result']=='PASS'
 if s=='008':assert read('rh-verified.json')['result']=='PASS'and read('push.json')['result']=='PASS'
 if s=='010':assert read('008-verified.json')['result']=='PASS'
 deployment_commit=read('010-source.json')['commit'] if s=='010' else c
 # 010使用补验后保留现网默认业绩的合并提交，禁止以瑞昊文件覆盖该站已上线功能。
 assert g('rev-parse',deployment_commit+':'+p['paths'][0])==g('rev-parse',p['candidateTree']+':'+p['paths'][0])
 root=before['root'];d='/www/backups/'+s+'/r37-card-labor-'+c[:8];backend=p['backendPaths'][0].removeprefix('后端代码/');files={backend:g('show',deployment_commit+':'+p['backendPaths'][0])}
 # 只发布候选相对完整基线变化的派生文件；不覆盖favicon、图片或客户独立公共资源。
 for n,h in b['assets'].items():
  # 010原包已更新，逐文件读取现网CAS，仅发布HTML与JS/CSS，不覆盖客户图片等公共资源。
  if s=='010' and not (n=='system.html' or (n.startswith('view_admin/') and n.endswith(('.js','.css')))):continue
  if baseline['assets'].get(n)==h:continue
  assert n=='system.html'or n.startswith('view_admin/'),n;data=(Path(b['output'])/n).read_bytes();assert sha(data)==h;files['public/'+n]=data
 encoded=base64.b64encode(json.dumps(list(files)).encode()).decode();php="<?php $r='"+root+"';$o=[];foreach(json_decode(base64_decode('"+encoded+"'),true)as$n)$o[$n]=is_file($r.'/'.$n)?hash_file('sha256',$r.'/'.$n):null;echo json_encode($o);";old=json.loads(ssh(s,'/www/server/php/74/bin/php',php.encode()));front=next(x for x in before['frontends']if x['kind']=='admin'and x['root']==root);assert old[front['entry']]==front['sha256']and old[backend]==before['backend'][backend];files={n:data for n,data in files.items()if old[n]!=sha(data)}
 manifest={'site':s,'commit':c,'deploymentCommit':deployment_commit,'sourceTree':p['candidateTree'],'targets':dict(sorted({n:{'before':old[n],'after':sha(data)}for n,data in files.items()}.items(),key=lambda x:(x[0]=='public/system.html',x[0]))),'scope':'R37-00002 only; no DB/config mutations; preserve 010 menu style, R68 link options and all other deployed functions'}
 payload=io.BytesIO()
 with tarfile.open(fileobj=payload,mode='w:gz')as tar:
  for n,data in [('payload/'+n,data)for n,data in files.items()]+[('payload/manifest.json',json.dumps(manifest).encode()),('install.php',Path('scripts/r37-card-release-install.php').read_bytes())]:item=tarfile.TarInfo(n);item.size=len(data);item.mode=0o600;tar.addfile(item,io.BytesIO(data))
 ssh(s,'test ! -e '+d+' && mkdir -p -m 700 '+d);ssh(s,'tar -xzf - -C '+d,payload.getvalue(),120);assert probe(s)==before;ssh(s,'/www/server/php/74/bin/php -l '+d+'/payload/'+backend);save(s+'-manifest.json',manifest)
 try:
  log=ssh(s,'/www/server/php/74/bin/php '+d+'/install.php '+d+' install');log+=restart(s);(R/(s+'-deployment.log')).write_bytes(log);runtime=smoke(s,'after');candidate=read(s+'-runtime-candidate.json');assert all(runtime[k]==candidate[k]for k in ['effectiveMode','configSHA256','relatedRows','relatedSHA256','responses','cases']),'RUNTIME_REGRESSION';actual=json.loads(ssh(s,'/www/server/php/74/bin/php',php.encode()));assert all(actual[n]==sha(data)for n,data in files.items());after=probe(s);expected=json.loads(json.dumps(before));expected['frontends']=after['frontends'];expected['backend'][backend]=sha(files[backend]);assert after==expected,'UNRELATED_SERVER_CHANGE'
  for a,z in zip(before['frontends'],after['frontends']):
   if a['kind']!='admin'or a['root']!=root:assert a==z
   else:assert z['sha256']==b['assets']['system.html']and all(b['assets'][n.lstrip('/')]==h for n,h in z['assets'].items())
  save(s+'-after.json',after)
 except BaseException:ssh(s,'/www/server/php/74/bin/php '+d+'/install.php '+d+' rollback');restart(s);raise
 subprocess.run([sys.executable,'scripts/r37-card-release-public.py',s],check=True);print('FILES/RUNTIME/PUBLIC PASS; browser proof required',s,flush=True)

def finish(s):
 info=read('commit.json');assert read(s+'-public.json')['result']=='PASS'and read(s+'-browser.json')['result']=='PASS';assert probe(s)==read(s+'-after.json');save(s+'-verified.json',{'site':s,'commit':info['commit'],'deploymentCommit':read('010-source.json')['commit'] if s=='010' else info['commit'],'result':'PASS','sourceTree':plan(s)['candidateTree'],'rollback':'/www/backups/'+s+'/r37-card-labor-'+info['commit'][:8],'existingFunctionsPreserved':True,'configurationAndCardFeesUnchanged':True,'businessWrites':0,'timeUTC':datetime.datetime.now(datetime.timezone.utc).isoformat()});print('DEPLOY VERIFIED',s,flush=True)
def push():
 i=read('commit.json');assert read('rh-verified.json')['result']=='PASS';g('push','origin','refs/heads/'+BRANCH+':refs/heads/'+BRANCH);assert g('ls-remote','origin','refs/heads/'+BRANCH).decode().split()[0]==i['commit'];save('push.json',{'commit':i['commit'],'result':'PASS'});print('PUSH PASS',flush=True)
if __name__=='__main__':
 mode=sys.argv[1]
 if mode=='preflight':preflight(sys.argv[2])
 elif mode=='builds':builds()
 elif mode=='commit':commit()
 elif mode=='deploy':deploy(sys.argv[2])
 elif mode=='finish':finish(sys.argv[2])
 elif mode=='push':push()
 else:raise RuntimeError('EXPLICIT_MODE_REQUIRED')
