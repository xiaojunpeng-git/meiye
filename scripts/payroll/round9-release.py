"""第9轮逐实例固定发布：瑞昊核验→push→008→010；不迁移业务数据，不上传小程序。"""
from pathlib import Path
import subprocess,json,hashlib,base64,shlex,tarfile,io,sys,re
from datetime import datetime
ROOT=Path(__file__).resolve().parents[2];E=ROOT/'tests/customer-010/evidence/round9-release'
SOURCE='5cd3f53c3efb0e6da96354e8ff1ba8f568f1371b';HOSTS={'rh':'rh-server','008':'mohe-server','010':'mohe-server'}
def run(args,**kw):return subprocess.check_output(args,cwd=ROOT,stderr=subprocess.PIPE,**kw)
def sha(b):return hashlib.sha256(b).hexdigest()
def read(n):return json.loads((E/n).read_text())
def write(n,d):(E/n).write_text(json.dumps(d,ensure_ascii=False,indent=2))
def ssh(site,cmd,data=None):return run(['ssh','-q','-o','BatchMode=yes',HOSTS[site],cmd],input=data,timeout=180)
def inspect(site,files,bounds=None):
 code=base64.b64encode((ROOT/'scripts/payroll/round9-release-inspect.php').read_bytes()).decode()
 cmd='/www/server/php/74/bin/php -r '+shlex.quote('eval(substr(base64_decode("'+code+'"),5));')+' '+site
 body={'files':list(files)}
 if bounds is not None:body['bounds']=bounds
 return json.loads(ssh(site,cmd,json.dumps(body).encode()))
def check_source():
 fixed=read('R38基线补齐与固定补验-2026-10-28.json')
 for p,h in fixed['files_sha256'].items():assert sha((ROOT/p).read_bytes())==sha(run(['git','show',SOURCE+':'+p]))==h,'Source drift: '+p
 return fixed
def prepare():
 fixed=check_source();build=read('candidate-build.json');files={p.removeprefix('后端代码/'):run(['git','show',SOURCE+':'+p])for p in fixed['files_sha256']if p.startswith('后端代码/')}
 for p,h in build['assets'].items():
  data=(Path(build['output'])/p).read_bytes();assert sha(data)==h;files['public/view_cashier_v3/'+p]=data
 for site in HOSTS:
  prior=read(site+'-preflight.json');selected=dict(files)
  if site!='010':selected.pop('app/services/query/metric/MetricReadTransaction.php')
  baseline=prior['files']|prior['cashier_assets']|prior['mobile_assets'];fresh=inspect(site,set(baseline)|set(selected)|{'.env'})
  for p,h in baseline.items():assert fresh['files'][p]==h,'Unknown baseline drift: '+site+':'+p
  assert fresh['mode']==prior['mode'] and fresh['position_model']==prior['position_model'] and fresh['realtime_start_date']=='','Configuration drift'
  changed={p:b for p,b in selected.items()if fresh['files'][p]!=sha(b)}
  baseline=fresh['files'];m={'site':site,'source_commit':SOURCE,'files':{p:sha(b)for p,b in changed.items()},'before':{p:baseline[p]for p in changed},'baseline_files':baseline,'preserve':{p:h for p,h in baseline.items()if p not in changed and p!='.env'},'bounds':fresh['bounds'],'business':fresh['business'],'salary_info_sha256':fresh['salary_info_sha256'],'mode':fresh['mode'],'position_model':fresh['position_model']}
  write(site+'-manifest.json',m);write(site+'-refreshed-preflight.json',fresh)
  package=E/(site+'-payload.tar.gz')
  with tarfile.open(package,'w:gz')as archive:
   for p,b in [('manifest.json',json.dumps(m).encode())]+[('files/'+p,b)for p,b in changed.items()]:
    info=tarfile.TarInfo('payload/'+p);info.size=len(b);info.mode=0o600;archive.addfile(info,io.BytesIO(b))
  package.chmod(0o600);write(site+'-package.json',{'source_commit':SOURCE,'sha256':sha(package.read_bytes()),'files':len(changed)});print(site,'PREPARED',len(changed),flush=True)
