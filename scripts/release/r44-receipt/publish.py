"""仅发布已补验的统一小票候选；逐站备份/CAS/公网核验，禁止数据库写入及其他终端更新。"""
from pathlib import Path
import subprocess,json,hashlib,os,tempfile,importlib.util,sys,io,tarfile,base64,gzip,urllib.request,datetime
ROOT=Path(__file__).resolve().parents[3];os.chdir(ROOT)
E=ROOT.parent/'任务管理/验收证据/R44-收银订单统一小票-2026-11-04';R=E/'release';BRANCH='release/r44-unified-receipt-20261105';NODE='/Users/xiaojunpeng/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node'
spec=importlib.util.spec_from_file_location('transport',ROOT/'scripts/r37-release.py');transport=importlib.util.module_from_spec(spec);spec.loader.exec_module(transport)
def git(*a,**kw):return subprocess.check_output(['git',*a],**kw)
def sha(b):return hashlib.sha256(b).hexdigest()
def read(p):return json.loads((R/p).read_text())
def save(p,v):(R/p).write_text(json.dumps(v,ensure_ascii=False,indent=2)+'\n')
def ssh(site,command,data=None):return transport.ssh(site,command,data,90)
def probe(site):return json.loads(ssh(site,'/www/server/php/74/bin/php -- '+site,Path('scripts/store-address-release-probe.php').read_bytes()))
def guard():
 t=read('trees.json');assert t['candidateTree']=='350770d3ef604716340243fde161e2cef7aabce4';f=json.loads((E/'fixed-version.json').read_text());assert all(sha(Path(p).read_bytes())==h for p,h in f['files'].items()),'Accepted files changed'
 b=read('baseline-cashier-v3-build.json');c=read('candidate-cashier-v3-build.json');assert b['result']==c['result']=='PASS'and b['sourceStable']and c['sourceStable'];assert c['tree']==t['candidateTree']
 for s in ['rh','008','010']:
  x=read(s+'-before.json')['front']['cashier'];assert x['index']==b['assets']['index.html']and all(b['assets'][p.removeprefix('/view_cashier_v3/')]==h for p,h in x['assets'].items())
 return t,c
def commit():
 t,c=guard();assert git('rev-parse','HEAD').decode().strip()==t['head'];idx=Path(git('rev-parse','--git-path','index').decode().strip());before=sha(idx.read_bytes())
 # 前置提交仅将已上线前端合回当前正本；本轮另一个提交，始终不切换共享工作区或复用其暂存区。
 base=git('commit-tree',t['baselineTree'],'-p',t['head'],'-p',t['publishedSource'],input='R44-BASE 保留三站已上线赠送项目与小票前端基线\n'.encode()).decode().strip()
 with tempfile.TemporaryDirectory(prefix='r44-commit-')as d:
  env={**os.environ,'GIT_INDEX_FILE':d+'/index'};git('read-tree',t['candidateTree'],env=env)
  extra=['scripts/release/r44-receipt/'+p for p in ['build.cjs','prepare.py','install.php','publish.py']]
  for p in extra:git('update-index','--add','--cacheinfo','100644',git('hash-object','-w',p).decode().strip(),p,env=env)
  tree=git('write-tree',env=env).decode().strip();changes=git('diff','--name-only',t['baselineTree'],tree).decode().splitlines();assert set(changes)==set(t['businessFiles']+extra)
  commit=git('commit-tree',tree,'-p',base,input='R44-00001 收银完成与全部订单统一已落账小票\n\n统一只读订单查询与渲染器，移除购物车打印副本；保留既有排版、签字和纸宽。产品经理验收固定候选并授权三站按序发布，无数据库或支付配置变更。\n'.encode()).decode().strip();git('update-ref','refs/heads/'+BRANCH,commit,'0'*40)
 assert sha(idx.read_bytes())==before and git('rev-parse','HEAD').decode().strip()==t['head'];save('commit.json',{'commit':commit,'tree':tree,'baseCommit':base,'candidateTree':t['candidateTree'],'branch':BRANCH,'paths':changes,'sharedHeadAndIndexUntouched':True});print('COMMIT',commit,flush=True)
def public(site):
 m=read(site+'-manifest.json');rows=[]
 for p,v in m['targets'].items():
  if p.endswith(('.gz','.br')):continue
  url='https://'+site+'.cc3798.com/'+p.removeprefix('public/');req=urllib.request.Request(url,headers={'Accept-Encoding':'identity','Cache-Control':'no-cache'});data=urllib.request.urlopen(req,timeout=30).read();assert sha(data)==v['after'],p;rows.append({'path':p,'sha256':v['after']})
 save(site+'-public.json',{'result':'PASS','files':rows});return rows
