"""R38补漏发布：沿用现有唯一提交链，仅发布四个业务文件及同源构建，不写业务库。"""
import importlib.util, pathlib, json, subprocess, hashlib, os, tempfile, base64, sys, concurrent.futures
P=pathlib.Path
spec=importlib.util.spec_from_file_location('r38base','scripts/r38-release.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
m.E=P('tests/inventory/evidence/R38-followup-statistics');m.R=m.E/'release';m.BRANCH=m.read('trees.json')['existingBranch']
def builds():
 p=m.read('trees.json')
 for terminal in ['cashier-v3','inventory-vue3']:
  b=m.read('candidate-'+terminal+'-build.json');assert b['tree']==p['candidateTree'] and b['result']=='PASS' and b['sourceStable']
  old=json.loads((P('tests/inventory/evidence/R38-warehouse-labels-expiry/release')/('candidate-'+terminal+'-build.json')).read_text())
  # 已在当前正本提交对象中核对两端源码子树与上轮正式产物一致，不从线上文件反向构建。
  for site in ['rh','008','010']:
   live=m.read(site+'-before.json')
   if terminal=='cashier-v3':
    f=next(x for x in live['frontends']if x['kind']=='cashier'and x['root']==live['root']);assert f['sha256']==old['assets']['index.html'];assert all(old['assets'][n.removeprefix('/view_cashier_v3/')]==h for n,h in f['assets'].items())
   else:assert all(m.read(site+'-inventory-before.json')[n]==h for n,h in old['assets'].items() if n=='index.html' or n.endswith(('.js','.css')))
 backend=p['backendPaths'][0];old=m.g('show',p['baselineTree']+':'+backend)
 assert all(m.read(s+'-before.json')['backend'][backend.removeprefix('后端代码/')]==m.sha(old)for s in ['rh','008','010'])
def smoke(site,mode):
 p=m.read('trees.json');body=P('scripts/r38-followup-smoke.php').read_text();path=p['backendPaths'][0]
 if mode=='candidate':body=body.replace('// FIXED_CANDIDATE',"eval(substr(base64_decode('"+base64.b64encode(m.g('show',p['candidateTree']+':'+path)).decode()+"'),5));")
 if mode!='before':
  source=m.g('show',p['baselineTree']+':'+path).decode().replace('final class InventoryStatisticsUnifiedQueryContract','final class R38FollowupReferenceContract');body=body.replace('// FIXED_REFERENCE',"eval(substr(base64_decode('"+base64.b64encode(source.encode()).decode()+"'),5));")
 tmp='/tmp/r38-followup-'+site+'-'+mode+'.php';m.ssh(site,'install -m 600 -o www -g www /dev/stdin '+tmp,body.encode())
 try:
  cmd='/www/server/php/74/bin/php '+tmp+' '+site+' '+mode
  out=m.ssh(site,"su -s /bin/sh www -c '"+cmd+"'" if mode=='after' else cmd,t=180)
 except subprocess.CalledProcessError as e:print(e.stderr.decode()[-2000:]);raise
 finally:m.ssh(site,'rm -f '+tmp)
 v=json.loads([line for line in out.decode().splitlines()if line.startswith('{')][-1]);assert v['result']=='PASS';m.save(site+'-runtime-'+mode+'.json',v);return v
m.builds=builds;m.smoke=smoke
originalRestart=m.restart

def restartWithReadableSource(site):
 # 不改源码和工资算法；文件清单备份已完成后，仅修正应用目录的遍历/读取权限。
 manifest=m.read(site+'-manifest.json');directory='/www/wwwroot/'+site+'.cc3798.com/app/services/payroll'
 original=m.ssh(site,"stat -c '%a' "+directory).decode().strip();assert original in ['700','755']
 record={'directory':directory,'beforeMode':original,'afterMode':'755','contentChanges':False}
 m.ssh(site,'install -m 600 /dev/stdin '+manifest['backup']+'/directory-permissions.json',json.dumps(record).encode())
 if original=='700':m.ssh(site,'chmod 755 '+directory)
 m.save(site+'-directory-permissions.json',record)
 return originalRestart(site)
m.restart=restartWithReadableSource


def commit():
 p=m.read('trees.json');fixed=json.loads((m.E/'fixed-version.json').read_text());assert all(m.sha(P(n).read_bytes())==h for n,h in fixed['files'].items());assert all(m.read(s+'-preflight.json')['result']=='PASS'for s in ['rh','008','010']);builds()
 head=m.g('rev-parse','HEAD').decode().strip();assert head==p['head'];branch=m.g('symbolic-ref','HEAD').decode().strip();assert branch=='refs/heads/'+p['existingBranch'];idx=P(m.g('rev-parse','--git-path','index').decode().strip());indexHash=m.sha(idx.read_bytes())
 # 独立前置合入此前已验收上线的基线；不把这些既有功能重复算成本轮成果。
 base=m.g('commit-tree',p['baselineTree'],'-p',head,'-p',p['baselineCommit'],input='R38-00002 合入已上线库存与收银基线，保留现有补交分成修复\n'.encode()).decode().strip()
 fd,private=tempfile.mkstemp(prefix='r38-followup-');os.close(fd);os.unlink(private);env=dict(os.environ,GIT_INDEX_FILE=private)
 try:
  m.g('read-tree',p['candidateTree'],env=env)
  extra=[str(x)for x in m.E.iterdir()if x.is_file()]+['scripts/'+n for n in ['r38-followup-build.cjs','r38-followup-smoke.php','r38-followup-release.py']]
  for n in extra:m.g('update-index','--add','--cacheinfo','100644',m.g('hash-object','-w',n).decode().strip(),n,env=env)
  tree=m.g('write-tree',env=env).decode().strip();paths=m.g('diff','--name-only',base,tree).decode().splitlines();assert set(paths)==set(p['paths']+extra);m.g('diff','--check',base,tree)
  c=m.g('commit-tree',tree,'-p',base,input='R38-00002 修复库存统计仓库继承、单位白名单、返回与分页\n\n产品经理已测试验收并授权瑞昊、008、010按序发布；无数据库迁移或业务写入。\n'.encode()).decode().strip();m.g('update-ref',branch,c,head);assert m.sha(idx.read_bytes())==indexHash
  m.save('commit.json',{'commit':c,'baselineMergeCommit':base,'branch':p['existingBranch'],'tree':tree,'paths':paths,'sharedIndexUntouched':True,'newBranch':False});print('COMMIT',c,flush=True)
 finally:P(private).unlink(missing_ok=True)

if __name__=='__main__':
 mode=sys.argv[1]
 if mode=='preflight':m.preflight(sys.argv[2])
 elif mode=='commit':commit()
 elif mode=='deploy':m.deploy(sys.argv[2])
 elif mode=='finish':m.finish(sys.argv[2])
 elif mode=='push':m.push()
 else:raise RuntimeError('EXPLICIT_MODE_REQUIRED')
