"""R28 fixed payload and sequential customer release; no shared index mutation."""
import concurrent.futures,hashlib,json,os,subprocess,sys,tarfile
from pathlib import Path
ROOT=Path(__file__).resolve().parent.parent
E=ROOT/'tests/store-permission-r28/evidence/release';BASE=Path('/private/tmp/mohe-r28-deployment-20261023')
SITES={'rh':('rh-server','rh.cc3798.com','/etc/init.d/ruihao_swoole restart',"ss -lnt | grep -q ':20800 '"),'008':('mohe-server','008.cc3798.com','systemctl restart mohe-008-swoole.service','systemctl is-active --quiet mohe-008-swoole.service'),'010':('mohe-server','010.cc3798.com','systemctl restart mohe-010-swoole.service','systemctl is-active --quiet mohe-010-swoole.service')}
def run(args,**kw):return subprocess.check_output(args,cwd=ROOT,**kw)
def sha(b):return hashlib.sha256(b).hexdigest()
def save(name,v):(E/name).write_text(json.dumps(v,ensure_ascii=False,indent=2)+'\n')
def read(name):return json.loads((E/name).read_text())
def ssh(site,cmd,data=None):return run(['ssh','-q','-o','BatchMode=yes','-o','ConnectTimeout=10',SITES[site][0],cmd],input=data)
def probe(site):return json.loads(ssh(site,'/www/server/php/74/bin/php -- '+site,(ROOT/'scripts/r28-permissions-inspect.php').read_bytes()))
trees=read('trees.json')
def commit():
 if (E/'commit.json').exists():raise RuntimeError('Commit already recorded')
 if run(['git','rev-parse','HEAD']).decode().strip()!=trees['head']:raise RuntimeError('Shared HEAD changed')
 index=ROOT/run(['git','rev-parse','--git-path','index']).decode().strip()
 if sha(index.read_bytes())!=trees['sharedIndexSha256']:raise RuntimeError('Shared index changed')
 # Independent accepted baseline brings R27/Growth into the ancestry, without switching the shared checkout.
 base=run(['git','commit-tree',trees['baselineTree'],'-p',trees['head'],'-p',trees['acceptedBaselineCommit']],input='R28-00028 补齐已验收上线的R27及成长管理源码基线\n'.encode()).decode().strip()
 new=run(['git','commit-tree',trees['candidateTree'],'-p',base],input='R28-00028 门店端权限规则与配置化顶部菜单闭环\n\n产品经理已确认测试通过；只纳入本轮47项验收清单的独立变更，保留既有岗位业务权限与已上线功能。\n'.encode()).decode().strip()
 run(['git','update-ref','refs/heads/'+trees['branch'],new,'0'*40])
 if sha(index.read_bytes())!=trees['sharedIndexSha256']:raise RuntimeError('Shared index drift')
 save('commit.json',{**trees,'baselineCommit':base,'commit':new,'actualFiles':run(['git','diff','--name-only',base,new]).decode().splitlines()});print('Committed',new)
