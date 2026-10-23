"""Fixed one-file release: private Git index, per-instance backup, RH -> push -> 008 -> 010."""
import hashlib,json,os,subprocess,sys,tempfile
from pathlib import Path
ROOT=Path(__file__).resolve().parent.parent;E=ROOT/'tests/fund-management/evidence/R29-ledger-summary/release';SOURCE='后端代码/app/services/fund/StoreFundServices.php';REMOTE='app/services/fund/StoreFundServices.php'
SITES={'rh':('rh-server','rh.cc3798.com','/etc/init.d/ruihao_swoole restart',"ss -lnt | grep -q ':20800 '"),'008':('mohe-server','008.cc3798.com','systemctl restart mohe-008-swoole.service','systemctl is-active --quiet mohe-008-swoole.service'),'010':('mohe-server','010.cc3798.com','systemctl restart mohe-010-swoole.service','systemctl is-active --quiet mohe-010-swoole.service')}
def run(args,**kw):return subprocess.check_output(args,cwd=ROOT,**kw)
def sha(b):return hashlib.sha256(b).hexdigest()
def read(p):return json.loads((E/p).read_text())
def save(p,v):(E/p).write_text(json.dumps(v,ensure_ascii=False,indent=2)+'\n')
def ssh(site,command,input=None):return run(['ssh','-q','-o','BatchMode=yes',SITES[site][0],command],input=input)
def probe(site):return json.loads(ssh(site,'/www/server/php/74/bin/php -- '+site,(ROOT/'scripts/r29-fund-instance.php').read_bytes()))
def commit():
 fixed=json.loads((E.parent/'fixed-files.json').read_text());baseline=read('baseline.json');head=run(['git','rev-parse','HEAD']).decode().strip()
 if head!=baseline['head']:raise RuntimeError('Shared HEAD changed')
 for f,h in fixed['files'].items():
  if sha((ROOT/f).read_bytes())!=h:raise RuntimeError('Accepted file changed: '+f)
 for site in SITES:
  before=read(site+'-before.json')
  if probe(site)!=before or before['files'][REMOTE]!=sha(run(['git','show',baseline['baselineTree']+':'+SOURCE])):raise RuntimeError('Affected live baseline changed')
 indexPath=ROOT/run(['git','rev-parse','--git-path','index']).decode().strip();indexHash=sha(indexPath.read_bytes());fd,index=tempfile.mkstemp(prefix='r29-private-index-');os.close(fd);os.unlink(index);env=dict(os.environ,GIT_INDEX_FILE=index)
 try:
  base=run(['git','commit-tree',baseline['baselineTree'],'-p',head,'-p',baseline['r28']],input='R29-00001 前置合入已验收第28轮源码基线\n'.encode()).decode().strip();run(['git','read-tree',base],env=env)
  files=list(fixed['files'])+[str(p.relative_to(ROOT))for p in E.parent.iterdir()if p.is_file()]+[str(p.relative_to(ROOT))for p in ROOT.glob('scripts/r29-fund-*')]
  for f in sorted(set(files)):
   blob=run(['git','hash-object','-w','--stdin'],input=(ROOT/f).read_bytes()).decode().strip();run(['git','update-index','--add','--cacheinfo','100644',blob,f],env=env)
  tree=run(['git','write-tree'],env=env).decode().strip();diff=run(['git','diff','--name-only',base,tree]).decode().splitlines()
  if [f for f in diff if f.startswith('后端代码/')]!=[SOURCE] or any(f.startswith('前端代码/')for f in diff):raise RuntimeError('Unscoped business changes')
  commit=run(['git','commit-tree',tree,'-p',base],input='R29-00001 收支台账摘要导出闭环\n\n产品经理已确认验收。保留摘要原文0，沿用已授权台账投影、列与余额口径；真实Excel及页面下载均已对账。\n'.encode()).decode().strip();branch='release/r29-fund-summary-20261024';run(['git','update-ref','refs/heads/'+branch,commit,'0'*40])
  if sha(indexPath.read_bytes())!=indexHash or run(['git','rev-parse','HEAD']).decode().strip()!=head:raise RuntimeError('Shared checkout changed')
  save('commit.json',{'commit':commit,'branch':branch,'baselineCommit':base,'head':head,'sourceSha256':fixed['files'][SOURCE],'actualFiles':diff,'sharedIndexUntouched':True,'pmAccepted':True});print('COMMITTED',commit)
 finally:
  if Path(index).exists():Path(index).unlink()
