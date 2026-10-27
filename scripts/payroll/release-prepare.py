"""形成逐实例固定包及旧版本指纹，所有业务载荷来自同一Git树，暂不部署。"""
from pathlib import Path
import json,subprocess,hashlib,os,tempfile,tarfile,io,shlex
r=Path(__file__).resolve().parents[2];e=r/'tests/customer-010/evidence/round6-payroll-release';old=r/'tests/customer-010/evidence/round6-payroll-algorithm'
git=lambda *args,**kw:subprocess.check_output(['git',*args],cwd=r,**kw)
sha=lambda b:hashlib.sha256(b).hexdigest()
fd,index=tempfile.mkstemp(prefix='payroll-release-index-');os.close(fd);os.unlink(index);env=dict(os.environ,GIT_INDEX_FILE=index)
try:
 git('read-tree','f00479df',env=env);p='后端代码/app/services/product/product/StoreProductServices.php';data=(r/p).read_bytes();blob=git('hash-object','-w','--stdin',input=data).decode().strip();assert blob=='7a64930b0a18812db1103014df2d4c98529bee19';git('update-index','--add','--cacheinfo','100644',blob,p,env=env);tree=git('write-tree',env=env).decode().strip()
finally:Path(index).unlink(missing_ok=True)
files={};source_paths=json.loads((e/'backend-source-list.json').read_text())+['后端代码/config/payroll.php','后端代码/config/basic_report.php']
for p in source_paths:files[p[len('后端代码/'):]]=git('show',tree+':'+p)
frontends={}
for term,remote in [('admin','public'),('cashier-v3','public/view_cashier_v3'),('mobile-vue3','public/view_mobile_v3')]:
 b=json.loads((old/('candidate-'+term+'-build.json')).read_text());assert git('rev-parse',tree+':前端代码/'+term)==git('rev-parse',b['tree']+':前端代码/'+term)
 for p,h in b['assets'].items():
  if term=='admin' and not(p=='system.html' or p=='basic-report-menu.css' or p.startswith('view_admin/')):continue
  data=(Path(b['output'])/p).read_bytes();assert sha(data)==h;files[remote+'/'+p]=data
 frontends[term]={'build_tree':b['tree'],'fixed_source_tree':tree,'subtree_identical':True}
committed_source=json.loads((e/'source-commit.json').read_text())['commit'] if (e/'source-commit.json').exists() else None
if committed_source:assert git('rev-parse',committed_source+'^{tree}').decode().strip()==tree
summary={'tree':tree,'business_source_commit':'931edeac5220c1eab53c62d104a1d97f8aebe9e1','baseline_restoration_source_commit':'9a0ac8f3','baseline_restoration_file':'后端代码/app/services/product/product/StoreProductServices.php','backend_files':source_paths,'frontends':frontends,'target_modes':{'rh':0,'008':0,'010':1},'business_import':False,'schema_new_empty_tables':2,'menu_groups':3,'menu_reports':21}
(e/'expanded-fixed-source.json').write_text(json.dumps(summary,ensure_ascii=False,indent=2))
for site,host in [('rh','rh-server'),('008','mohe-server'),('010','mohe-server')]:
 before=json.loads((e/(site+'-full-preflight.json')).read_text())
 # 扩展完整构建资源白名单的当前指纹，依旧只读取摘要，不下载线上源码。
 code='import pathlib,hashlib,json,sys;r=pathlib.Path("/www/wwwroot/'+site+'.cc3798.com"); print(json.dumps({p:hashlib.sha256((r/p).read_bytes()).hexdigest() if (r/p).is_file() else None for p in json.load(sys.stdin)}))'
 current=json.loads(subprocess.check_output(['ssh','-q','-o','BatchMode=yes',host,'python3 -c '+shlex.quote(code)],input=json.dumps(list(files)).encode(),stderr=subprocess.PIPE,timeout=60))
 for p,h in before['files'].items():
  if p in current:assert current[p]==h,'Baseline drift '+site+':'+p
 changed={p:b for p,b in files.items() if current[p]!=sha(b)}
 manifest={'site':site,'tree':tree,'source_commit':committed_source,'business_source_commit':'931edeac5220c1eab53c62d104a1d97f8aebe9e1','files':{p:sha(b)for p,b in changed.items()},'required_assets':{p:sha(b)for p,b in files.items() if p.startswith('public/')},'before':{'files':{p:current[p]for p in changed},'business':before['business'],'business_bounds':before['business_bounds']},'preserve_algorithm':before['mode'],'preserve_position_model':before['position_model'],'frontend_source_proofs':frontends}
 (e/(site+'-manifest.json')).write_text(json.dumps(manifest,ensure_ascii=False,indent=2))
 extra={'manifest.json':json.dumps(manifest,ensure_ascii=False,indent=2).encode(),'schema.sql':git('show',tree+':后端代码/database/upgrades/2026-10-27-独立职位工资规则/01-结构升级.sql'),'menu-library.php':git('show',tree+':后端代码/database/upgrades/2026-10-26-基础报表平台菜单分组/01-正式升级.php')}
 with tarfile.open(e/(site+'-payload.tar.gz'),'w:gz') as tar:
  for p,b in list(extra.items())+[('files/'+p,b)for p,b in changed.items()]:
   info=tarfile.TarInfo('payload/'+p);info.size=len(b);info.mode=0o600;tar.addfile(info,io.BytesIO(b))
 receipt={'site':site,'tree':tree,'files':len(changed),'sha256':sha((e/(site+'-payload.tar.gz')).read_bytes()),'source_only_baseline_restore':True,'scope_includes_prior_permission_prerequisites':True}
 (e/(site+'-package.json')).write_text(json.dumps(receipt,indent=2));print(json.dumps(receipt),flush=True)
