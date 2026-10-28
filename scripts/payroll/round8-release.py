"""第八轮固定提交逐站发布，顺序瑞昊验证→push→008→010；不迁移数据。"""
from pathlib import Path
import subprocess,json,hashlib,shlex,tarfile,io,sys,re
from datetime import datetime
from round8_preflight import inspect
ROOT=Path(__file__).resolve().parents[2]
SUPPLEMENT='--remove-net' in sys.argv
E=ROOT/('tests/customer-010/evidence/round8-remove-net-release' if SUPPLEMENT else 'tests/customer-010/evidence/round8-release')
SOURCE='0100bf16' if SUPPLEMENT else '872b22e3'
HOSTS={'rh':'rh-server','008':'mohe-server','010':'mohe-server'}
def run(args,**kw):return subprocess.check_output(args,cwd=ROOT,stderr=subprocess.PIPE,**kw)
def sha(b):return hashlib.sha256(b).hexdigest()
def read(n):return json.loads((E/n).read_text())
def write(n,d):(E/n).write_text(json.dumps(d,ensure_ascii=False,indent=2))
def ssh(site,cmd,data=None):return run(['ssh','-q','-o','BatchMode=yes',HOSTS[site],cmd],input=data,timeout=180)
def source_check():
 fixed=json.loads((ROOT/('tests/customer-010/evidence/round8-remove-net-card/fixed-candidate.json' if SUPPLEMENT else 'tests/customer-010/evidence/round8-mobile-fee-project-count/fixed-candidate.json')).read_text())
 for p,h in fixed['files'].items():assert sha(run(['git','show',SOURCE+':'+p]))==h and sha((ROOT/p).read_bytes())==h,'Accepted source drift: '+p
 return fixed
def prepare():
 fixed=source_check();build=read('h5-build.json');full=run(['git','rev-parse',SOURCE]).decode().strip();assert build['commit']==full
 files={p.removeprefix('后端代码/'):run(['git','show',SOURCE+':'+p]) for p in fixed['files'] if p.startswith('后端代码/')}
 for p,h in build['assets'].items():
  data=(Path(build['output'])/p).read_bytes();assert sha(data)==h;files['public/view_mobile_v3/'+p]=data
 for site in HOSTS:
  assert read(site+'-baseline-check.json')['baseline_result']=='PASS'
  prior=read(site+'-preflight.json')
  current=inspect(site,{'files':list(files),'bounds':prior['bounds']})
  assert current['business']==prior['business'] and current['mode']==prior['mode'] and current['position_model']==prior['position_model'],'Business/config changed since preflight'
  for p,h in prior['mobile_assets'].items():assert current['mobile_assets'].get(p)==h,'Live mobile drift: '+site+':'+p
  for p,h in prior['files'].items():
   if p in current['files']:assert current['files'][p]==h,'Live shared file drift: '+site+':'+p
  changed={p:b for p,b in files.items() if sha(b)!=current['files'][p]}
  # 两后端固定文件始终保留在只读候选中，供同事务差异测试使用。
  assert all(p in changed for p in files if p.startswith('app/'))
  m={'site':site,'source_commit':full,'files':{p:sha(b) for p,b in changed.items()},'before':{p:current['files'][p] for p in changed},'baseline_files':prior['files']|prior['mobile_assets'],'preserve':{p:h for p,h in prior['files'].items() if p not in changed},'bounds':prior['bounds'],'business':prior['business'],'mode':prior['mode'],'position_model':prior['position_model'],'static_only':SUPPLEMENT}
  write(site+'-manifest.json',m)
  package=E/(site+'-payload.tar.gz')
  with tarfile.open(package,'w:gz') as archive:
   for p,b in [('manifest.json',json.dumps(m).encode())]+[('files/'+p,b) for p,b in changed.items()]:
    info=tarfile.TarInfo('payload/'+p);info.size=len(b);info.mode=0o600;archive.addfile(info,io.BytesIO(b))
  write(site+'-package.json',{'sha256':sha(package.read_bytes()),'files':len(changed),'source_commit':full});print(site,'PREPARED',len(changed),flush=True)
