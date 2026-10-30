"""R15 accepted fixed-tree publication. Instance-bound allowlists, exact backups,
atomic entry switches and rollback protect configuration, business data and old assets."""
import pathlib,subprocess,json,hashlib,os,sys,re,concurrent.futures,tarfile,io
ROOT=pathlib.Path(__file__).resolve().parents[1];R=ROOT/'tests/r15-release';HOST={'rh':'rh-server','008':'mohe-server','010':'mohe-server'}
def sha(b):return hashlib.sha256(b).hexdigest()
def read(n):return json.loads((R/n).read_text())
def save(n,x):(R/n).write_text(json.dumps(x,ensure_ascii=False,indent=2)+'\n')
def run(a,**kw):return subprocess.check_output(a,cwd=ROOT,stderr=subprocess.PIPE,timeout=kw.pop('timeout',90),**kw)
def git(*a):return run(['git',*a])
def ssh(s,c,b=None):return run(['ssh','-T','-q','-o','BatchMode=yes','-o','ConnectTimeout=15','-o','ServerAliveInterval=10','-o','ServerAliveCountMax=2',HOST[s],c],input=b,timeout=120)
def probe(s):return json.loads(ssh(s,'/www/server/php/74/bin/php -- '+s,(ROOT/'scripts/store-address-release-probe.php').read_bytes()))
def guard():
 p=read('plan.json');assert p['supplementalAccepted'];assert git('rev-parse','HEAD').decode().strip()==p['head'];assert sha((ROOT/'.git/index').read_bytes())==p['indexSHA256'];assert git('rev-parse',p['commit']+'^{tree}').decode().strip()==p['candidateTree']
 for f,h in p['acceptedFiles'].items():assert sha(git('show',p['commit']+':'+f))==h,f
 return p

def hashes(s,files):
 code='<?php $root='+json.dumps('/www/wwwroot/'+s+'.cc3798.com')+';$files=json_decode('+json.dumps(json.dumps(files))+',true);$o=[];foreach($files as $f){$p=$root."/".$f;$o[$f]=is_file($p)?hash_file("sha256",$p):null;}echo json_encode($o);'
 return json.loads(ssh(s,'/www/server/php/74/bin/php',code.encode()))
def package(s,kind,m,data):
 raw=json.dumps(m).encode();save(s+'-'+kind+'-manifest.json',m)
 with tarfile.open(R/(s+'-'+kind+'.tar.gz'),'w:gz')as t:
  for f,b in [('manifest.json',raw)]+list(data.items()):
   i=tarfile.TarInfo('payload/'+f);i.size=len(b);i.mode=0o600;t.addfile(i,io.BytesIO(b))
 save(s+'-'+kind+'-package.json',{'manifestSHA256':sha(raw),'archiveSHA256':sha((R/(s+'-'+kind+'.tar.gz')).read_bytes())})
