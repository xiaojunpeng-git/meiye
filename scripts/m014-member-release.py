"""M014 authorized sequential publication. Guard each instance, preserve old assets,
back up before atomic switches, and never write business data or configuration."""
import pathlib,subprocess,json,hashlib,os,sys,re,concurrent.futures,tarfile,io
ROOT=pathlib.Path(__file__).resolve().parents[1];R=ROOT/'tests/member-entitlement-display/release'
HOST={'rh':'rh-server','008':'mohe-server','010':'mohe-server'}
def sha(b):return hashlib.sha256(b).hexdigest()
def read(n):return json.loads((R/n).read_text())
def save(n,x):(R/n).write_text(json.dumps(x,ensure_ascii=False,indent=2)+'\n')
def run(a,**kw):return subprocess.check_output(a,cwd=ROOT,stderr=subprocess.PIPE,timeout=kw.pop('timeout',60),**kw)
def git(*a):return run(['git',*a])
def ssh(s,cmd,body=None):return run(['ssh','-T','-q','-o','BatchMode=yes','-o','ConnectTimeout=10','-o','ServerAliveInterval=10','-o','ServerAliveCountMax=2',HOST[s],cmd],input=body,timeout=90)
def probe(s):return json.loads(ssh(s,'/www/server/php/74/bin/php -- '+s,(ROOT/'scripts/store-address-release-probe.php').read_bytes()))
def guard():
 p=read('plan.json');assert git('rev-parse','HEAD').decode().strip()==p['sharedHead'];assert sha((ROOT/'.git/index').read_bytes())==p['sharedIndex']
 f=json.loads((ROOT/'tests/member-entitlement-display/evidence/fixed.json').read_text())
 for file,h in f['files'].items():assert sha((ROOT/file).read_bytes())==h,file
 assert git('rev-parse',p['commit']+'^{tree}').decode().strip()==p['candidateTree'];return p

def prepare():
 p=guard();art={}
 for s in HOST:
  b=read(s+'-before.json');assert probe(s)==b,'Live baseline drift '+s
  out=pathlib.Path('/private/tmp/m014-entitlement-'+s+'-h5/output');inp=json.loads(out.parent.joinpath('input.json').read_text());assert inp['tree']==p['candidateTree']
  assert ' DONE  Build complete.'in(R/(s+'-build.log')).read_text()
  assets={str(f.relative_to(out)):sha(f.read_bytes())for f in out.rglob('*')if f.is_file()}
  # The only source difference from published member code is the accepted projection.
  changed=git('diff','--name-only',p['baselinePublishedCommit'],p['commit'],'--','前端代码/uniapp','前端代码/shared').decode().strip().splitlines()
  assert changed==['前端代码/uniapp/pages/users/user_card_list/index.vue'],changed
  prior=pathlib.Path(json.loads((ROOT/('tests/r12-mall-return-native/'+s+'-build.json')).read_text())['output'])
  for f,h in assets.items():
   if not(f=='index.html'or f.startswith('static/js/')or f.startswith('static/css/')):assert (prior/f).is_file()and sha((prior/f).read_bytes())==h,'Unrelated asset changed '+f
  files={};data={}
  for f,h in assets.items():
   if not(f=='index.html'or re.fullmatch(r'static/(js/[^/]+\.js(?:\.gz)?|css/[^/]+\.css(?:\.gz)?|index\.[a-f0-9]+\.css)',f)):continue
   old=b['front']['member']['index']if f=='index.html'else b['assets'].get(f,b['front']['member']['assets'].get('/'+f))
   if old!=h:files['public/'+f]={'before':old,'after':h};data['public/'+f]=(out/f).read_bytes()
  entry=files.pop('public/index.html',None)
  if entry:files['public/index.html']=entry
  protected={}
  for k,f in [('admin','public/system.html'),('cashier','public/view_cashier_v3/index.html'),('pc','public/static/html/pc.html')]:
   protected[f]=b['front'][k]['index']
   for a,h in dict(b['front'][k]['assets']).items():protected['public/'+a.lstrip('/')]=h
  manifest={'site':s,'commit':p['commit'],'environment':b['environment'],'protected':protected,'files':files};raw=json.dumps(manifest).encode()
  with tarfile.open(R/(s+'-payload.tar.gz'),'w:gz')as t:
   for f,body in [('manifest.json',raw)]+list(data.items()):
    i=tarfile.TarInfo('payload/'+f);i.size=len(body);i.mode=0o600;t.addfile(i,io.BytesIO(body))
  save(s+'-manifest.json',manifest);save(s+'-package.json',{'manifestSHA256':sha(raw),'archiveSHA256':sha((R/(s+'-payload.tar.gz')).read_bytes())});art[s]={'output':str(out),'assets':assets};print('PREPARED',s,len(files),flush=True)
 save('artifacts.json',art)