def deploy(site):
 source_check()
 if site!='rh':assert read('rh-verified.json')['result']=='PASS' and read('push.json')['result']=='PASS'
 if site=='010':assert read('008-verified.json')['result']=='PASS'
 m=read(site+'-manifest.json');package=E/(site+'-payload.tar.gz');assert sha(package.read_bytes())==read(site+'-package.json')['sha256']
 before=inspect(site,{'files':list(m['baseline_files']),'bounds':m['bounds']})
 assert before['files']==m['baseline_files'] and before['business']==m['business'],'Candidate/live drift before switch'
 backup='/www/backups/'+site+'/round8-'+m['source_commit'][:12]+'-'+datetime.now().strftime('%Y%m%d%H%M%S')
 installer=ROOT/'scripts/payroll/round8-release-install.php';probe=ROOT/'scripts/payroll/round8-mobile-probe.php'
 ssh(site,'umask 077; mkdir -p '+shlex.quote(backup))
 run(['scp','-q','-O',str(package),str(installer),str(probe),HOSTS[site]+':'+backup+'/'])
 command='/www/server/php/74/bin/php '+shlex.quote(backup+'/round8-release-install.php')+' '+site+' '+shlex.quote(backup)
 probe_command='/www/server/php/74/bin/php '+shlex.quote(backup+'/round8-mobile-probe.php')+' '+site+' '+shlex.quote(backup)
 # 纯手机展示补丁不重启后端，原服务保持运行；原发布流程仍按后端范围重启。
 restart='true' if SUPPLEMENT else ('/etc/init.d/ruihao_swoole restart' if site=='rh' else 'systemctl restart mohe-'+site+'-swoole.service')
 health="ss -lnt | grep -q ':20800 '" if site=='rh' else 'systemctl is-active --quiet mohe-'+site+'-swoole.service'
 script=f'''set -euo pipefail
umask 077
cd {shlex.quote(backup)}
test "$(sha256sum {package.name} | cut -d' ' -f1)" = '{sha(package.read_bytes())}'
test "$(sha256sum round8-release-install.php | cut -d' ' -f1)" = '{sha(installer.read_bytes())}'
test "$(sha256sum round8-mobile-probe.php | cut -d' ' -f1)" = '{sha(probe.read_bytes())}'
tar -xzf {package.name}
find payload/files -type f -name '*.php' -exec /www/server/php/74/bin/php -l {{}} \\;
{command} prepare > prepare.json
{probe_command} {'live' if SUPPLEMENT else 'parity'} > parity.json
rollback() {{ set +e; {command} rollback; {restart}; exit 1; }}
trap rollback ERR
{command} install > install.json
{restart}
{health}
{command} activate > activate.json
{command} verify > verify.json
{probe_command} live > mobile-live.json
trap - ERR
cat verify.json
'''
 write(site+'-started.json',{'backup':backup,'source_commit':m['source_commit'],'installer_sha256':sha(installer.read_bytes()),'probe_sha256':sha(probe.read_bytes())})
 try:output=ssh(site,'bash -s',script.encode());(E/(site+'-install.log')).write_bytes(output)
 except subprocess.CalledProcessError as error:
  (E/(site+'-install-error.log')).write_bytes((error.output or b'')+(error.stderr or b''));raise
 result=json.loads(ssh(site,command+' verify'))
 for name in ['parity','mobile-live']:
  d=json.loads(ssh(site,'cat '+shlex.quote(backup+'/'+name+'.json')));assert d['result']=='PASS';write(site+'-'+name+'.json',d)
 after=inspect(site,{'files':list(m['baseline_files']),'bounds':m['bounds']})
 assert after['business']==before['business'] and after['mode']==before['mode'] and after['position_model']==before['position_model'],'Business/config drift after switch'
 write(site+'-postflight.json',after)
 build=read('h5-build.json');url='https://'+site+'.cc3798.com/view_mobile_v3/'
 html=run(['curl','-fsS','--max-time','30',url]);disk=ssh(site,'cat '+shlex.quote('/www/wwwroot/'+site+'.cc3798.com/public/view_mobile_v3/index.html'))
 assert sha(html)==sha(disk)==build['assets']['index.html'],'Public entry mismatch'
 checked=[]
 for asset in re.findall(rb'(?:src|href)=["\x27]([^"\x27]+\.(?:js|css))["\x27]',html):
  relative=asset.decode().removeprefix('/view_mobile_v3/');data=run(['curl','-fsS','--max-time','30','https://'+site+'.cc3798.com'+asset.decode()]);assert sha(data)==build['assets'][relative];checked.append(relative)
 assert len(checked)>=2,'Entry resources unavailable'
 if site=='rh':
  for p in ['preview-8080/','store-v3/']:assert run(['curl','-sS','--max-time','20','-o','/dev/null','-w','%{http_code}','https://rh.cc3798.com/'+p])==b'410'
 result.update({'backup':backup,'mode':after['mode'],'position_model':after['position_model'],'existing_business_unchanged':True,'public_assets_verified':checked,'real_mobile_projection':'PASS'})
 write(site+'-verified.json',result);print(json.dumps(result,ensure_ascii=False),flush=True)
