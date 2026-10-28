"""Named instances only: RH verified -> push -> 008 ->010. Never reset the shared checkout."""
import pathlib,subprocess,json,hashlib,base64,shlex,tarfile,io,sys,re
ROOT=pathlib.Path(__file__).resolve().parents[1];R=ROOT/'tests/platform-member-login/release/unified';HOST={'rh':'rh-server','008':'mohe-server','010':'mohe-server'}
def read(f):return json.loads((R/f).read_text())
def save(f,x):(R/f).write_text(json.dumps(x,ensure_ascii=False,indent=2)+'\n')
def sha(x):return hashlib.sha256(x).hexdigest()
def run(args,**kw):return subprocess.check_output(args,cwd=ROOT,stderr=subprocess.PIPE,**kw)
def git(*a):return run(['git',*a])
def ssh(site,cmd,body=None):return run(['ssh','-q','-o','BatchMode=yes','-o','ConnectTimeout=15','-o','ServerAliveInterval=10',HOST[site],cmd],input=body,timeout=240)
def scope_files():
 a=json.load(open(ROOT/'tests/platform-member-login/evidence/fixed.json'));b=json.load(open(ROOT/'tests/member-phone-reset/evidence/fixed.json'));p=read('supplemental-review.json')
 return sorted({f.removeprefix('后端代码/')for f in set(a['files'])|set(b['files'])|set(p['readOnlyPrerequisites'])if f.startswith('后端代码/')and f.endswith('.php')})
def probe(site):
 extra=read('supplemental-review.json')['preservedBaselineFiles'];files=scope_files()+[f.removeprefix('后端代码/')for f in extra if f.startswith('后端代码/')and f.endswith('.php')]
 arg=base64.b64encode(json.dumps(sorted(set(files))).encode()).decode();return json.loads(ssh(site,'/www/server/php/74/bin/php -- '+site+' '+shlex.quote(arg),(ROOT/'scripts/platform-member-release-probe.php').read_bytes()))
def guard():
 p=read('supplemental-review.json');a=read('acceptance.json');c=read('commit.json');assert a['answer']=='确认补验通过，继续执行'and p['supplementalAcceptance'] and (p['sourceTree']==c['sourceTree']or(json.load(open(R.parent/'permission-format/fixed.json'))['acceptedByUser']and json.load(open(R.parent/'permission-format/fixed.json'))['sourceTree']==c['sourceTree']));assert git('rev-parse','refs/heads/'+c['branch']).decode().strip()==c['commit']
 f=json.load(open(ROOT/'tests/platform-member-login/evidence/fixed.json'));assert git('rev-parse','HEAD').decode().strip()==f['head']
 assert sha(pathlib.Path(git('rev-parse','--git-path','index').decode().strip()).read_bytes())==f['sharedIndexSHA256']
 expected=dict(f['worktreeFiles']);compat=json.load(open(R.parent/'permission-format/fixed.json'));
 if compat['acceptedByUser']:expected.update(compat['files'])
 for path,h in expected.items():assert sha((ROOT/path).read_bytes())==h,path
 return c