def backend(s,remote):
 file='app/services/member/MemberCommerceServices.php';expected=sha(git('show','a6bcb56c:后端代码/'+file));old=read(s+'-backend-before.json')[file]
 if old==expected:return {'alreadyCurrent':True,'sha256':expected}
 assert old=='512092a4f572a120a95790bd860585c489bd5a222a4caa8ed3afaa0317e1149c'
 body=git('show','a6bcb56c:后端代码/'+file);temp=R/(s+'-adapter.php');temp.write_bytes(body)
 run(['scp','-q','-O',str(temp),HOST[s]+':'+remote+'/candidate-MemberCommerceServices.php'],timeout=90)
 source=(ROOT/'scripts/m014-008-member-card-install.php').read_text().replace("$root='/www/wwwroot/008.cc3798.com'","$root='/www/wwwroot/"+('rh'if s=='rh'else s)+".cc3798.com'").replace("!=='mohe_008'","!=="+repr({'rh':'ruihao','010':'mohe_010_app'}[s])).replace("(int)($c['hostport']??0)!==3307","(int)($c['hostport']??3306)!=="+('3306'if s=='rh'else'3307')).replace("$dir='/www/backups/008/m014-card-relations-20261029'","$dir="+repr(remote)).replace("'site'=>'008'","'site'=>"+repr(s))
 (R/(s+'-backend-install.log')).write_bytes(ssh(s,'/www/server/php/74/bin/php --',source.encode()))
 command='/etc/init.d/ruihao_swoole restart'if s=='rh'else'systemctl restart mohe-010-swoole.service && systemctl is-active mohe-010-swoole.service'
 (R/(s+'-worker-reload.log')).write_bytes(ssh(s,command))
 return {'installed':True,'sha256':expected,'rollback':remote+'/MemberCommerceServices.before-a6bcb56c.php'}

def public(s):
 origin='https://'+('rh'if s=='rh'else s)+'.cc3798.com';a=read('artifacts.json')[s]['assets'];html=run(['curl','--http1.1','-fsS','--max-time','30',origin+'/pages/users/user_card_list/index'])
 assert sha(html)==a['index.html'],'Public entry drift'
 refs=[x.decode()for x in re.findall(rb'(?:src|href)=["\x27]?([^"\x27\s>]+\.(?:js|css))',html)if x.startswith(b'/static/')]
 lazy=['/'+f for f in a if re.search(r'pages-(users-(survey|user_card|user_card_list)|discover-discoverIndex)-index\.[^/]+\.(js|css)$',f)]
 def check(f):
  for n in range(2):
   try:
    assert sha(run(['curl','--http1.1','--compressed','-fsS','--connect-timeout','10','--max-time','45',origin+f]))==a[f.lstrip('/')];return
   except Exception:
    if n:raise
 with concurrent.futures.ThreadPoolExecutor(3)as pool:list(pool.map(check,sorted(set(refs+lazy))));
 statuses={}
 for path in ['/api/diy/get_diy/0','/api/menu/user']:
  x=json.loads(run(['curl','-fsS','--max-time','30',origin+path]));assert x['status']==200;statuses[path]=200
 return {'result':'PASS','checked':refs+lazy,'readOnlyAPIs':statuses,'businessWrites':0}