def prepare(s):
 p=guard();b=read(s+'-before.json');assert probe(s)==b,'Live baseline drift '+s
 admin=pathlib.Path('/private/tmp/r15-unified-admin-release/candidate-admin-dist');member=pathlib.Path('/private/tmp/r15-unified-release-'+s+'-h5/output')
 ab={str(f.relative_to(admin)):f.read_bytes()for f in admin.rglob('*')if f.is_file()};hb={str(f.relative_to(member)):f.read_bytes()for f in member.rglob('*')if f.is_file()};backend='app/services/diy/DiyServices.php';body=git('show',p['commit']+':后端代码/'+backend)
 paths=sorted(set(['public/'+f for f in ab]+['public/'+f for f in hb]+[backend]));old=hashes(s,paths);save(s+'-target-before.json',old)
 expected=read(s+'-backend-before.json')[backend];assert old[backend]==expected
 at={};ad={}
 for f,body2 in ab.items():
  if f!='system.html'and not f.startswith('view_admin/'):
   assert old['public/'+f]==sha(body2),'Excluded platform dependency drift '+f
   continue
  rel='public/'+f;h=sha(body2)
  if old[rel]!=h:at['formal/'+rel]={'before':old[rel],'after':h};ad['formal/'+rel]=body2
 at['formal/'+backend]={'before':expected,'after':sha(body)};ad['formal/'+backend]=body
 entry=at.pop('formal/public/system.html',None)
 if entry:at['formal/public/system.html']=entry
 am={'site':s,'commit':p['commit'],'previewAuthorized':False,'backendAllowlist':[backend],'targets':at};package(s,'admin',am,ad)
 ht={};hd={};extras=[]
 for f,bb in hb.items():
  if f!='index.html'and not re.fullmatch(r'static/(js/[^/]+\.js(?:\.gz)?|css/[^/]+\.css(?:\.gz)?|index\.[a-f0-9]+\.css)',f):
   if old['public/'+f]!=sha(bb):extras.append(f)
   continue
  rel='public/'+f;h=sha(bb)
  if old[rel]!=h:ht[rel]={'before':old[rel],'after':h};hd[rel]=bb
 assert not extras,'Excluded member dependency drift '+repr(extras)
 entry=ht.pop('public/index.html',None)
 if entry:ht['public/index.html']=entry
 protected={}
 for k,f in [('cashier','public/view_cashier_v3/index.html'),('pc','public/static/html/pc.html')]:
  protected[f]=b['front'][k]['index']
  for a,h in dict(b['front'][k]['assets']).items():protected['public/'+a.lstrip('/')]=h
 hm={'site':s,'commit':p['commit'],'environment':b['environment'],'protected':protected,'files':ht};package(s,'member',hm,hd)
 save(s+'-artifacts.json',{'admin':{f:sha(v)for f,v in ab.items()},'member':{f:sha(v)for f,v in hb.items()},'backend':sha(body)})
 print('PREPARED',s,len(at),len(ht),flush=True)
def restart(s):
 cmd='/etc/init.d/ruihao_swoole restart'if s=='rh'else'systemctl restart mohe-'+s+'-swoole.service'
 (R/(s+'-restart.log')).write_bytes(ssh(s,cmd));ssh(s,"ss -lnt | grep -q ':20800 '"if s=='rh'else'systemctl is-active --quiet mohe-'+s+'-swoole.service')
def runtime(s):
 out={}
 for name,file in [('member','r15-release-runtime.php'),('login','r14-member-runtime.php'),('platform','platform-member-live.php'),('rights','m014-member-runtime.php')]:
  x=json.loads(ssh(s,'/www/server/php/74/bin/php -- '+s,(ROOT/'scripts'/file).read_bytes()));assert x['result']=='PASS';out[name]=x
 return out

def public(s):
 base='https://'+s+'.cc3798.com';a=read(s+'-artifacts.json');checked=[]
 for terminal,path,key in [('member','/pages/user/index','index.html'),('admin','/admin/setting/pages/home','system.html')]:
  body=run(['curl','--http1.1','-fsS','--max-time','35',base+path]);assert sha(body)==a[terminal][key],terminal+' public entry'
  refs=[x.decode()for x in re.findall(rb'(?:src|href)=["\x27]?([^"\x27\s>]+\.(?:js|css))',body)if x.startswith(b'/')]
  lazy=['/'+f for f in a[terminal]if terminal=='member'and re.search(r'pages-(user-index|users-(survey|user_card|user_card_list)|discover-discoverIndex)-index\.[^/]+\.(js|css)$',f)]
  def check(f):
   h=a[terminal].get(f.lstrip('/'));assert h,'Missing artifact '+f
   v=run(['curl','--http1.1','--compressed','-fsS','--connect-timeout','15','--max-time','50',base+f]);assert sha(v)==h,f
  with concurrent.futures.ThreadPoolExecutor(3)as e:list(e.map(check,sorted(set(refs+lazy))))
  checked+=refs+lazy
 for api in ['/api/diy/get_diy/0','/api/menu/user']:
  x=json.loads(run(['curl','-fsS','--max-time','35',base+api]));assert x['status']==200
  if api.endswith('user'):
   orders=x['data']['diy_data']['order']['list'];assert len([r for r in orders if r.get('key')=='all'])==1
 return {'result':'PASS','checkedAssets':checked,'allOrdersFromPublicAPI':True,'businessWrites':0}