def push():
 assert read('rh-verified.json')['result']=='PASS';branch=run(['git','branch','--show-current']).decode().strip();assert branch=='010/basic-report-menu'
 head=run(['git','rev-parse','HEAD']).decode().strip();run(['git','push','origin','HEAD:refs/heads/'+branch],timeout=180)
 remote=run(['git','ls-remote','origin','refs/heads/'+branch],timeout=60).decode().split()[0];assert remote==head
 write('push.json',{'result':'PASS','commit':head,'source_commit':run(['git','rev-parse',SOURCE]).decode().strip(),'remote':'origin','branch':branch});print('PUSH_PASS',head)
def upload(site):
 # 同一客户的未知任务禁止重发；微信pending任务只能在用户完成本机确认后查询原ID。
 for target in HOSTS:assert read(target+'-verified.json')['result']=='PASS'
 assert read('push.json')['result']=='PASS'
 build=read(site+'-build.json');expected={'rh':('wx29d9b63c92555701','https://rh.cc3798.com'),'008':('wx14666197911d052b','https://008.cc3798.com'),'010':('wxd92c6d010bd6bf01','https://010.cc3798.com')}
 assert (build['appid'],build['origin'])==expected[site]
 output=Path(build['output']);assert json.loads((output/'project.config.json').read_text())['appid']==build['appid']
 for p,h in build['assets'].items():assert sha((output/p).read_bytes())==h,'Mini asset drift: '+p
 marker=E/(site+'-upload-started.json');assert not marker.exists(),'Reconcile original upload task before retry'
 write(marker.name,{'site':site,'appid':build['appid'],'version':build['version'],'project':str(output)})
 description='第8轮补验：去掉净销售汇总卡片，保留提成算法与计算依据' if SUPPLEMENT else '第8轮：计算法隐藏手工费，劳动明细始终显示项目数；保留原算法'
 proc=subprocess.run(['/Applications/wechatwebdevtools.app/Contents/MacOS/wechatide','-c','Codex','upload','--project',str(output),'--upload-version',build['version'],'--desc',description],cwd=ROOT,text=True,capture_output=True,timeout=180)
 (E/(site+'-upload-response.json')).write_text(proc.stdout);(E/(site+'-upload-tool.log')).write_text(proc.stderr)
 if proc.returncode:raise RuntimeError('Upload outcome requires inspection: '+site)
 response=json.loads(proc.stdout);write(site+'-upload-result.json',response);print(json.dumps(response,ensure_ascii=False),flush=True)
if __name__=='__main__':
 if sys.argv[1]=='prepare':prepare()
 elif sys.argv[1]=='deploy' and sys.argv[2] in HOSTS:deploy(sys.argv[2])
 elif sys.argv[1]=='push':push()
 elif sys.argv[1]=='upload' and sys.argv[2] in HOSTS:upload(sys.argv[2])
 else:raise RuntimeError('Explicit release phase required')