def deploy(site):
 check_source()
 if site!='rh':assert read('rh-verified.json')['result']=='PASS'and read('push.json')['result']=='PASS'
 if site=='010':assert read('008-verified.json')['result']=='PASS'
 m=read(site+'-manifest.json');pre=inspect(site,m['baseline_files'],m['bounds'])
 assert pre['files']==m['baseline_files'] and pre['business']==m['business'] and pre['salary_info_sha256']==m['salary_info_sha256'],'Prepared live/data baseline drift'
 package=E/(site+'-payload.tar.gz');assert sha(package.read_bytes())==read(site+'-package.json')['sha256']
 backup='/www/backups/'+site+'/round9-'+SOURCE[:12]+'-'+datetime.now().strftime('%Y%m%d%H%M%S')
 installer=ROOT/'scripts/payroll/round9-release-install.php';probe=ROOT/'scripts/payroll/round9-release-live.php'
 ssh(site,'umask 077; mkdir -p '+shlex.quote(backup));run(['scp','-q','-O',str(package),str(installer),str(probe),HOSTS[site]+':'+backup+'/'])
 command='/www/server/php/74/bin/php '+shlex.quote(backup+'/round9-release-install.php')+' '+site+' '+shlex.quote(backup)
 live='/www/server/php/74/bin/php '+shlex.quote(backup+'/round9-release-live.php')+' '+site
 restart='/etc/init.d/ruihao_swoole restart'if site=='rh'else'systemctl restart mohe-'+site+'-swoole.service'
 health="ss -lnt | grep -q ':20800 '"if site=='rh'else'systemctl is-active --quiet mohe-'+site+'-swoole.service'
 script=f'''set -euo pipefail
umask 077
cd {shlex.quote(backup)}
test "$(sha256sum {package.name} | cut -d' ' -f1)" = '{sha(package.read_bytes())}'
test "$(sha256sum round9-release-install.php | cut -d' ' -f1)" = '{sha(installer.read_bytes())}'
test "$(sha256sum round9-release-live.php | cut -d' ' -f1)" = '{sha(probe.read_bytes())}'
tar -xzf {package.name}
find payload/files -type f -name '*.php' -exec /www/server/php/74/bin/php -l {{}} \\;
{command} prepare > prepare.json
rollback() {{ set +e; {command} rollback; {restart}; exit 1; }}
trap rollback ERR
{command} install > install.json
{restart}
{health}
{command} activate > activate.json
{command} verify > verify.json
{live} > live.json
trap - ERR
cat verify.json
'''
 write(site+'-started.json',{'source_commit':SOURCE,'backup':backup,'installer_sha256':sha(installer.read_bytes()),'probe_sha256':sha(probe.read_bytes())})
 try:(E/(site+'-install.log')).write_bytes(ssh(site,'bash -s',script.encode()))
 except subprocess.CalledProcessError as error:
  (E/(site+'-install-error.log')).write_bytes((error.output or b'')+(error.stderr or b''));raise
 result=json.loads(ssh(site,command+' verify'));assert result['result']=='PASS'
 live_result=json.loads(ssh(site,'cat '+shlex.quote(backup+'/live.json')));assert live_result['result']=='PASS';write(site+'-live.json',live_result)
 after=inspect(site,m['baseline_files'],m['bounds']);assert after['business']==m['business']and after['salary_info_sha256']==m['salary_info_sha256'],'Existing business changed'
 write(site+'-postflight.json',after)
 for p,h in m['preserve'].items():assert after['files'][p]==h,'Unaffected resource changed'
 html_and_status=run(['curl','-sS','--max-time','30','-w','\n%{http_code}','https://'+site+'.cc3798.com/view_cashier_v3/']);html,status=html_and_status.rsplit(b'\n',1);checked=[]
 if status==b'200':
  assert sha(html)==read('candidate-build.json')['assets']['index.html']
  for a in re.findall(rb'(?:src|href)=["\x27]([^"\x27]+\.(?:js|css))["\x27]',html):
   relative=a.decode().removeprefix('/view_cashier_v3/');b=run(['curl','-fsS','--max-time','30','https://'+site+'.cc3798.com'+a.decode()]);assert sha(b)==read('candidate-build.json')['assets'][relative];checked.append(relative)
  assert len(checked)>=2
 else:assert status==b'401','Unexpected public status'
 if site=='rh':
  for p in ['preview-8080/','store-v3/']:assert run(['curl','-sS','--max-time','20','-o','/dev/null','-w','%{http_code}','https://rh.cc3798.com/'+p])==b'410'
 result.update({'backup':backup,'public_status':status.decode(),'public_assets_verified':checked,'business_unchanged':True,'real_service_query':'PASS'});write(site+'-verified.json',result);print(json.dumps(result),flush=True)
def push():
 assert read('rh-verified.json')['result']=='PASS';branch=run(['git','branch','--show-current']).decode().strip();assert branch=='010/basic-report-menu'
 head=run(['git','rev-parse','HEAD']).decode().strip();assert head==SOURCE
 run(['git','push','origin','HEAD:refs/heads/'+branch],timeout=180);remote=run(['git','ls-remote','origin','refs/heads/'+branch],timeout=90).decode().split()[0];assert remote==head
 write('push.json',{'result':'PASS','commit':head,'branch':branch,'remote':'origin'});print('PUSH_PASS',head)
if __name__=='__main__':
 if sys.argv[1]=='prepare':prepare()
 elif sys.argv[1]=='deploy'and sys.argv[2]in HOSTS:deploy(sys.argv[2])
 elif sys.argv[1]=='push':push()
 else:raise RuntimeError('Explicit phase required')