def deploy(s):
 p=guard();b=read(s+'-before.json');assert probe(s)==b,'Freshness drift '+s
 if s!='rh':assert read('rh-verified.json')['result']=='PASS'and read('push.json')['result']=='PASS'
 if s=='010':assert read('008-verified.json')['result']=='PASS'
 rem={'admin':'/www/backups/'+s+'/combination-group-'+p['commit'][:8],'member':'/www/backups/'+s+'/store-address-'+p['commit'][:8]}
 def invoke(k,mode):
  pack=read(s+'-'+k+'-package.json');file='r8-product-install.php'if k=='admin'else'store-address-release-install.php';args=rem[k]+' '+mode+' '+pack['manifestSHA256'];args=(s+' '+args)if k=='member'else args
  v=ssh(s,'/www/server/php/74/bin/php -- '+args,(ROOT/'scripts'/file).read_bytes());(R/(s+'-'+k+'-'+mode+'.log')).write_bytes(v)
 for k,d in rem.items():
  ar=R/(s+'-'+k+'.tar.gz');assert sha(ar.read_bytes())==read(s+'-'+k+'-package.json')['archiveSHA256'];ssh(s,'umask 077; mkdir -p '+d);run(['scp','-q','-O',str(ar),HOST[s]+':'+d+'/payload.tar.gz'],timeout=180);ssh(s,'umask 077; tar -xzf '+d+'/payload.tar.gz -C '+d);invoke(k,'backup')
 started=[]
 try:
  for k in ['admin','member']:started.append(k);invoke(k,'install')
  restart(s)
  for k in ['admin','member']:invoke(k,'verify')
  after=probe(s)
  for k in ['cashier','pc']:assert after['front'][k]==b['front'][k]
  for k in ['environment','migration','decoration']:assert after[k]==b[k],k+' mutated'
  for f,h in b['assets'].items():assert after['assets'].get(f)==read(s+'-member-manifest.json')['files'].get('public/'+f,{}).get('after',h),'Old member asset changed'
  save(s+'-public.json',public(s));save(s+'-runtime.json',runtime(s));save(s+'-after.json',after)
  save(s+'-verified.json',{'result':'PASS','commit':p['commit'],'tree':p['candidateTree'],'backups':rem,'businessWrites':0,'configurationChanged':False,'migration':False});print('VERIFIED',s,flush=True)
 except Exception as e:
  save(s+'-failure.json',{'error':str(e),'stderr':getattr(e,'stderr',b'').decode(errors='replace')[-1500:],'started':started})
  for k in reversed(started):invoke(k,'rollback')
  if started:restart(s)
  raise

def push():
 p=guard();assert read('rh-verified.json')['result']=='PASS';ref='refs/heads/'+p['branch'];env={**os.environ,'GIT_SSH_COMMAND':'ssh -i /Users/xiaojunpeng/.ssh/id_ed25519_gitee -o IdentitiesOnly=yes -o BatchMode=yes -o ConnectTimeout=15 -o ServerAliveInterval=10 -o ServerAliveCountMax=2'};run(['git','push','origin',ref+':'+ref],env=env,timeout=180);assert run(['git','ls-remote','origin',ref],env=env).decode().split()[0]==p['commit'];save('push.json',{'result':'PASS','commit':p['commit'],'branch':p['branch']});print('PUSH_VERIFIED',flush=True)
if __name__=='__main__':
 mode=sys.argv[1]
 if mode=='prepare':
  for s in ['rh','008','010']:prepare(s)
 elif mode=='deploy':deploy(sys.argv[2])
 elif mode=='push':push()
