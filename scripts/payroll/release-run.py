"""发布执行编排：严格rh验证→push→008→010→三家商家端上传；固定验收证据是写入门禁。"""
from pathlib import Path
import subprocess,json,hashlib,shlex,sys
r=Path(__file__).resolve().parents[2];e=r/'tests/customer-010/evidence/round6-payroll-release'
def read(n):return json.loads((e/n).read_text())
def save(n,d):(e/n).write_text(json.dumps(d,ensure_ascii=False,indent=2))
def run(a,**kw):return subprocess.check_output(a,cwd=r,stderr=subprocess.PIPE,**kw)
def sha(b):return hashlib.sha256(b).hexdigest()
def ssh(site,command,data=None):return run(['ssh','-q','-o','BatchMode=yes','rh-server' if site=='rh' else 'mohe-server',command],input=data,timeout=150)
def approved():
 a=read('acceptance.json');assert a['pm_confirmed'] and a['tree']==read('expanded-fixed-source.json')['tree']
 for p,h in a['release_files'].items():assert sha((r/p).read_bytes())==h,'Accepted infrastructure drift '+p
 return a

def deploy(site):
 assert site in ['rh','008','010'];a=approved()
 if site!='rh':assert read('rh-verified.json')['result']=='PASS' and read('push.json')['result']=='PASS'
 if site=='010':assert read('008-verified.json')['result']=='PASS'
 m=read(site+'-manifest.json');assert m['source_commit'] and run(['git','rev-parse',m['source_commit']+'^{tree}']).decode().strip()==a['tree'];package=read(site+'-package.json');assert m['tree']==a['tree'] and package['tree']==a['tree'];tar=e/(site+'-payload.tar.gz');assert sha(tar.read_bytes())==package['sha256']
 directory='/www/backups/'+site+'/20261027-payroll-release-'+m['tree'][:12]
 public_before=run(['curl','-sS','--max-time','20','-o','/dev/null','-w','%{http_code}','https://'+site+'.cc3798.com/view_mobile_v3/']).decode()
 ssh(site,'umask 077; test ! -e '+shlex.quote(directory)+' && mkdir -p '+shlex.quote(directory))
 host='rh-server'if site=='rh'else'mohe-server';installer=r/'scripts/payroll/release-install.php'
 run(['scp','-q','-O',str(tar),str(installer),host+':'+directory+'/'])
 command='/www/server/php/74/bin/php '+directory+'/release-install.php '+site+' '+directory
 root='/www/wwwroot/'+site+'.cc3798.com';restart='/etc/init.d/ruihao_swoole restart'if site=='rh'else'systemctl restart mohe-'+site+'-swoole.service';health="ss -lnt | grep -q ':20800 '"if site=='rh'else'systemctl is-active --quiet mohe-'+site+'-swoole.service'
 script=f'''set -euo pipefail
umask 077
cd '{directory}'
test "$(sha256sum '{tar.name}' | cut -d' ' -f1)" = '{package['sha256']}'
test "$(sha256sum release-install.php | cut -d' ' -f1)" = '{sha(installer.read_bytes())}'
tar -xzf '{tar.name}'
find payload/files -type f -name '*.php' -exec /www/server/php/74/bin/php -l {{}} \\;
{command} prepare
# 全部受影响入口单独归档，旧资源保留；未恢复停用的预览入口。
tar -czf frontend-before.tar.gz -C '{root}/public' view_admin view_cashier_v3 view_mobile_v3 system.html
gzip -t frontend-before.tar.gz
rollback() {{ set +e; {command} rollback; {restart}; exit 1; }}
trap rollback ERR
{command} install
{restart}
{health}
{command} verify
trap - ERR
'''
 try:out=ssh(site,'bash -s',script.encode());(e/(site+'-install.log')).write_bytes(out)
 except subprocess.CalledProcessError as error:(e/(site+'-install-error.log')).write_bytes(error.output+error.stderr);raise
 verification=json.loads(ssh(site,command+' verify'));assert verification['result']=='PASS'
 # 入口必须引用固定新资源；公开站同时校验主脚本/CSS内容，受保护010不绕过认证。
 public_after=run(['curl','-sS','--max-time','20','-o','/dev/null','-w','%{http_code}','https://'+site+'.cc3798.com/view_mobile_v3/']).decode();assert public_after==public_before
 public=[]
 for p in ['public/system.html','public/view_cashier_v3/index.html','public/view_mobile_v3/index.html']:
  # 磁盘入口及完整资源图已由verify核验；公网只接受现有200/401边界。
  url='https://'+site+'.cc3798.com/'+('admin/login'if p=='public/system.html'else p[len('public/'):].removesuffix('index.html'))
  response=run(['curl','-sS','--max-time','30','-w','\n%{http_code}',url]);body,status=response.rsplit(b'\n',1)
  if status==b'200':
   import re
   assets=re.findall(rb'(?:src|href)=["\']([^"\']+\.(?:js|css))["\']',body)
   for value in assets:
    u=value.decode()
    if not u.startswith('/') or 'public'+u not in m['required_assets']:continue
    data=run(['curl','-fsS','--max-time','30','https://'+site+'.cc3798.com'+u]);assert sha(data)==m['required_assets']['public'+u];public.append(u)
  elif status!=b'401':raise RuntimeError('Unexpected public entry '+url+':'+status.decode())
 if site=='rh':
  for path in ['preview-8080/','store-v3/']:
   assert run(['curl','-sS','--max-time','20','-o','/dev/null','-w','%{http_code}','https://rh.cc3798.com/'+path])==b'410'
 verification.update({'backup':directory,'public_assets_checked':len(public),'public_h5_before':public_before,'public_h5_after':public_after,'business_import':False,'result':'PASS'})
 save(site+'-verified.json',verification);print(json.dumps(verification),flush=True)