def package():
 info=read('commit.json');commit=info['commit'];front={};baseline={}
 for kind,terminal in [('cashier','cashier-v3'),('admin','admin')]:
  build=read('candidate-'+terminal+'-build.json');old=read('baseline-'+terminal+'-build.json')
  if build['tree']not in {trees['candidateTree'],trees.get('candidateBuildTree')} or old['tree']not in {trees['baselineTree'],trees.get('baselineBuildTree')}:raise RuntimeError('Wrong immutable build')
  # 并发已验收提交只增加010迁移记录；构建输入的整个前端/运行时后端必须字节一致。
  for built,committed in [(build['tree'],trees['candidateTree']),(old['tree'],trees['baselineTree'])]:
   if run(['git','diff','--name-only',built,committed,'--','前端代码','后端代码/app','后端代码/route','后端代码/config']):raise RuntimeError('Concurrent runtime source change requires new build and acceptance')
  front[kind]=build;baseline[kind]=old
 if BASE.exists():raise RuntimeError('Payload already exists')
 payload=BASE/'payload';payload.mkdir(parents=True)
 manifest={'commit':commit,'sourceTree':trees['candidateTree'],'backend':{},'frontends':{},'before':{},'menuConfigSha256':sha((E/'accepted-menu-config.json').read_bytes())}
 for site in SITES:
  before=read(site+'-before-including-admin-assets.json')
  if probe(site)!=before:raise RuntimeError('Live baseline changed: '+site)
  for f in before['frontends']:
   if f['kind']=='admin' and f['root'].endswith('-8080'):continue
   assets=baseline[f['kind']]['assets'];entry='index.html' if f['kind']=='cashier' else 'system.html'
   if f['kind']=='admin':
    proof=read('admin-baseline-proof.json')
    if proof['result']!='PASS'or proof['rebuiltTree']!=baseline['admin']['tree']:raise RuntimeError('Missing accepted baseline graph proof')
    assets=proof['liveAssets']
   if f['sha256']!=assets[entry]:raise RuntimeError('Baseline HTML not reproduced: '+site+' '+f['kind'])
   for name,h in f['assets'].items():
    local=name.removeprefix('/view_cashier_v3/') if f['kind']=='cashier' else name.lstrip('/')
    if assets.get(local)!=h:raise RuntimeError('Baseline asset not reproduced: '+name)
  manifest['before'][site]=before
 for rec in trees['files']:
  f=rec['path']
  if not f.startswith('后端代码/'):continue
  target=f.removeprefix('后端代码/');data=run(['git','show',commit+':'+f]);dest=payload/'backend'/target;dest.parent.mkdir(parents=True,exist_ok=True);dest.write_bytes(data);manifest['backend'][target]=sha(data)
 for kind,build in front.items():
  # 平台只发布本轮终端入口及其编译资源；不覆盖客户favicon、公共静态目录或其他终端入口。
  selected={f:h for f,h in build['assets'].items()if kind=='cashier' or f=='system.html' or f.startswith('view_admin/')}
  if kind=='admin':
   for f,h in build['assets'].items():
    if f not in selected and baseline[kind]['assets'].get(f)!=h:raise RuntimeError('Unscoped public asset changed: '+f)
  manifest['frontends'][kind]=selected
  for f,h in selected.items():
   data=(Path(build['output'])/f).read_bytes()
   if sha(data)!=h:raise RuntimeError('Generated asset changed')
   dest=payload/kind/f;dest.parent.mkdir(parents=True,exist_ok=True);dest.write_bytes(data)
 sql=run(['git','show',commit+':后端代码/database/upgrades/2026-10-23-门店端独立权限规则/02-正式升级.sql']);(payload/'02-upgrade.sql').write_bytes(sql);manifest['migrationSha256']=sha(sql)
 (payload/'menu-config.json').write_bytes((E/'accepted-menu-config.json').read_bytes());(payload/'manifest.json').write_text(json.dumps(manifest,ensure_ascii=False,indent=2))
 with tarfile.open(BASE/'payload.tar.gz','w:gz')as tar:tar.add(payload,arcname='payload')
 save('payload.json',{'commit':commit,'sha256':sha((BASE/'payload.tar.gz').read_bytes()),'manifest':manifest});print('PAYLOAD_PASS',commit)
def public_verify(site):
 manifest=read('payload.json')['manifest'];domain=SITES[site][1];checks=[]
 if site=='010':
  # 沿用该实例部署前的实际访问保护；不读取或修改 Basic Auth、账号或代理配置。
  public=run(['curl','-sS','-o','/dev/null','-w','%{http_code}','https://'+domain+'/view_cashier_v3/']).decode()
  expected=read('perimeter-before.json')['010']
  if public!=expected:raise RuntimeError('010 perimeter protection changed')
  if public=='401':
   checks=[{'site':site,'publicProtection':'401 preserved','assetVerification':'server filesystem fingerprints + read-only app smoke','result':'PASS'}];save(site+'-public.json',checks);return checks
 bases=[('cashier','https://'+domain+'/view_cashier_v3/'),('admin','https://'+domain+'/')]
 if site=='rh':bases.append(('cashier','https://'+domain+'/preview-8080/view_cashier_v3/'))
 for kind,base in bases:
  assets=manifest['frontends'][kind];entry='index.html'if kind=='cashier'else'system.html'
  # Verify every immutable JS/CSS reached by this build and the entry itself.
  selected={p:h for p,h in assets.items()if p==entry or p.endswith(('.js','.css'))}
  def fetch(item):
   p,h=item;data=run(['curl','-fsS','--max-time','90',base+p])
   # Existing RH reverse proxy rewrites only HTML asset prefixes for preview.
   if p=='index.html' and '/preview-8080/' in base:
    expected=(BASE/'payload/cashier/index.html').read_bytes().replace(b'/view_cashier_v3/',b'/preview-8080/view_cashier_v3/')
    h=sha(expected)
   if sha(data)!=h:raise RuntimeError('Public asset mismatch: '+base+p)
   return {'url':base+p,'sha256':h,'result':'PASS'}
  with concurrent.futures.ThreadPoolExecutor(max_workers=4)as pool:checks.extend(pool.map(fetch,selected.items()))
 save(site+'-public.json',checks);return checks
