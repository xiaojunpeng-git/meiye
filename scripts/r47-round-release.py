"""R47 ordered release: exact accepted tree, per-site backup/schema authorization, guarded rollback."""
from pathlib import Path
import subprocess,json,hashlib,base64,tarfile,io,sys,datetime
R=Path(__file__).resolve().parent.parent;E=R.parent/'任务管理/验收证据/R47-发布-2026-11-05'
S={'rh':'rh-server','008':'mohe-server','010':'mohe-server'};BRANCH='release/r47-card-project-20261105'
sha=lambda x:hashlib.sha256(x).hexdigest()
def read(n):return json.loads((E/n).read_text())
def save(n,v):(E/n).write_text(json.dumps(v,ensure_ascii=False,indent=2)+'\n')
def git(*a,**kw):return subprocess.check_output(['git',*a],cwd=R,**kw)
def ssh(s,cmd,data=None,timeout=100):
 x=subprocess.run(['ssh','-q','-o','BatchMode=yes','-o','ConnectTimeout=15',S[s],cmd],input=data,capture_output=True,timeout=timeout)
 if x.returncode:raise RuntimeError((x.stdout+x.stderr).decode(errors='replace')[-2500:])
 return x.stdout

def probe(s):return json.loads(ssh(s,'/www/server/php/74/bin/php -- '+s,(R/'scripts/r28-permissions-inspect.php').read_bytes()))
def check_acceptance():
 a=read('authorization.json');p=read('candidate.json');assert a['candidateTree']==p['candidateTree']and a['supplementAccepted']and a['migrationSites']==['rh','008','010'],'Specific acceptance and migration authorization required'
 for f,v in p['files'].items():
  q=R/f;assert(sha(q.read_bytes())if q.exists()else None)==v.get('sha256'),'Accepted shared file changed: '+f
 return p

def commit():
 p=check_acceptance();previous=read('commit.json')if(E/'commit.json').exists()else None;index=R/git('rev-parse','--git-path','index').decode().strip();ih=sha(index.read_bytes());head=git('rev-parse','HEAD').decode().strip()
 if previous:
  assert previous['tree']!=p['candidateTree'],'Already committed';parent=previous['commit']
 else:parent=git('commit-tree',p['baselineTree'],'-p',p['baselineCommit'],'-m','baseline(cashier): retain deployed R47 shared-source dependencies').decode().strip()
 c=git('commit-tree',p['candidateTree'],'-p',parent,'-m',('baseline(cashier): retain newly deployed member period filters for R47'if previous else'R47-00001 普通卡单一总价与门店购买项目自动核销开关')).decode().strip()
 if previous:save('commit-prior-'+previous['commit'][:8]+'.json',previous)
 git('update-ref','refs/heads/'+BRANCH,c,previous['commit']if previous else'0'*40);assert sha(index.read_bytes())==ih and git('rev-parse','HEAD').decode().strip()==head
 save('commit.json',{'commit':c,'tree':p['candidateTree'],'branch':BRANCH,'baselinePreservationCommit':parent,'paths':p['paths'],'sharedIndexUnchanged':True,'sharedHeadUnchanged':True});print('COMMIT',c,flush=True)

def prepare(s):
 p=check_acceptance();before=read(s+'-before.json');assert probe(s)==before,'Live baseline changed';c=read('commit.json');build=read('candidate-cashier-v3-build.json');assert build['tree']==c['tree']and build['result']=='PASS'and build['sourceStable'];data={};retired={}
 for f in p['paths']:
  if not f.startswith('后端代码/'):continue
  rel=f[len('后端代码/'):]
  try:data[rel]=git('show',c['commit']+':'+f,stderr=subprocess.DEVNULL)
  except subprocess.CalledProcessError:retired[rel]=before['backend'][rel]
 for f,h in build['assets'].items():
  b=(Path(build['output'])/f).read_bytes();assert sha(b)==h;data['public/view_cashier_v3/'+f]=b
 names=base64.b64encode(json.dumps(list(data)).encode()).decode();query="<?php $r='"+before['root']+"/';$a=[];foreach(json_decode(base64_decode('"+names+"'),true)as$p)$a[$p]=is_file($r.$p)?hash_file('sha256',$r.$p):null;echo json_encode($a);"
 old=json.loads(ssh(s,'/www/server/php/74/bin/php',query.encode()));data={f:b for f,b in data.items()if sha(b)!=old[f]};guards=dict(before['backend']);guards.update(before['protected'])
 for f in before['frontends']:
  if f['root']==before['root']:guards[f['entry']]=f['sha256'];guards.update({'public'+a:h for a,h in f['assets'].items()})
 for f in p['paths']:
  if f.startswith('后端代码/app/'):
   rel=f[len('后端代码/'):]
   try:expected=sha(git('show',p['baselineTree']+':'+f,stderr=subprocess.DEVNULL))
   except subprocess.CalledProcessError:expected=None
   assert before['backend'].get(rel)==(p['preserved'][f]['liveHashes'][s] if f in p['preserved'] else expected),'Deployed source mismatch: '+rel
 m={'site':s,'root':before['root'],'commit':c['commit'],'tree':c['tree'],'backup':'/www/backups/'+s+'/r47-card-project-'+c['commit'][:8],'guards':guards,'before':{f:old[f]for f in data}|retired,'targets':{f:sha(b)for f,b in data.items()},'retired':retired,'migrationAuthorized':True}
 save(s+'-manifest.json',m);print('PREPARED',s,len(data),'targets',flush=True)
 return m,data

