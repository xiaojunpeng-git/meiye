"""Execute the explicitly approved R12 release, with site-isolated backups and order gates."""
from pathlib import Path
from datetime import datetime
import json,hashlib,subprocess,shlex,tarfile,io,sys,re
ROOT=Path(__file__).resolve().parents[2]; E=ROOT/'tests/customer-010/evidence/round12-release'
HOSTS={'rh':'rh-server','008':'mohe-server','010':'mohe-server'}
SOURCE='d76a8ce44b9603b15091554dc320c7b23beead1a'
def sha(b):return hashlib.sha256(b).hexdigest()
def read(n):return json.loads((E/n).read_text())
def write(n,d):(E/n).write_text(json.dumps(d,ensure_ascii=False,indent=2))
def run(args,**kw):return subprocess.check_output(args,cwd=ROOT,stderr=subprocess.PIPE,**kw)
def ssh(site,cmd,data=None):return run(['ssh','-q','-o','BatchMode=yes',HOSTS[site],cmd],input=data,timeout=240)
def guard():
 f=read('fixed-supplement.json');a=read('acceptance.json');assert a['accepted'] and a['fingerprint']==f['fingerprint']
 assert run(['git','rev-parse','HEAD']).decode().strip()==SOURCE
 for p in f['files']:assert sha((ROOT/p['path']).read_bytes())==p['sha256'],p['path']
 return f
def inspect(site,paths):
 # PHP uses filesystem bytes independently of the remote shell's ASCII locale.
 code="$r='/www/wwwroot/"+site+".cc3798.com/';$out=[];foreach(json_decode(stream_get_contents(STDIN),true)as$p){$out[$p]=is_file($r.$p)?hash_file('sha256',$r.$p):null;}echo json_encode($out);"
 return json.loads(ssh(site,'/www/server/php/74/bin/php -r '+shlex.quote(code),json.dumps(paths).encode()))
def prepare(site):
 f=guard();assets=read('candidate-assets.json');files={}
 for p in f['files']:
  if p['path'].startswith('后端代码/'):files[p['path'].removeprefix('后端代码/')]=(ROOT/p['path']).read_bytes()
 # Only the platform build's actual assets/entry, not its copied legacy public tree.
 for label in ['admin',site]:
  out=Path(f['builds'][label]['output'])
  for p,h in assets[label].items():
   if label=='admin' and not(p=='system.html' or p.startswith(('view_admin/','static_admin/'))):continue
   data=(out/p).read_bytes();assert sha(data)==h;dest='public/'+p;assert dest not in files;files[dest]=data
 prior=read(site+'-preflight.json');baseline={**prior['files'],**prior['frontend_assets']}
 preserved=['public/view_cashier_v3/index.html','public/view_inventory_v3/index.html','public/view_mobile_v3/index.html','public/view_fund_v3/index.html']
 live=inspect(site,sorted(set(baseline)|set(files)|set(preserved)))
 for p,h in baseline.items():assert live[p]==h,'Accepted live baseline drift: '+site+':'+p
 changed={p:b for p,b in files.items() if live[p]!=sha(b)}
 m={'site':site,'source_commit':SOURCE,'fingerprint':f['fingerprint'],'files':{p:sha(b)for p,b in changed.items()},'before':{p:live[p]for p in list(changed)+['.env']},'baseline':baseline,'preserve':{p:live[p]for p in preserved}}
 write(site+'-manifest.json',m)
 package=E/(site+'-payload.tar.gz')
 with tarfile.open(package,'w:gz')as tar:
  items=[('manifest.json',json.dumps(m).encode()),('install.php',(ROOT/'scripts/member-survey/install.php').read_bytes())]+[('files/'+p,b)for p,b in changed.items()]
  for name,data in items:
   info=tarfile.TarInfo('payload/'+name);info.size=len(data);info.mode=0o600;tar.addfile(info,io.BytesIO(data))
 write(site+'-package.json',{'sha256':sha(package.read_bytes()),'files':len(changed),'source_commit':SOURCE});print(site,'PAYLOAD_READY',len(changed),flush=True)
