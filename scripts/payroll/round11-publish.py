"""第11轮已授权逐站发布执行器：精确文件、独立备份、业务/旧功能验证及受序列保护的push。"""
from pathlib import Path
from datetime import datetime
from zoneinfo import ZoneInfo
import importlib,subprocess,json,hashlib,tarfile,io,shlex,sys,re
R=importlib.import_module('round11-release');OLD=importlib.import_module('round9-release')
ROOT=R.ROOT;E=R.E;SOURCE='fdefbefb8591cfca481746a17ffb2bc6bd464534'
def guard():
 f=R.read('fixed-supplement.json');a=R.read('supplement-acceptance.json')
 assert a['accepted']and a['fingerprint']==f['fingerprint']
 assert R.run(['git','rev-parse','HEAD']).decode().strip()==SOURCE
 for p in f['files']:assert R.sha((ROOT/p['path']).read_bytes())==p['sha256'],p['path']
 for p in f['frontend_assets_unchanged_from_accepted_candidate']:assert R.sha(Path(p['path']).read_bytes())==p['sha256']
 return f
def prepare():
 f=guard();runtime={p['path'].removeprefix('后端代码/'):(ROOT/p['path']).read_bytes()for p in f['files']if p['path'].startswith('后端代码/')}
 build=Path('/private/tmp/beauty-r11-store-reconciliation-build')
 for a in f['frontend_assets_unchanged_from_accepted_candidate']:runtime['public/view_cashier_v3/'+str(Path(a['path']).relative_to(build))]=Path(a['path']).read_bytes()
 for site in R.HOSTS:
  prior=R.read(site+'-preflight.json');baseline=prior['files']|prior['cashier_assets']|prior['mobile_assets']
  fresh=OLD.inspect(site,set(baseline)|set(runtime))
  for p,h in baseline.items():assert fresh['files'][p]==h,'Changed live baseline '+site+':'+p
  assert (fresh['mode'],fresh['position_model'],fresh['realtime_start_date'])==(prior['mode'],prior['position_model'],prior['realtime_start_date'])
  selected={p:b for p,b in runtime.items()if fresh['files'][p]!=R.sha(b)}
  m={'site':site,'source_commit':SOURCE,'files':{p:R.sha(b)for p,b in selected.items()},'before':{p:fresh['files'][p]for p in selected},'baseline_files':fresh['files'],'preserve':{p:h for p,h in fresh['files'].items()if p not in selected},'business':fresh['business'],'bounds':fresh['bounds'],'salary_info_sha256':fresh['salary_info_sha256'],'mode':fresh['mode'],'position_model':fresh['position_model'],'cutover':fresh['realtime_start_date']}
  R.write(site+'-manifest.json',m);R.write(site+'-refreshed-preflight.json',fresh)
  package=E/(site+'-payload.tar.gz')
  with tarfile.open(package,'w:gz')as archive:
   for p,data in [('manifest.json',json.dumps(m).encode())]+[('files/'+p,b)for p,b in selected.items()]:
    info=tarfile.TarInfo('payload/'+p);info.size=len(data);info.mode=0o600;archive.addfile(info,io.BytesIO(data))
  package.chmod(0o600);R.write(site+'-package.json',{'sha256':R.sha(package.read_bytes()),'source_commit':SOURCE,'files':len(selected)})
  print(site,'PREPARED',len(selected),flush=True)
