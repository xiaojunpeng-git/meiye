# coding: utf-8
"""R37 精准提交/逐站发布。保留现网已验收基线，只更新4个PHP文件、显示配置与门店静态资源。"""
import subprocess,json,hashlib,tempfile,os,sys,io,tarfile,base64,datetime
from pathlib import Path
E=Path('tests/cashier-v3/evidence/R37-labor-fee-display');R=E/'release';BRANCH='release/r37-labor-fee-display-20261027'
def sha(b):return hashlib.sha256(b).hexdigest()
def g(*a,**kw):return subprocess.check_output(['git',*a],**kw)
def read(n):return json.loads((R/n).read_text())
def save(n,v):(R/n).write_text(json.dumps(v,ensure_ascii=False,indent=2)+'\n')
def ssh(s,cmd,b=None,t=90):return subprocess.check_output(['ssh','-q','-o','BatchMode=yes','-o','ControlMaster=no','-o','ControlPath=none','-o','ConnectTimeout=10','-o','ServerAliveInterval=10','-o','ServerAliveCountMax=2','rh-server'if s=='rh'else'mohe-server',cmd],input=b,stderr=subprocess.PIPE,timeout=t)
def probe(s):
 for attempt in range(3):
  try:return json.loads(ssh(s,'/www/server/php/74/bin/php -- '+s,Path('scripts/r28-permissions-inspect.php').read_bytes(),65))
  except(subprocess.CalledProcessError,subprocess.TimeoutExpired):
   if attempt==2:raise

def smoke(s,mode):
 body=Path('scripts/r37-release-smoke.php').read_text()
 if mode=='candidate':
  p=read('trees.json')['sites'][s];inject='\n'.join("eval(substr(base64_decode('"+base64.b64encode(g('show',p['candidateTree']+':'+n)).decode()+"'),5));"for n in p['backendPaths']);body=body.replace('// Inject fixed candidate Git classes here, before application autoload.',inject)
 temp='/tmp/r37-labor-'+s+'-'+mode+'.php';ssh(s,'install -m 600 -o www -g www /dev/stdin '+temp,body.encode())
 try:out=ssh(s,"su -s /bin/sh www -c '/www/server/php/74/bin/php "+temp+' '+s+' '+mode+"'",t=120)
 except subprocess.CalledProcessError as err:
  print(err.stderr.decode()[-2000:],file=sys.stderr);raise
 finally:ssh(s,'rm -f '+temp)
 lines=[x for x in out.decode().splitlines()if x.startswith('{')];assert lines,out.decode();v=json.loads(lines[-1]);assert v['result']=='PASS';save(s+'-runtime-'+mode+'.json',v);return v

def preflight(s):
 assert probe(s)==read(s+'-before.json'),'LIVE_BASELINE_CHANGED'
 a=smoke(s,'before');b=smoke(s,'candidate');assert all(a[k]==b[k]for k in ['existingFormSHA256','otherConfigSHA256','config','effective']),'EXISTING_CONFIGURATION_CHANGED'
 p=read('trees.json')['sites'][s];old=read(s+'/baseline-cashier-v3-build.json');new=read(s+'/candidate-cashier-v3-build.json');live=read(s+'-before.json');front=next(x for x in live['frontends']if x['kind']=='cashier'and x['root']==live['root'])
 assert old['result']=='PASS'and new['result']=='PASS'and new['tree']==p['candidateTree']and new['sourceStable'];assert old['assets']['index.html']==front['sha256']and all(old['assets'][n.removeprefix('/view_cashier_v3/')]==h for n,h in front['assets'].items());assert read(s+'/candidate-functional.json')['result']=='PASS'
 save(s+'-preflight.json',{'site':s,'result':'PASS','existingFormUnchanged':True,'existingConfigsUnchanged':True,'baselineReproducesLive':True,'candidatePayloadParity':True});print('PREFLIGHT PASS',s,flush=True)

