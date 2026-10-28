"""R39只发布已验收排行榜显示控制：现有提交链、独立索引、逐站备份，无后端/数据库/服务重启。"""
import json,pathlib,subprocess,hashlib,importlib.util,tempfile,os,io,tarfile,base64,urllib.request,urllib.error,datetime,sys
P=pathlib.Path;E=P('tests/report-group-dashboard/evidence/R39-rank-scroll-toggle');R=E/'release'
spec=importlib.util.spec_from_file_location('r37','scripts/r37-release.py');core=importlib.util.module_from_spec(spec);spec.loader.exec_module(core)
def g(*a,**kw):return subprocess.check_output(['git',*a],**kw)
def read(n):return json.loads((R/n).read_text())
def save(n,v):(R/n).write_text(json.dumps(v,ensure_ascii=False,indent=2)+'\n')
def sha(b):return hashlib.sha256(b).hexdigest()
def buildGuard():
 p=read('trees.json');old=read('baseline-cashier-v3-build.json');new=read('candidate-cashier-v3-build.json');assert old['result']==new['result']=='PASS' and new['sourceStable'] and old['sourceStable'] and new['tree']==p['candidateTree'] and old['tree']==p['baselineTree'];assert g('diff','--name-only',p['baselineTree'],p['candidateTree']).decode().splitlines()==p['paths']
 for site in ['rh','008','010']:
  v=read(site+'-before.json');f=next(x for x in v['frontends']if x['root']==v['root']and x['kind']=='cashier');assert f['sha256']==old['assets']['index.html'] and all(old['assets'][n.removeprefix('/view_cashier_v3/')]==h for n,h in f['assets'].items())
def commit():
 p=read('trees.json');buildGuard();f=json.loads((E/'fixed-version.json').read_text());assert sha(P(f['file']).read_bytes())==f['sha256'];head=g('rev-parse','HEAD').decode().strip();branch=g('symbolic-ref','HEAD').decode().strip();assert head==p['head'] and branch=='refs/heads/'+p['existingBranch'];shared=P(g('rev-parse','--git-path','index').decode().strip());indexHash=sha(shared.read_bytes());fd,idx=tempfile.mkstemp(prefix='r39-index-');os.close(fd);os.unlink(idx);env=dict(os.environ,GIT_INDEX_FILE=idx)
 try:
  g('read-tree',p['candidateTree'],env=env);extra=[str(x)for x in E.iterdir()if x.is_file()]+['scripts/'+x for x in ['r39-release-build.cjs','r39-release-install.php','r39-release.py']]
  for path in extra:g('update-index','--add','--cacheinfo','100644',g('hash-object','-w',path).decode().strip(),path,env=env)
  tree=g('write-tree',env=env).decode().strip();paths=g('diff','--name-only',p['baselineCommit'],tree).decode().splitlines();assert set(paths)==set(p['paths']+extra);g('diff','--check',p['baselineCommit'],tree,'--',*[x for x in paths if not x.endswith('.diff')]);c=g('commit-tree',tree,'-p',p['baselineCommit'],input='R39-00001 三个目标排行榜独立暂停与继续滚动\n\n默认滚动，从当前查看位置继续；仅页面显示控制。产品经理测试验收并授权三站按序发布。保留现网会员管理、工资报表、团员查询与库存等既有功能。\n'.encode()).decode().strip();g('update-ref',branch,c,head);assert sha(shared.read_bytes())==indexHash;save('commit.json',{'commit':c,'tree':tree,'branch':p['existingBranch'],'paths':paths,'businessPaths':p['paths'],'preservedBaseline':p['baselineCommit'],'newBranch':False,'sharedIndexUntouched':True});print('COMMIT',c,flush=True)
 finally:P(idx).unlink(missing_ok=True)
def public(site):
 checks=[]
 for path,value in read(site+'-manifest.json')['targets'].items():
  url='https://'+site+'.cc3798.com/'+path.removeprefix('public/');data=urllib.request.urlopen(url,timeout=30).read();assert sha(data)==value['after'];checks.append({'path':path,'sha256':value['after']})
 assert urllib.request.urlopen('https://'+site+'.cc3798.com/admin/login',timeout=30).status==200
 if site=='rh':
  for path in ['/preview-8080/view_cashier_v3/','/store-v3/']:
   try:urllib.request.urlopen('https://rh.cc3798.com'+path,timeout=20);raise RuntimeError('RETIRED_PATH_ACTIVE')
   except urllib.error.HTTPError as e:assert e.code==410
 save(site+'-public.json',{'result':'PASS','checks':checks})