def deploy(site):
 guard()
 if site!='rh':assert read('rh-verified.json')['result']=='PASS' and read('push.json')['result']=='PASS'
 if site=='010':assert read('008-verified.json')['result']=='PASS'
 prepare(site);m=read(site+'-manifest.json');package=E/(site+'-payload.tar.gz');installer=ROOT/'scripts/member-survey/release-install.php'
 backup='/www/backups/'+site+'/round12-'+SOURCE[:12]+'-'+datetime.now().strftime('%Y%m%d%H%M%S')
 ssh(site,'umask 077; mkdir -p '+shlex.quote(backup));run(['scp','-q','-O',str(package),str(installer),HOSTS[site]+':'+backup+'/'],timeout=240)
 command='/www/server/php/74/bin/php '+shlex.quote(backup+'/release-install.php')+' '+site+' '+shlex.quote(backup)
 setup=f'''set -euo pipefail
umask 077
cd {shlex.quote(backup)}
test "$(sha256sum {package.name} | cut -d' ' -f1)" = '{sha(package.read_bytes())}'
test "$(sha256sum release-install.php | cut -d' ' -f1)" = '{sha(installer.read_bytes())}'
tar -xzf {package.name}
find payload/files -type f -name '*.php' -exec /www/server/php/74/bin/php -l {{}} \\;
{command} prepare > prepare.json
'''
 write(site+'-started.json',{'backup':backup,'source_commit':SOURCE,'payload_sha256':sha(package.read_bytes()),'installer_sha256':sha(installer.read_bytes())})
 (E/(site+'-prepare.log')).write_bytes(ssh(site,'bash -s',setup.encode()))
 restart='/etc/init.d/ruihao_swoole restart'if site=='rh'else'systemctl restart mohe-'+site+'-swoole.service'
 try:
  write(site+'-install.json',json.loads(ssh(site,command+' install')))
  ssh(site,restart)
  verification=json.loads(ssh(site,command+' verify'));assert verification['result']=='PASS'
  # Public checks exercise routed authentication, error handling and actual deployed assets.
  http=[]
  paths=['public/system.html','public/index.html']
  for p in paths:
   body=run(['curl','-fsS','--max-time','40','https://'+site+'.cc3798.com/'+p.removeprefix('public/')],timeout=50);assert sha(body)==m['files'][p],p;http.append(p)
   for asset in re.findall(rb'(?:src|href)=["\']?(/(?:view_admin|static)/[^\s>"\']+\.(?:js|css))',body):
    ap=asset.decode();data=run(['curl','-fsS','--retry','2','--max-time','90','https://'+site+'.cc3798.com'+ap],timeout=280)
    # Unchanged root H5 CSS is intentionally absent from the differential payload;
    # verify against the accepted complete asset manifest, not the changed-files list.
    accepted=read('candidate-assets.json');expected=accepted['admin'if ap.startswith('/view_admin/')else site].get(ap.lstrip('/'))
    assert sha(data)==expected,(ap,sha(data),expected);http.append(ap)
  for url in ['/api/survey/entry/'+'f'*48,'/api/survey/1','/adminapi/user/survey']:
   body=run(['curl','-fsS','--max-time','40','https://'+site+'.cc3798.com'+url],timeout=50);data=json.loads(body);assert data.get('status') not in [200,None],(url,data);http.append({'url':url,'status':data['status']})
  if site=='rh':
   for p in ['/preview-8080/','/store-v3/']:assert run(['curl','-sS','--max-time','20','-o','/dev/null','-w','%{http_code}','https://rh.cc3798.com'+p])==b'410'
  verification.update(backup=backup,http_checks=http,frontend_entries_verified=True)
  write(site+'-verified.json',verification);print(site,'DEPLOYED_VERIFIED',flush=True)
 except BaseException:
  try:
   write(site+'-rollback.json',json.loads(ssh(site,command+' rollback')));ssh(site,restart)
  except BaseException as error:write(site+'-rollback-error.json',{'error':str(error)})
  raise
def push():
 guard();assert read('rh-verified.json')['result']=='PASS';branch=run(['git','branch','--show-current']).decode().strip();assert branch=='010/basic-report-menu'
 run(['git','push','origin',SOURCE+':refs/heads/'+branch],timeout=240)
 assert run(['git','ls-remote','origin','refs/heads/'+branch],timeout=120).decode().split()[0]==SOURCE
 write('push.json',{'result':'PASS','commit':SOURCE,'remote':'origin','branch':branch});print('PUSH_VERIFIED',SOURCE,flush=True)
if __name__=='__main__':
 try:
  if sys.argv[1]=='deploy'and sys.argv[2]in HOSTS:deploy(sys.argv[2])
  elif sys.argv[1]=='push':push()
  else:raise RuntimeError('Explicit phase/target required')
 except subprocess.CalledProcessError as e:
  print((e.stderr or b'').decode()[-4000:],file=sys.stderr);raise