def commit():
 p=read('trees.json');f=json.loads((E/'fixed-files.json').read_text());assert all(sha(Path(n).read_bytes())==h for n,h in f['files'].items());assert all(read(s+'-preflight.json')['result']=='PASS'for s in ['rh','008','010']);shared=Path(g('rev-parse','--git-path','index').decode().strip());ih=sha(shared.read_bytes());head=g('rev-parse','HEAD');fd,idx=tempfile.mkstemp(prefix='r37-commit-');os.close(fd);os.unlink(idx);env=dict(os.environ,GIT_INDEX_FILE=idx)
 try:
  variants={}
  for s in ['008','010']:
   a=p['sites'][s];base=g('commit-tree',a['baselineTree'],'-p',p['baselineCommit'],input=('R37 '+s+'既有已验收基线保留\n').encode()).decode().strip();variants[s]=g('commit-tree',a['candidateTree'],'-p',base,input=('R37-00001 '+s+'同一显示补丁固定构建树\n').encode()).decode().strip()
  g('read-tree',p['sites']['rh']['candidateTree'],env=env)
  extra=[str(x)for x in E.iterdir()if x.is_file()]+['tests/cashier-v3/js/r37-labor-fee-display.mjs','tests/cashier-v3/php/r37-labor-fee-display.php']+['scripts/'+n for n in ['r37-release.py','r37-release-prepare.py','r37-release-build.cjs','r37-release-install.php','r37-release-smoke.php','r37-config-migrate.php','r37-release-public.py','r37-release-archive.py']]
  for n in extra:g('update-index','--add','--cacheinfo','100644',g('hash-object','-w','--stdin',input=Path(n).read_bytes()).decode().strip(),n,env=env)
  tree=g('write-tree',env=env).decode().strip();paths=g('diff','--name-only',p['baselineCommit'],tree).decode().splitlines();assert sorted(paths)==sorted(extra+p['paths']);g('diff','--check',p['baselineCommit'],tree,'--',*[n for n in paths if not n.endswith('.patch')]);assert g('rev-parse',tree+':前端代码/cashier-v3')==g('rev-parse',p['sites']['rh']['candidateTree']+':前端代码/cashier-v3')
  c=g('commit-tree',tree,'-p',p['baselineCommit'],'-p',variants['008'],'-p',variants['010'],input='R37-00001 手工费算法选项控制收银与订单显隐\n\n产品经理验收通过。可修改保持现状，计算法仅隐藏指定字段，不改变手工费金额、计算口径或唯一业务快照。只应用本轮补丁，保留各站既有领用与报表功能。\n'.encode()).decode().strip();g('update-ref','refs/heads/'+BRANCH,c,'0'*40);assert g('rev-parse','HEAD')==head and sha(shared.read_bytes())==ih;save('commit.json',{'commit':c,'branch':BRANCH,'tree':tree,'variants':variants,'paths':paths,'sharedHEADAndIndexUntouched':True,'acceptance':'2026-10-27 产品经理测试无问题并明确授权commit、瑞昊部署、push、008及010部署'});print('COMMIT',c,flush=True)
 finally:Path(idx).unlink(missing_ok=True)

def restart(s):return ssh(s,'/etc/init.d/ruihao_swoole restart'if s=='rh'else'systemctl restart mohe-'+s+'-swoole.service && systemctl is-active mohe-'+s+'-swoole.service',t=90)
def config(s,mode,d):
 out=ssh(s,"su -s /bin/sh www -c '/www/server/php/74/bin/php "+d+'/config.php '+s+' '+mode+' '+d+"'",t=90);lines=[x for x in out.decode().splitlines()if x.startswith('{')];assert lines,out.decode();v=json.loads(lines[-1]);assert v['result']=='PASS';save(s+'-config-'+mode+'.json',v);return v