def deploy(site):
 assert site in ['rh','008','010'];t,c=guard();info=read('commit.json');before=read(site+'-before.json');assert probe(site)==before,'Live baseline changed'
 if site=='008':assert read('rh-verified.json')['result']=='PASS'and read('push.json')['result']=='PASS'
 if site=='010':assert read('008-verified.json')['result']=='PASS'
 files={}
 for p,h in c['assets'].items():
  if p=='index.html'or(p.startswith('assets/')and p.endswith(('.js','.css'))):
   data=(Path(c['output'])/p).read_bytes();assert sha(data)==h;key='public/view_cashier_v3/'+p;files[key]=data
   if p=='index.html':
    files[key+'.gz']=gzip.compress(data,mtime=0);files[key+'.br']=subprocess.check_output([NODE,'-e',"const z=require('node:zlib');process.stdin.on('data',b=>process.stdout.write(z.brotliCompressSync(b)))"],input=data)
 encoded=base64.b64encode(json.dumps(list(files)).encode()).decode();root='/www/wwwroot/'+site+'.cc3798.com'
 php=("<?php $r='"+root+"';$x=[];foreach(json_decode(base64_decode('"+encoded+"'),true)as$p)$x[$p]=is_file($r.'/'.$p)?hash_file('sha256',$r.'/'.$p):null;echo json_encode($x);").encode();old=json.loads(ssh(site,'/www/server/php/74/bin/php',php));files={p:b for p,b in files.items()if sha(b)!=old[p]}
 targets=dict(sorted({p:{'before':old[p],'after':sha(b)}for p,b in files.items()}.items(),key=lambda x:('index.html'in x[0],x[0])))
 protected={'.env':before['environment']}
 for kind,p in {'member':'public/index.html','admin':'public/system.html','pc':'public/static/html/pc.html'}.items():protected[p]=before['front'][kind]['index']
 backup='/www/backups/'+site+'/r44-unified-receipt-'+info['commit'][:8];m={'site':site,'commit':info['commit'],'sourceTree':t['candidateTree'],'targets':targets,'protected':protected,'backup':backup,'databaseChanges':False};raw=json.dumps(m,ensure_ascii=False).encode();mh=sha(raw);payload=io.BytesIO()
 with tarfile.open(fileobj=payload,mode='w:gz')as tar:
  for p,b in [('payload/'+p,b)for p,b in files.items()]+[('payload/manifest.json',raw),('install.php',Path('scripts/release/r44-receipt/install.php').read_bytes())]:
   item=tarfile.TarInfo(p);item.size=len(b);item.mode=0o600;tar.addfile(item,io.BytesIO(b))
 save(site+'-manifest.json',m);(R/(site+'-payload.tar.gz')).write_bytes(payload.getvalue());ssh(site,'test ! -e '+backup+' && mkdir -p -m 700 '+backup);ssh(site,'tar -xzf - -C '+backup,payload.getvalue());assert probe(site)==before,'Baseline changed during upload';ssh(site,'/www/server/php/74/bin/php -l '+backup+'/install.php')
 command='/www/server/php/74/bin/php '+backup+'/install.php '+backup
 try:
  (R/(site+'-install.log')).write_bytes(ssh(site,command+' install '+mh));after=probe(site);expected=json.loads(json.dumps(before));expected['front']['cashier']=after['front']['cashier'];assert after==expected,'Unrelated entry/config/migration changed';assert after['front']['cashier']['index']==c['assets']['index.html'];assert all(c['assets'][p.removeprefix('/view_cashier_v3/')]==h for p,h in after['front']['cashier']['assets'].items());save(site+'-after.json',after);public(site)
 except BaseException:
  (R/(site+'-rollback.log')).write_bytes(ssh(site,command+' rollback '+mh));raise
 print('DEPLOY/PUBLIC/PROTECTED PASS',site,flush=True)
def finish(site):
 assert read(site+'-public.json')['result']=='PASS'and read(site+'-browser.json')['result']=='PASS';assert probe(site)==read(site+'-after.json');save(site+'-verified.json',{'result':'PASS','site':site,'commit':read('commit.json')['commit'],'rollback':read(site+'-manifest.json')['backup'],'databaseWrites':0,'configurationUnchanged':True,'timeUTC':datetime.datetime.now(datetime.timezone.utc).isoformat()});print('VERIFIED',site,flush=True)
def push():
 x=read('commit.json');assert read('rh-verified.json')['result']=='PASS';ref='refs/heads/'+BRANCH;git('push','origin',ref+':'+ref);assert git('ls-remote','origin',ref).decode().split()[0]==x['commit'];save('push.json',{'result':'PASS','commit':x['commit'],'branch':BRANCH});print('PUSH PASS',flush=True)
if __name__=='__main__':
 mode=sys.argv[1]
 if mode=='commit':commit()
 elif mode=='deploy':deploy(sys.argv[2])
 elif mode=='finish':finish(sys.argv[2])
 elif mode=='push':push()
 elif mode=='public':public(sys.argv[2])
 else:raise RuntimeError('Explicit mode required')