def call(s,mode,m):
 user='www'if mode in ['migrate','verify']else'root'
 cmd='/www/server/php/74/bin/php '+m['backup']+'/install.php '+m['backup']+' '+mode
 if user=='www':cmd="su -s /bin/sh www -c '"+cmd+"'"
 return json.loads(ssh(s,cmd,timeout=120))
def restart(s):
 cmd='/etc/init.d/ruihao_swoole restart'if s=='rh'else'systemctl restart mohe-'+s+'-swoole.service && systemctl is-active --quiet mohe-'+s+'-swoole.service'
 (E/(s+'-restart.log')).write_bytes(ssh(s,cmd,timeout=120))
def public(s,m):
 b=ssh(s,"curl -fsS --max-time 30 'https://"+s+".cc3798.com/view_cashier_v3/'");assert sha(b)==m['targets']['public/view_cashier_v3/index.html'];checks=['index.html']
 build=read('candidate-cashier-v3-build.json')
 for f,h in build['assets'].items():
  if f.endswith(('.js','.css')):
   content=ssh(s,"curl -fsS --max-time 30 'https://"+s+'.cc3798.com/view_cashier_v3/'+f+"'");assert sha(content)==h,f;checks.append(f)
 if s=='rh':
  for path in ['/preview-8080/view_cashier_v3/','/store-v3/']:
   code=ssh(s,"curl -sS -o /dev/null -w '%{http_code}' --max-time 20 'https://rh.cc3798.com"+path+"'").decode();assert code=='410'
 return checks

def deploy(s):
 assert s in S
 if s=='008':assert read('rh-verified.json')['result']==read('push.json')['result']=='PASS'
 if s=='010':assert read('008-verified.json')['result']=='PASS'
 m,data=prepare(s);archive=io.BytesIO()
 with tarfile.open(fileobj=archive,mode='w:gz')as tar:
  for f,b in [('payload/'+f,b)for f,b in data.items()]+[('payload/manifest.json',json.dumps(m).encode()),('install.php',(R/'scripts/r47-round-release-install.php').read_bytes())]:
   t=tarfile.TarInfo(f);t.size=len(b);t.mode=0o600;tar.addfile(t,io.BytesIO(b))
 ssh(s,'umask 077; test ! -e '+m['backup']+' && mkdir -m 700 -p '+m['backup']);ssh(s,'tar -xzf - -C '+m['backup'],archive.getvalue());ssh(s,'chown -R www:www '+m['backup'])
 save(s+'-backup.json',call(s,'backup',m))
 if s=='010':save(s+'-schema.json',call(s,'schema',m))
 save(s+'-migration.json',call(s,'migrate',m))
 try:
  assert probe(s)['backend']==read(s+'-before.json')['backend'],'Concurrent backend changed before switch'
  save(s+'-install.json',call(s,'install',m));restart(s);save(s+'-retired.json',call(s,'delete-retired',m));runtime=call(s,'verify',m);checks=public(s,m)
  after=probe(s);before=read(s+'-before.json');expected=dict(before['backend'])
  for f,h in m['targets'].items():
   if f.startswith(('app/','config/','route/')):expected[f]=h
  for f in m['retired']:expected.pop(f,None)
  assert after['backend']==expected and after['protected']==before['protected']and after['channelRules']==before['channelRules'],'Unrelated source/config/permissions changed'
  for f in after['frontends']:
   if f['root']==m['root']and f['kind']=='cashier':assert f['sha256']==m['targets']['public/view_cashier_v3/index.html']
   else:assert f in before['frontends'],'Unrelated frontend changed'
  save(s+'-after.json',after);save(s+'-verified.json',{'site':s,'commit':m['commit'],'tree':m['tree'],'result':'PASS','runtime':runtime,'publicAssets':checks,'backup':m['backup'],'businessWrites':0,'otherTerminalsAndPermissionsUnchanged':True,'timeUTC':datetime.datetime.now(datetime.timezone.utc).isoformat()});print('DEPLOY VERIFIED',s,flush=True)
 except BaseException:
  try:save(s+'-rollback.json',call(s,'rollback',m));restart(s)
  except BaseException as ex:save(s+'-rollback-blocked.json',{'reason':str(ex)})
  raise

def push():
 assert read('rh-verified.json')['result']=='PASS';c=read('commit.json');assert git('rev-parse','refs/heads/'+BRANCH).decode().strip()==c['commit'];git('push','origin','refs/heads/'+BRANCH+':refs/heads/'+BRANCH);assert git('ls-remote','origin','refs/heads/'+BRANCH).decode().split()[0]==c['commit'];save('push.json',{'result':'PASS','commit':c['commit'],'branch':BRANCH});print('PUSH VERIFIED',flush=True)
if __name__=='__main__':
 mode=sys.argv[1]
 if mode=='commit':commit()
 elif mode=='deploy':deploy(sys.argv[2])
 elif mode=='push':push()
 elif mode=='prepare':prepare(sys.argv[2])
 else:raise RuntimeError('Explicit phase required')
