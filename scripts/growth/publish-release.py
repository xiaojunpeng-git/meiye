"""固定提交逐实例发布；生产包只含成长后端、迁移与正式前端，不包含演示数据或密钥。"""
from pathlib import Path
import json,subprocess,hashlib,tarfile,sys,shlex
root=Path(__file__).resolve().parents[2];e=root.parent/'任务管理/进行中/美业大学成长管理/证据/发布'
site=sys.argv[2] if len(sys.argv)>2 else '';mode=sys.argv[1]
sites={'rh':('rh-server','/etc/init.d/ruihao_swoole restart'),'008':('mohe-server','systemctl restart mohe-008-swoole.service')}
def run(args,**kw):return subprocess.check_output(args,cwd=root,**kw)
def sha(b):return hashlib.sha256(b).hexdigest()
def load(n):return json.loads((e/n).read_text())
def save(n,d):(e/n).write_text(json.dumps(d,ensure_ascii=False,indent=2))
def ssh(command):return run(['ssh','-q','-o','BatchMode=yes','-o','ConnectTimeout=12',sites[site][0],command])
r=load('commit.json');commit=r['commit'];directory='/www/backups/'+site+'/20261023-growth-'+commit[:8]
if mode=='package':
 p=e/'payload';p.mkdir(exist_ok=False);t=load('trees.json');manifest={'commit':commit,'sourceTree':r['tree'],'files':{},'migrations':[],'before':{s:load(s+'-before.json') for s in sites}}
 records=[x for x in t['files'] if x['path'].startswith('后端代码/')]
 for row in records:
  f=row['path'];data=run(['git','show',commit+':'+f]);relative=f[len('后端代码/'):];dest=p/relative;dest.parent.mkdir(parents=True,exist_ok=True);dest.write_bytes(data);manifest['files'][relative]=sha(data)
  if relative.startswith('database/'):manifest['migrations'].append(relative)
 for terminal in ['admin','mobile']:
  build=load('candidate-'+terminal+'-build.json')
  for relative,expected in build['assets'].items():
   if terminal=='admin' and not(relative=='system.html' or relative.startswith('view_admin/')):continue
   data=(Path(build['output'])/relative).read_bytes();assert sha(data)==expected
   f='public/'+(relative if terminal=='admin' else 'view_mobile_v3/'+relative);dest=p/f;dest.parent.mkdir(parents=True,exist_ok=True);dest.write_bytes(data);manifest['files'][f]=expected
 (p/'manifest.json').write_text(json.dumps(manifest,ensure_ascii=False,indent=2));(e/'install-release.php').write_bytes(run(['git','show',commit+':scripts/growth/install-release.php']))
 with tarfile.open(e/'payload.tar.gz','w:gz') as tar:tar.add(p,arcname='payload')
 save('package.json',{'commit':commit,'sha256':sha((e/'payload.tar.gz').read_bytes()),'helper_sha256':sha((e/'install-release.php').read_bytes()),'files':len(manifest['files'])});print('Packaged',len(manifest['files']));sys.exit()
assert site in sites
if mode=='deploy':
 if site=='008':assert load('rh-verified.json')['commit']==commit and load('push.json')['commit']==commit
 pkg=load('package.json');assert pkg['commit']==commit
 ssh('set -eu; umask 077; mkdir -p '+shlex.quote(directory))
 for name in ['payload.tar.gz','install-release.php']:run(['scp','-q','-O',str(e/name),sites[site][0]+':'+directory+'/'+name])
 ssh('set -eu; cd '+shlex.quote(directory)+"; test \"$(sha256sum payload.tar.gz | cut -d' ' -f1)\" = '"+pkg['sha256']+"'; test \"$(sha256sum install-release.php | cut -d' ' -f1)\" = '"+pkg['helper_sha256']+"'; tar -xzf payload.tar.gz")
 for phase in ['backup','install']:
  result=json.loads(ssh('php '+shlex.quote(directory+'/install-release.php')+' '+site+' '+shlex.quote(directory)+' '+phase));save(site+'-'+phase+'.json',result);print(site,phase,flush=True)
 ssh(sites[site][1]);print(site,'restarted',flush=True)
if mode in ['verify','deploy']:
 result=json.loads(ssh('php '+shlex.quote(directory+'/install-release.php')+' '+site+' '+shlex.quote(directory)+' verify'));save(site+'-verified.json',result);print(json.dumps(result,ensure_ascii=False))