def deploy(site):
 info=read('commit.json');commit=info['commit'];manifest=read('payload.json')['manifest'];before=manifest['before'][site]
 if site!='rh':
  for prior in (['rh']if site=='008'else['rh','008']):
   if read(prior+'-verified.json')['result']!='PASS':raise RuntimeError('Previous site not verified')
  if read('push-verified.json')['commit']!=commit:raise RuntimeError('Verified push required')
 if probe(site)!=before:raise RuntimeError('Live changed before switch: '+site)
 backup='/www/backups/'+site+'/20261023-r28-'+commit[:8]
 ssh(site,"test ! -e '"+backup+"' && mkdir -m 700 -p '"+backup+"'")
 sources=[BASE/'payload.tar.gz',ROOT/'scripts/r28-permissions-install.php',ROOT/'scripts/r28-permissions-smoke.php']
 run(['scp','-q',*map(str,sources),SITES[site][0]+':'+backup+'/'])
 if probe(site)!=before:raise RuntimeError('Live changed during upload')
 verifier='\n'.join("test \"$(sha256sum '"+backup+'/'+p.name+"' | cut -d' ' -f1)\" = '"+sha(p.read_bytes())+"'"for p in sources)
 installer='/www/server/php/74/bin/php '+backup+'/r28-permissions-install.php '+site
 restart,health=SITES[site][2:]
 shell=f'''set -euo pipefail
{verifier}
tar -xzf '{backup}/payload.tar.gz' -C '{backup}'
{installer} prepare '{backup}'
gzip -t '{backup}/before/metadata.json.gz'
tar -czf '{backup}/before.tar.gz' -C '{backup}' before prepared.json
gzip -t '{backup}/before.tar.gz'
find '{backup}/payload/backend' -type f -name '*.php' -exec /www/server/php/74/bin/php -l {{}} \\;
rollback() {{ set +e; {installer} rollback '{backup}'; {restart}; exit 1; }}
trap rollback ERR
{installer} install '{backup}'
{restart}
{health}
{installer} verify '{backup}'
/www/server/php/74/bin/php '{backup}/r28-permissions-smoke.php' {site}
trap - ERR
echo R28_INSTANCE_PASS
'''
 try:
  ssh(site,'bash -s > '+backup+'/deployment-output.log 2>&1',shell.encode())
  out=ssh(site,"cat '"+backup+"/deployment-output.log'");(E/(site+'-deployment.log')).write_bytes(out)
  if b'R28_INSTANCE_PASS'not in out:raise RuntimeError('No completion marker')
  verify_installed(site)
 except BaseException:
  out=ssh(site,"cat '"+backup+"/deployment-output.log'");(E/(site+'-deployment.log')).write_bytes(out);raise
def verify_installed(site,reuse_public=False):
 info=read('commit.json');commit=info['commit'];manifest=read('payload.json')['manifest'];before=manifest['before'][site];backup='/www/backups/'+site+'/20261023-r28-'+commit[:8]
 ssh(site,'/www/server/php/74/bin/php '+backup+'/r28-permissions-install.php '+site+' verify '+backup)
 after=probe(site)
 for f,h in before['backend'].items():
  if after['backend'].get(f)!=(manifest['backend'].get(f,h)):raise RuntimeError('Untouched backend drift: '+f)
 for f,h in manifest['backend'].items():
  if f.startswith(('app/','route/','config/'))and after['backend'].get(f)!=h:raise RuntimeError('Backend mismatch: '+f)
 if after['protected']!=before['protected']:raise RuntimeError('Protected entry changed')
 for f in before['frontends']:
  if f['kind']=='admin'and f['root'].endswith('-8080')and f not in after['frontends']:raise RuntimeError('Unrequested preview platform changed')
 checks=read(site+'-public.json')if reuse_public and (E/(site+'-public.json')).exists()else public_verify(site)
 cached=read(site+'-public-browser.json')if reuse_public and (E/(site+'-public-browser.json')).exists()else []
 if not cached or any(r.get('commit')!=commit or r.get('result')!='PASS'for r in cached):
  browser=run(['/Users/xiaojunpeng/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node','scripts/r28-permissions-public-browser.cjs',site],stderr=subprocess.STDOUT);(E/(site+'-public-browser.log')).write_bytes(browser)
 save(site+'-after.json',after);save(site+'-verified.json',{'site':site,'commit':commit,'result':'PASS','backup':backup,'checks':len(checks),'menuConfigSha256':manifest['menuConfigSha256'],'unrelatedBackend':'unchanged','otherChannels':'unchanged','businessWrites':0});print('DEPLOY_VERIFIED',site,commit)

def push():
 info=read('commit.json');commit=info['commit']
 if read('rh-verified.json')['commit']!=commit:raise RuntimeError('RH verification required')
 run(['git','push','origin','refs/heads/'+info['branch']+':refs/heads/'+info['branch']],stderr=subprocess.STDOUT)
 actual=run(['git','ls-remote','origin','refs/heads/'+info['branch']]).decode().split()[0]
 if actual!=commit:raise RuntimeError('Push fingerprint mismatch')
 save('push-verified.json',{'commit':commit,'branch':info['branch'],'result':'PASS'});print('PUSH_VERIFIED',commit)
if __name__=='__main__':
 action=sys.argv[1]
 if action=='commit':commit()
 elif action=='package':package()
 elif action=='push':push()
 elif action=='verify':verify_installed(sys.argv[2],reuse_public=True)
 elif action=='deploy':deploy(sys.argv[2])
 else:raise RuntimeError('Unknown action')