def deploy(site):
 assert site in ['rh','008','010'];buildGuard();before=read(site+'-before.json');assert core.probe(site)==before,'LIVE_CHANGED'
 if site=='008':assert read('rh-verified.json')['result']=='PASS'and read('push.json')['result']=='PASS'
 if site=='010':assert read('008-verified.json')['result']=='PASS'
 info=read('commit.json');b=read('candidate-cashier-v3-build.json');files={}
 for name,h in b['assets'].items():
  if not(name=='index.html'or(name.startswith('assets/')and name.endswith(('.js','.css')))):continue
  data=(P(b['output'])/name).read_bytes();assert sha(data)==h;files['public/view_cashier_v3/'+name]=data
 encoded=base64.b64encode(json.dumps(list(files)).encode()).decode();php="<?php $root='"+before['root']+"';$a=[];foreach(json_decode(base64_decode('"+encoded+"'),true)as$p)$a[$p]=is_file($root.'/'.$p)?hash_file('sha256',$root.'/'.$p):null;echo json_encode($a);";old=json.loads(core.ssh(site,'/www/server/php/74/bin/php',php.encode()));files={p:v for p,v in files.items()if sha(v)!=old[p]};targets=dict(sorted({p:{'before':old[p],'after':sha(v)}for p,v in files.items()}.items(),key=lambda x:(x[0].endswith('index.html'),x[0])));backup='/www/backups/'+site+'/r39-rank-scroll-'+info['commit'][:8];manifest={'site':site,'commit':info['commit'],'sourceTree':read('trees.json')['candidateTree'],'targets':targets,'backup':backup,'backendChanges':False,'databaseChanges':False};payload=io.BytesIO()
 with tarfile.open(fileobj=payload,mode='w:gz')as tar:
  for path,data in [('payload/'+p,v)for p,v in files.items()]+[('payload/manifest.json',json.dumps(manifest).encode()),('install.php',P('scripts/r39-release-install.php').read_bytes())]:entry=tarfile.TarInfo(path);entry.mode=0o600;entry.size=len(data);tar.addfile(entry,io.BytesIO(data))
 core.ssh(site,'test ! -e '+backup+' && mkdir -m 700 -p '+backup);core.ssh(site,'tar -xzf - -C '+backup,payload.getvalue(),120);assert core.probe(site)==before,'LIVE_CHANGED';save(site+'-manifest.json',manifest)
 try:
  log=core.ssh(site,'/www/server/php/74/bin/php '+backup+'/install.php '+backup+' install');(R/(site+'-install.log')).write_bytes(log);after=core.probe(site);expected=json.loads(json.dumps(before))
  for n,f in enumerate(after['frontends']):
   if f['kind']=='cashier'and f['root']==before['root']:expected['frontends'][n]=f;assert f['sha256']==b['assets']['index.html']and all(b['assets'][p.removeprefix('/view_cashier_v3/')]==h for p,h in f['assets'].items())
  assert after==expected,'UNRELATED_CHANGE';save(site+'-after.json',after);public(site);print('DEPLOY/PROTECTED/PUBLIC PASS',site,flush=True)
 except BaseException:core.ssh(site,'/www/server/php/74/bin/php '+backup+'/install.php '+backup+' rollback');raise

def finish(site):
 assert read(site+'-public.json')['result']=='PASS'and read(site+'-browser.json')['result']=='PASS';assert core.probe(site)==read(site+'-after.json'),'LIVE_CHANGED';save(site+'-verified.json',{'result':'PASS','site':site,'sourceCommit':read('commit.json')['commit'],'rollback':read(site+'-manifest.json')['backup'],'backendAndConfigurationUnchanged':True,'databaseWrites':0,'timeUTC':datetime.datetime.now(datetime.timezone.utc).isoformat()});print('VERIFIED',site,flush=True)
def push():
 i=read('commit.json');assert read('rh-verified.json')['result']=='PASS';branch='refs/heads/'+i['branch'];assert g('rev-parse',branch).decode().strip()==i['commit'];g('push','origin',branch+':'+branch);assert g('ls-remote','origin',branch).decode().split()[0]==i['commit'];save('push.json',{'result':'PASS','commit':i['commit'],'branch':i['branch'],'forcePush':False});print('PUSH PASS',flush=True)
if __name__=='__main__':
 mode=sys.argv[1]
 if mode=='commit':commit()
 elif mode=='deploy':deploy(sys.argv[2])
 elif mode=='finish':finish(sys.argv[2])
 elif mode=='push':push()
 else:raise RuntimeError('EXPLICIT_MODE_REQUIRED')