def deploy(site):
 guard()
 if site!='rh':assert R.read('rh-verified.json')['result']=='PASS'and R.read('push.json')['result']=='PASS'
 if site=='010':assert R.read('008-verified.json')['result']=='PASS'
 m=R.read(site+'-manifest.json');pre=OLD.inspect(site,m['baseline_files'],m['bounds'])
 assert pre['files']==m['baseline_files'],'Prepared file baseline changed'
 assert pre['business']==m['business']and pre['salary_info_sha256']==m['salary_info_sha256'],'Prepared business baseline changed'
 backup='/www/backups/'+site+'/round11-'+SOURCE[:12]+'-'+datetime.now(ZoneInfo('Asia/Shanghai')).strftime('%Y%m%d%H%M%S')
 package=E/(site+'-payload.tar.gz');assert R.sha(package.read_bytes())==R.read(site+'-package.json')['sha256']
 installer=ROOT/'scripts/payroll/round11-release-install.php';metadata=ROOT/'scripts/payroll/round11-reconciliation-install.php'
 R.ssh(site,'umask 077; mkdir -p '+shlex.quote(backup));R.run(['scp','-q','-O',str(package),str(installer),str(metadata),R.HOSTS[site]+':'+backup+'/'],timeout=180)
 command='/www/server/php/74/bin/php '+shlex.quote(backup+'/round11-release-install.php')+' '+site+' '+shlex.quote(backup)
 restart='/etc/init.d/ruihao_swoole restart'if site=='rh'else'systemctl restart mohe-'+site+'-swoole.service'
 health="ss -lnt | grep -q ':20800 '"if site=='rh'else'systemctl is-active --quiet mohe-'+site+'-swoole.service'
 script=f'''set -euo pipefail
umask 077
cd {shlex.quote(backup)}
test "$(sha256sum {package.name} | cut -d' ' -f1)" = '{R.sha(package.read_bytes())}'
test "$(sha256sum round11-release-install.php | cut -d' ' -f1)" = '{R.sha(installer.read_bytes())}'
test "$(sha256sum round11-reconciliation-install.php | cut -d' ' -f1)" = '{R.sha(metadata.read_bytes())}'
tar -xzf {package.name}
find payload/files -type f -name '*.php' -exec /www/server/php/74/bin/php -l {{}} \\;
/www/server/php/74/bin/php -l round11-release-install.php
{command} prepare > prepare.json
rollback() {{ set +e; {command} rollback; {restart}; exit 1; }}
trap rollback ERR
{command} install > install.json
{command} activate > activate.json
{restart}
{health}
{command} verify > verify.json
trap - ERR
cat verify.json
'''
 R.write(site+'-started.json',{'source_commit':SOURCE,'backup':backup,'installer_sha256':R.sha(installer.read_bytes()),'metadata_installer_sha256':R.sha(metadata.read_bytes()),'payload_sha256':R.sha(package.read_bytes())})
 installed=False
 try:
  log=R.ssh(site,'bash -s',script.encode());(E/(site+'-install.log')).write_bytes(log);installed=True
  result=json.loads(R.ssh(site,command+' verify'));assert result['result']=='PASS'
  live=R.probe(site,False);expected=R.read(site+'-candidate-probe.json')
  assert live['regressions']==expected['regressions'],'Old report result changed'
  assert (live['matrix_sha256'],live['matrix_columns'],live['export_rows'])==(expected['matrix_sha256'],expected['matrix_columns'],expected['export_rows']),'New report differs from readonly candidate'
  after=OLD.inspect(site,m['baseline_files'],m['bounds'])
  for p,h in m['preserve'].items():assert after['files'][p]==h,'Preserved file changed: '+p
  for p,h in m['files'].items():assert after['files'][p]==h,'Target file mismatch: '+p
  # 本轮目录节点及其审计允许变化；角色/岗位及所有已有业务、工资、冻结条件仍须一致。
  before_business={k:v for k,v in m['business'].items()if k!='store_v3_permission_catalog'}
  after_business={k:v for k,v in after['business'].items()if k!='store_v3_permission_catalog'}
  assert before_business==after_business and after['salary_info_sha256']==m['salary_info_sha256'],'Business or grants changed'
  assert (after['mode'],after['position_model'],after['realtime_start_date'])==(m['mode'],m['position_model'],m['cutover']),'Algorithm config changed'
  checked=[]
  for p,h in m['files'].items():
   if p.startswith('public/'):
    data=R.run(['curl','-fsS','--max-time','30','https://'+site+'.cc3798.com/'+p.removeprefix('public/')]);assert R.sha(data)==h,'Public asset mismatch: '+p;checked.append(p)
  public={}
  for p in ['/admin/login','/view_cashier_v3/']:
   status=R.run(['curl','-sS','--max-time','30','-o','/dev/null','-w','%{http_code}','https://'+site+'.cc3798.com'+p]).decode();assert status in ['200','401'];public[p]=status
  if site=='rh':
   for p in ['/preview-8080/','/store-v3/']:
    status=R.run(['curl','-sS','--max-time','20','-o','/dev/null','-w','%{http_code}','https://rh.cc3798.com'+p]).decode();assert status=='410';public[p]=status
  R.write(site+'-postflight.json',after);result.update(backup=backup,public_assets_verified=checked,public_status=public,business_and_grants_unchanged=True,live_query=live);R.write(site+'-verified.json',result)
  print(site,'DEPLOYED_VERIFIED',flush=True)
 except BaseException as error:
  if isinstance(error,subprocess.CalledProcessError):(E/(site+'-install-error.log')).write_bytes((error.output or b'')+(error.stderr or b''))
  if installed:
   try:R.ssh(site,command+' rollback && '+restart)
   except BaseException as rollback_error:R.write(site+'-rollback-error.json',{'error':str(rollback_error)})
  raise
def push():
 guard();assert R.read('rh-verified.json')['result']=='PASS';branch=R.run(['git','branch','--show-current']).decode().strip();assert branch=='010/basic-report-menu'
 R.run(['git','push','origin','HEAD:refs/heads/'+branch],timeout=180)
 assert R.run(['git','ls-remote','origin','refs/heads/'+branch],timeout=90).decode().split()[0]==SOURCE
 R.write('push.json',{'result':'PASS','commit':SOURCE,'branch':branch,'remote':'origin'});print('PUSH_VERIFIED',SOURCE,flush=True)
if __name__=='__main__':
 if sys.argv[1]=='prepare':prepare()
 elif sys.argv[1]=='deploy'and sys.argv[2]in R.HOSTS:deploy(sys.argv[2])
 elif sys.argv[1]=='push':push()
 else:raise RuntimeError('Explicit authorized phase required')