def push():
 approved();assert read('rh-verified.json')['result']=='PASS';branch=run(['git','branch','--show-current']).decode().strip();assert branch=='010/basic-report-menu'
 commit=run(['git','rev-parse','HEAD']).decode().strip();run(['git','push','origin','HEAD:refs/heads/'+branch],timeout=180)
 actual=run(['git','ls-remote','origin','refs/heads/'+branch],timeout=60).decode().split()[0];assert actual==commit
 save('push.json',{'result':'PASS','commit':commit,'branch':branch,'remote':'origin'});print('PUSH_PASS '+commit)

def upload(site):
 approved()
 for target in ['rh','008','010']:assert read(target+'-verified.json')['result']=='PASS'
 assert read('push.json')['result']=='PASS'
 b=read(site+'-mini-build.json');expected={'rh':('wx29d9b63c92555701','https://rh.cc3798.com'),'008':('wx14666197911d052b','https://008.cc3798.com'),'010':('wxd92c6d010bd6bf01','https://010.cc3798.com')};assert (b['appid'],b['origin'])==expected[site]
 output=Path(b['output']);assert json.loads((output/'project.config.json').read_text())['appid']==b['appid']
 for p,h in b['assets'].items():assert sha((output/p).read_bytes())==h
 marker=e/(site+'-upload-started.json');assert not marker.exists(),'Existing upload outcome must be reconciled before retry';marker.write_text(json.dumps({'site':site,'appid':b['appid'],'version':b['version']}))
 proc=subprocess.run(['/Applications/wechatwebdevtools.app/Contents/MacOS/wechatide','-c','Codex','upload','--project',str(output),'--upload-version',b['version'],'--desc','已验收工资项目提成与基础报表权限配套，保留原算法'],cwd=r,text=True,capture_output=True,timeout=180)
 (e/(site+'-upload-response.json')).write_text(proc.stdout);(e/(site+'-upload-tool.log')).write_text(proc.stderr)
 if proc.returncode:raise RuntimeError('Upload needs inspection: '+site)
 response=json.loads(proc.stdout);save(site+'-upload-result.json',response);print(json.dumps(response,ensure_ascii=False))
if __name__=='__main__':
 action=sys.argv[1]
 if action=='deploy':deploy(sys.argv[2])
 elif action=='push':push()
 elif action=='upload':upload(sys.argv[2])
 else:raise RuntimeError('Explicit phase required')
