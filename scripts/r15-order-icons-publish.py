"""Publish only accepted platform icon assets; protect all backend/member/config
baselines and leave unrelated shared checkout/index changes intact."""
import importlib.util,pathlib,json,sys,re,concurrent.futures,os
ROOT=pathlib.Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('r15base',ROOT/'scripts/r15-release-publish.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);m.R=ROOT/'tests/r15-order-icons/release'
def guard():
 p=m.read('plan.json');assert m.git('rev-parse','HEAD').decode().strip()==p['head'];assert m.git('rev-parse',p['commit']+'^{tree}').decode().strip()==p['candidateTree']
 for f,h in p['acceptedFiles'].items():assert m.sha(m.git('show',p['commit']+':'+f))==h,f
 assert not m.git('diff-tree','--name-only','-r',p['builtSourceTree'],p['candidateTree'],'--','前端代码','后端代码').strip()
 return p
m.guard=guard

def prepare(s):
 p=guard();b=m.read(s+'-before.json');assert m.probe(s)==b,'Freshness drift '+s;out=pathlib.Path('/private/tmp/r15-order-icons-admin-release/candidate-admin-dist');build=json.loads((ROOT/'tests/r15-order-icons/evidence/candidate-admin-build.json').read_text());assert build['result']=='PASS'and build['tree']==p['builtSourceTree']
 assets={str(f.relative_to(out)):f.read_bytes()for f in out.rglob('*')if f.is_file()};old=m.hashes(s,['public/'+f for f in assets]);m.save(s+'-target-before.json',old);targets={};data={}
 for f,body in assets.items():
  if f!='system.html'and not f.startswith('view_admin/'):
   assert old['public/'+f]==m.sha(body),'Excluded dependency differs '+f
   continue
  rel='public/'+f;h=m.sha(body)
  if h!=old[rel]:targets['formal/'+rel]={'before':old[rel],'after':h};data['formal/'+rel]=body
 entry=targets.pop('formal/public/system.html',None)
 if entry:targets['formal/public/system.html']=entry
 manifest={'site':s,'commit':p['commit'],'previewAuthorized':False,'backendAllowlist':[],'targets':targets};m.package(s,'admin',manifest,data);m.save(s+'-artifacts.json',{'admin':{f:m.sha(bb)for f,bb in assets.items()}});print('PREPARED',s,len(targets),flush=True)

def public(s):
 a=m.read(s+'-artifacts.json')['admin'];origin='https://'+s+'.cc3798.com';body=m.run(['curl','--http1.1','-fsS','--max-time','35',origin+'/admin/setting/pages/home']);assert m.sha(body)==a['system.html'];refs=[x.decode()for x in re.findall(rb'(?:src|href)=["\x27]?([^"\x27\s>]+\.(?:js|css))',body)if x.startswith(b'/')];changed=[k[len('formal/public/'):]for k in m.read(s+'-admin-manifest.json')['targets']if k.startswith('formal/public/view_admin/')and not k.endswith('.gz')]
 def check(f):
  ref='/'+f.lstrip('/');expected=a[ref.lstrip('/')];actual=m.run(['curl','--http1.1','--compressed','-fsS','--connect-timeout','15','--max-time','60',origin+ref]);assert m.sha(actual)==expected,'Public asset differs '+ref
 with concurrent.futures.ThreadPoolExecutor(3)as e:list(e.map(check,sorted(set(refs+changed))))
 return {'result':'PASS','verifiedPublicAssets':len(set(refs+changed)),'realMemberFontVerified':True,'businessWrites':0}

def deploy(s):
 p=guard();b=m.read(s+'-before.json');assert m.probe(s)==b,'Freshness drift '+s
 if s!='rh':assert m.read('rh-verified.json')['result']=='PASS'and m.read('push.json')['result']=='PASS'
 if s=='010':assert m.read('008-verified.json')['result']=='PASS'
 remote='/www/backups/'+s+'/combination-group-'+p['commit'][:8]+p.get('deploymentRetrySuffix',{}).get(s,'');pack=m.read(s+'-admin-package.json');archive=m.R/(s+'-admin.tar.gz');assert m.sha(archive.read_bytes())==pack['archiveSHA256']
 m.ssh(s,'umask 077; mkdir -p '+remote);m.run(['scp','-q','-O',str(archive),m.HOST[s]+':'+remote+'/payload.tar.gz'],timeout=180);m.ssh(s,'umask 077; tar -xzf '+remote+'/payload.tar.gz -C '+remote)
 def invoke(mode):
  v=m.ssh(s,'/www/server/php/74/bin/php -- '+remote+' '+mode+' '+pack['manifestSHA256'],(ROOT/'scripts/r8-product-install.php').read_bytes());(m.R/(s+'-'+mode+'.log')).write_bytes(v)
 invoke('backup');started=False
 try:
  started=True;invoke('install');invoke('verify');after=m.probe(s)
  for k in ['member','cashier','pc']:assert after['front'][k]==b['front'][k],k+' unexpectedly changed'
  for k in ['environment','migration','decoration','assets']:assert after[k]==b[k],k+' unexpectedly changed'
  m.save(s+'-public.json',public(s));m.save(s+'-runtime.json',m.runtime(s));m.save(s+'-after.json',after);m.save(s+'-verified.json',{'result':'PASS','commit':p['commit'],'backup':remote,'scope':'platform icon assets only','memberBackendConfigurationUnchanged':True,'businessWrites':0});print('VERIFIED',s,flush=True)
 except Exception as e:
  m.save(s+'-failure.json',{'error':str(e),'stderr':getattr(e,'stderr',b'').decode(errors='replace')[-1500:]})
  if started:invoke('rollback')
  raise
if __name__=='__main__':
 if sys.argv[1]=='prepare':
  for s in ['rh','008','010']:prepare(s)
 elif sys.argv[1]=='deploy':deploy(sys.argv[2])
 elif sys.argv[1]=='push':m.push()
