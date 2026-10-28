"""Accepted search-only release; strict named-site order, immutable inputs and per-instance rollback."""
import pathlib,subprocess,json,hashlib,gzip,base64,shlex,tarfile,io,sys
ROOT=pathlib.Path(__file__).resolve().parents[1];R=ROOT/'tests/combination-member-search/release';E=R.parent/'evidence'
HOST={'rh':'rh-server','008':'mohe-server','010':'mohe-server'};PHP='app/services/activity/combination/CombinationGroupListServices.php'
def digest(b):return hashlib.sha256(b).hexdigest()
def read(f):return json.loads((R/f).read_text())
def save(f,x):(R/f).write_text(json.dumps(x,ensure_ascii=False,indent=2)+'\n')
def run(a,**kw):return subprocess.check_output(a,cwd=ROOT,stderr=subprocess.PIPE,**kw)
def git(*a,body=None):return run(['git',*a],input=body)
def ssh(site,cmd,body=None):return run(['ssh','-q','-o','BatchMode=yes','-o','ConnectTimeout=15','-o','ServerAliveInterval=10',HOST[site],cmd],input=body,timeout=180)
def probe(site):return json.loads(gzip.decompress(ssh(site,'set -o pipefail; /www/server/php/74/bin/php -- '+site+' snapshot | gzip -c',(ROOT/'scripts/combination-group-release-probe.php').read_bytes())))
def stable(x):
 # Business rows may change while a deployment is prepared. Only executable/config/schema baselines are fixed.
 return {k:x[k]for k in ['backend','entries','admin_assets','admin_refs','environment','migration']}|{'schema':{k:v.get('ddl_sha256')for k,v in x['tables'].items()}}
def guard():
 f=json.loads((E/'fixed.json').read_text());assert read('acceptance.json')['acceptedPatch']==f['patchSHA256']
 assert git('rev-parse','HEAD').decode().strip()==f['baselineHead'];assert digest((ROOT/'.git/index').read_bytes())==json.loads((E/'baseline.json').read_text())['sharedIndexSHA256']
 for p,h in f['files'].items():assert digest((ROOT/p).read_bytes())==h,p
 return f
def prepare():
 f=guard();b=read('candidate-admin-build.json');assert b['result']=='PASS'and b['sourceStable']and b['tree']==f['sourceTree']
 for p,h in b['assets'].items():assert digest(pathlib.Path(b['output'],p).read_bytes())==h
 controls=['scripts/combination-member-search-publish.py','scripts/combination-member-search-runtime.php','scripts/combination-group-release-install.php','scripts/combination-group-release-probe.php']
 for site in HOST:
  before=read(site+'-before.json');assert stable(probe(site))==stable(before),'Live source advanced '+site
  targets={};data={}
  def add(p,raw,old):
   if old==digest(raw):return
   targets['formal/'+p]={'before':old,'after':digest(raw)};data['formal/'+p]=raw
  add(PHP,git('show',f['sourceTree']+':后端代码/'+PHP),before['backend'][PHP])
  for p,h in b['assets'].items():
   if p=='system.html'or p.startswith('view_admin/'):add('public/'+p,pathlib.Path(b['output'],p).read_bytes(),before['entries'].get('public/system.html')if p=='system.html'else before['admin_assets'].get(p))
  entry='formal/public/system.html'
  if entry in targets:row=targets.pop(entry);targets[entry]=row
  manifest={'site':site,'commit':read('commit.json')['commit'],'previewAuthorized':False,'backendAllowlist':[PHP],'targets':targets}
  raw=json.dumps(manifest,ensure_ascii=False).encode();archive=R/(site+'-payload.tar.gz')
  with tarfile.open(archive,'w:gz')as tar:
   for p,body in [('manifest.json',raw)]+list(data.items()):
    i=tarfile.TarInfo('payload/'+p);i.size=len(body);i.mode=0o600;tar.addfile(i,io.BytesIO(body))
  archive.chmod(0o600);save(site+'-manifest.json',manifest);save(site+'-package.json',{'manifestSHA256':digest(raw),'sha256':digest(archive.read_bytes()),'controls':{p:digest((ROOT/p).read_bytes())for p in controls}});print(site,'PREPARED',len(targets),flush=True)
def restart(site):
 (R/(site+'-restart.log')).write_bytes(ssh(site,'/etc/init.d/ruihao_swoole restart'if site=='rh'else'systemctl restart mohe-'+site+'-swoole.service'))
 ssh(site,"ss -lnt | grep -q ':20800 '"if site=='rh'else'systemctl is-active --quiet mohe-'+site+'-swoole.service')
