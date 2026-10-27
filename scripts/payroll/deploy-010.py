"""用户授权的010测试部署：验收前测试例外；不提交、不push、不操作其他实例。"""
from pathlib import Path
import json,subprocess,hashlib,tarfile,io,shlex,sys
r=Path(__file__).resolve().parents[2];e=r/'tests/customer-010/evidence/round6-payroll-algorithm'
def sha(b):return hashlib.sha256(b).hexdigest()
def git(*a):return subprocess.check_output(['git',*a],cwd=r)
def ssh(cmd,body=None):return subprocess.check_output(['ssh','-q','-o','BatchMode=yes','-o','ConnectTimeout=10','mohe-server',cmd],input=body,stderr=subprocess.PIPE,timeout=100)
fixed=json.loads((e/'fixed-source.json').read_text());tree=fixed['candidateTree'];before=json.loads((e/'010-before.json').read_text());directory='/www/backups/010/20261027-payroll-'+tree[:12]
if sys.argv[1]=='package':
 files={};expected={};builds={}
 # 后续测试修复以前次已核验部署为精确基线，仍由安装器逐文件检查；不下载服务器源码。
 prior=json.loads((e/'010-prior-deploy-manifest.json').read_text()) if (e/'010-prior-deploy-manifest.json').exists() else {'files':{}}
 for terminal in ['admin','cashier-v3','mobile-vue3']:
  a=json.loads((e/f'baseline-{terminal}-build.json').read_text());b=json.loads((e/f'candidate-{terminal}-build.json').read_text())
  prefix='前端代码/'+terminal
  assert git('rev-parse',b['tree']+':'+prefix)==git('rev-parse',tree+':'+prefix),'Candidate frontend subtree drift'
  key='system.html'if terminal=='admin'else'index.html';remote='public/'+key if terminal=='admin'else'public/'+('view_cashier_v3'if terminal=='cashier-v3'else'view_mobile_v3')+'/'+key
  assert a['assets'][key]==before['files'][remote],f'Baseline must reproduce live {terminal}'
  builds[terminal]={'built_tree':b['tree'],'fixed_tree':tree,'same_source_subtree':True}
  for file,h in b['assets'].items():
   if terminal=='admin' and not(file=='system.html' or file.startswith('view_admin/')):continue
   target='public/'+file if terminal=='admin'else'public/'+('view_cashier_v3'if terminal=='cashier-v3'else'view_mobile_v3')+'/'+file
   if a['assets'].get(file)==h:continue
   data=(Path(b['output'])/file).read_bytes();assert sha(data)==h
   files[target]=data;expected[target]=a['assets'].get(file)
 for file,h in fixed['files'].items():
  if not file.startswith('后端代码/')or file.endswith('.sql'):continue
  data=(r/file).read_bytes();assert sha(data)==h
  target=file[len('后端代码/'):];files[target]=data;expected[target]=before['files'].get(target)
 expected.update({f:prior['files'][f] for f in files if f in prior['files']})
 manifest={'site':'010','tree':tree,'files':{f:sha(b)for f,b in files.items()},'before':expected,'builds':builds,'business_data_migration':False}
 schema=(r/'后端代码/database/upgrades/2026-10-27-独立职位工资规则/01-结构升级.sql').read_bytes();helper=(r/'scripts/payroll/install-010.php').read_bytes()
 with tarfile.open(e/'candidate.tar.gz','w:gz')as tar:
  for file,data in [('manifest.json',json.dumps(manifest,ensure_ascii=False,indent=2).encode()),('schema.sql',schema),('install.php',helper)]+[('payload/'+f,b)for f,b in files.items()]:
   item=tarfile.TarInfo(file);item.size=len(data);item.mode=0o600;tar.addfile(item,io.BytesIO(data))
 receipt={'tree':tree,'directory':directory,'sha256':sha((e/'candidate.tar.gz').read_bytes()),'files':len(files)}
 (e/'package.json').write_text(json.dumps(receipt,indent=2));(e/'deploy-manifest.json').write_text(json.dumps(manifest,ensure_ascii=False,indent=2));print(json.dumps(receipt));sys.exit()
package=json.loads((e/'package.json').read_text());assert package['tree']==tree
if sys.argv[1]=='deploy':
 ssh('umask 077; mkdir -p '+shlex.quote(directory))
 subprocess.run(['scp','-q','-O',str(e/'candidate.tar.gz'),'mohe-server:'+directory+'/candidate.tar.gz'],check=True)
 ssh('cd '+shlex.quote(directory)+'; test "$(sha256sum candidate.tar.gz | cut -d\' \' -f1)" = '+shlex.quote(package['sha256'])+' && tar -xzf candidate.tar.gz && chown -R www:www '+shlex.quote(directory))
 manifest=json.loads((e/'deploy-manifest.json').read_text())
 for f in manifest['files']:
  if f.endswith('.php'):ssh('/www/server/php/74/bin/php -l '+shlex.quote(directory+'/payload/'+f))
 try:
  out=ssh('/www/server/php/74/bin/php '+directory+'/install.php '+directory+' install');assert json.loads(out)['result']=='INSTALLED';(e/'install.json').write_bytes(out)
  ssh('systemctl restart mohe-010-swoole.service && systemctl is-active mohe-010-swoole.service')
  out=ssh('/www/server/php/74/bin/php '+directory+'/install.php '+directory+' verify');assert json.loads(out)['result']=='PASS';(e/'010-installed-verify.json').write_bytes(out);print(out.decode())
 except BaseException as error:
  if isinstance(error,subprocess.CalledProcessError):print(error.stderr.decode()[-3000:],file=sys.stderr)
  try:ssh('/www/server/php/74/bin/php '+directory+'/install.php '+directory+' rollback');ssh('systemctl restart mohe-010-swoole.service')
  finally:raise
elif sys.argv[1]=='verify':
 out=ssh('/www/server/php/74/bin/php '+directory+'/install.php '+directory+' verify');print(out.decode())
else:raise RuntimeError('Explicit package/deploy/verify only')
