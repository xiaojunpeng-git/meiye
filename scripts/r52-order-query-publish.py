"""R52 explicit sequential release. Private index, per-instance backup, CAS switch/rollback, no database mutation."""
import subprocess,json,hashlib,os,tempfile,tarfile,io,base64,sys
from pathlib import Path
R=Path('tests/order-center/evidence/r52-release-v2')
SITES={'rh':('rh-server','rh.cc3798.com','/etc/init.d/ruihao_swoole restart',"ss -lnt | grep -q ':20800 '"),'008':('mohe-server','008.cc3798.com','systemctl restart mohe-008-swoole.service','systemctl is-active --quiet mohe-008-swoole.service'),'010':('mohe-server','010.cc3798.com','systemctl restart mohe-010-swoole.service','systemctl is-active --quiet mohe-010-swoole.service')}
def run(a,**kw):return subprocess.check_output(a,**kw)
def git(*args,**kw):return run(['git',*args],**kw)
def sha(data):return hashlib.sha256(data).hexdigest()
def read(name):return json.loads((R/name).read_text())
def save(name,value):
 with (R/name).open('x') as f:json.dump(value,f,ensure_ascii=False,indent=2);f.write('\n')
def ssh(site,command,data=None):return run(['ssh','-q','-o','BatchMode=yes',SITES[site][0],command],input=data,stderr=subprocess.PIPE)
def probe(site):return json.loads(ssh(site,'/www/server/php/74/bin/php -- '+site,Path('scripts/r28-permissions-inspect.php').read_bytes()))
def runtime(site,name):
 value=json.loads(ssh(site,'/www/server/php/74/bin/php -- '+site,Path('scripts/r52-order-query-runtime.php').read_bytes()));assert value['result']=='PASS' and value['readOnly'] and value['businessWrites']==0
 save(name,value);return value
def ready():
 t=read('trees.json');assert t['candidateTree']=='b3c5bb23c8a3ebcac6986d905dedd01d5d225fc0'
 assert read('acceptance.json')['candidateTree']==t['candidateTree']
 accepted=json.loads(Path(t['acceptedFingerprintFile']).read_text())
 for file,h in accepted['fingerprints'].items():assert sha(Path(file).read_bytes())==h,'Accepted shared source changed '+file
 assert read('fixed-tests.json')['result']=='PASS' and read('fixed-tests.json')['sourceStable']
 for kind in ['baseline','candidate']:
  b=read(kind+'-cashier-v3-build.json');assert b['result']=='PASS' and b['sourceStable'] and b['tree']==t[kind+'Tree']
 # Frontend baseline must reproduce every authorized entry before candidate publication.
 old=read('baseline-cashier-v3-build.json')['assets']
 for site in SITES:
  p=read(site+'-before.json')
  for f in p['frontends']:
   if f['kind']=='cashier':
    assert all(old[path.removeprefix('/view_cashier_v3/')]==h for path,h in f['assets'].items())
  for path,data in t['preserved'].items():
   observed=p['backend'][path.removeprefix('后端代码/')]
   base=sha(git('show',t['baselineCommit']+':'+path))
   assert observed in [base,data['sha256']],'Unknown deployed dependency '+site+path
 return t
def commit():
 t=ready();assert git('rev-parse','HEAD').decode().strip()==t['head']
 for site in SITES:assert probe(site)==read(site+'-before.json'),'Baseline changed '+site
 accepted=json.loads(Path(t['acceptedFingerprintFile']).read_text())
 for path,h in accepted['fingerprints'].items():assert sha(Path(path).read_bytes())==h,'Accepted source changed '+path
 index=Path(git('rev-parse','--git-path','index').decode().strip());before=sha(index.read_bytes())
 fd,private=tempfile.mkstemp(prefix='r52-order-commit-');os.close(fd);os.unlink(private);env=dict(os.environ,GIT_INDEX_FILE=private)
 try:
  git('read-tree',t['baselineTree'],env=env)
  assert git('write-tree',env=env).decode().strip()==t['baselineTree']
  archive=git('commit-tree',t['baselineTree'],'-p',t['baselineCommit'],input='R52-BASE 独立保留已上线订单分类及服务双业绩依赖\n\n来源cb03288e；仅两份后端文件；保留R31/R32/R51。产品经理已确认基线归档及补验。\n'.encode()).decode().strip()
  git('read-tree',t['candidateTree'],env=env)
  assert set(git('-c','core.quotepath=false','diff','--name-only',t['baselineTree'],t['candidateTree']).decode().splitlines())==set(t['paths'])
  current=git('commit-tree',t['candidateTree'],'-p',archive,input='R52-00052 九类订单统一动态统计筛选闭环\n\n产品经理固定候选b3c5bb23补验通过；只提交本轮9个文件；无数据库迁移。\n'.encode()).decode().strip()
  branch='release/r52-order-query-20261024';git('update-ref','refs/heads/'+branch,current,'0'*40)
  assert sha(index.read_bytes())==before and git('rev-parse','HEAD').decode().strip()==t['head']
  save('commit.json',{'commit':current,'branch':branch,'candidateTree':t['candidateTree'],'dependencyArchiveCommit':archive,'baselineCommit':t['baselineCommit'],'pmAccepted':True,'sharedIndexUntouched':True,'files':t['paths']});print('COMMITTED',current)
 finally:Path(private).unlink(missing_ok=True)