def public(site):
 import re,concurrent.futures
 base='https://'+('rh.cc3798.com'if site=='rh'else site+'.cc3798.com');b=read('candidate-admin-build.json')
 html=run(['curl','-fsS','--max-time','30',base+'/admin/login']);refs=[x.decode()for x in re.findall(rb'(?:src|href)=["\x27]?([^"\x27\s>]+\.(?:js|css))',html)if x.startswith(b'/view_admin/')];assert len(refs)>=4
 def check(ref):assert digest(run(['curl','-fsS','--max-time','30',base+ref]))==b['assets'][ref.lstrip('/')]
 with concurrent.futures.ThreadPoolExecutor(max_workers=4)as pool:list(pool.map(check,refs))
 if site=='rh':
  for p in ['/preview-8080/view_cashier_v3/','/store-v3/']:assert run(['curl','-sS','-o','/dev/null','-w','%{http_code}','--max-time','20',base+p]).decode()=='410'
 return {'result':'PASS','checkedRefs':refs,'retiredRoutesPreserved':site=='rh'}
def deploy(site):
 guard();c=read('commit.json');assert site in HOST
 if site!='rh':assert read('rh-verified.json')['result']=='PASS'and read('push.json')['result']=='PASS'
 if site=='010':assert read('008-verified.json')['result']=='PASS'
 before=read(site+'-before.json');assert stable(probe(site))==stable(before),'Live source changed'
 p=read(site+'-package.json');m=read(site+'-manifest.json')
 for f,h in p['controls'].items():assert digest((ROOT/f).read_bytes())==h,'Control drift'
 archive=R/(site+'-payload.tar.gz');assert digest(archive.read_bytes())==p['sha256']
 remote='/www/backups/'+site+'/combination-group-'+c['commit'][:8]
 ssh(site,'umask 077; mkdir -p '+shlex.quote(remote));run(['scp','-q','-O',str(archive),HOST[site]+':'+remote+'/payload.tar.gz'],timeout=180);ssh(site,'umask 077; tar -xzf '+remote+'/payload.tar.gz -C '+remote)
 def invoke(mode):return ssh(site,'/www/server/php/74/bin/php -- '+remote+' '+mode+' '+p['manifestSHA256'],(ROOT/'scripts/combination-group-release-install.php').read_bytes())
 started=False
 try:
  (R/(site+'-backup.log')).write_bytes(invoke('backup'));started=True;(R/(site+'-install.log')).write_bytes(invoke('install'));restart(site);(R/(site+'-verify.log')).write_bytes(invoke('verify'))
  runtime=json.loads(ssh(site,'/www/server/php/74/bin/php -- '+site+' runtime',(ROOT/'scripts/combination-member-search-runtime.php').read_bytes()));assert runtime['result']=='PASS';save(site+'-runtime.json',runtime)
  retained=json.loads(ssh(site,'/www/server/php/74/bin/php -- '+site,(ROOT/'scripts/platform-member-live.php').read_bytes()));assert retained['result']=='PASS';save(site+'-retained.json',retained)
  save(site+'-public.json',public(site));after=probe(site)
  for f,h in before['backend'].items():assert after['backend'][f]==(m['targets']['formal/'+PHP]['after']if f==PHP else h),'Backend protection '+f
  for entry in ['public/index.html','public/view_cashier_v3/index.html']:assert after['entries'][entry]==before['entries'][entry],'Unrelated entry'
  assert after['environment']==before['environment']and after['migration']==before['migration'],'Config or schema migration changed'
  for k,h in before['admin_assets'].items():
   changed=m['targets'].get('formal/public/'+k);assert after['admin_assets'][k]==(changed['after']if changed else h),'Old asset protection '+k
  for k,row in m['targets'].items():
   rel=k[len('formal/'):];actual=after['entries'][rel]if rel=='public/system.html'else(after['admin_assets'][rel[len('public/'):]]if rel.startswith('public/')else after['backend'][rel]);assert actual==row['after'],rel
  save(site+'-after.json',after);save(site+'-verified.json',{'result':'PASS','commit':c['commit'],'backup':remote,'files':len(m['targets']),'businessWrites':0,'databaseMigration':False});print(site,'VERIFIED',flush=True)
 except Exception as e:
  save(site+'-failure.json',{'started':started,'error':str(e),'stderr':getattr(e,'stderr',b'').decode(errors='replace')[-1500:]})
  if started:(R/(site+'-rollback.log')).write_bytes(invoke('rollback'));restart(site)
  raise

def push():
 guard();c=read('commit.json');assert read('rh-verified.json')['result']=='PASS';ref='refs/heads/'+c['branch'];subprocess.run(['git','push','origin',ref+':'+ref],cwd=ROOT,check=True);assert git('ls-remote','origin',ref).decode().split()[0]==c['commit'];save('push.json',{'result':'PASS','commit':c['commit'],'ref':ref});print('PUSH_VERIFIED')
if __name__=='__main__':
 if sys.argv[1]=='prepare':prepare()
 elif sys.argv[1]=='deploy':deploy(sys.argv[2])
 elif sys.argv[1]=='push':push()
 else:raise RuntimeError('prepare|deploy named-site|push')