def prepare(sites=None):
 c=guard();tree=c['sourceTree'];backend=scope_files();builds=[('admin',json.load(open(R.parent/'candidate-admin-build.json'))),('cashier',read('candidate-cashier-v3-build.json'))]
 payloads={}
 for f in backend:payloads[f]=git('show',tree+':后端代码/'+f)
 for kind,b in builds:
  assert b['result']=='PASS'and b['sourceStable']
  # Admin package is unchanged by the baseline preservation merge.
  package='前端代码/admin'if kind=='admin'else'前端代码/cashier-v3'
  assert git('rev-parse',b['tree']+':'+package)==git('rev-parse',tree+':'+package)
  for f,h in b['assets'].items():
   data=pathlib.Path(b['output'],f).read_bytes();assert sha(data)==h
   dest='public/'+f if kind=='admin'else'public/view_cashier_v3/'+f
   if kind=='admin'and not(f=='system.html'or f.startswith('view_admin/')):continue
   payloads[dest]=data
 migrations=[];sqls={}
 for folder,table,key,title,cols in [
  ('2026-10-28-会员改号撤销登录','eb_member_phone_login_reset','2026-10-28-member-phone-login-reset','会员改号撤销登录',['id','uid','nonce','phone_verified','binding_ids','operator_id','source','created_at']),
  ('2026-10-28-平台门店会员管理','eb_member_management_receipt','2026-10-28-member-management-receipt','平台门店会员管理回执',['id','actor_type','actor_id','request_key','request_hmac','action','member_id','response_json','created_at'])]:
  source='后端代码/database/upgrades/'+folder+'/02-正式升级.sql';data=git('show',tree+':'+source);name=key+'.sql';sqls[name]=data;migrations.append({'file':name,'table':table,'key':key,'title':title,'source':source,'sha256':sha(data),'columns':cols})
 for site in (sites or HOST):
  assert site in HOST
  before=probe(site);original=json.load(open(R/'base-c3a69f96'/(site+'-after.json')))if(R/'base-c3a69f96'/(site+'-after.json')).exists()else json.load(open(R.parent/(site+'-before.json')))
  for f,h in original['files'].items():assert before['files'][f]==h,'Backend advanced '+site+':'+f
  for k in ['admin','cashier','member']:assert before['front'][k]['entry']==original['front'][k]['entry']and before['front'][k]['refs']==original['front'][k]['refs'],'Frontend advanced '+site+':'+k
  assert before['environment']==original['environment']
  current=dict(before['files'])
  for k in ['admin','cashier']:current.update(before['front'][k]['assets'])
  current['public/system.html']=before['front']['admin']['entry'];current['public/view_cashier_v3/index.html']=before['front']['cashier']['entry']
  changed={f:b for f,b in payloads.items()if current.get(f)!=sha(b)}
  # PHP, assets, then active entries; older hashed resources are never deleted.
  changed=dict(sorted(changed.items(),key=lambda x:(2 if x[0]in ['public/system.html','public/view_cashier_v3/index.html']else(1 if x[0].startswith('public/')else 0),x[0])))
  protected={'public/index.html':before['front']['member']['entry']}
  for ref,h in before['front']['member']['refs'].items():protected['public/'+ref.lstrip('/')]=h
  for f,h in before['files'].items():
   if f not in changed and h is not None:protected[f]=h
  for kind in ['admin','cashier']:
   for ref,h in before['front'][kind]['refs'].items():
    f='public/'+ref.lstrip('/')
    if f not in changed and h is not None:protected[f]=h
  for f,k in [('public/system.html','admin'),('public/view_cashier_v3/index.html','cashier')]:
   if f not in changed:protected[f]=before['front'][k]['entry']
  mi=[dict(x,existedBefore=before['tables'].get(x['table'])is not None)for x in migrations]
  manifest={'site':site,'commit':c['commit'],'sourceTree':tree,'environment':before['environment'],'backendAllowlist':backend,'protected':protected,'files':{f:{'before':current.get(f),'after':sha(b)}for f,b in changed.items()},'migrations':mi,'retiredPreviewAuthorized':False}
  manifestBytes=json.dumps(manifest,ensure_ascii=False).encode();archive=R/(site+'-payload.tar.gz')
  with tarfile.open(archive,'w:gz')as tar:
   for f,b in [('manifest.json',manifestBytes)]+[('files/'+f,b)for f,b in changed.items()]+[('sql/'+f,b)for f,b in sqls.items()]:
    info=tarfile.TarInfo('payload/'+f);info.mode=0o600;info.size=len(b);tar.addfile(info,io.BytesIO(b))
  archive.chmod(0o600);save(site+'-manifest.json',manifest);save(site+'-preflight.json',before);save(site+'-package.json',{'sha256':sha(archive.read_bytes()),'manifestSHA256':sha(manifestBytes),'files':len(changed),'commit':c['commit'],'controls':{f:sha((ROOT/f).read_bytes())for f in ['scripts/platform-member-install.php','scripts/platform-member-live.php','scripts/platform-member-publish.py','scripts/platform-member-release-probe.php']}});print(site,'PREPARED',len(changed),flush=True)

def restart(site):
 command='/etc/init.d/ruihao_swoole restart'if site=='rh'else'systemctl restart mohe-'+site+'-swoole.service'
 (R/(site+'-restart.log')).write_bytes(ssh(site,command));ssh(site,"ss -lnt | grep -q ':20800 '"if site=='rh'else'systemctl is-active --quiet mohe-'+site+'-swoole.service')
def public(site):
 base='https://'+('rh.cc3798.com'if site=='rh'else site+'.cc3798.com');m=read(site+'-manifest.json');checks=[]
 for entry in ['public/system.html','public/view_cashier_v3/index.html']:
  data=run(['curl','-fsS','--max-time','30',base+('/admin/login'if entry.endswith('system.html')else'/view_cashier_v3/')])
  # Nginx may transform HTML, so resource bodies are the public authority.
  refs=re.findall(rb'(?:src|href)=["\x27]?([^"\x27\s>]+\.(?:js|css))',data);assert refs
  for ref in refs:
   ref=ref.decode()
   if not ref.startswith('/'):continue
   f='public/'+ref.lstrip('/');expected=m['files'].get(f,{}).get('after') or m['protected'].get(f)
   if expected:
    actual=run(['curl','-fsS','--max-time','30',base+ref]);assert sha(actual)==expected,'Public asset mismatch '+ref;checks.append(ref)
  assert any('/js/app.'in ref or '/assets/index-'in ref for ref in checks)
 member=run(['curl','-fsS','--max-time','30',base+'/pages/index/index']);assert b'/static/js/'in member
 if site=='rh':
  for p in ['/preview-8080/view_cashier_v3/','/store-v3/']:
   assert run(['curl','-sS','-o','/dev/null','-w','%{http_code}','--max-time','20',base+p]).decode()=='410'
 return{'result':'PASS','checkedAssets':checks,'memberH5Available':True,'businessWrites':0}