def public_proof(site):
 rows=read(site+'-public-browser.json');assert rows and all(x['result']=='PASS' for x in rows)
def preserved_proof(site):
 assert read(site+'-price-preserved.json')['result']=='PASS'
 assert all(x['result']=='PASS' for x in read(site+'-production-css.json'))
def deploy(site):
 assert site in SITES
 t=ready();i=read('commit.json');current=i['commit'];host,domain,restart,health=SITES[site];root='/www/wwwroot/'+domain
 if site!='rh':
  assert read('push.json')['result']=='PASS'
  for previous in (['rh']if site=='008' else ['rh','008']):assert read(previous+'-verified.json')['result']=='PASS';public_proof(previous);preserved_proof(previous)
 before=read(site+'-before.json');assert probe(site)==before,'Changed before deploy '+site
 runtime(site,site+'-runtime-before.json')
 files={}
 for p in t['preserved']:
  key='formal/'+p.removeprefix('后端代码/');files[key]=git('show',current+':'+p)
 build=read('candidate-cashier-v3-build.json')
 for relative,h in build['assets'].items():
  data=(Path(build['output'])/relative).read_bytes();assert sha(data)==h
  files['formal/public/view_cashier_v3/'+relative]=data
  if site=='rh':files['preview/public/view_cashier_v3/'+relative]=data
 encoded=base64.b64encode(json.dumps(list(files)).encode()).decode()
 php="<?php $root='"+root+"';$a=[];foreach(json_decode(base64_decode('"+encoded+"'),true)as $k){$p=strpos($k,'preview/')===0?$root.'-8080/'.substr($k,8):$root.'/'.substr($k,7);$a[$k]=is_file($p)?hash_file('sha256',$p):null;}echo json_encode($a);"
 olds=json.loads(ssh(site,'/www/server/php/74/bin/php',php.encode()))
 for p in t['preserved']:assert olds['formal/'+p.removeprefix('后端代码/')]==before['backend'][p.removeprefix('后端代码/')]
 for f in before['frontends']:
  if f['kind']=='cashier':assert olds[('preview/'if f['root'].endswith('-8080')else'formal/')+f['entry']]==f['sha256']
 targets=dict(sorted({key:{'before':olds[key],'after':sha(data)}for key,data in files.items()}.items(),key=lambda item:(item[0].endswith('index.html'),item[0])))
 manifest={'site':site,'commit':current,'candidateTree':t['candidateTree'],'targets':targets,'database':'no migration or business writes','platform':'unchanged'}
 directory='/www/backups/'+site+'/r52-order-query-'+current[:8];payload=io.BytesIO()
 with tarfile.open(fileobj=payload,mode='w:gz')as tf:
  for name,data in [('payload/'+k,v)for k,v in files.items()]+[('payload/manifest.json',json.dumps(manifest).encode()),('install.php',Path('scripts/r52-order-query-install.php').read_bytes())]:
   entry=tarfile.TarInfo(name);entry.size=len(data);entry.mode=0o600;tf.addfile(entry,io.BytesIO(data))
 ssh(site,"test ! -e '"+directory+"' && mkdir -m 700 -p '"+directory+"'")
 ssh(site,'tar -xzf - -C '+directory,payload.getvalue());assert probe(site)==before,'Changed during upload'
 for p in t['preserved']:ssh(site,"/www/server/php/74/bin/php -l '"+directory+'/payload/formal/'+p.removeprefix('后端代码/')+"'")
 save(site+'-manifest.json',manifest)
 try:
  output=ssh(site,"set -e; /www/server/php/74/bin/php '"+directory+"/install.php' '"+directory+"' install; "+restart+"; sleep 2; "+health)
  (R/(site+'-deployment.log')).write_bytes(output)
  actual=json.loads(ssh(site,'/www/server/php/74/bin/php',php.encode()));assert actual=={k:v['after']for k,v in targets.items()},'Installed hash mismatch'
  after=probe(site);expected=json.loads(json.dumps(before))
  for p in t['preserved']:expected['backend'][p.removeprefix('后端代码/')]=sha(files['formal/'+p.removeprefix('后端代码/')])
  assert after['backend']==expected['backend'] and after['protected']==before['protected'] and after['upgrades']==before['upgrades'] and after['channelRules']==before['channelRules']
  for old,now in zip(before['frontends'],after['frontends']):
   if old['kind']!='cashier':assert old==now,'Protected frontend changed'
   else:
    prefix='preview/'if now['root'].endswith('-8080')else'formal/'
    assert now['sha256']==sha(files[prefix+now['entry']])
    assert all(sha(files[prefix+'public/'+p.lstrip('/')])==h for p,h in now['assets'].items())
  runtime(site,site+'-runtime-after.json')
  save(site+'-after.json',after);save(site+'-verified.json',{'site':site,'result':'PASS','commit':current,'backup':directory,'targets':len(targets),'migration':False,'preview':site=='rh'});print('DEPLOY_VERIFIED',site,current)
 except BaseException:
  rollback=ssh(site,"set -e; /www/server/php/74/bin/php '"+directory+"/install.php' '"+directory+"' rollback; "+restart+"; sleep 2; "+health)
  (R/(site+'-rollback.log')).write_bytes(rollback);save(site+'-rollback.json',{'result':'PASS'if probe(site)==before else'FAIL','backup':directory});raise