def deploy(s):
 p=guard();assert s in HOST
 if s!='rh':assert read('rh-verified.json')['result']=='PASS'and read('push.json')['result']=='PASS'
 if s=='010':assert read('008-verified.json')['result']=='PASS'
 b=read(s+'-before.json');assert probe(s)==b,'Live baseline advanced '+s
 pack=read(s+'-package.json');archive=R/(s+'-payload.tar.gz');assert sha(archive.read_bytes())==pack['archiveSHA256']
 remote='/www/backups/'+s+'/store-address-'+p['commit'][:8];ssh(s,'umask 077; mkdir -p '+remote)
 run(['scp','-q','-O',str(archive),HOST[s]+':'+remote+'/payload.tar.gz'],timeout=120);ssh(s,'umask 077; tar -xzf '+remote+'/payload.tar.gz -C '+remote)
 invoke=lambda mode:ssh(s,'/www/server/php/74/bin/php -- '+s+' '+remote+' '+mode+' '+pack['manifestSHA256'],(ROOT/'scripts/store-address-release-install.php').read_bytes())
 started=False;backendResult={}
 try:
  (R/(s+'-backup.log')).write_bytes(invoke('backup'));backendResult=backend(s,remote);save(s+'-backend-result.json',backendResult)
  started=True;(R/(s+'-install.log')).write_bytes(invoke('install'));(R/(s+'-verify.log')).write_bytes(invoke('verify'));after=probe(s)
  for k in ['admin','cashier','pc']:assert after['front'][k]==b['front'][k]
  for k in ['environment','migration','decoration']:assert after[k]==b[k]
  manifest=read(s+'-manifest.json')
  for f,h in b['assets'].items():assert after['assets'].get(f)==manifest['files'].get('public/'+f,{}).get('after',h),'Old asset drift '+f
  save(s+'-public.json',public(s))
  runtime=json.loads(ssh(s,'/www/server/php/74/bin/php -- '+s,(ROOT/'scripts/m014-member-runtime.php').read_bytes()));assert runtime['result']=='PASS'and runtime['backendSHA256']==backendResult['sha256'];save(s+'-runtime.json',runtime)
  save(s+'-after.json',after);save(s+'-verified.json',{'result':'PASS','commit':p['commit'],'backup':remote,'files':len(manifest['files']),'backend':backendResult,'businessWrites':0,'migration':False});print('VERIFIED',s,flush=True)
 except Exception as e:
  save(s+'-failure.json',{'error':str(e),'stderr':getattr(e,'stderr',b'').decode(errors='replace')[-1200:],'frontendStarted':started})
  if started:(R/(s+'-rollback.log')).write_bytes(invoke('rollback'))
  if backendResult.get('installed'):
   domain=('rh'if s=='rh'else s)+'.cc3798.com';target='/www/wwwroot/'+domain+'/app/services/member/MemberCommerceServices.php'
   php='<?php $f='+json.dumps(target)+';$b='+json.dumps(backendResult['rollback'])+';if(hash_file("sha256",$f)!=='+json.dumps(backendResult['sha256'])+')throw new RuntimeException("Concurrent backend change");if(hash_file("sha256",$b)!=="512092a4f572a120a95790bd860585c489bd5a222a4caa8ed3afaa0317e1149c")throw new RuntimeException("Backup drift");$t=$f.".m014-rollback";copy($b,$t);chmod($t,fileperms($f)&0777);chown($t,fileowner($f));chgrp($t,filegroup($f));rename($t,$f);echo "BACKEND_ROLLBACK_PASS";'
   (R/(s+'-backend-rollback.log')).write_bytes(ssh(s,'/www/server/php/74/bin/php --',php.encode()))
   ssh(s,'/etc/init.d/ruihao_swoole restart'if s=='rh'else'systemctl restart mohe-010-swoole.service')
  raise

def push():
 p=guard();assert read('rh-verified.json')['result']=='PASS';ref='refs/heads/'+p['branch'];env={**os.environ,'GIT_SSH_COMMAND':'ssh -i /Users/xiaojunpeng/.ssh/id_ed25519_gitee -o IdentitiesOnly=yes -o BatchMode=yes -o ConnectTimeout=15'}
 run(['git','push','origin',ref+':'+ref],env=env,timeout=120);assert run(['git','ls-remote','origin',ref],env=env).decode().split()[0]==p['commit'];save('push.json',{'result':'PASS','commit':p['commit'],'remote':'origin','branch':p['branch']});print('PUSH_VERIFIED',flush=True)
if __name__=='__main__':
 mode=sys.argv[1]
 if mode=='prepare':prepare()
 elif mode=='deploy':deploy(sys.argv[2])
 elif mode=='push':push()
 else:raise ValueError('prepare|deploy site|push')