def deploy(site,attempt=''):
 assert attempt in ['','retry1']
 c=guard();assert site in HOST
 if site!='rh':assert read('rh-verified.json')['result']=='PASS'and read('push.json')['result']=='PASS'
 if site=='010':assert read('008-verified.json')['result']=='PASS'
 before=probe(site);prior=read(site+'-preflight.json');assert before==prior,'Prepared baseline changed; refuse overwrite'
 pack=read(site+'-package.json');
 for f,h in pack['controls'].items():assert sha((ROOT/f).read_bytes())==h,'Release control drift'
 archive=R/(site+'-payload.tar.gz');assert sha(archive.read_bytes())==pack['sha256']
 remote='/www/backups/'+site+'/platform-member-'+c['commit'][:8]+('-'+attempt if attempt else '');ssh(site,'umask 077; mkdir -p '+shlex.quote(remote))
 run(['scp','-q','-O','-o','BatchMode=yes',str(archive),HOST[site]+':'+remote+'/payload.tar.gz'],timeout=180)
 ssh(site,'umask 077; tar -xzf '+shlex.quote(remote+'/payload.tar.gz')+' -C '+shlex.quote(remote))
 def invoke(mode):return ssh(site,'/www/server/php/74/bin/php -- '+shlex.quote(remote)+' '+mode+' '+pack['manifestSHA256'],(ROOT/'scripts/platform-member-install.php').read_bytes())
 started=False
 try:
  (R/(site+'-backup.log')).write_bytes(invoke('backup'));(R/(site+'-migration.log')).write_bytes(invoke('migrate'));started=True;(R/(site+'-install.log')).write_bytes(invoke('install'));restart(site);(R/(site+'-verify.log')).write_bytes(invoke('verify'))
  runtime=ssh(site,'/www/server/php/74/bin/php -- '+site,(ROOT/'scripts/platform-member-live.php').read_bytes());(R/(site+'-runtime.txt')).write_bytes(runtime);rv=json.loads(runtime);assert rv['result']=='PASS';save(site+'-runtime.json',rv)
  save(site+'-public.json',public(site));after=probe(site);m=read(site+'-manifest.json')
  for f,row in m['files'].items():
   if f.startswith('public/'):
    actual=after['front']['admin']['entry']if f=='public/system.html'else(after['front']['cashier']['entry']if f=='public/view_cashier_v3/index.html'else after['front']['admin']['assets'].get(f,after['front']['cashier']['assets'].get(f)))
   else:actual=after['files'][f]
   assert actual==row['after'],f
  assert after['environment']==before['environment']and after['front']['member']==before['front']['member']
  save(site+'-after.json',after);save(site+'-verified.json',{'result':'PASS','commit':c['commit'],'backup':remote,'manifestSHA256':pack['manifestSHA256'],'newTables':2,'files':len(m['files']),'businessDataWrites':0});print(site,'VERIFIED',flush=True)
 except Exception as e:
  save(site+'-failure.json',{'error':str(e),'started':started,'stderr':getattr(e,'stderr',b'').decode(errors='replace')[-1600:]})
  if started:
   (R/(site+'-rollback.log')).write_bytes(invoke('rollback'));restart(site);save(site+'-rollback.json',{'result':'PASS','additiveAuditTablesRetained':True})
  raise

def push():
 c=guard();assert read('rh-verified.json')['result']=='PASS';ref='refs/heads/'+c['branch'];subprocess.run(['git','push','origin',ref+':'+ref],cwd=ROOT,check=True);assert git('ls-remote','origin',ref).decode().split()[0]==c['commit'];save('push.json',{'result':'PASS','commit':c['commit'],'ref':ref});print('PUSH_VERIFIED')
if __name__=='__main__':
 mode=sys.argv[1]
 if mode=='prepare':prepare(sys.argv[2:] or None)
 elif mode=='deploy':deploy(sys.argv[2],sys.argv[3] if len(sys.argv)>3 else '')
 elif mode=='push':push()
 else:raise RuntimeError('prepare|deploy named-site|push')