def push():
 i=read('commit.json');assert read('rh-verified.json')['result']=='PASS';public_proof('rh');preserved_proof('rh')
 output=git('push','origin','refs/heads/'+i['branch']+':refs/heads/'+i['branch'],stderr=subprocess.STDOUT)
 (R/'push.log').write_bytes(output);assert git('ls-remote','origin','refs/heads/'+i['branch']).decode().split()[0]==i['commit'];save('push.json',{'result':'PASS','commit':i['commit'],'branch':i['branch']});print('PUSH_VERIFIED',i['commit'])
def archive():
 i=read('commit.json');sites=[]
 for site in SITES:
  assert read(site+'-verified.json')['result']=='PASS';public_proof(site);preserved_proof(site)
  runtime_result=read(site+'-runtime-provenance.json');manifest=read(site+'-manifest.json')
  for p,h in runtime_result['codeSources'].items():assert h==manifest['targets']['formal/'+p]['after'],'Runtime autoload source differs'
  encoded=base64.b64encode(json.dumps(list(manifest['targets'])).encode()).decode();root='/www/wwwroot/'+SITES[site][1]
  php="<?php $r='"+root+"';$a=[];foreach(json_decode(base64_decode('"+encoded+"'),true)as $k){$p=strpos($k,'preview/')===0?$r.'-8080/'.substr($k,8):$r.'/'.substr($k,7);$a[$k]=is_file($p)?hash_file('sha256',$p):null;}echo json_encode($a);"
  actual=json.loads(ssh(site,'/www/server/php/74/bin/php',php.encode()));assert actual=={k:v['after']for k,v in manifest['targets'].items()},'Final installed target drift '+site
  sites.append({'site':site,'targets':len(actual),'backup':read(site+'-verified.json')['backup'],'publicEntries':len(read(site+'-public-browser.json')),'runtimeScope':runtime_result['scope'],'result':'PASS'})
 save('final-verification.json',{'commit':i['commit'],'candidateTree':i['candidateTree'],'sites':sites,'databaseWrites':0,'migration':False,'platformStaticPackage':'unchanged','knownLimitations':['008/010 have no available authorized staff sample in this probe; actual platform read-only scopes verified instead; staff export not asserted','Empty business types are not positive business acceptance','Three legacy shared-package structure assertions remain recorded, not silently fixed']})
 summary='# 第52轮发布结果\n\n业务提交：`'+i['commit']+'`；发布分支：`'+i['branch']+'`。固定源码树：`'+i['candidateTree']+'`。产品经理已明确补验，按本轮提交、瑞昊正式/预览核验、push、008、010顺序完成。\n\n## 本轮优化\n\n门店九类订单统一日期、更多筛选、查询及正常/全部范围；按后端字段权限动态加载文本、金额范围、枚举及日期时间；保留退款操作、原查询/分页/导出权限，查询与草稿隔离、切页不串条件，空来源在共享只读投影归一。业务提交只含九个本轮源码/测试文件，无数据库迁移。\n\n## 既有功能保留\n\n以R31/R32及R51已上线提交链77173ee8为前置；两份已上线订单分类/服务双业绩后端依赖从已验收cb03288e独立归档为`'+i['dependencyArchiveCommit']+'`，不计作本轮新功能。该基线可逐字节复现三站旧门店包。候选来自唯一正本固定树只读构建，其他未验收工作区改动未纳入。R31单价区间每站17项只读检查通过，R32双向滚动条每站两视口真实上线CSS回归通过；平台包、客户配置、迁移日志及岗位规则指纹保持不变。\n\n## 实例核验与回滚点\n\n'
 for site in sites:summary+='- '+site['site']+'：'+str(site['targets'])+'个目标文件SHA-256全匹配，'+str(site['publicEntries'])+'个公网入口真实引用及加载通过；备份 `'+site['backup']+'`。\n'
 summary+='\n备份清单保留所有原文件指纹；原哈希资源未删除。回滚由各备份目录install.php执行rollback并重启该实例Swoole，先确认本轮目标无并发变化；不得覆盖后续任务的新版本。\n\n## 验证边界\n\n固定候选九页实际组件、三种视口、226项字段/投影合同及共享筛选回归通过。瑞昊真实在职授权身份覆盖正例筛选、翻页、冻结导出计划；008/010未找到可用门店测试身份，使用既有平台只读授权核对共享查询，未修改岗位权限、未创建导出任务。空样本不记作正例验收。旧共享包结构三项失败记录保留。线上未操作真实收款、退款或业务写入。\n\n## 文件分组\n\n前端：5个本轮组件/适配/宿主文件；后端：1个共享来源归一投影；自动化测试：3个文件。工程脚本：scripts/r52-order-query-*；本目录及相邻r52-report-filters目录为证据，外部/tmp只读输入及dist为生成物，不是第二份可编辑源码。发布控制和证据单独归档，不改变已部署业务。共享HEAD、暂存区及其他任务改动保持原样。\n'
 (R/'发布结果.md').write_text(summary)
 controls=[p for p in Path('scripts').glob('r52-order-query-*')if p.is_file()]
 evidence=[p for directory in ['tests/order-center/evidence/r52-release','tests/order-center/evidence/r52-release-v2','tests/order-center/evidence/r52-report-filters']for p in Path(directory).rglob('*')if p.is_file()]
 files=sorted(set(controls+evidence));save('archive-plan.json',{'parentBusinessCommit':i['commit'],'scope':'controls/evidence only; no deployed source change','files':{str(p):sha(p.read_bytes())for p in files},'selfReference':'archive-plan.json is separately bound by the Git tree, not recursively hashed'})
 files.append(R/'archive-plan.json');index=Path(git('rev-parse','--git-path','index').decode().strip());before=sha(index.read_bytes());head=git('rev-parse','HEAD').decode().strip()
 fd,private=tempfile.mkstemp(prefix='r52-archive-');os.close(fd);os.unlink(private);env=dict(os.environ,GIT_INDEX_FILE=private)
 try:
  git('read-tree',i['commit'],env=env)
  for p in files:
   blob=git('hash-object','-w','--stdin',input=p.read_bytes()).decode().strip();git('update-index','--add','--cacheinfo','100644',blob,str(p),env=env)
  tree=git('write-tree',env=env).decode().strip();changed=git('-c','core.quotepath=false','diff','--name-only',i['commit'],tree).decode().splitlines();assert all(p.startswith(('scripts/r52-order-query-','tests/order-center/evidence/'))for p in changed)
  current=git('commit-tree',tree,'-p',i['commit'],input='R52-00052 三站发布控制及独立核验证据归档\n\n仅工程控制与证据；线上业务仍为5ff1318b；不变更客户数据、配置或权限。\n'.encode()).decode().strip()
  assert git('ls-remote','origin','refs/heads/'+i['branch']).decode().split()[0]==i['commit']
  git('update-ref','refs/heads/'+i['branch'],current,i['commit']);git('push','origin','refs/heads/'+i['branch']+':refs/heads/'+i['branch'],stderr=subprocess.STDOUT)
  assert git('ls-remote','origin','refs/heads/'+i['branch']).decode().split()[0]==current
  assert sha(index.read_bytes())==before and git('rev-parse','HEAD').decode().strip()==head
  save('archive-result.json',{'archiveCommit':current,'deployedBusinessCommit':i['commit'],'branch':i['branch'],'result':'PASS','sharedIndexUntouched':True,'files':len(files)});print('ARCHIVE_PUSH_VERIFIED',current)
 finally:Path(private).unlink(missing_ok=True)
if __name__=='__main__':
 if sys.argv[1]=='commit':commit()
 elif sys.argv[1]=='deploy':deploy(sys.argv[2])
 elif sys.argv[1]=='push':push()
 elif sys.argv[1]=='archive':archive()
 else:raise RuntimeError('EXPLICIT_ACTION_REQUIRED')