def deploy(s):
 assert s in ['rh','008','010'];info=read('commit.json');c=info['commit'];before=read(s+'-before.json');p=read('trees.json')['sites'][s];b=read(s+'/candidate-cashier-v3-build.json');assert probe(s)==before,'LIVE_BASELINE_CHANGED';assert read(s+'-preflight.json')['result']=='PASS'
 if s=='008':assert read('rh-verified.json')['result']=='PASS'and read('push.json')['result']=='PASS'
 if s=='010':assert read('008-verified.json')['result']=='PASS'
 root=before['root'];d='/www/backups/'+s+'/r37-labor-display-'+c[:8];files={n.removeprefix('后端代码/'):g('show',c+':'+n)for n in p['backendPaths']+[p['migration']]}
 for n,h in b['assets'].items():
  data=(Path(b['output'])/n).read_bytes();assert sha(data)==h;files['public/view_cashier_v3/'+n]=data
 encoded=base64.b64encode(json.dumps(list(files)).encode()).decode();php="<?php $r='"+root+"';$o=[];foreach(json_decode(base64_decode('"+encoded+"'),true)as$n)$o[$n]=is_file($r.'/'.$n)?hash_file('sha256',$r.'/'.$n):null;echo json_encode($o);"
 old=json.loads(ssh(s,'/www/server/php/74/bin/php',php.encode()));front=next(x for x in before['frontends']if x['kind']=='cashier'and x['root']==root);assert old[front['entry']]==front['sha256']
 for n in p['backendPaths']:assert old[n.removeprefix('后端代码/')]==before['backend'][n.removeprefix('后端代码/')]
 files={n:data for n,data in files.items()if old[n]!=sha(data)};manifest={'site':s,'commit':c,'sourceTree':p['candidateTree'],'baselineTree':p['baselineTree'],'targets':dict(sorted({n:{'before':old[n],'after':sha(data)}for n,data in files.items()}.items(),key=lambda x:(x[0].endswith('index.html'),x[0]))),'scope':'R37 explicit display fields only; preserve all previous site features; add config default0 without changing existing config values'}
 payload=io.BytesIO()
 with tarfile.open(fileobj=payload,mode='w:gz')as tar:
  entries=[('payload/'+n,data)for n,data in files.items()]+[('payload/manifest.json',json.dumps(manifest).encode()),('install.php',Path('scripts/r37-release-install.php').read_bytes()),('config.php',Path('scripts/r37-config-migrate.php').read_bytes()),('upgrade.sql',g('show',c+':'+p['migration']))]
  for n,data in entries:
   item=tarfile.TarInfo(n);item.size=len(data);item.mode=0o600;tar.addfile(item,io.BytesIO(data))
 ssh(s,'test ! -e '+d+' && mkdir -p -m 700 '+d);ssh(s,'tar -xzf - -C '+d,payload.getvalue(),120);assert probe(s)==before;save(s+'-manifest.json',manifest)
 for n in p['backendPaths']:ssh(s,'/www/server/php/74/bin/php -l '+d+'/payload/'+n.removeprefix('后端代码/'))
 ssh(s,'chown -R www:www '+d)
 try:
  log=ssh(s,'/www/server/php/74/bin/php '+d+'/install.php '+d+' install');config(s,'apply',d);log+=restart(s);(R/(s+'-deployment.log')).write_bytes(log);runtime=smoke(s,'after');candidate=read(s+'-runtime-candidate.json');assert all(runtime[k]==candidate[k]for k in ['existingFormSHA256','otherConfigSHA256','rootVisibilityCases']),'RUNTIME_REGRESSION';config(s,'verify',d)
  actual=json.loads(ssh(s,'/www/server/php/74/bin/php',php.encode()));assert all(actual[n]==sha(data)for n,data in files.items());after=probe(s);expected=json.loads(json.dumps(before));expected['frontends']=after['frontends']
  for n in p['backendPaths']:expected['backend'][n.removeprefix('后端代码/')]=sha(files[n.removeprefix('后端代码/')])
  assert after==expected,'UNRELATED_SERVER_CHANGE'
  for a,z in zip(before['frontends'],after['frontends']):
   if a['kind']!='cashier'or a['root']!=root:assert a==z
   else:assert z['sha256']==b['assets']['index.html']and all(b['assets'][n.removeprefix('/view_cashier_v3/')]==h for n,h in z['assets'].items())
  save(s+'-after.json',after)
 except BaseException:
  ssh(s,'/www/server/php/74/bin/php '+d+'/install.php '+d+' rollback');config(s,'rollback',d);restart(s);raise
 subprocess.run([sys.executable,'scripts/r37-release-public.py',s],check=True);print('DEPLOY FILES/RUNTIME/PUBLIC PASS; browser verification required',s,flush=True)

def finish(s):
 info=read('commit.json');assert read(s+'-public.json')['result']=='PASS'and read(s+'-browser.json')['result']=='PASS';assert probe(s)==read(s+'-after.json');save(s+'-verified.json',{'site':s,'commit':info['commit'],'sourceTree':read('trees.json')['sites'][s]['candidateTree'],'result':'PASS','rollback':'/www/backups/'+s+'/r37-labor-display-'+info['commit'][:8],'existingFeaturesPreserved':True,'otherConfigurationsUnchanged':True,'financialWrites':0,'verifiedAtUTC':datetime.datetime.now(datetime.timezone.utc).isoformat()});print('DEPLOY VERIFIED',s,flush=True)
def push():
 info=read('commit.json');assert read('rh-verified.json')['result']=='PASS';g('push','origin','refs/heads/'+BRANCH+':refs/heads/'+BRANCH);assert g('ls-remote','origin','refs/heads/'+BRANCH).decode().split()[0]==info['commit'];save('push.json',{'commit':info['commit'],'branch':BRANCH,'result':'PASS'});print('PUSH VERIFIED',flush=True)
if __name__=='__main__':
 mode=sys.argv[1]
 if mode=='preflight':preflight(sys.argv[2])
 elif mode=='commit':commit()
 elif mode=='deploy':deploy(sys.argv[2])
 elif mode=='finish':finish(sys.argv[2])
 elif mode=='push':push()
 else:raise RuntimeError('EXPLICIT_MODE_REQUIRED')