def deploy(site):
 info=read('commit.json');commit=info['commit'];before=read(site+'-before.json');host,domain,restart,health=SITES[site];root='/www/wwwroot/'+domain;backup='/www/backups/'+site+'/20261024-r29-fund-'+commit[:8]
 if site!='rh':
  if read('push.json')['commit']!=commit:raise RuntimeError('Verified push required')
  for prior in (['rh']if site=='008'else['rh','008']):
   if read(prior+'-verified.json')['result']!='PASS':raise RuntimeError('Prior site required')
 if probe(site)!=before:raise RuntimeError('Live changed before deployment: '+site)
 payload=run(['git','show',commit+':'+SOURCE]);expected=info['sourceSha256']
 if sha(payload)!=expected:raise RuntimeError('Payload source mismatch')
 ssh(site,"test ! -e '"+backup+"' && mkdir -m 700 -p '"+backup+"'")
 ssh(site,"cat > '"+backup+"/candidate.php'",payload);ssh(site,"cat > '"+backup+"/smoke.php'",(ROOT/'scripts/r29-fund-smoke.php').read_bytes())
 if probe(site)!=before:raise RuntimeError('Live changed during upload')
 old=before['files'][REMOTE];target=root+'/'+REMOTE;smokeSha=sha((ROOT/'scripts/r29-fund-smoke.php').read_bytes())
 shell=f'''set -euo pipefail
test "$(sha256sum '{backup}/candidate.php' | cut -d' ' -f1)" = '{expected}'
test "$(sha256sum '{backup}/smoke.php' | cut -d' ' -f1)" = '{smokeSha}'
test "$(sha256sum '{target}' | cut -d' ' -f1)" = '{old}'
cp -p '{target}' '{backup}/before.php'
test "$(sha256sum '{backup}/before.php' | cut -d' ' -f1)" = '{old}'
/www/server/php/74/bin/php -l '{backup}/candidate.php'
rollback() {{ set +e; current=$(sha256sum '{target}' | cut -d' ' -f1); if test "$current" = '{expected}'; then cp -p '{backup}/before.php' '{target}.r29-rollback'; mv '{target}.r29-rollback' '{target}'; {restart}; fi; exit 1; }}
trap rollback ERR
cp '{backup}/candidate.php' '{target}.r29-next'
chmod --reference='{target}' '{target}.r29-next'
chown --reference='{target}' '{target}.r29-next'
mv '{target}.r29-next' '{target}'
{restart}
{health}
su -s /bin/sh www -c '/www/server/php/74/bin/php /dev/stdin {site}' < '{backup}/smoke.php'
test "$(sha256sum '{target}' | cut -d' ' -f1)" = '{expected}'
trap - ERR
echo R29_FUND_INSTANCE_PASS
'''
 try:ssh(site,'bash -s > '+backup+'/release.log 2>&1',shell.encode())
 finally:(E/(site+'-deployment.log')).write_bytes(ssh(site,'cat '+backup+'/release.log'))
 log=(E/(site+'-deployment.log')).read_bytes()
 if b'R29_FUND_INSTANCE_PASS'not in log or b'"result":"PASS"'not in log:raise RuntimeError('Missing verified completion')
 after=probe(site);expectedProbe=json.loads(json.dumps(before));expectedProbe['files'][REMOTE]=expected
 if after!=expectedProbe:raise RuntimeError('Unexpected adjacent file, entry, configuration or migration change')
 save(site+'-after.json',after);save(site+'-verified.json',{'site':site,'commit':commit,'result':'PASS','beforeSourceSha256':old,'sourceSha256':expected,'backup':backup,'frontends':'unchanged; formal and preview recorded separately','configurationAndMigrations':'unchanged','businessWrites':0});print('DEPLOY_VERIFIED',site,commit)
def push():
 info=read('commit.json')
 if read('rh-verified.json')['commit']!=info['commit']:raise RuntimeError('RH verified deployment required')
 out=run(['git','push','origin','refs/heads/'+info['branch']+':refs/heads/'+info['branch']],stderr=subprocess.STDOUT);(E/'push.log').write_bytes(out)
 if run(['git','ls-remote','origin','refs/heads/'+info['branch']]).decode().split()[0]!=info['commit']:raise RuntimeError('Remote SHA mismatch')
 save('push.json',{'commit':info['commit'],'branch':info['branch'],'result':'PASS'});print('PUSH_VERIFIED',info['commit'])
if __name__=='__main__':
 if sys.argv[1]=='commit':commit()
 elif sys.argv[1]=='deploy':deploy(sys.argv[2])
 elif sys.argv[1]=='push':push()
 else:raise RuntimeError('Explicit phase required')
